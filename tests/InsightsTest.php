<?php

declare(strict_types=1);

namespace ApiForge\Tests;

use ApiForge\Database;
use ApiForge\Insights;
use PHPUnit\Framework\TestCase;

/**
 * @requires extension pdo_sqlite
 */
class InsightsTest extends TestCase
{
    private string   $dbFile;
    private Database $db;

    protected function setUp(): void
    {
        $this->dbFile = tempnam(sys_get_temp_dir(), 'apiforge_insights_');
        $this->db     = new Database($this->dbFile);
    }

    protected function tearDown(): void
    {
        $this->db->close();
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
    }

    public function test_should_return_empty_insights_when_no_data(): void
    {
        $insights = Insights::getInsights($this->db);

        $this->assertIsArray($insights);
        $this->assertCount(0, $insights);
    }

    public function test_should_return_null_health_score_when_no_data(): void
    {
        $score = Insights::computeHealthScore($this->db);

        $this->assertNull($score);
    }

    public function test_should_compute_full_health_score_with_all_2xx(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->db->insertEvent(['route' => '/ping', 'method' => 'GET', 'status' => 200, 'duration_ms' => 10.0, 'env' => 'test']);
        }

        $score = Insights::computeHealthScore($this->db);

        $this->assertNotNull($score);
        $this->assertGreaterThan(70, $score);
    }

    public function test_should_detect_untracked_routes(): void
    {
        $this->db->upsertKnownRoutes([
            ['route' => '/never-hit', 'method' => 'DELETE'],
        ]);

        $insights = Insights::getInsights($this->db);

        $untracked = array_filter($insights, fn($i) => $i['type'] === 'UNTRACKED');
        $this->assertCount(1, $untracked);
        $this->assertSame('/never-hit', array_values($untracked)[0]['route']);
    }
}
