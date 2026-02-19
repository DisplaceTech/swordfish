<?php

namespace Swordfish\Server;

class CreateRequest
{
    protected string $salt;
    protected string $verifier;
    protected string $secret;
    protected int $views;

    public function __construct(string $salt, string $verifier, string $secret, int $views = ViewCounter::UNLIMITED)
    {
        $this->salt = $salt;
        $this->verifier = $verifier;
        $this->secret = $secret;
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
     * Return the remaining view count, or ViewCounter::UNLIMITED (-1) for no limit.
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
       $views = isset($parts[3]) ? (int) $parts[3] : ViewCounter::UNLIMITED;

       if (strlen($salt) !== 16) {
           throw new \Exception('Invalid salt length!');
       }

       if (strlen($verifier) !== 32) {
           throw new \Exception('Invalid verifier length!');
       }

       return new self($salt, $verifier, $secret, $views);
    }
}