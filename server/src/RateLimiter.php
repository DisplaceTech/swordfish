<?php

namespace Swordfish\Server;

use Predis\Client;

class RateLimiter
{
    private const LIMIT = 30;
    private const WINDOW = 60;

    public function __construct(private Client $redis) {}

    /**
     * Check whether the given IP is within the rate limit for the current minute window.
     *
     * Uses a Redis counter keyed by IP and minute window. The counter is set to expire
     * after WINDOW seconds so keys clean up automatically.
     *
     * @param string $ip Client IP address
     * @return bool true if the request is allowed, false if the limit is exceeded
     */
    public function isAllowed(string $ip): bool
    {
        $window = (int) floor(time() / self::WINDOW);
        $key    = "rate_limit:{$ip}:{$window}";

        $count = (int) $this->redis->incr($key);
        if ($count === 1) {
            $this->redis->expire($key, self::WINDOW);
        }

        return $count <= self::LIMIT;
    }
}
