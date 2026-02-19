<?php

namespace Swordfish\Server;

class CreateRequest
{
    const DEFAULT_TTL = 86400;
    const MAX_TTL = 604800;
    const ALLOWED_MAX_VIEWS = [0, 1, 3, 5, 10];

    protected string $salt;
    protected string $verifier;
    protected string $secret;
    protected int $ttl;
    protected int $maxViews;

    public function __construct(string $salt, string $verifier, string $secret, int $ttl = self::DEFAULT_TTL, int $maxViews = 0)
    {
        $this->salt = $salt;
        $this->verifier = $verifier;
        $this->secret = $secret;
        $this->ttl = $ttl;
        $this->maxViews = $maxViews;
    }

    /**
     * Retrieve a hashed version of the verifier used to authenticate requests for secrets.
     *
     * @return string
     */
    public function verifier(): string
    {
        return password_hash($this->verifier, PASSWORD_DEFAULT);
    }

    /**
     * Return a hex-encoded bundle of the salt and secret used to actually store data.
     *
     * @return string
     */
    public function secret(): string
    {
        return bin2hex($this->salt . $this->secret);
    }

    /**
     * Return the requested TTL in seconds for the secret.
     *
     * @return int
     */
    public function ttl(): int
    {
        return $this->ttl;
    }

    /**
     * Return the maximum number of views allowed (0 = unlimited).
     *
     * @return int
     */
    public function maxViews(): int
    {
        return $this->maxViews;
    }

    /**
     * Deserialize a JSON API request and create a new object from it.
     *
     * Expects: {"encrypted_secret": "hex(salt)$hex(verifier)$hex(secret)", "ttl": N, "max_views": N}
     *
     * @param string $json
     *
     * @throws \Exception
     * @throws \InvalidArgumentException
     *
     * @return CreateRequest
     */
    public static function fromJson(string $json): CreateRequest
    {
        $parsed = json_decode($json, true);
        if ($parsed === null || !isset($parsed['encrypted_secret'])) {
            throw new \Exception('Invalid JSON request!');
        }

        $parts = explode('$', $parsed['encrypted_secret']);
        if (sizeof($parts) !== 3) {
            throw new \Exception('Invalid encrypted_secret format!');
        }

        $salt     = hex2bin($parts[0]);
        $verifier = hex2bin($parts[1]);
        $secret   = hex2bin($parts[2]);

        if (strlen($salt) !== 16) {
            throw new \Exception('Invalid salt length!');
        }

        if (strlen($verifier) !== 32) {
            throw new \Exception('Invalid verifier length!');
        }

        $ttl = self::DEFAULT_TTL;
        if (isset($parsed['ttl'])) {
            $ttl = (int) $parsed['ttl'];
            if ($ttl < 1 || $ttl > self::MAX_TTL) {
                throw new \InvalidArgumentException(sprintf('TTL must be between 1 and %d seconds.', self::MAX_TTL));
            }
        }

        $maxViews = 0;
        if (isset($parsed['max_views'])) {
            $maxViews = (int) $parsed['max_views'];
            if (!in_array($maxViews, self::ALLOWED_MAX_VIEWS, true)) {
                throw new \InvalidArgumentException(sprintf('max_views must be one of: %s.', implode(', ', self::ALLOWED_MAX_VIEWS)));
            }
        }

        return new self($salt, $verifier, $secret, $ttl, $maxViews);
    }

    /**
     * Deserialize a request and create a new object from it.
     *
     * Format: hex(salt)$hex(verifier)$hex(secret)[$ttl[$max_views]]
     *
     * @param string $raw
     *
     * @throws \Exception
     * @throws \InvalidArgumentException
     *
     * @return CreateRequest
     */
    public static function fromString(string $raw): CreateRequest
    {
       $parts = explode('$', $raw);

       if (sizeof($parts) < 3 || sizeof($parts) > 5) {
           throw new \Exception('Invalid serialized request!');
       }

       $salt = hex2bin($parts[0]);
       $verifier = hex2bin($parts[1]);
       $secret = hex2bin($parts[2]);

       if (strlen($salt) !== 16) {
           throw new \Exception('Invalid salt length!');
       }

       if (strlen($verifier) !== 32) {
           throw new \Exception('Invalid verifier length!');
       }

       $ttl = self::DEFAULT_TTL;
       if (sizeof($parts) >= 4) {
           $ttl = (int) $parts[3];
           if ($ttl < 1 || $ttl > self::MAX_TTL) {
               throw new \InvalidArgumentException(sprintf('TTL must be between 1 and %d seconds.', self::MAX_TTL));
           }
       }

       $maxViews = 0;
       if (sizeof($parts) === 5) {
           $maxViews = (int) $parts[4];
           if (!in_array($maxViews, self::ALLOWED_MAX_VIEWS, true)) {
               throw new \InvalidArgumentException(sprintf('max_views must be one of: %s.', implode(', ', self::ALLOWED_MAX_VIEWS)));
           }
       }

       return new self($salt, $verifier, $secret, $ttl, $maxViews);
    }
}