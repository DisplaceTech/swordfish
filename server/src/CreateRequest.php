<?php

namespace Swordfish\Server;

class CreateRequest
{
    const DEFAULT_TTL   = 86400;
    const MAX_TTL       = 604800;
    const DEFAULT_VIEWS = 0;
    const MAX_VIEWS     = 100;

    protected string $salt;
    protected string $verifier;
    protected string $secret;
    protected int $ttl;
    protected int $views;

    public function __construct(string $salt, string $verifier, string $secret, int $ttl = self::DEFAULT_TTL, int $views = self::DEFAULT_VIEWS)
    {
        $this->salt = $salt;
        $this->verifier = $verifier;
        $this->secret = $secret;
        $this->ttl = $ttl;
        $this->views = $views;
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
    public function views(): int
    {
        return $this->views;
    }

    /**
     * Deserialize a request and create a new object from it.
     *
     * Accepted formats:
     *   hex(salt)$hex(verifier)$hex(secret)
     *   hex(salt)$hex(verifier)$hex(secret)$ttl
     *   hex(salt)$hex(verifier)$hex(secret)$ttl$views
     *
     * @param string $raw
     *
     * @throws \Exception
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

       $views = self::DEFAULT_VIEWS;
       if (sizeof($parts) === 5) {
           $views = (int) $parts[4];
           if ($views < 0 || $views > self::MAX_VIEWS) {
               throw new \InvalidArgumentException(sprintf('View limit must be between 0 (unlimited) and %d.', self::MAX_VIEWS));
           }
       }

       return new self($salt, $verifier, $secret, $ttl, $views);
    }
}