<?php

namespace Swordfish\Server;

class CreateRequest
{
    const DEFAULT_TTL = 86400;
    const ALLOWED_TTLS = [3600, 21600, 86400, 259200, 604800];

    protected string $salt;
    protected string $verifier;
    protected string $secret;
    protected int $ttl;

    public function __construct(string $salt, string $verifier, string $secret, int $ttl = self::DEFAULT_TTL)
    {
        $this->salt = $salt;
        $this->verifier = $verifier;
        $this->secret = $secret;
        $this->ttl = $ttl;
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
     * Deserialize a request and create a new object from it.
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

       if (sizeof($parts) < 3 || sizeof($parts) > 4) {
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
       if (sizeof($parts) === 4) {
           $ttl = (int) $parts[3];
           if (!in_array($ttl, self::ALLOWED_TTLS, true)) {
               throw new \InvalidArgumentException(sprintf('TTL must be one of: %s seconds.', implode(', ', self::ALLOWED_TTLS)));
           }
       }

       return new self($salt, $verifier, $secret, $ttl);
    }
}