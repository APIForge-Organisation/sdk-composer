<?php

declare(strict_types=1);

namespace ApiForge\Tests;

use ApiForge\Aggregator;
use ApiForge\TransportInterface;
use PHPUnit\Framework\TestCase;

class AggregatorTest extends TestCase
{
    private function makeTransport(): TransportInterface
    {
        return new class implements TransportInterface {
            public array $received = [];
            public int   $calls    = 0;

            public function write(array $events): void
            {
                $this->received = array_merge($this->received, $events);
                $this->calls++;
            }
        };
    }

    public function test_should_not_flush_immediately_after_record(): void
    {
        $transport = $this->makeTransport();
        $agg = new Aggregator($transport);

        $agg->record(['route' => '/foo', 'method' => 'GET', 'status' => 200, 'duration_ms' => 10.0]);

        $this->assertCount(0, $transport->received, 'transport.write should not be called before flush()');
    }

    public function test_should_flush_buffered_events_to_transport(): void
    {
        $transport = $this->makeTransport();
        $agg = new Aggregator($transport);

        $agg->record(['route' => '/a', 'method' => 'GET',  'status' => 200, 'duration_ms' => 10.0]);
        $agg->record(['route' => '/b', 'method' => 'POST', 'status' => 201, 'duration_ms' => 20.0]);
        $agg->flush();

        $this->assertCount(2, $transport->received);
        $this->assertSame('/a', $transport->received[0]['route']);
        $this->assertSame('/b', $transport->received[1]['route']);
    }

    public function test_should_clear_buffer_after_flush(): void
    {
        $transport = $this->makeTransport();
        $agg = new Aggregator($transport);

        $agg->record(['route' => '/x', 'method' => 'GET', 'status' => 200, 'duration_ms' => 5.0]);
        $agg->flush();
        $agg->flush(); // second flush must not re-send

        $this->assertSame(1, $transport->calls, 'transport.write called more than once');
    }

    public function test_should_not_call_transport_on_empty_flush(): void
    {
        $transport = $this->makeTransport();
        $agg = new Aggregator($transport);

        $agg->flush();

        $this->assertSame(0, $transport->calls);
    }
}
