<?php

declare(strict_types=1);

namespace ApiForge;

/**
 * Buffers raw events to a per-project temp file, aggregates them into
 * per-minute route buckets (identical to the Node.js / Python aggregators),
 * then flushes one batch to the SaaS ingest endpoint every 60 seconds.
 *
 * PHP has no long-running timer, so the 60-second window is enforced via
 * the creation timestamp written as the first line of the buffer file.
 */
class CloudTransport implements TransportInterface
{
    private const CIRCUIT_OPEN_SEC  = 60;
    private const FAILURE_THRESHOLD = 5;

    private readonly string $ingestUrl;
    private readonly string $bufferPath;
    private readonly string $tsPath;
    private int $failures  = 0;
    private int $openUntil = 0;

    /**
     * @param int $flushInterval Seconds between cloud flushes (mirrors Node/Python flushIntervalMs / 1000).
     *                           Default 60s. Lower for testing (e.g. 15s via APIFORGE_FLUSH_INTERVAL env var).
     */
    public function __construct(
        string $cloudUrl,
        private readonly string $apiKey,
        private readonly string $service,
        private readonly int $flushInterval = 60,
    ) {
        $this->ingestUrl  = rtrim($cloudUrl, '/') . '/ingest';
        $prefix           = sys_get_temp_dir() . '/apiforgephp_' . substr(md5($apiKey), 0, 12);
        $this->bufferPath = $prefix . '.jsonl';
        $this->tsPath     = $prefix . '.ts';
    }

    public function writeRoutes(array $routes): void
    {
        if (empty($routes)) {
            return;
        }

        $payload = [
            'routes' => array_map(fn($r) => [
                'route'   => $r['route'],
                'method'  => $r['method'],
                'service' => $this->service,
            ], $routes),
        ];

        try {
            $this->post($this->ingestUrl . '/routes', $payload);
        } catch (\RuntimeException $e) {
            error_log('[apiforgephp] Failed to sync route registry: ' . $e->getMessage());
        }
    }

    // ── TransportInterface ─────────────────────────────────────────────────────

    public function write(array $events): void
    {
        if (empty($events)) {
            return;
        }

        $this->appendToBuffer($events);

        if ($this->bufferIsReady()) {
            $this->flushBuffer();
        }
    }

    // ── Buffer I/O ─────────────────────────────────────────────────────────────

    private function appendToBuffer(array $events): void
    {
        // Write creation timestamp on first event of a new window
        if (!file_exists($this->tsPath)) {
            file_put_contents($this->tsPath, (string) time(), LOCK_EX);
        }

        $fh = @fopen($this->bufferPath, 'a');
        if ($fh === false) {
            return;
        }

        flock($fh, LOCK_EX);
        foreach ($events as $event) {
            fwrite($fh, json_encode($event, JSON_THROW_ON_ERROR) . "\n");
        }
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    private function bufferIsReady(): bool
    {
        if (!file_exists($this->tsPath) || !file_exists($this->bufferPath)) {
            return false;
        }

        $createdAt = (int) file_get_contents($this->tsPath);

        return $createdAt > 0 && (time() - $createdAt) >= $this->flushInterval;
    }

    private function flushBuffer(): void
    {
        if (time() < $this->openUntil) {
            return;
        }

        $fh = @fopen($this->bufferPath, 'r+');
        if ($fh === false) {
            return;
        }

        // Non-blocking lock — skip if another request is already flushing
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            return;
        }

        $size = (int) fstat($fh)['size'];
        if ($size === 0) {
            flock($fh, LOCK_UN);
            fclose($fh);
            return;
        }

        $content = fread($fh, $size);
        ftruncate($fh, 0);
        flock($fh, LOCK_UN);
        fclose($fh);

        // Reset the timestamp so the next window starts fresh
        @unlink($this->tsPath);

        $rawEvents = $this->parseBuffer((string) $content);
        if (empty($rawEvents)) {
            return;
        }

        $buckets = $this->aggregate($rawEvents);
        $metrics = array_map($this->formatBucket(...), $buckets);

        try {
            $this->post($this->ingestUrl, ['metrics' => $metrics]);
            $this->failures = 0;
        } catch (\RuntimeException $e) {
            error_log('[apiforgephp] Cloud flush error: ' . $e->getMessage());
            $this->failures++;
            if ($this->failures >= self::FAILURE_THRESHOLD) {
                $this->openUntil = time() + self::CIRCUIT_OPEN_SEC;
                $this->failures  = 0;
                error_log('[apiforgephp] Circuit open — pausing for ' . self::CIRCUIT_OPEN_SEC . 's.');
            }
            // Re-buffer failed events so they are not lost (restore ts file too)
            if (!file_exists($this->tsPath)) {
                file_put_contents($this->tsPath, (string) time(), LOCK_EX);
            }
            $this->appendToBuffer($rawEvents);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function parseBuffer(string $content): array
    {
        $events = [];
        foreach (explode("\n", trim($content)) as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }
        return $events;
    }

    // ── Aggregation (mirrors Node.js Aggregator._flush exactly) ───────────────

    /** @param array<int, array<string, mixed>> $rawEvents */
    private function aggregate(array $rawEvents): array
    {
        // Round down to the current minute — same as Node: Math.floor(Date.now() / 60_000) * 60
        $bucketTs = (int) (floor(time() / 60) * 60);
        $buckets  = [];

        foreach ($rawEvents as $e) {
            $key = ($e['method'] ?? '') . '|' . ($e['route'] ?? '') . '|' .
                   ($e['env'] ?? 'production') . '|' . ($e['release_tag'] ?? '') . '|' .
                   (($e['is_ghost'] ?? false) ? '1' : '0');

            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'method'           => $e['method'],
                    'route'            => $e['route'],
                    'env'              => $e['env'] ?? 'production',
                    'release'          => $e['release_tag'] ?? null,
                    'is_ghost'         => (bool) ($e['is_ghost'] ?? false),
                    'durations'        => [],
                    'ttfb_durations'   => [],
                    'response_sizes'   => [],
                    'request_sizes'    => [],
                    'inflight_samples' => [],
                    'status_2xx'       => 0,
                    'status_3xx'       => 0,
                    'status_4xx'       => 0,
                    'status_5xx'       => 0,
                    'status_map'       => [],
                    'bucket_ts'        => $bucketTs,
                ];
            }

            $b = &$buckets[$key];
            $b['durations'][] = (float) $e['duration_ms'];

            if (($e['ttfb_ms'] ?? null) !== null)       $b['ttfb_durations'][]   = (float) $e['ttfb_ms'];
            if (($e['response_size'] ?? null) !== null)  $b['response_sizes'][]   = (int)   $e['response_size'];
            if (($e['request_size'] ?? null) !== null)   $b['request_sizes'][]    = (int)   $e['request_size'];
            if (($e['inflight'] ?? null) !== null)       $b['inflight_samples'][] = (int)   $e['inflight'];

            $s = (int) $e['status'];
            $b['status_map'][$s] = ($b['status_map'][$s] ?? 0) + 1;
            if      ($s >= 200 && $s < 300) $b['status_2xx']++;
            elseif  ($s >= 300 && $s < 400) $b['status_3xx']++;
            elseif  ($s >= 400 && $s < 500) $b['status_4xx']++;
            elseif  ($s >= 500)             $b['status_5xx']++;
        }

        return array_values($buckets);
    }

    /** @param array<string, mixed> $b */
    private function formatBucket(array $b): array
    {
        $sorted   = $b['durations'];
        $ttfb     = $b['ttfb_durations'];
        $sizes    = $b['response_sizes'];
        $reqSizes = $b['request_sizes'];
        $inflight = $b['inflight_samples'];

        sort($sorted);
        sort($ttfb);

        $n = count($sorted);

        // status_dist — sorted by count desc, same as Node/Python
        $statusMap = $b['status_map'];
        arsort($statusMap);
        $statusDist = !empty($statusMap) ? json_encode($statusMap) : null;

        return [
            'route'            => $b['route'],
            'method'           => $b['method'],
            'service'          => $this->service,
            'env'              => $b['env'],
            'release'          => $b['release'],
            'time'             => gmdate('Y-m-d\TH:i:s.000\Z', (int) $b['bucket_ts']),
            'calls_total'      => $n,
            'calls_2xx'        => $b['status_2xx'],
            'calls_3xx'        => $b['status_3xx'],
            'calls_4xx'        => $b['status_4xx'],
            'calls_5xx'        => $b['status_5xx'],
            'status_dist'      => $statusDist,
            'lat_p50'          => $this->percentile($sorted, 0.50),
            'lat_p90'          => $this->percentile($sorted, 0.90),
            'lat_p99'          => $this->percentile($sorted, 0.99),
            'lat_avg'          => $n > 0 ? array_sum($sorted) / $n : null,
            'lat_ttfb_p50'     => !empty($ttfb) ? $this->percentile($ttfb, 0.50) : null,
            'lat_ttfb_p90'     => !empty($ttfb) ? $this->percentile($ttfb, 0.90) : null,
            'lat_ttfb_p99'     => !empty($ttfb) ? $this->percentile($ttfb, 0.99) : null,
            'bytes_avg'        => !empty($sizes)    ? array_sum($sizes)    / count($sizes)    : null,
            'request_size_avg' => !empty($reqSizes) ? array_sum($reqSizes) / count($reqSizes) : null,
            'inflight_avg'     => !empty($inflight) ? array_sum($inflight) / count($inflight) : null,
            'inflight_max'     => !empty($inflight) ? max($inflight)                          : null,
            'is_ghost'         => $b['is_ghost'],
        ];
    }

    /** @param float[] $sorted sorted ASC */
    private function percentile(array $sorted, float $p): float
    {
        if (empty($sorted)) {
            return 0.0;
        }
        $idx = max(0, min((int) ceil($p * count($sorted)) - 1, count($sorted) - 1));
        return (float) $sorted[$idx];
    }

    // ── HTTP ───────────────────────────────────────────────────────────────────

    private function post(string $url, array $payload): void
    {
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nX-API-Key: {$this->apiKey}",
                'content'       => json_encode($payload, JSON_THROW_ON_ERROR),
                'timeout'       => 5,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $ctx);
        $status = $http_response_header[0] ?? 'HTTP/1.1 500';
        $code   = (int) explode(' ', $status)[1];

        if ($result === false || $code >= 400) {
            throw new \RuntimeException("HTTP $code — " . substr((string) $result, 0, 200));
        }
    }
}
