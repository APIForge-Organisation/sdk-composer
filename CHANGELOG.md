# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
