<?php

namespace Swordfish\Server;

class RetrievalRequest
{
    protected string $secretID;
    protected string $verifier;

    public function __construct(string $secretID, string $verifier)
    {
        $this->secretID = $secretID;
        $this->verifier = $verifier;
    }

    /**
     * Get the secret ID to process the request.
     *
     * @return string
     */
    public function ID(): string
    {
        return $this->secretID;
    }

    /**
     * Verify the submitted password against a known/stored password hash.
     *
     * @param string $hash
     * @return bool
     */
    public function verify_password(string $hash): bool
    {
       return password_verify($this->verifier, $hash);
    }

    /**
     * Deserialize a request and create a new object from it.
     *
     * @param string $raw
     *
     * @throws \Exception
     *
     * @return RetrievalRequest
     */
    public static function fromString(string $raw): RetrievalRequest
    {
        $parts = explode('$', $raw);

        if (sizeof($parts) !== 2) {
            throw new \Exception('Invalid serialized request!');
        }

        $secretID = $parts[0];
        $verifier = hex2bin($parts[1]);

        if (strlen($secretID) !== 12) {
            throw new \Exception('Invalid secret ID length!');
        }

        if (strlen($verifier) !== 32) {
            throw new \Exception('Invalid verifier length!');
        }

        return new self($secretID, $verifier);
    }
}