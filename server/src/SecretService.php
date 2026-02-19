<?php

namespace Swordfish\Server;

use Predis\Client;

class SecretService
{
    public function __construct(private Client $redis) {}

    /**
     * Store a new secret and its verifier hash in Redis.
     *
     * Generates a random 12-character hex ID, stores the verifier hash with the
     * requested TTL, and stores the secret payload with a 30-second grace period.
     * Also stores JSON-API keys (json_*) to support the view-limited retrieve endpoint.
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
        $views       = $request->views();

        $this->redis->setex($verifierKey, $ttl, $request->verifier());
        $this->redis->setex($secretKey, $ttl + 30, $request->secret());

        // Also store JSON-API keys so the view-limited retrieve endpoint works.
        $jsonVerifierKey = "json_verifier:{$secretID}";
        $jsonSecretKey   = "json_secret:{$secretID}";
        $jsonViewsKey    = "json_views:{$secretID}";
        $jsonExpiresKey  = "json_expires:{$secretID}";

        $expiresAt = time() + $ttl;
        $this->redis->setex($jsonVerifierKey, $ttl, $request->verifier());
        $this->redis->setex($jsonSecretKey, $ttl + 30, $request->secret());
        $this->redis->setex($jsonExpiresKey, $ttl + 30, (string) $expiresAt);

        if ($views > 0) {
            $this->redis->setex($jsonViewsKey, $ttl, (string) $views);
        } else {
            // Unlimited: use a large sentinel value that won't be exhausted in practice.
            $this->redis->setex($jsonViewsKey, $ttl, (string) PHP_INT_MAX);
        }

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
            throw new SecretNotFoundException(sprintf('Verification code for secret %s not found.', $secretID));
        }

        if (!password_verify($verifier, $hash)) {
            throw new InvalidVerifierException(sprintf('Invalid verifier for secret %s.', $secretID));
        }

        $secret = $this->redis->get($secretKey);
        if ($secret === null) {
            throw new SecretNotFoundException(sprintf('Secret %s not found.', $secretID));
        }

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
            throw new SecretNotFoundException(sprintf('Verification code for JSON secret %s not found.', $secretID));
        }

        if (!password_verify($verifier, $hash)) {
            throw new InvalidVerifierException(sprintf('Invalid verifier for JSON secret %s.', $secretID));
        }

        $encryptedSecret = $this->redis->get($secretKey);
        if ($encryptedSecret === null) {
            throw new SecretNotFoundException(sprintf('JSON secret %s not found.', $secretID));
        }

        $expiresAt  = (int) $this->redis->get($expiresKey);
        $viewsAfter = (int) $this->redis->decr($viewsKey);

        if ($viewsAfter <= 0) {
            $this->redis->del($secretKey, $verifierKey, $viewsKey, $expiresKey);
        }

        return [
            'encrypted_secret' => $encryptedSecret,
            'views_remaining'  => max(0, $viewsAfter),
            'expires_at'       => $expiresAt,
        ];
    }
}
