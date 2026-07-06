<?php

declare(strict_types=1);

namespace ApiForge;

class LocalTransport implements TransportInterface
{
    private const CIRCUIT_OPEN_SEC  = 60;
    private const FAILURE_THRESHOLD = 5;

    private int $failures  = 0;
    private int $openUntil = 0;

    public function __construct(private readonly Database $db)
    {
    }

    public function write(array $events): void
    {
        if (empty($events) || time() < $this->openUntil) {
            return;
        }

        try {
            foreach ($events as $event) {
                $this->db->insertEvent($event);
            }
            $this->failures = 0;
        } catch (\Throwable $e) {
            $this->failures++;
            if ($this->failures >= self::FAILURE_THRESHOLD) {
                $this->openUntil = time() + self::CIRCUIT_OPEN_SEC;
                $this->failures  = 0;
                error_log('[apiforgephp] SQLite write failures — pausing for ' . self::CIRCUIT_OPEN_SEC . 's. Error: ' . $e->getMessage());
            }
        }
    }
}
