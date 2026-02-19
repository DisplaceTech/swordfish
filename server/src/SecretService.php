<?php

namespace Swordfish\Server;

use Predis\Client;

class SecretService
{
    /**
     * Atomic Lua script for view-limited secret retrieval.
     *
     * KEYS: [1]=viewsKey, [2]=secretKey, [3]=verifierKey, [4]=expiresKey
     *
     * Returns a three-element array:
     *   [0] encrypted_secret (string) or nil when not found / views exhausted
     *   [1] expires_at timestamp string or nil
     *   [2] views_remaining as a string, or false (nil) for unlimited secrets
     *
     * The entire read-decrement-delete sequence executes atomically, preventing
     * two concurrent requests from both retrieving the last available view.
     */
    private const LUA_RETRIEVE = <<<'LUA'
local viewsKey    = KEYS[1]
local secretKey   = KEYS[2]
local verifierKey = KEYS[3]
local expiresKey  = KEYS[4]

local views = redis.call('GET', viewsKey)

if views == false then
    local secret  = redis.call('GET', secretKey)
    local expires = redis.call('GET', expiresKey)
    return {secret, expires, false}
end

local newViews = redis.call('DECR', viewsKey)

if newViews < 0 then
    return {false, false, false}
end

local secret  = redis.call('GET', secretKey)
local expires = redis.call('GET', expiresKey)

if newViews == 0 then
    redis.call('DEL', secretKey, verifierKey, viewsKey, expiresKey)
end

return {secret, expires, tostring(newViews)}
LUA;

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

        if (!password_verify(bin2hex($verifier), $hash)) {
            throw new InvalidVerifierException(sprintf('Invalid verifier for secret %s.', $secretID));
        }

        $secret = $this->redis->get($secretKey);
        if ($secret === null) {
            throw new SecretNotFoundException(sprintf('Secret %s not found.', $secretID));
        }

        return $secret;
    }

    /**
     * Return metadata for a JSON-API secret without consuming a view.
     *
     * Reads views_remaining and expires_at from Redis without decrementing
     * the view counter or requiring the passphrase/verifier.
     *
     * @param string $secretID
     * @return array{views_remaining: int|null, expires_at: int}
     *
     * @throws SecretNotFoundException When no secret with the given ID exists
     */
    public function getInfo(string $secretID): array
    {
        $expiresKey = "json_expires:{$secretID}";
        $viewsKey   = "json_views:{$secretID}";

        $expiresAt = $this->redis->get($expiresKey);
        if ($expiresAt === null) {
            throw new SecretNotFoundException(sprintf('JSON secret %s not found.', $secretID));
        }

        $views          = $this->redis->get($viewsKey);
        $viewsRemaining = $views !== null ? (int) $views : null;

        return [
            'views_remaining' => $viewsRemaining,
            'expires_at'      => (int) $expiresAt,
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

        /** @var array{0: string|null, 1: string|null, 2: string|null} $result */
        $result = $this->redis->eval(self::LUA_RETRIEVE, 4, $viewsKey, $secretKey, $verifierKey, $expiresKey);

        $encryptedSecret = $result[0] ?? null;
        if ($encryptedSecret === null) {
            throw new SecretNotFoundException(sprintf('JSON secret %s not found or views exhausted.', $secretID));
        }

        $expiresAt      = (int) ($result[1] ?? 0);
        $viewsRemaining = isset($result[2]) ? max(0, (int) $result[2]) : null;

        return [
            'encrypted_secret' => $encryptedSecret,
            'views_remaining'  => $viewsRemaining,
            'expires_at'       => $expiresAt,
        ];
    }
}
