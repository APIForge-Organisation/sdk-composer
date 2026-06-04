# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

---

## [2.0.0] — 2026-06-04

### Breaking Changes

- `APIFORGE_FLUSH_INTERVAL` env var **removed** — cloud flush window is fixed at **60 seconds**.
- All env var auto-reads removed from `config/apiforge.php` — `env`, `release`, `service`, `sampling`, `cloud_url`, `api_key` must be set explicitly in the published config file. The SDK no longer calls `env()` internally.
- PHP minimum version raised to **8.2** (was 8.1).

### Added

- `inflight` tracking per request via file-based atomic counter shared across PHP-FPM workers
- `inflight_avg` and `inflight_max` in route stats (local dashboard) and cloud ingest payload
- `lat_max` in local dashboard route table
- `p90` and `p99` in local dashboard time series (previously only `p50` / `AVG`)
- `POST /ingest/routes` called on ServiceProvider boot in cloud mode — prevents all routes from appearing as ghost endpoints
- File-based event buffer for cloud mode — events are batched over 60 s before a single ingest call
- `inflight` column in `api_raw_events` SQLite table (automatic migration for existing databases)

### Fixed

- Cloud mode: `time` field was using `+00:00` timezone offset format, which failed the SaaS Zod `datetime()` validation. Now uses UTC `Z` format (`2026-06-04T13:00:00.000Z`).
- Cloud mode: buffer window timestamp was unreliable due to `ftell()` behavior in PHP append mode. Now uses a separate `.ts` file.

### Migration guide

```bash
php artisan vendor:publish --tag=apiforge-config --force
```

Edit `config/apiforge.php` and set `env`, `release`, `service` explicitly:

```php
return [
    'env'     => 'production',
    'release' => 'v2.0.0',
    'service' => 'my-api',
    // ...
];
```

---

## [1.0.0] - 2026-06-03

### Added
- Laravel middleware (`ApiForgeMiddleware`) capturing route, method, status, latency, TTFB, request/response sizes
- Embedded dashboard served at `/_apiforge` via auto-discovered `ApiForgeServiceProvider`
- Local mode: SQLite storage with per-route stats, time series, dead-endpoint and drift detection
- Cloud mode: flushes metrics to the APIForge SaaS API at request end
- Insight detection: latency anomalies, dead endpoints, release regressions, latency drift, untracked routes
- Health score computation (availability × performance × quality)
- Ghost route detection (requests with no matching Laravel route)
- Circuit-breaker on both local and cloud transports
- Configurable sampling rate, ignore paths, env, release tag, service name
- Auto-discovery of the `ApiForgeServiceProvider` via Composer `extra.laravel.providers`
- GitHub Actions: CI (PHP 8.1/8.2/8.3), release (tag + Packagist notify), dashboard UI sync
