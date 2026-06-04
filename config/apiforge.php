<?php

return [
    /*
     * Observability mode: 'local' stores metrics in a local SQLite database with an
     * embedded dashboard. 'cloud' ships metrics to the APIForge SaaS platform.
     */
    'cloud_url' => env('APIFORGE_CLOUD_URL'),
    'api_key'   => env('APIFORGE_API_KEY'),

    /*
     * Local mode — SQLite file path and dashboard settings.
     */
    'db_path'          => storage_path('.apiforge.db'),
    'dashboard_enabled'=> (bool) env('APIFORGE_DASHBOARD', true),
    'dashboard_prefix' => env('APIFORGE_DASHBOARD_PREFIX', '_apiforge'),

    /*
     * Metadata attached to every recorded metric.
     */
    'env'     => env('APIFORGE_ENV', env('APP_ENV', 'production')),
    'release' => env('APIFORGE_RELEASE', env('APP_VERSION')),
    'service' => env('APIFORGE_SERVICE', env('APP_NAME', 'default')),

    /*
     * Sampling rate between 0.0 and 1.0 (default: record every request).
     */
    'sampling'        => (float) env('APIFORGE_SAMPLING', 1.0),
    'flush_interval'  => (int) env('APIFORGE_FLUSH_INTERVAL', 60),

    /*
     * Paths that will never be recorded (exact match on the URI path).
     */
    'ignore_paths' => [
        '/favicon.ico',
    ],
];
