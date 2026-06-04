<?php

declare(strict_types=1);

namespace ApiForge\Laravel;

use ApiForge\Aggregator;
use ApiForge\CloudTransport;
use ApiForge\Database;
use ApiForge\Dashboard;
use ApiForge\Insights;
use ApiForge\LocalTransport;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Request;

class ApiForgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/apiforge.php', 'apiforge');

        $this->app->singleton(Database::class, function (): Database {
            return new Database((string) config('apiforge.db_path', storage_path('.apiforge.db')));
        });

        $this->app->singleton(Aggregator::class, function (): Aggregator {
            $cloudUrl = config('apiforge.cloud_url');
            $apiKey   = config('apiforge.api_key');

            $transport = ($cloudUrl && $apiKey)
                ? new CloudTransport(
                    (string) $cloudUrl,
                    (string) $apiKey,
                    (string) config('apiforge.service', 'default'),
                    (int) config('apiforge.flush_interval', 60),
                  )
                : new LocalTransport($this->app->make(Database::class));

            return new Aggregator($transport);
        });

        $this->app->bind(ApiForgeMiddleware::class, function (): ApiForgeMiddleware {
            return new ApiForgeMiddleware($this->app->make(Aggregator::class), [
                'ignore_paths' => config('apiforge.ignore_paths', ['/favicon.ico']),
                'sampling'     => (float) config('apiforge.sampling', 1.0),
                'env'          => (string) config('apiforge.env', app()->environment()),
                'release_tag'  => config('apiforge.release') ?? env('APP_VERSION'),
            ]);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/apiforge.php' => config_path('apiforge.php'),
            ], 'apiforge-config');
        }

        $isCloud = (bool) config('apiforge.cloud_url');
        if (!$isCloud && config('apiforge.dashboard_enabled', true)) {
            $this->registerDashboardRoutes();
        }

        if ($isCloud) {
            $this->app->booted(fn() => $this->syncKnownRoutes());
        }
    }

    private function syncKnownRoutes(): void
    {
        $cloudUrl = config('apiforge.cloud_url');
        $apiKey   = config('apiforge.api_key');
        if (!$cloudUrl || !$apiKey) {
            return;
        }

        // Use a flag file to avoid re-registering on every request
        $flag = sys_get_temp_dir() . '/apiforgephp_routes_' . substr(md5((string) $apiKey), 0, 8) . '.flag';
        if (file_exists($flag) && (time() - (int) filemtime($flag)) < 3600) {
            return;
        }

        touch($flag);

        $routes = [];
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $routes[] = ['route' => '/' . ltrim($route->uri(), '/'), 'method' => $method];
            }
        }

        if (!empty($routes)) {
            $transport = new CloudTransport(
                (string) $cloudUrl,
                (string) $apiKey,
                (string) config('apiforge.service', 'default'),
            );
            $transport->writeRoutes($routes);
        }
    }

    private function registerDashboardRoutes(): void
    {
        $prefix = (string) config('apiforge.dashboard_prefix', '_apiforge');
        $db     = $this->app->make(Database::class);

        Route::prefix($prefix)->group(function () use ($db): void {
            Route::get('/', fn() => response(Dashboard::getHtml(), 200, ['Content-Type' => 'text/html; charset=utf-8']));
            Route::get('/api/summary',          fn() => $this->apiSummary($db));
            Route::get('/api/routes',           fn(Request $req) => $this->apiRoutes($db, $req));
            Route::get('/api/timeseries',       fn(Request $req) => $this->apiTimeseries($db, $req));
            Route::get('/api/global-timeseries',fn(Request $req) => $this->apiGlobalTimeseries($db, $req));
            Route::get('/api/releases',         fn() => response()->json($db->getReleases()));
        });
    }

    private function apiSummary(Database $db): \Illuminate\Http\JsonResponse
    {
        $data   = $db->getSummary();
        $health = Insights::computeHealthScore($db);
        $all    = Insights::getInsights($db);
        $total  = (int) ($data['recent']['calls_total'] ?? 0);
        $errors = (int) ($data['recent']['calls_4xx'] ?? 0) + (int) ($data['recent']['calls_5xx'] ?? 0);

        return response()->json([
            'health_score'    => $health,
            'calls_24h'       => $total,
            'error_rate_24h'  => $total > 0 ? round($errors / $total * 100, 2) : 0.0,
            'avg_p90_24h'     => isset($data['recent']['avg_p90']) ? round((float) $data['recent']['avg_p90'], 2) : null,
            'avg_p99_24h'     => isset($data['recent']['avg_p99']) ? round((float) $data['recent']['avg_p99'], 2) : null,
            'active_routes'   => $data['activeRoutes'],
            'total_routes'    => $data['totalRoutes'],
            'insights_count'  => count($all),
            'insights'        => $all,
        ]);
    }

    private function apiRoutes(Database $db, Request $req): \Illuminate\Http\JsonResponse
    {
        $hours     = (int) $req->query('hours', 24);
        $routes    = $db->getRoutes($hours);
        $untracked = array_map(fn($r) => array_merge($r, [
            'calls' => 0, 'calls_2xx' => 0, 'calls_4xx' => 0, 'calls_5xx' => 0,
            'p50' => null, 'p90' => null, 'p99' => null, 'lat_max' => null, 'untracked' => true,
        ]), $db->getUntrackedRoutes());

        return response()->json(array_merge($routes, $untracked));
    }

    private function apiTimeseries(Database $db, Request $req): \Illuminate\Http\JsonResponse
    {
        $route  = (string) $req->query('route', '');
        $method = (string) $req->query('method', '');
        $hours  = (int) $req->query('hours', 24);

        if (!$route || !$method) {
            return response()->json(['error' => 'route and method are required'], 400);
        }

        return response()->json($db->getTimeSeries($route, $method, $hours));
    }

    private function apiGlobalTimeseries(Database $db, Request $req): \Illuminate\Http\JsonResponse
    {
        return response()->json($db->getGlobalTimeSeries((int) $req->query('hours', 24)));
    }
}
