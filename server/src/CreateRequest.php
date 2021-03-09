<?php

namespace Swordfish\Server;

class CreateRequest
{
    protected string $salt;
    protected string $verifier;
    protected string $secret;

    public function __construct(string $salt, string $verifier, string $secret)
    {
        $this->salt = $salt;
        $this->verifier = $verifier;
        $this->secret = $secret;
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

       if (sizeof($parts) !== 3) {
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

       return new self($salt, $verifier, $secret);
    }
}