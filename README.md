# apiforgephp

**API observability & intelligence for Laravel — local-first, privacy-first.**

[![Packagist Version](https://img.shields.io/packagist/v/apiforge/apiforgephp?color=0066FF)](https://packagist.org/packages/apiforge/apiforgephp)
[![CI](https://img.shields.io/github/actions/workflow/status/APIForge-Organisation/sdk-composer/ci.yml?branch=main&label=CI)](https://github.com/APIForge-Organisation/sdk-composer/actions)
[![License: MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.1-brightgreen)](https://php.net)

> Track latency, error rates, and behavioral trends of your APIs. Everything stays on your machine.

**→ [Full documentation](https://apiforge-organisation.github.io/docs/)**

---

## Install

```bash
composer require apiforge/apiforgephp
```

> Requires PHP ≥ 8.1, `pdo_sqlite` extension enabled (bundled with most PHP distributions).

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

That's it. The local dashboard is now live at **`http://localhost:8000/_apiforge`**.

## Dashboard

Open `/_apiforge` in your browser while the Laravel app is running. No separate process needed — the dashboard routes are registered automatically.

```
http://localhost:8000/_apiforge
```

## Configuration

Publish the config file (optional):

```bash
php artisan vendor:publish --tag=apiforge-config
```

Or configure via environment variables:

| Variable                    | Default             | Description                                    |
|-----------------------------|---------------------|------------------------------------------------|
| `APIFORGE_ENV`              | `APP_ENV`           | Environment label (`production`, `staging`…)  |
| `APIFORGE_RELEASE`          | `APP_VERSION`       | Release/version tag for regression tracking   |
| `APIFORGE_SERVICE`          | `APP_NAME`          | Service name                                   |
| `APIFORGE_SAMPLING`         | `1.0`               | Sample rate 0.0–1.0                            |
| `APIFORGE_DASHBOARD`        | `true`              | Enable/disable the local dashboard routes      |
| `APIFORGE_DASHBOARD_PREFIX` | `_apiforge`         | URL prefix for the dashboard                   |
| `APIFORGE_CLOUD_URL`        | —                   | Cloud mode: SaaS API base URL                  |
| `APIFORGE_API_KEY`          | —                   | Cloud mode: project API key (`af_…`)           |

## Cloud mode

```env
APIFORGE_CLOUD_URL=https://api.apiforge.fr
APIFORGE_API_KEY=af_your_project_key
```

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\ApiForge\Laravel\ApiForgeMiddleware::class);
})
```

In cloud mode, the local dashboard is disabled and metrics are flushed to the SaaS platform at the end of each request.

## What you get

- **Per-route latency** — P50, P90, P99 per endpoint
- **Error rate tracking** — 2xx / 3xx / 4xx / 5xx breakdown
- **Ghost route detection** — requests with no matching Laravel route
- **Latency anomaly alerts** — Z-score against 7-day baseline
- **Dead endpoint detection** — routes inactive for 21+ days
- **Release regression analysis** — P90 comparison before/after deploys
- **Progressive drift detection** — slow latency increases over weeks
- **Untracked route detection** — declared routes never hit

## Graceful shutdown

The middleware flushes automatically at the end of every request. No manual teardown needed.

For long-running processes (Octane, FrankenPHP, Swoole), the aggregator is request-scoped — no shared state between requests.

## Privacy by design

- No request or response bodies are ever read
- No PII captured — only route patterns, HTTP methods, status codes, and timing
- SQLite database stays entirely on your server in local mode
- Sampling rate can be set below 1.0 to reduce storage footprint

## Requirements

- PHP ≥ 8.1
- Laravel ≥ 10.0
- `ext-pdo_sqlite` (enabled by default in most PHP distributions)

## License

MIT — see [LICENSE](LICENSE).
