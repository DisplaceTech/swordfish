<?php

namespace Swordfish\Server;

class JsonCreateRequest
{
    public const MAX_TTL = 604800;

    protected string $encryptedSecret;
    protected int $ttl;
    protected int $maxViews;

    public function __construct(string $encryptedSecret, int $ttl, int $maxViews)
    {
        $this->encryptedSecret = $encryptedSecret;
        $this->ttl = $ttl;
        $this->maxViews = $maxViews;
    }

    public function encryptedSecret(): string
    {
        return $this->encryptedSecret;
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    public function maxViews(): int
    {
        return $this->maxViews;
    }

    /**
     * Parse and validate a JSON request body.
     *
     * @param string $raw
     *
     * @throws \InvalidArgumentException
     *
     * @return JsonCreateRequest
     */
    public static function fromString(string $raw): JsonCreateRequest
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Invalid JSON body');
        }

        if (empty($data['encrypted_secret']) || !is_string($data['encrypted_secret'])) {
            throw new \InvalidArgumentException('Missing or invalid encrypted_secret');
        }

        if (!isset($data['ttl']) || !is_int($data['ttl'])) {
            throw new \InvalidArgumentException('Missing or invalid ttl');
        }

        if ($data['ttl'] < 1 || $data['ttl'] > self::MAX_TTL) {
            throw new \InvalidArgumentException(sprintf('ttl must be between 1 and %d', self::MAX_TTL));
        }

        if (!isset($data['max_views']) || !is_int($data['max_views'])) {
            throw new \InvalidArgumentException('Missing or invalid max_views');
        }

        if ($data['max_views'] < 1) {
            throw new \InvalidArgumentException('max_views must be at least 1');
        }

        return new self($data['encrypted_secret'], $data['ttl'], $data['max_views']);
    }
}
