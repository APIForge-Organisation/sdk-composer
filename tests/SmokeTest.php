<?php

declare(strict_types=1);

namespace ApiForge\Tests;

use ApiForge\Aggregator;
use ApiForge\CloudTransport;
use ApiForge\Database;
use ApiForge\Dashboard;
use ApiForge\Insights;
use ApiForge\LocalTransport;
use PHPUnit\Framework\TestCase;

class SmokeTest extends TestCase
{
    /**
     * @requires extension pdo_sqlite
     */
    public function test_database_can_be_instantiated(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'apiforge_smoke_');
        $db   = new Database($path);

        $this->assertInstanceOf(Database::class, $db);

        $db->close();
        unlink($path);
    }

    /**
     * @requires extension pdo_sqlite
     */
    public function test_local_transport_and_aggregator_can_be_wired(): void
    {
        $path      = tempnam(sys_get_temp_dir(), 'apiforge_smoke_');
        $db        = new Database($path);
        $transport = new LocalTransport($db);
        $agg       = new Aggregator($transport);

        $agg->record(['route' => '/smoke', 'method' => 'GET', 'status' => 200, 'duration_ms' => 1.0, 'env' => 'test']);
        $agg->flush();

        $routes = $db->getRoutes(24);
        $this->assertCount(1, $routes);

        $db->close();
        unlink($path);
    }

    public function test_insights_class_is_accessible(): void
    {
        $this->assertTrue(class_exists(Insights::class));
        $this->assertTrue(method_exists(Insights::class, 'getInsights'));
        $this->assertTrue(method_exists(Insights::class, 'computeHealthScore'));
    }

    public function test_dashboard_returns_fallback_html_when_no_html_embedded(): void
    {
        $html = Dashboard::getHtml();

        $this->assertIsString($html);
        $this->assertNotEmpty($html);
    }

    public function test_cloud_transport_can_be_instantiated(): void
    {
        $transport = new CloudTransport('https://api.apiforge.fr', 'af_test', 'test-service');

        $this->assertInstanceOf(CloudTransport::class, $transport);
    }
}
