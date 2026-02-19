<?php

namespace Swordfish\Server;

use Predis\Client;

class ViewCounter
{
    const UNLIMITED = -1;

    /**
     * Initialize the view counter for a secret with the given TTL.
     * A views value of UNLIMITED (-1) stores the sentinel and skips decrement logic.
     *
     * @param Client $redis
     * @param string $id     Secret ID
     * @param int    $views  Remaining view count, or UNLIMITED (-1)
     * @param int    $ttl    TTL in seconds, matching the secret's TTL
     */
    public static function initialize(Client $redis, string $id, int $views, int $ttl): void
    {
        $redis->setex("views:{$id}", $ttl, $views);
    }

    /**
     * Decrement the view counter and return the new remaining count.
     * Returns UNLIMITED (-1) without decrementing when the sentinel is stored.
     *
     * @param Client $redis
     * @param string $id
     * @return int  New remaining count, or UNLIMITED (-1)
     */
    public static function decrement(Client $redis, string $id): int
    {
        $current = (int) $redis->get("views:{$id}");

        if ($current === self::UNLIMITED) {
            return self::UNLIMITED;
        }

        return (int) $redis->decr("views:{$id}");
    }

    /**
     * Atomically delete the secret, verifier, and views keys when the counter
     * reaches zero. Returns true if keys were deleted, false otherwise.
     *
     * @param Client $redis
     * @param string $id
     * @param int    $remaining  Count returned by decrement()
     * @return bool
     */
    public static function deleteIfExhausted(Client $redis, string $id, int $remaining): bool
    {
        if ($remaining !== 0) {
            return false;
        }

        $redis->del(["secret:{$id}", "verifier:{$id}", "views:{$id}"]);
        return true;
    }
}
