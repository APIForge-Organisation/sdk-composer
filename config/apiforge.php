<?php

return [
    /*
     * Cloud mode: set both cloud_url and api_key to send metrics to the APIForge SaaS.
     * Leave null to use local mode (SQLite + embedded dashboard).
     */
    'cloud_url' => null,
    'api_key'   => null,

    /*
     * Local mode — SQLite file path and dashboard settings.
     */
    'db_path'           => storage_path('.apiforge.db'),
    'dashboard_enabled' => true,
    'dashboard_prefix'  => '_apiforge',

    /*
     * Metadata attached to every recorded metric.
     * Set these explicitly — the SDK does not read environment variables.
     */
    'env'     => 'production',
    'release' => null,
    'service' => 'default',

    /*
     * Sampling rate between 0.0 and 1.0 (default: record every request).
     */
    'sampling' => 1.0,

    /*
     * Paths that will never be recorded (exact match on the URI path).
     */
    'ignore_paths' => [
        '/favicon.ico',
    ],
];
