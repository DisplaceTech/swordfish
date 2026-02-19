<?php

namespace Swordfish\Server;

use Predis\Client;

class MetricsService
{
    public function __construct(private Client $redis) {}

    /**
     * Record a secret creation event.
     *
     * Increments the hourly created count and bytes-stored counter for today.
     *
     * @param int $bytes Number of bytes stored
     */
    public function recordCreated(int $bytes): void
    {
        $date = date('Y-m-d');
        $hour = date('H');
        $this->redis->hincrby("metrics:created:{$date}", $hour, 1);
        $this->redis->hincrby("metrics:bytes_stored:{$date}", $hour, $bytes);
    }

    /**
     * Record a successful secret retrieval event.
     *
     * Increments the hourly retrieved count and bytes-retrieved counter for today.
     *
     * @param int $bytes Number of bytes retrieved
     */
    public function recordRetrieved(int $bytes): void
    {
        $date = date('Y-m-d');
        $hour = date('H');
        $this->redis->hincrby("metrics:retrieved:{$date}", $hour, 1);
        $this->redis->hincrby("metrics:bytes_retrieved:{$date}", $hour, $bytes);
    }

    /**
     * Record a secret-expired-without-retrieval event (lazy detection).
     *
     * Called when a retrieval attempt finds no secret in Redis, indicating
     * the secret expired before it was retrieved.
     */
    public function recordExpired(): void
    {
        $date = date('Y-m-d');
        $hour = date('H');
        $this->redis->hincrby("metrics:expired:{$date}", $hour, 1);
    }

    /**
     * Return all metric counters for the given date.
     *
     * Each counter is a hash keyed by two-digit hour (00–23).
     *
     * @param string $date Date in YYYY-MM-DD format; defaults to today
     * @return array{created: array, bytes_stored: array, retrieved: array, bytes_retrieved: array, expired: array}
     */
    public function getMetrics(string $date = ''): array
    {
        if ($date === '') {
            $date = date('Y-m-d');
        }

        return [
            'created'        => $this->redis->hgetall("metrics:created:{$date}") ?: [],
            'bytes_stored'   => $this->redis->hgetall("metrics:bytes_stored:{$date}") ?: [],
            'retrieved'      => $this->redis->hgetall("metrics:retrieved:{$date}") ?: [],
            'bytes_retrieved' => $this->redis->hgetall("metrics:bytes_retrieved:{$date}") ?: [],
            'expired'        => $this->redis->hgetall("metrics:expired:{$date}") ?: [],
        ];
    }
}
