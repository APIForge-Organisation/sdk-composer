<?php

declare(strict_types=1);

namespace ApiForge\Tests;

use ApiForge\CloudTransport;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class CloudTransportTest extends TestCase
{
    /**
     * Regression guard: the send cadence stays pinned at 60s. The `$flushInterval`
     * constructor parameter is internal (no public path forwards it — both call sites
     * in ApiForgeServiceProvider pass exactly three arguments); this test freezes the
     * default so it cannot be silently shortened. Real ingest throttling is enforced
     * server-side (per-key rate limit + monthly quota), not by the SDK.
     */
    public function test_flush_interval_defaults_to_60_seconds(): void
    {
        $ctor   = (new ReflectionClass(CloudTransport::class))->getConstructor();
        $params = $ctor->getParameters();

        $flushInterval = $params[3];
        $this->assertSame('flushInterval', $flushInterval->getName());
        $this->assertTrue($flushInterval->isDefaultValueAvailable());
        $this->assertSame(60, $flushInterval->getDefaultValue());
    }
}
