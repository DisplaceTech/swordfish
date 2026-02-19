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

        $this->redis->setex($verifierKey, $ttl, $request->verifier());
        $this->redis->setex($secretKey, $ttl + 30, $request->secret());

        return $secretID;
    }

    /**
     * Store a new JSON-API secret with view counter and expiry metadata.
     *
     * Generates a random 12-character hex ID and stores the verifier hash,
     * secret payload, expiry timestamp, and (when limited) view counter in Redis.
     * When max_views is 0 the secret has unlimited views.
     *
     * @param CreateRequest $request
     * @return string The generated secret ID
     */
    public function createJson(CreateRequest $request): string
    {
        $secretID    = bin2hex(random_bytes(6));
        $secretKey   = "json_secret:{$secretID}";
        $verifierKey = "json_verifier:{$secretID}";
        $viewsKey    = "json_views:{$secretID}";
        $expiresKey  = "json_expires:{$secretID}";
        $ttl         = $request->ttl();
        $maxViews    = $request->maxViews();
        $expiresAt   = time() + $ttl;

        $this->redis->setex($verifierKey, $ttl, $request->verifier());
        $this->redis->setex($secretKey, $ttl + 30, $request->secret());
        $this->redis->setex($expiresKey, $ttl + 30, (string) $expiresAt);

        if ($maxViews > 0) {
            $this->redis->setex($viewsKey, $ttl, (string) $maxViews);
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
     * Store a new JSON-API secret and generate a random verifier.
     *
     * Generates a random 12-character hex ID and a random 32-character hex verifier,
     * stores all four JSON-API keys in Redis, and returns the compound ID and metadata.
     *
     * @param string $encryptedSecret Client-side encrypted secret payload (hex-encoded)
     * @param int    $ttl             Lifetime in seconds
     * @param int    $maxViews        Maximum number of retrieval views
     * @return array{id: string, expires_at: int, max_views: int}
     */
    public function createJson(string $encryptedSecret, int $ttl, int $maxViews): array
    {
        $secretID  = bin2hex(random_bytes(6));
        $verifier  = bin2hex(random_bytes(16));
        $expiresAt = time() + $ttl;

        $this->redis->setex("json_verifier:{$secretID}", $ttl,      password_hash($verifier, PASSWORD_DEFAULT));
        $this->redis->setex("json_secret:{$secretID}",   $ttl + 30, $encryptedSecret);
        $this->redis->setex("json_views:{$secretID}",    $ttl,      (string) $maxViews);
        $this->redis->setex("json_expires:{$secretID}",  $ttl,      (string) $expiresAt);

        return [
            'id'         => "{$secretID}:{$verifier}",
            'expires_at' => $expiresAt,
            'max_views'  => $maxViews,
        ];
    }

    /**
     * Retrieve a JSON-API secret after verifying the supplied verifier.
     *
     * Decrements the view counter and deletes all associated keys when views
     * are exhausted. When no views key exists the secret has unlimited views
     * and views_remaining is returned as null.
     *
     * @param string $secretID
     * @param string $verifier Plain-text verifier submitted by the client
     * @return array{encrypted_secret: string, views_remaining: int|null, expires_at: int}
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

        $expiresAt = (int) $this->redis->get($expiresKey);

        $viewsRaw = $this->redis->get($viewsKey);
        if ($viewsRaw === null) {
            // Unlimited views — no counter to decrement
            $viewsRemaining = null;
        } else {
            $viewsAfter = (int) $this->redis->decr($viewsKey);
            if ($viewsAfter <= 0) {
                $this->redis->del($secretKey, $verifierKey, $viewsKey, $expiresKey);
            }
            $viewsRemaining = max(0, $viewsAfter);
        }

        return [
            'encrypted_secret' => $encryptedSecret,
            'views_remaining'  => $viewsRemaining,
            'expires_at'       => $expiresAt,
        ];
    }
}
