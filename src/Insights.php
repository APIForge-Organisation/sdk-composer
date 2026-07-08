<?php

declare(strict_types=1);

namespace ApiForge;

class Insights
{
    private const DEAD_DAYS         = 21;
    private const REGRESSION_RATIO  = 0.20;
    private const ANOMALY_Z         = 2.5;
    private const DRIFT_SLOPE       = 5.0;
    private const DRIFT_MIN_DAYS    = 7;

    public static function getInsights(Database $db): array
    {
        $insights = [];

        try {
            array_push($insights, ...self::detectLatencyAnomalies($db));
        } catch (\Throwable) {
        }
        try {
            array_push($insights, ...self::detectDeadEndpoints($db));
        } catch (\Throwable) {
        }
        try {
            array_push($insights, ...self::detectReleaseRegressions($db));
        } catch (\Throwable) {
        }
        try {
            array_push($insights, ...self::detectUntrackedRoutes($db));
        } catch (\Throwable) {
        }
        try {
            array_push($insights, ...self::detectDrift($db));
        } catch (\Throwable) {
        }

        return $insights;
    }

    public static function computeHealthScore(Database $db): ?int
    {
        try {
            $data = $db->getSummary();
            $total = (int) ($data['recent']['calls_total'] ?? 0);
            if ($total === 0) {
                return null;
            }

            $availability = min(100, (($data['recent']['calls_2xx'] ?? 0) / $total) * 100);

            $performance = 100.0;
            $baseP90     = $data['baseline']['baseline_p90'] ?? 0;
            $recentP90   = $data['recent']['avg_p90'] ?? 0;
            if ($baseP90 > 0 && $recentP90 > 0) {
                $performance = max(0, min(100, 100 - ($recentP90 / $baseP90 - 1) * 100));
            }

            $quality = $data['totalRoutes'] > 0
                ? min(100, ($data['activeRoutes'] / $data['totalRoutes']) * 100)
                : 100.0;

            return (int) round($availability * 0.30 + $performance * 0.30 + 100 * 0.25 + $quality * 0.15);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function detectUntrackedRoutes(Database $db): array
    {
        return array_map(fn ($r) => [
            'type'     => 'UNTRACKED',
            'severity' => 'info',
            'route'    => $r['route'],
            'method'   => $r['method'],
            'message'  => "`{$r['method']} {$r['route']}` is declared but has received no requests since monitoring started.",
            'data'     => ['first_seen_ts' => $r['first_seen']],
        ], $db->getUntrackedRoutes());
    }

    private static function detectDeadEndpoints(Database $db): array
    {
        $now = time();
        return array_map(function ($r) use ($now) {
            $days = (int) floor(($now - $r['last_seen']) / 86_400);
            return [
                'type'     => 'DEAD',
                'severity' => 'info',
                'route'    => $r['route'],
                'method'   => $r['method'],
                'message'  => "`{$r['method']} {$r['route']}` has received no requests in {$days} days. Consider deprecating this endpoint.",
                'data'     => ['last_seen_ts' => $r['last_seen'], 'inactive_days' => $days],
            ];
        }, $db->getDeadCandidates(self::DEAD_DAYS));
    }

    private static function detectLatencyAnomalies(Database $db): array
    {
        ['recent' => $recent, 'baselineRows' => $baseline] = $db->getLatencyAnomalyData();

        if (empty($recent) || empty($baseline)) {
            return [];
        }

        $baselineMap = [];
        foreach ($baseline as $row) {
            $baselineMap[$row['method'] . '|' . $row['route']][] = (float) $row['lat_p99'];
        }

        $insights = [];
        foreach ($recent as $r) {
            $key     = $r['method'] . '|' . $r['route'];
            $samples = $baselineMap[$key] ?? [];
            if (count($samples) < 5) {
                continue;
            }

            $mean  = array_sum($samples) / count($samples);
            $stdev = sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $samples)) / count($samples));

            if ($stdev === 0.0) {
                continue;
            }

            $z = ((float) $r['avg_p99'] - $mean) / $stdev;
            if ($z < self::ANOMALY_Z) {
                continue;
            }

            $insights[] = [
                'type'     => 'ANOMALY',
                'severity' => 'warning',
                'route'    => $r['route'],
                'method'   => $r['method'],
                'message'  => "`{$r['method']} {$r['route']}` P99 latency is abnormally high this hour (" . round((float)$r['avg_p99']) . "ms vs baseline " . round($mean) . "ms — Z-score " . round($z, 1) . ").",
                'data'     => ['current_p99' => $r['avg_p99'], 'baseline_p99' => $mean, 'z_score' => $z],
            ];
        }

        return $insights;
    }

    private static function detectReleaseRegressions(Database $db): array
    {
        $comparison = $db->getReleaseComparison();
        if (!$comparison) {
            return [];
        }

        ['release_tag' => $tag, 'before' => $before, 'after' => $after] = $comparison;

        $beforeMap = [];
        foreach ($before as $r) {
            $beforeMap[$r['method'] . '|' . $r['route']] = $r;
        }

        $insights = [];
        foreach ($after as $a) {
            $b     = $beforeMap[$a['method'] . '|' . $a['route']] ?? null;
            $bP90  = (float) ($b['avg_p90'] ?? 0);
            $aP90  = (float) ($a['avg_p90'] ?? 0);
            if (!$b || $bP90 === 0.0 || $aP90 === 0.0) {
                continue;
            }

            $delta = ($aP90 - $bP90) / $bP90;

            if ($delta >= self::REGRESSION_RATIO) {
                $insights[] = ['type' => 'PERF', 'severity' => 'error', 'route' => $a['route'], 'method' => $a['method'],
                    'message' => "`{$a['method']} {$a['route']}` P90 increased by " . round($delta * 100) . "% after {$tag}. Before: " . round($bP90) . "ms — After: " . round($aP90) . "ms.",
                    'data'    => ['release' => $tag, 'before_p90' => $bP90, 'after_p90' => $aP90, 'delta_pct' => $delta * 100],
                ];
            } elseif ($delta <= -self::REGRESSION_RATIO) {
                $insights[] = ['type' => 'OK', 'severity' => 'success', 'route' => $a['route'], 'method' => $a['method'],
                    'message' => "{$tag} improved `{$a['method']} {$a['route']}` by " . round(-$delta * 100) . "%. Before: " . round($bP90) . "ms — After: " . round($aP90) . "ms.",
                    'data'    => ['release' => $tag, 'before_p90' => $bP90, 'after_p90' => $aP90, 'delta_pct' => $delta * 100],
                ];
            }
        }

        return $insights;
    }

    private static function detectDrift(Database $db): array
    {
        $rows = $db->getDriftData();
        if (empty($rows)) {
            return [];
        }

        $byEndpoint = [];
        foreach ($rows as $row) {
            $key = $row['method'] . '|' . $row['route'];
            $byEndpoint[$key]['method'] = $row['method'];
            $byEndpoint[$key]['route']  = $row['route'];
            $byEndpoint[$key]['points'][] = ['x' => (int) $row['day_bucket'], 'y' => (float) $row['p90']];
        }

        $insights = [];
        foreach ($byEndpoint as ['method' => $method, 'route' => $route, 'points' => $points]) {
            if (count($points) < self::DRIFT_MIN_DAYS) {
                continue;
            }

            $slope = self::linearSlope($points);
            if ($slope < self::DRIFT_SLOPE) {
                continue;
            }

            $days       = $points[count($points) - 1]['x'] - $points[0]['x'];
            $projection = (int) round($slope * 30);

            $insights[] = [
                'type'     => 'DRIFT',
                'severity' => 'warning',
                'route'    => $route,
                'method'   => $method,
                'message'  => "`{$method} {$route}` has been progressively degrading for {$days} day" . ($days !== 1 ? 's' : '') . ": +" . round($slope, 1) . "ms/day. 30-day projection: +{$projection}ms.",
                'data'     => ['slope_ms_per_day' => $slope, 'observed_days' => $days, 'projection_30d_ms' => $projection],
            ];
        }

        return $insights;
    }

    /** @param array{x: int, y: float}[] $points */
    private static function linearSlope(array $points): float
    {
        $x0   = $points[0]['x'];
        $n    = count($points);
        $sumX = $sumY = $sumXY = $sumX2 = 0.0;

        foreach ($points as $p) {
            $x = $p['x'] - $x0;
            $sumX  += $x;
            $sumY  += $p['y'];
            $sumXY += $x * $p['y'];
            $sumX2 += $x * $x;
        }

        $denom = $n * $sumX2 - $sumX * $sumX;
        if ($denom === 0.0) {
            return 0.0;
        }

        return ($n * $sumXY - $sumX * $sumY) / $denom;
    }
}
