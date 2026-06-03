<?php

declare(strict_types=1);

namespace ApiForge;

interface TransportInterface
{
    /** @param array<int, array<string, mixed>> $events */
    public function write(array $events): void;
}
