<?php

declare(strict_types=1);

namespace ApiForge\Laravel;

use ApiForge\AggregatorInterface;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiForgeMiddleware
{
    private array $ignorePaths;
    private float $sampling;
    private string $env;
    private ?string $releaseTag;
    // Shared counter file — tracks concurrent requests across FPM workers
    private ?string $inflightPath;

    public function __construct(
        private readonly AggregatorInterface $aggregator,
        array $options = [],
    ) {
        $this->ignorePaths  = $options['ignore_paths'] ?? ['/favicon.ico'];
        $this->sampling     = (float) ($options['sampling']    ?? 1.0);
        $this->env          = (string) ($options['env']        ?? 'production');
        $this->releaseTag   = $options['release_tag'] ?? null;
        $this->inflightPath = $options['inflight_path'] ?? null;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $start    = hrtime(true);
        $inflight = 1;

        try {
            $inflight = $this->incrementInflight();
        } catch (\Throwable $e) {
            error_log('[apiforgephp] incrementInflight error: ' . $e->getMessage());
        }

        try {
            $response = $next($request);
        } finally {
            try {
                $this->decrementInflight();
            } catch (\Throwable $e) {
                error_log('[apiforgephp] decrementInflight error: ' . $e->getMessage());
            }
        }

        try {
            $duration = (hrtime(true) - $start) / 1_000_000;
            $path     = '/' . ltrim($request->path(), '/');

            if (!in_array($path, $this->ignorePaths, true)) {
                $sampled = $this->sampling >= 1.0 || (mt_rand() / mt_getrandmax()) <= $this->sampling;
                if ($sampled) {
                    $this->record($request, $response, $duration, $inflight);
                }
            }
        } catch (\Throwable $e) {
            error_log('[apiforgephp] middleware record error: ' . $e->getMessage());
        }

        return $response;
    }

    private function record(Request $request, Response $response, float $durationMs, int $inflight): void
    {
        $route   = $request->route();
        $pattern = $route !== null
            ? '/' . ltrim($route->uri(), '/')
            : $this->normalizePath($request->path());

        $contentLen = (int) ($request->header('Content-Length') ?: 0);

        $this->aggregator->record([
            'route'         => $pattern,
            'method'        => $request->method(),
            'status'        => $response->getStatusCode(),
            'duration_ms'   => $durationMs,
            'ttfb_ms'       => $durationMs, // PHP-FPM cannot measure true TTFB
            'response_size' => $this->responseSize($response),
            'request_size'  => $contentLen ?: null,
            'env'           => $this->env,
            'release_tag'   => $this->releaseTag,
            'is_ghost'      => $route === null,
            'inflight'      => $inflight,
        ]);

        $this->aggregator->flush();
    }

    // ── Inflight counter (shared across FPM workers via file) ─────────────────

    private function incrementInflight(): int
    {
        if ($this->inflightPath === null) {
            return 1;
        }

        $fh = @fopen($this->inflightPath, 'c+');
        if ($fh === false) {
            return 1;
        }

        flock($fh, LOCK_EX);
        $val = (int) fread($fh, 32);
        $val++;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) $val);
        flock($fh, LOCK_UN);
        fclose($fh);

        return $val;
    }

    private function decrementInflight(): void
    {
        if ($this->inflightPath === null) {
            return;
        }

        $fh = @fopen($this->inflightPath, 'c+');
        if ($fh === false) {
            return;
        }

        flock($fh, LOCK_EX);
        $val = max(0, (int) fread($fh, 32) - 1);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) $val);
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    private function responseSize(Response $response): ?int
    {
        if ($response instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
            return null;
        }

        $len = strlen($response->getContent());
        return $len > 0 ? $len : null;
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $path = (string) preg_replace('/\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '/:uuid', $path);
        return (string) preg_replace('/\/\d+/', '/:id', $path);
    }
}
