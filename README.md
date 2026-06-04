# apiforgephp

**API observability & intelligence for Laravel — local-first, privacy-first.**

[![Packagist Version](https://img.shields.io/packagist/v/apiforge/apiforgephp?color=0066FF)](https://packagist.org/packages/apiforge/apiforgephp)
[![CI](https://img.shields.io/github/actions/workflow/status/APIForge-Organisation/sdk-composer/ci.yml?branch=main&label=CI)](https://github.com/APIForge-Organisation/sdk-composer/actions)
[![License: MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.2-brightgreen)](https://php.net)

> Track latency, error rates, and behavioral trends of your APIs. Everything stays on your machine.

**→ [Full documentation](https://apiforge-organisation.github.io/docs/)**

---

## Install

```bash
composer require apiforge/apiforgephp
```

> Requires PHP ≥ 8.2 and the `pdo_sqlite` extension (`php8.x-sqlite3` package on Debian/Ubuntu).

## Quick start

The service provider is auto-discovered by Laravel. Register the middleware in your `bootstrap/app.php` (Laravel 11+) or `app/Http/Kernel.php` (Laravel 10):

```php
// Laravel 11+ — bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\ApiForge\Laravel\ApiForgeMiddleware::class);
})
```

```php
// Laravel 10 — app/Http/Kernel.php
protected $middleware = [
    // ...
    \ApiForge\Laravel\ApiForgeMiddleware::class,
];
```

That's it. The local dashboard is now live at **`http://localhost:8000/_apiforge`** (adjust the port to match your Laravel app).

## Dashboard

In local mode, the dashboard is served automatically at `/_apiforge` on your Laravel app's port — no separate process needed.

```
http://localhost:8000/_apiforge
```

- **Health Score** (0–100) — global API health at a glance
- **Latency percentiles** — P50 / P90 / P99 per route
- **Error rates** — 4xx and 5xx breakdown
- **Automatic insights** — latency anomalies, dead endpoints, release regressions
- **Time series chart** — click any route to see its latency over time

## Configuration

Publish the config file (optional):

```bash
php artisan vendor:publish --tag=apiforge-config
```

Or configure via environment variables:

| Variable                    | Default             | Description                                       |
|-----------------------------|---------------------|---------------------------------------------------|
| `APIFORGE_ENV`              | `APP_ENV`           | Environment label (`production`, `staging`…)     |
| `APIFORGE_RELEASE`          | `APP_VERSION`       | Release/version tag for regression tracking      |
| `APIFORGE_SERVICE`          | `APP_NAME`          | Service name                                      |
| `APIFORGE_SAMPLING`         | `1.0`               | Sample rate 0.0–1.0                               |
| `APIFORGE_DASHBOARD`        | `true`              | Enable/disable the local dashboard routes         |
| `APIFORGE_DASHBOARD_PREFIX` | `_apiforge`         | URL prefix for the local dashboard                |
| `APIFORGE_CLOUD_URL`        | —                   | Cloud mode: SaaS API base URL                     |
| `APIFORGE_API_KEY`          | —                   | Cloud mode: project API key (`af_…`)              |

## Cloud mode

Send metrics to the APIForge SaaS platform instead of storing them locally:

```env
APIFORGE_CLOUD_URL=https://api.apiforge.fr
APIFORGE_API_KEY=af_your_project_key
```

```php
// bootstrap/app.php — same middleware registration, no code change needed
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\ApiForge\Laravel\ApiForgeMiddleware::class);
})
```

In cloud mode, events are buffered to a local temp file and flushed to the SaaS ingest API every 60 seconds. The local dashboard is disabled automatically.

## Release tracking

Set the release tag to enable before/after deployment comparison:

```env
APIFORGE_RELEASE=v1.4.0
```

Or dynamically in `AppServiceProvider`:

```php
config(['apiforge.release' => config('app.version')]);
```

When a new release is detected, APIForge compares P90 latency before and after and surfaces regressions automatically.

## What you get

- **Per-route latency** — P50, P90, P99 per endpoint
- **Error rate by route** — 2xx / 3xx / 4xx / 5xx breakdown
- **API Health Score** — a single 0–100 score summarising your API's health
- **Ghost route detection** — requests that match no declared Laravel route
- **Latency anomaly alerts** — Z-score detection against a 7-day baseline
- **Dead endpoint detection** — routes with no traffic for 21+ days
- **Release regression analysis** — automatic P90 comparison per deploy
- **Progressive drift detection** — slow latency increases over weeks
- **Untracked route detection** — declared routes that never received traffic
- **Inflight concurrency tracking** — approximate `inflight_avg` and `inflight_max` per route

## Graceful shutdown

The middleware flushes automatically at the end of every request. No manual teardown is needed for standard PHP-FPM deployments.

For long-running processes (Laravel Octane, FrankenPHP, Swoole), call `shutdown()` on application termination if needed — the aggregator is otherwise request-scoped and carries no state between requests.

## Known limitations

| Limitation | Detail |
|-----------|--------|
| **TTFB = total duration** | PHP-FPM sends responses atomically — true Time to First Byte cannot be measured at the middleware layer. `lat_ttfb_*` values equal `lat_*`. |
| **Approximate inflight count** | PHP workers are isolated; the inflight counter is shared via a file lock, which is accurate to the order of magnitude but not microsecond-precise. |

## Privacy by design

- Request and response bodies are never read
- No PII captured — only route patterns, HTTP methods, status codes, and timing
- Route parameters are normalised (`/users/42` → `/users/:id`, UUIDs → `/:uuid`)
- SQLite database stays entirely on your server in local mode
- Sampling rate can be set below 1.0 to reduce storage footprint

## Requirements

- PHP ≥ 8.2
- Laravel ≥ 10.0
- `ext-pdo_sqlite` — install with `apt install php8.x-sqlite3` if not present

## License

MIT — see [LICENSE](LICENSE).
