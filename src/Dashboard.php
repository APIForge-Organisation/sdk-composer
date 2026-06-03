<?php

declare(strict_types=1);

namespace ApiForge;

class Dashboard
{
    // <<DASHBOARD_UI_START>>
    private const HTML_B64 = '';
    // <<DASHBOARD_UI_END>>

    public static function getHtml(): string
    {
        if (self::HTML_B64 !== '') {
            return base64_decode(self::HTML_B64);
        }

        // Fallback during local development before the sync workflow runs
        $path = __DIR__ . '/../ui.html';
        if (file_exists($path)) {
            return (string) file_get_contents($path);
        }

        return '<html><body><h1>APIForge Dashboard</h1><p>Run the sync-dashboard workflow to embed the UI.</p></body></html>';
    }
}
