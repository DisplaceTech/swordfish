<?php

namespace Swordfish\Server;

use Predis\Client;

class SecretService
{
    public function __construct(private Client $redis, private ?MetricsService $metrics = null) {}

    /**
     * Store a new secret and its verifier hash in Redis.
     *
     * Generates a random 12-character hex ID, stores the verifier hash with the
     * requested TTL, and stores the secret payload with a 30-second grace period.
     *
     * @param CreateRequest $request
     * @return string The generated secret ID
     */
    public function create(CreateRequest $request): string
    {
        $secretID    = bin2hex(random_bytes(6));
        $secretKey   = "secret:{$secretID}";
        $verifierKey = "verifier:{$secretID}";
        $ttl         = $request->ttl();

        $secret = $request->secret();
        $this->redis->setex($verifierKey, $ttl, $request->verifier());
        $this->redis->setex($secretKey, $ttl + 30, $secret);

        $this->metrics?->recordCreated(strlen($secret));

        return $secretID;
    }

    /**
     * Retrieve a v1 secret after verifying the supplied password.
     *
     * @param string $secretID
     * @param string $verifier Plain-text verifier submitted by the client
     * @return string The stored secret payload
     *
     * @throws SecretNotFoundException  When the verifier or secret key is absent
     * @throws InvalidVerifierException When the verifier does not match the stored hash
     */
    public function retrieve(string $secretID, string $verifier): string
    {
        $verifierKey = "verifier:{$secretID}";
        $secretKey   = "secret:{$secretID}";

        $hash = $this->redis->get($verifierKey);
        if ($hash === null) {
            $this->metrics?->recordExpired();
            throw new SecretNotFoundException(sprintf('Verification code for secret %s not found.', $secretID));
        }

        if (!password_verify($verifier, $hash)) {
            throw new InvalidVerifierException(sprintf('Invalid verifier for secret %s.', $secretID));
        }

        $secret = $this->redis->get($secretKey);
        if ($secret === null) {
            $this->metrics?->recordExpired();
            throw new SecretNotFoundException(sprintf('Secret %s not found.', $secretID));
        }

        $this->metrics?->recordRetrieved(strlen($secret));

        return $secret;
    }

    /**
     * Retrieve a JSON-API secret after verifying the supplied verifier.
     *
     * Decrements the view counter and deletes all associated keys when views
     * are exhausted.
     *
     * @param string $secretID
     * @param string $verifier Plain-text verifier submitted by the client
     * @return array{encrypted_secret: string, views_remaining: int, expires_at: int}
     *
     * @throws SecretNotFoundException  When the verifier or secret key is absent
     * @throws InvalidVerifierException When the verifier does not match the stored hash
     */
    public function retrieveJson(string $secretID, string $verifier): array
    {
        $verifierKey = "json_verifier:{$secretID}";
        $secretKey   = "json_secret:{$secretID}";
        $viewsKey    = "json_views:{$secretID}";
        $expiresKey  = "json_expires:{$secretID}";

        $hash = $this->redis->get($verifierKey);
        if ($hash === null) {
            $this->metrics?->recordExpired();
            throw new SecretNotFoundException(sprintf('Verification code for JSON secret %s not found.', $secretID));
        }

        if (!password_verify($verifier, $hash)) {
            throw new InvalidVerifierException(sprintf('Invalid verifier for JSON secret %s.', $secretID));
        }

        $encryptedSecret = $this->redis->get($secretKey);
        if ($encryptedSecret === null) {
            $this->metrics?->recordExpired();
            throw new SecretNotFoundException(sprintf('JSON secret %s not found.', $secretID));
        }

        $expiresAt  = (int) $this->redis->get($expiresKey);
        $viewsAfter = (int) $this->redis->decr($viewsKey);

        if ($viewsAfter <= 0) {
            $this->redis->del($secretKey, $verifierKey, $viewsKey, $expiresKey);
        }

        $this->metrics?->recordRetrieved(strlen($encryptedSecret));

        return [
            'encrypted_secret' => $encryptedSecret,
            'views_remaining'  => max(0, $viewsAfter),
            'expires_at'       => $expiresAt,
        ];
    }
}
