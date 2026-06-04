<?php

declare(strict_types=1);

namespace ApiForge;

use PDO;

class Database
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $this->pdo = new PDO('sqlite:' . $path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->init();
    }

    private function init(): void
    {
        $this->pdo->exec("PRAGMA journal_mode = WAL");
        $this->pdo->exec("PRAGMA synchronous = NORMAL");
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS api_raw_events (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                route         TEXT    NOT NULL,
                method        TEXT    NOT NULL,
                status        INTEGER NOT NULL,
                duration_ms   REAL    NOT NULL,
                ttfb_ms       REAL,
                response_size INTEGER,
                request_size  INTEGER,
                env           TEXT    NOT NULL DEFAULT 'production',
                release_tag   TEXT,
                is_ghost      INTEGER NOT NULL DEFAULT 0,
                created_at    INTEGER NOT NULL DEFAULT (strftime('%s', 'now'))
            );
            CREATE INDEX IF NOT EXISTS idx_events_route_ts ON api_raw_events (route, method, created_at);
            CREATE INDEX IF NOT EXISTS idx_events_ts       ON api_raw_events (created_at);
            CREATE INDEX IF NOT EXISTS idx_events_release  ON api_raw_events (release_tag) WHERE release_tag IS NOT NULL;

            CREATE TABLE IF NOT EXISTS known_routes (
                route      TEXT    NOT NULL,
                method     TEXT    NOT NULL,
                first_seen INTEGER NOT NULL DEFAULT (strftime('%s', 'now')),
                PRIMARY KEY (route, method)
            );
        ");
    }

    public function insertEvent(array $e): void
    {
        $this->pdo->prepare("
            INSERT INTO api_raw_events
                (route, method, status, duration_ms, ttfb_ms, response_size, request_size, env, release_tag, is_ghost)
            VALUES
                (:route, :method, :status, :duration_ms, :ttfb_ms, :response_size, :request_size, :env, :release_tag, :is_ghost)
        ")->execute([
            'route'         => $e['route'],
            'method'        => $e['method'],
            'status'        => $e['status'],
            'duration_ms'   => $e['duration_ms'],
            'ttfb_ms'       => $e['ttfb_ms'] ?? null,
            'response_size' => $e['response_size'] ?? null,
            'request_size'  => $e['request_size'] ?? null,
            'env'           => $e['env'] ?? 'production',
            'release_tag'   => $e['release_tag'] ?? null,
            'is_ghost'      => ($e['is_ghost'] ?? false) ? 1 : 0,
        ]);
    }

    public function getSummary(): array
    {
        $since24h = time() - 86_400;
        $since7d  = time() - 604_800;

        $counts = $this->queryRow("
            SELECT
                COUNT(*) as calls_total,
                SUM(CASE WHEN status >= 200 AND status < 300 THEN 1 ELSE 0 END) as calls_2xx,
                SUM(CASE WHEN status >= 300 AND status < 400 THEN 1 ELSE 0 END) as calls_3xx,
                SUM(CASE WHEN status >= 400 AND status < 500 THEN 1 ELSE 0 END) as calls_4xx,
                SUM(CASE WHEN status >= 500              THEN 1 ELSE 0 END) as calls_5xx
            FROM api_raw_events WHERE created_at >= ? AND is_ghost = 0
        ", [$since24h]);

        $dur24h  = $this->fetchColumn("SELECT duration_ms FROM api_raw_events WHERE created_at >= ? AND is_ghost = 0 ORDER BY duration_ms", [$since24h]);
        $durPrev = $this->fetchColumn("SELECT duration_ms FROM api_raw_events WHERE created_at >= ? AND created_at < ? AND is_ghost = 0 ORDER BY duration_ms", [$since7d, $since24h]);

        $counts['avg_p90'] = $this->percentile($dur24h, 0.90);
        $counts['avg_p99'] = $this->percentile($dur24h, 0.99);

        return [
            'recent'       => $counts,
            'baseline'     => ['baseline_p90' => $this->percentile($durPrev, 0.90)],
            'activeRoutes' => (int) $this->fetchScalar("SELECT COUNT(DISTINCT route || '|' || method) FROM api_raw_events WHERE created_at >= ? AND is_ghost = 0", [$since24h]),
            'totalRoutes'  => (int) $this->fetchScalar("SELECT COUNT(DISTINCT route || '|' || method) FROM api_raw_events WHERE is_ghost = 0"),
        ];
    }

    public function getRoutes(int $hours = 24): array
    {
        $since = time() - $hours * 3600;
        $rows  = $this->fetchRouteStats($since);
        $durs  = $this->fetchDurationsByRoute($since);

        foreach ($rows as &$r) {
            $key     = $r['method'] . '|' . $r['route'];
            $sorted  = $durs[$key] ?? [];
            $r['p50'] = $this->percentile($sorted, 0.50);
            $r['p90'] = $this->percentile($sorted, 0.90);
            $r['p99'] = $this->percentile($sorted, 0.99);
        }

        return $rows;
    }

    private function fetchRouteStats(int $since): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                route, method, is_ghost,
                COUNT(*)                                                                  as calls,
                SUM(CASE WHEN status >= 200 AND status < 300 THEN 1 ELSE 0 END)          as calls_2xx,
                SUM(CASE WHEN status >= 300 AND status < 400 THEN 1 ELSE 0 END)          as calls_3xx,
                SUM(CASE WHEN status >= 400 AND status < 500 THEN 1 ELSE 0 END)          as calls_4xx,
                SUM(CASE WHEN status >= 500              THEN 1 ELSE 0 END)              as calls_5xx,
                AVG(duration_ms)  as lat_avg,
                MIN(duration_ms)  as lat_min,
                MAX(duration_ms)  as lat_max,
                AVG(response_size) as bytes_avg,
                AVG(request_size)  as request_size_avg
            FROM api_raw_events
            WHERE created_at >= ?
            GROUP BY route, method, is_ghost
            ORDER BY is_ghost ASC, calls DESC
            LIMIT 100
        ");
        $stmt->execute([$since]);
        return $stmt->fetchAll();
    }

    /** @return array<string, float[]> keys are "METHOD|/route", values are sorted ASC */
    private function fetchDurationsByRoute(int $since): array
    {
        $stmt = $this->pdo->prepare("
            SELECT method, route, duration_ms
            FROM api_raw_events
            WHERE created_at >= ?
            ORDER BY route, method, duration_ms
        ");
        $stmt->execute([$since]);

        $grouped = [];
        while ($row = $stmt->fetch()) {
            $grouped[$row['method'] . '|' . $row['route']][] = (float) $row['duration_ms'];
        }

        return $grouped;
    }

    public function getTimeSeries(string $route, string $method, int $hours = 24): array
    {
        $since  = time() - $hours * 3600;
        $bucket = 60;

        // Fetch raw durations per bucket — percentiles computed in PHP like Node.js does
        $stmt = $this->pdo->prepare("
            SELECT
                CAST(created_at / :b AS INTEGER) * :b AS bucket_ts,
                duration_ms,
                status
            FROM api_raw_events
            WHERE route = :route AND method = :method AND created_at >= :since
            ORDER BY bucket_ts ASC, duration_ms ASC
        ");
        $stmt->execute(['b' => $bucket, 'route' => $route, 'method' => $method, 'since' => $since]);
        $rows = $stmt->fetchAll();

        return $this->aggregateTimeSeries($rows);
    }

    public function getGlobalTimeSeries(int $hours = 24): array
    {
        $since  = time() - $hours * 3600;
        $bucket = 60;

        $stmt = $this->pdo->prepare("
            SELECT
                CAST(created_at / :b AS INTEGER) * :b AS bucket_ts,
                duration_ms,
                status
            FROM api_raw_events
            WHERE created_at >= :since AND is_ghost = 0
            ORDER BY bucket_ts ASC, duration_ms ASC
        ");
        $stmt->execute(['b' => $bucket, 'since' => $since]);
        $rows = $stmt->fetchAll();
        return $this->aggregateTimeSeries($rows);
    }

    /** @param array<int, array{bucket_ts: int, duration_ms: float, status: int}> $rows */
    private function aggregateTimeSeries(array $rows): array
    {
        $buckets = [];
        foreach ($rows as $r) {
            $ts = (int) $r['bucket_ts'];
            if (!isset($buckets[$ts])) {
                $buckets[$ts] = ['bucket_ts' => $ts, 'durations' => [], 'errors' => 0, 'redirects' => 0];
            }
            $buckets[$ts]['durations'][] = (float) $r['duration_ms'];
            $s = (int) $r['status'];
            if ($s >= 500)             $buckets[$ts]['errors']++;
            if ($s >= 300 && $s < 400) $buckets[$ts]['redirects']++;
        }

        $result = [];
        foreach ($buckets as $b) {
            $sorted = $b['durations']; // already sorted ASC by SQL
            $n      = count($sorted);
            $result[] = [
                'bucket_ts' => $b['bucket_ts'],
                'calls'     => $n,
                'p50'       => $this->percentile($sorted, 0.50),
                'p90'       => $this->percentile($sorted, 0.90),
                'p99'       => $this->percentile($sorted, 0.99),
                'errors'    => $b['errors'],
                'redirects' => $b['redirects'],
            ];
        }

        return $result;
    }

    public function getDeadCandidates(int $inactiveDays = 21): array
    {
        $cutoff = time() - $inactiveDays * 86_400;

        $stmt = $this->pdo->prepare("
            SELECT route, method, MAX(created_at) as last_seen
            FROM api_raw_events
            WHERE is_ghost = 0
            GROUP BY route, method
            HAVING last_seen < ?
            ORDER BY last_seen ASC
        ");
        $stmt->execute([$cutoff]);
        return $stmt->fetchAll();
    }

    public function getReleaseComparison(): ?array
    {
        $latest = $this->queryRow("
            SELECT release_tag, MIN(created_at) as release_ts
            FROM api_raw_events
            WHERE release_tag IS NOT NULL AND release_tag != ''
            GROUP BY release_tag
            ORDER BY release_ts DESC
            LIMIT 1
        ");

        if (!$latest) {
            return null;
        }

        ['release_tag' => $tag, 'release_ts' => $ts] = $latest;
        $window = $ts - 86_400;

        $before = $this->pdo->prepare("
            SELECT route, method, AVG(duration_ms) as avg_p90, COUNT(*) as calls
            FROM api_raw_events WHERE created_at >= ? AND created_at < ? GROUP BY route, method
        ");
        $before->execute([$window, $ts]);

        $after = $this->pdo->prepare("
            SELECT route, method, AVG(duration_ms) as avg_p90, COUNT(*) as calls
            FROM api_raw_events WHERE created_at >= ? AND release_tag = ? GROUP BY route, method
        ");
        $after->execute([$ts, $tag]);

        return ['release_tag' => $tag, 'release_ts' => $ts, 'before' => $before->fetchAll(), 'after' => $after->fetchAll()];
    }

    public function getLatencyAnomalyData(): array
    {
        $since1h = time() - 3_600;
        $since7d = time() - 604_800;

        $recent = $this->pdo->prepare("
            SELECT route, method, AVG(duration_ms) as avg_p99
            FROM api_raw_events WHERE created_at >= ? AND is_ghost = 0 GROUP BY route, method
        ");
        $recent->execute([$since1h]);

        $baseline = $this->pdo->prepare("
            SELECT route, method, duration_ms as lat_p99
            FROM api_raw_events WHERE created_at >= ? AND created_at < ? AND is_ghost = 0
        ");
        $baseline->execute([$since7d, $since1h]);

        return ['recent' => $recent->fetchAll(), 'baselineRows' => $baseline->fetchAll()];
    }

    public function getDriftData(): array
    {
        $since30d = time() - 30 * 86_400;

        $stmt = $this->pdo->prepare("
            SELECT route, method,
                   CAST(created_at / 86400 AS INTEGER) as day_bucket,
                   AVG(duration_ms) as p90
            FROM api_raw_events
            WHERE created_at >= ? AND is_ghost = 0
            GROUP BY route, method, day_bucket
            ORDER BY route, method, day_bucket
        ");
        $stmt->execute([$since30d]);
        return $stmt->fetchAll();
    }

    public function upsertKnownRoutes(array $routes): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO known_routes (route, method) VALUES (?, ?) ON CONFLICT DO NOTHING");

        $this->pdo->beginTransaction();
        try {
            foreach ($routes as $r) {
                $stmt->execute([$r['route'], $r['method']]);
            }
            if (!empty($routes)) {
                $keys = array_map(fn($r) => $r['route'] . '|' . $r['method'], $routes);
                $ph   = implode(',', array_fill(0, count($keys), '?'));
                $this->pdo->prepare("
                    DELETE FROM known_routes
                    WHERE route || '|' || method NOT IN ($ph)
                      AND NOT EXISTS (SELECT 1 FROM api_raw_events e WHERE e.route = known_routes.route AND e.method = known_routes.method)
                ")->execute($keys);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function getUntrackedRoutes(): array
    {
        return $this->pdo->query("
            SELECT k.route, k.method, k.first_seen
            FROM known_routes k
            WHERE NOT EXISTS (SELECT 1 FROM api_raw_events e WHERE e.route = k.route AND e.method = k.method)
            ORDER BY k.method, k.route
        ")->fetchAll();
    }

    public function getReleases(): array
    {
        return $this->pdo->query("
            SELECT release_tag, MIN(created_at) AS release_ts,
                   COUNT(DISTINCT route || '|' || method) AS routes_affected
            FROM api_raw_events
            WHERE release_tag IS NOT NULL AND release_tag != ''
            GROUP BY release_tag
            ORDER BY release_ts DESC
            LIMIT 20
        ")->fetchAll();
    }

    public function pruneOldEvents(int $days = 30): void
    {
        $this->pdo->prepare("DELETE FROM api_raw_events WHERE created_at < ?")->execute([time() - $days * 86_400]);
    }

    public function close(): void
    {
        unset($this->pdo);
    }

    /** @return float[] */
    private function fetchColumn(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_column($stmt->fetchAll(PDO::FETCH_NUM), 0);
    }

    private function queryRow(string $sql, array $params = []): array|false
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    private function fetchScalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /** @param float[] $sorted sorted ASC */
    private function percentile(array $sorted, float $p): float
    {
        if (empty($sorted)) {
            return 0.0;
        }
        $idx = (int) ceil($p * count($sorted)) - 1;
        return (float) $sorted[max(0, min($idx, count($sorted) - 1))];
    }
}
