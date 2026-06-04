<?php

declare(strict_types=1);

namespace ApiForge;

class CloudTransport implements TransportInterface
{
    private const CIRCUIT_OPEN_SEC  = 60;
    private const FAILURE_THRESHOLD = 5;
    private const FLUSH_INTERVAL    = 60;   // seconds between cloud flushes
    private const FLUSH_MAX_EVENTS  = 500;  // flush early if buffer reaches this size

    private readonly string $ingestUrl;
    private readonly string $bufferPath;
    private int $failures  = 0;
    private int $openUntil = 0;

    public function __construct(
        string $cloudUrl,
        private readonly string $apiKey,
        private readonly string $service,
    ) {
        $this->ingestUrl  = rtrim($cloudUrl, '/') . '/ingest';
        // Buffer file is keyed by API key — one file per project, shared across requests
        $this->bufferPath = sys_get_temp_dir() . '/apiforgephp_' . substr(md5($apiKey), 0, 12) . '.jsonl';
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

    // ── Buffer helpers ─────────────────────────────────────────────────────────

    private function appendToBuffer(array $events): void
    {
        $fh = @fopen($this->bufferPath, 'a');
        if ($fh === false) {
            return;
        }

        if (flock($fh, LOCK_EX)) {
            foreach ($events as $event) {
                fwrite($fh, json_encode($event, JSON_THROW_ON_ERROR) . "\n");
            }
            flock($fh, LOCK_UN);
        }

        fclose($fh);
    }

    private function bufferIsReady(): bool
    {
        if (!file_exists($this->bufferPath)) {
            return false;
        }

        clearstatcache(true, $this->bufferPath);
        $age  = time() - (int) filemtime($this->bufferPath);
        $size = (int) filesize($this->bufferPath);

        // Flush after 60s or when ~500 events accumulated (~50KB)
        return $age >= self::FLUSH_INTERVAL || $size >= self::FLUSH_MAX_EVENTS * 100;
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

        // Atomic read + truncate under exclusive lock
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            return; // another request is already flushing
        }

        $size = (int) filesize($this->bufferPath);
        if ($size === 0) {
            flock($fh, LOCK_UN);
            fclose($fh);
            return;
        }

        $content = fread($fh, $size);
        ftruncate($fh, 0);
        flock($fh, LOCK_UN);
        fclose($fh);

        $rawEvents = [];
        foreach (explode("\n", trim((string) $content)) as $line) {
            if ($line !== '') {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $rawEvents[] = $decoded;
                }
            }
        }

        if (empty($rawEvents)) {
            return;
        }

        $metrics = array_map($this->formatEvent(...), $rawEvents);

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
            // Re-append failed events so they are not lost
            $this->appendToBuffer($rawEvents);
        }
    }

    // ── Formatting ─────────────────────────────────────────────────────────────

    private function formatEvent(array $e): array
    {
        $s = (int) $e['status'];

        return [
            'route'            => $e['route'],
            'method'           => $e['method'],
            'service'          => $this->service,
            'env'              => $e['env']         ?? 'production',
            'release'          => $e['release_tag'] ?? null,
            'time'             => gmdate('Y-m-d\TH:i:s.000\Z', (int) ($e['created_at'] ?? time())),
            'calls_total'      => 1,
            'calls_2xx'        => ($s >= 200 && $s < 300) ? 1 : 0,
            'calls_3xx'        => ($s >= 300 && $s < 400) ? 1 : 0,
            'calls_4xx'        => ($s >= 400 && $s < 500) ? 1 : 0,
            'calls_5xx'        => $s >= 500 ? 1 : 0,
            'lat_p50'          => $e['duration_ms'],
            'lat_p90'          => $e['duration_ms'],
            'lat_p99'          => $e['duration_ms'],
            'lat_avg'          => $e['duration_ms'],
            'lat_ttfb_p50'     => $e['ttfb_ms'] ?? null,
            'lat_ttfb_p90'     => $e['ttfb_ms'] ?? null,
            'lat_ttfb_p99'     => $e['ttfb_ms'] ?? null,
            'bytes_avg'        => $e['response_size'] ?? null,
            'request_size_avg' => $e['request_size']  ?? null,
            'is_ghost'         => (bool) ($e['is_ghost'] ?? false),
        ];
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
            throw new \RuntimeException("HTTP $code from $url — " . substr((string) $result, 0, 200));
        }
    }
}
