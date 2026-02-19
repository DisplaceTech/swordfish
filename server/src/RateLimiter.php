<?php

namespace Swordfish\Server;

use Predis\Client;

class RateLimiter
{
    private const LIMIT = 10;
    private const WINDOW = 60;

    public function __construct(private Client $redis) {}

    /**
     * Check whether the given IP is within the rate limit for the current minute window.
     *
     * Uses a Redis counter keyed by IP and minute window. The counter is set to expire
     * after WINDOW seconds so keys clean up automatically.
     *
     * @param string $ip Client IP address
     * @return array{allowed: bool, limit: int, remaining: int, reset: int}
     */
    public function isAllowed(string $ip): array
    {
        $window = (int) floor(time() / self::WINDOW);
        $key    = "rate_limit:{$ip}:{$window}";

        $count = (int) $this->redis->incr($key);
        if ($count === 1) {
            $this->redis->expire($key, self::WINDOW);
        }

        return [
            'allowed'   => $count <= self::LIMIT,
            'limit'     => self::LIMIT,
            'remaining' => max(0, self::LIMIT - $count),
            'reset'     => ($window + 1) * self::WINDOW,
        ];
    }
}
