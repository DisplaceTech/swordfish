<?php

namespace Swordfish\Server;

use Predis\Client;

class VerifierService
{
    public function __construct(private Client $redis) {}

    /**
     * Store a bcrypt-hashed verifier in Redis with a TTL.
     *
     * @param string $key   Redis key
     * @param string $hash  Already-hashed verifier value
     * @param int    $ttl   TTL in seconds
     */
    public function store(string $key, string $hash, int $ttl): void
    {
        $this->redis->setex($key, $ttl, $hash);
    }

    /**
     * Retrieve a stored verifier hash from Redis.
     *
     * @param string $key Redis key
     * @return string|null The stored hash, or null if not found
     */
    public function get(string $key): ?string
    {
        return $this->redis->get($key);
    }

    /**
     * Verify a plain-text verifier against a bcrypt hash.
     *
     * @param string $verifier Plain-text verifier
     * @param string $hash     Bcrypt hash to verify against
     * @return bool
     */
    public function verify(string $verifier, string $hash): bool
    {
        return password_verify($verifier, $hash);
    }
}
