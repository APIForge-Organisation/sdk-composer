<?php

declare(strict_types=1);

namespace ApiForge;

interface AggregatorInterface
{
    /** @param array<string, mixed> $event */
    public function record(array $event): void;

    public function flush(): void;
}
