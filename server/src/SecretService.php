<?php

namespace Swordfish\Server;

use Predis\Client;

class SecretService
{
    public function __construct(private Client $redis) {}

    /**
     * Store a new secret and its verifier hash in Redis.
     *
     * Generates a random 12-character hex ID and stores up to four keys:
     *   verifier:{id}  — bcrypt hash of the verifier (TTL = ttl)
     *   secret:{id}    — hex-encoded salt+ciphertext (TTL = ttl + 30s grace)
     *   expires:{id}   — Unix timestamp of expiry (TTL = ttl + 30s grace)
     *   views:{id}     — remaining view count (TTL = ttl); omitted for unlimited
     *
     * When views is 0 (unlimited) the views key is not created; the retrieve
     * endpoint treats a missing views key as unlimited.
     *
     * @param CreateRequest $request
     * @return string The generated secret ID
     */
    public function create(CreateRequest $request): string
    {
        $secretID    = bin2hex(random_bytes(6));
        $secretKey   = "secret:{$secretID}";
        $verifierKey = "verifier:{$secretID}";
        $viewsKey    = "views:{$secretID}";
        $expiresKey  = "expires:{$secretID}";
        $ttl         = $request->ttl();
        $views       = $request->views();

        $this->redis->setex($verifierKey, $ttl, $request->verifier());
        $this->redis->setex($secretKey, $ttl + 30, $request->secret());
        $this->redis->setex($expiresKey, $ttl + 30, (string) (time() + $ttl));

        if ($views > 0) {
            $this->redis->setex($viewsKey, $ttl, (string) $views);
        }
        // Unlimited (views === 0): no views key; retrieve treats absence as unlimited.

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
     * For limited-view secrets, decrements the view counter and deletes all
     * associated keys when views are exhausted.  For unlimited secrets (no
     * views key present) views_remaining is returned as -1.
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
        $verifierKey = "verifier:{$secretID}";
        $secretKey   = "secret:{$secretID}";
        $viewsKey    = "views:{$secretID}";
        $expiresKey  = "expires:{$secretID}";

        $hash = $this->redis->get($verifierKey);
        if ($hash === null) {
            throw new SecretNotFoundException(sprintf('Verification code for secret %s not found.', $secretID));
        }

        if (!password_verify($verifier, $hash)) {
            throw new InvalidVerifierException(sprintf('Invalid verifier for secret %s.', $secretID));
        }

        $encryptedSecret = $this->redis->get($secretKey);
        if ($encryptedSecret === null) {
            throw new SecretNotFoundException(sprintf('Secret %s not found.', $secretID));
        }

        $expiresAt    = (int) $this->redis->get($expiresKey);
        $currentViews = $this->redis->get($viewsKey);

        if ($currentViews !== null) {
            // Limited views: decrement and delete all keys when exhausted.
            $viewsAfter = (int) $this->redis->decr($viewsKey);
            if ($viewsAfter <= 0) {
                $this->redis->del($secretKey, $verifierKey, $viewsKey, $expiresKey);
            }
            $viewsRemaining = max(0, $viewsAfter);
        } else {
            // Unlimited: no views key present.
            $viewsRemaining = -1;
        }

        return [
            'encrypted_secret' => $encryptedSecret,
            'views_remaining'  => $viewsRemaining,
            'expires_at'       => $expiresAt,
        ];
    }
}
