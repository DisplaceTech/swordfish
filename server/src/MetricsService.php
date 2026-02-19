<?php

namespace Swordfish\Server;

use Predis\Client;

class MetricsService
{
    private const TTL = 90 * 24 * 3600; // 90 days

    public function __construct(private Client $redis) {}

    private function hourKey(): string
    {
        return date('Y-m-d:H');
    }

    /**
     * Record a secret creation event: increment the created counter and bytes stored.
     *
     * @param int $bytes Number of bytes in the stored secret payload
     */
    public function recordCreated(int $bytes): void
    {
        $hour = $this->hourKey();

        $this->redis->incrby("metrics:created:{$hour}", 1);
        $this->redis->expire("metrics:created:{$hour}", self::TTL);
        $this->redis->incrby("metrics:bytes_stored:{$hour}", $bytes);
        $this->redis->expire("metrics:bytes_stored:{$hour}", self::TTL);
    }

    /**
     * Record a secret retrieval event: increment the retrieved counter and bytes retrieved.
     *
     * @param int $bytes Number of bytes in the retrieved secret payload
     */
    public function recordRetrieved(int $bytes): void
    {
        $hour = $this->hourKey();

        $this->redis->incrby("metrics:retrieved:{$hour}", 1);
        $this->redis->expire("metrics:retrieved:{$hour}", self::TTL);
        $this->redis->incrby("metrics:bytes_retrieved:{$hour}", $bytes);
        $this->redis->expire("metrics:bytes_retrieved:{$hour}", self::TTL);
    }
}
