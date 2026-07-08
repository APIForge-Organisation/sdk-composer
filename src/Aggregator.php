<?php

declare(strict_types=1);

namespace ApiForge;

class Aggregator implements AggregatorInterface
{
    private array $buffer = [];

    public function __construct(private readonly TransportInterface $transport)
    {
    }

    public function record(array $event): void
    {
        $this->buffer[] = $event;
    }

    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $events        = $this->buffer;
        $this->buffer  = [];
        $this->transport->write($events);
    }
}
