<?php

declare(strict_types=1);

namespace ApiForge\Tests;

use ApiForge\Database;
use PHPUnit\Framework\TestCase;

/**
 * @requires extension pdo_sqlite
 */
class DatabaseTest extends TestCase
{
    private string  $dbFile;
    private Database $db;

    protected function setUp(): void
    {
        $this->dbFile = tempnam(sys_get_temp_dir(), 'apiforge_test_');
        $this->db     = new Database($this->dbFile);
    }

    protected function tearDown(): void
    {
        $this->db->close();
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    public function test_should_insert_and_count_events(): void
    {
        $this->db->insertEvent(['route' => '/users', 'method' => 'GET', 'status' => 200, 'duration_ms' => 42.0, 'env' => 'test']);
        $this->db->insertEvent(['route' => '/users', 'method' => 'GET', 'status' => 500, 'duration_ms' => 80.0, 'env' => 'test']);

        $routes = $this->db->getRoutes(24);

        $this->assertCount(1, $routes, 'Both events share the same route, expect one aggregated row');
        $this->assertSame('2', (string) $routes[0]['calls']);
        $this->assertSame('1', (string) $routes[0]['calls_2xx']);
        $this->assertSame('1', (string) $routes[0]['calls_5xx']);
    }

    public function test_should_compute_p50_p90_p99(): void
    {
        for ($i = 1; $i <= 100; $i++) {
            $this->db->insertEvent(['route' => '/ping', 'method' => 'GET', 'status' => 200, 'duration_ms' => (float) $i, 'env' => 'test']);
        }

        $routes = $this->db->getRoutes(24);

        $this->assertEqualsWithDelta(50.0, (float) $routes[0]['p50'], 5.0);
        $this->assertEqualsWithDelta(90.0, (float) $routes[0]['p90'], 5.0);
        $this->assertEqualsWithDelta(99.0, (float) $routes[0]['p99'], 5.0);
    }

    public function test_should_track_known_routes(): void
    {
        $this->db->upsertKnownRoutes([
            ['route' => '/users',   'method' => 'GET'],
            ['route' => '/users',   'method' => 'POST'],
            ['route' => '/profile', 'method' => 'GET'],
        ]);

        $untracked = $this->db->getUntrackedRoutes();

        $this->assertCount(3, $untracked, 'All routes have no traffic yet');
    }

    public function test_should_not_flag_route_with_traffic_as_untracked(): void
    {
        $this->db->upsertKnownRoutes([['route' => '/active', 'method' => 'GET']]);
        $this->db->insertEvent(['route' => '/active', 'method' => 'GET', 'status' => 200, 'duration_ms' => 10.0, 'env' => 'test']);

        $untracked = $this->db->getUntrackedRoutes();

        $this->assertCount(0, $untracked);
    }

    public function test_should_return_summary_with_health_stats(): void
    {
        $this->db->insertEvent(['route' => '/health', 'method' => 'GET', 'status' => 200, 'duration_ms' => 15.0, 'env' => 'test']);

        $summary = $this->db->getSummary();

        $this->assertArrayHasKey('recent', $summary);
        $this->assertArrayHasKey('baseline', $summary);
        $this->assertSame('1', (string) $summary['recent']['calls_total']);
    }

    public function test_should_prune_old_events(): void
    {
        // Insert event with a very old created_at by manipulating the data directly
        $this->db->insertEvent(['route' => '/old', 'method' => 'GET', 'status' => 200, 'duration_ms' => 5.0, 'env' => 'test']);

        $this->db->pruneOldEvents(-1); // cutoff = tomorrow → deletes all existing events

        $routes = $this->db->getRoutes(24 * 365);
        $this->assertCount(0, $routes);
    }
}
