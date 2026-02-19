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
     * Store a new JSON-API secret in Redis.
     *
     * Generates a random 12-character hex ID, stores the bcrypt-hashed verifier,
     * the encrypted secret payload, the expiry timestamp, and (if bounded) the
     * view counter — all keyed under the json_* namespace so they are compatible
     * with retrieveJson().
     *
     * @param string   $encryptedSecret Client-side AES-GCM ciphertext (hex-encoded)
     * @param string   $verifier        Plain-text verifier; bcrypt-hashed before storage
     * @param int      $ttl             Lifetime in seconds
     * @param int|null $maxViews        Maximum retrieval count, or null for unlimited
     * @return array{id: string, expires_at: string, max_views: int|null}
     */
    public function createJson(string $encryptedSecret, string $verifier, int $ttl, ?int $maxViews): array
    {
        $secretID    = bin2hex(random_bytes(6));
        $verifierKey = "json_verifier:{$secretID}";
        $secretKey   = "json_secret:{$secretID}";
        $viewsKey    = "json_views:{$secretID}";
        $expiresKey  = "json_expires:{$secretID}";

        $expiresAt = time() + $ttl;

        $this->redis->setex($verifierKey, $ttl, password_hash($verifier, PASSWORD_DEFAULT));
        $this->redis->setex($secretKey, $ttl + 30, $encryptedSecret);
        $this->redis->setex($expiresKey, $ttl + 30, (string) $expiresAt);

        if ($maxViews !== null) {
            $this->redis->setex($viewsKey, $ttl, (string) $maxViews);
        }

        return [
            'id'         => $secretID,
            'expires_at' => (new \DateTimeImmutable("@{$expiresAt}"))->format(\DateTimeInterface::ATOM),
            'max_views'  => $maxViews,
        ];
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
