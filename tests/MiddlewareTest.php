<?php

declare(strict_types=1);

namespace ApiForge\Tests;

use ApiForge\AggregatorInterface;
use ApiForge\Laravel\ApiForgeMiddleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\TestCase;

class MiddlewareTest extends TestCase
{
    private function makeAggregator(): AggregatorInterface
    {
        return new class implements AggregatorInterface {
            public array $recorded = [];
            public int   $flushes  = 0;

            public function record(array $event): void { $this->recorded[] = $event; }
            public function flush(): void              { $this->flushes++; }
        };
    }

    public function test_should_record_metrics_for_successful_request(): void
    {
        $agg        = $this->makeAggregator();
        $middleware = new ApiForgeMiddleware($agg);

        $request  = Request::create('/api/users', 'GET');
        $response = $middleware->handle($request, fn() => new Response('ok', 200));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $agg->recorded);
        $this->assertSame('GET', $agg->recorded[0]['method']);
        $this->assertSame(200, $agg->recorded[0]['status']);
        $this->assertGreaterThan(0, $agg->recorded[0]['duration_ms']);
    }

    public function test_should_record_error_status_correctly(): void
    {
        $agg        = $this->makeAggregator();
        $middleware = new ApiForgeMiddleware($agg);

        $request = Request::create('/api/broken', 'POST');
        $middleware->handle($request, fn() => new Response('error', 500));

        $this->assertSame(500, $agg->recorded[0]['status']);
    }

    public function test_should_skip_ignored_paths(): void
    {
        $agg        = $this->makeAggregator();
        $middleware = new ApiForgeMiddleware($agg);

        $request = Request::create('/favicon.ico', 'GET');
        $middleware->handle($request, fn() => new Response('', 200));

        $this->assertCount(0, $agg->recorded);
    }

    public function test_should_normalize_unknown_path_when_no_route_matches(): void
    {
        $agg        = $this->makeAggregator();
        $middleware = new ApiForgeMiddleware($agg);

        $request = Request::create('/users/42/orders/7', 'GET');
        $middleware->handle($request, fn() => new Response('', 200));

        $this->assertSame('/users/:id/orders/:id', $agg->recorded[0]['route']);
    }

    public function test_should_flush_aggregator_after_every_request(): void
    {
        $agg        = $this->makeAggregator();
        $middleware = new ApiForgeMiddleware($agg);

        $middleware->handle(Request::create('/a', 'GET'), fn() => new Response('', 200));
        $middleware->handle(Request::create('/b', 'GET'), fn() => new Response('', 200));

        $this->assertSame(2, $agg->flushes);
    }
}
