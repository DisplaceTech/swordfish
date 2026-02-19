<?php

declare(strict_types=1);

namespace Swordfish\Server\Tests;

use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;
use Swordfish\Server\CreateRequest;
use Swordfish\Server\InvalidVerifierException;
use Swordfish\Server\SecretNotFoundException;
use Swordfish\Server\SecretService;

class SecretServiceTest extends TestCase
{
    private function makeRedisMock(): RedisClient
    {
        return $this->getMockBuilder(RedisClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['setex', 'get', 'eval'])
            ->getMock();
    }

    // -------------------------------------------------------------------------
    // create()
    // -------------------------------------------------------------------------

    public function testCreateStoresVerifierAndSecretInRedis(): void
    {
        $redis = $this->makeRedisMock();

        $redis->expects($this->exactly(2))
            ->method('setex')
            ->willReturnCallback(function (string $key, int $ttl, string $value) {
                // Both keys must be set with a positive TTL
                $this->assertGreaterThan(0, $ttl);
                $this->assertNotEmpty($value);
            });

        $service = new SecretService($redis);
        $request = new CreateRequest(
            str_repeat('a', 16),
            str_repeat('b', 32),
            'my-secret-payload'
        );

        $secretID = $service->create($request);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $secretID);
    }

    public function testCreateUsesRequestTtlForVerifierKey(): void
    {
        $redis = $this->makeRedisMock();

        $capturedCalls = [];
        $redis->expects($this->exactly(2))
            ->method('setex')
            ->willReturnCallback(function (string $key, int $ttl, string $value) use (&$capturedCalls) {
                $capturedCalls[] = ['key' => $key, 'ttl' => $ttl];
            });

        $service = new SecretService($redis);
        $request = new CreateRequest(
            str_repeat('a', 16),
            str_repeat('b', 32),
            'payload',
            3600
        );

        $secretID = $service->create($request);

        $verifierCall = array_filter($capturedCalls, fn($c) => str_starts_with($c['key'], 'verifier:'));
        $secretCall   = array_filter($capturedCalls, fn($c) => str_starts_with($c['key'], 'secret:'));

        $this->assertCount(1, $verifierCall);
        $this->assertCount(1, $secretCall);

        $this->assertSame(3600, array_values($verifierCall)[0]['ttl']);
        $this->assertSame(3630, array_values($secretCall)[0]['ttl']);

        // Both keys must reference the same secretID
        $verifierID = substr(array_values($verifierCall)[0]['key'], strlen('verifier:'));
        $secretKeyID = substr(array_values($secretCall)[0]['key'], strlen('secret:'));
        $this->assertSame($secretID, $verifierID);
        $this->assertSame($secretID, $secretKeyID);
    }

    // -------------------------------------------------------------------------
    // retrieve()
    // -------------------------------------------------------------------------

    public function testRetrieveThrowsSecretNotFoundWhenVerifierKeyMissing(): void
    {
        $redis = $this->makeRedisMock();
        $redis->method('get')->willReturn(null);

        $service = new SecretService($redis);

        $this->expectException(SecretNotFoundException::class);
        $service->retrieve('abc123def456', 'any-verifier');
    }

    public function testRetrieveThrowsInvalidVerifierOnBadPassword(): void
    {
        $redis = $this->makeRedisMock();
        // Return a hash that will NOT match 'wrong-verifier'
        $redis->method('get')->willReturn(password_hash('correct-verifier', PASSWORD_DEFAULT));

        $service = new SecretService($redis);

        $this->expectException(InvalidVerifierException::class);
        $service->retrieve('abc123def456', 'wrong-verifier');
    }

    public function testRetrieveThrowsSecretNotFoundWhenSecretKeyMissing(): void
    {
        $redis = $this->makeRedisMock();
        $verifier = 'my-verifier';
        $hash     = password_hash($verifier, PASSWORD_DEFAULT);

        $redis->method('get')
            ->willReturnCallback(function (string $key) use ($hash) {
                if (str_starts_with($key, 'verifier:')) {
                    return $hash;
                }
                return null; // secret key missing
            });

        $service = new SecretService($redis);

        $this->expectException(SecretNotFoundException::class);
        $service->retrieve('abc123def456', $verifier);
    }

    public function testRetrieveReturnsSecretOnValidVerifier(): void
    {
        $redis    = $this->makeRedisMock();
        $verifier = 'my-verifier';
        $hash     = password_hash($verifier, PASSWORD_DEFAULT);
        $payload  = 'the-secret-payload';

        $redis->method('get')
            ->willReturnCallback(function (string $key) use ($hash, $payload) {
                if (str_starts_with($key, 'verifier:')) {
                    return $hash;
                }
                return $payload;
            });

        $service = new SecretService($redis);
        $result  = $service->retrieve('abc123def456', $verifier);

        $this->assertSame($payload, $result);
    }

    // -------------------------------------------------------------------------
    // retrieveJson()
    // -------------------------------------------------------------------------

    public function testRetrieveJsonThrowsSecretNotFoundWhenVerifierKeyMissing(): void
    {
        $redis = $this->makeRedisMock();
        $redis->method('get')->willReturn(null);

        $service = new SecretService($redis);

        $this->expectException(SecretNotFoundException::class);
        $service->retrieveJson('abc123def456', 'any-verifier');
    }

    public function testRetrieveJsonThrowsInvalidVerifierOnBadPassword(): void
    {
        $redis = $this->makeRedisMock();
        $redis->method('get')->willReturn(password_hash('correct-verifier', PASSWORD_DEFAULT));

        $service = new SecretService($redis);

        $this->expectException(InvalidVerifierException::class);
        $service->retrieveJson('abc123def456', 'wrong-verifier');
    }

    public function testRetrieveJsonThrowsSecretNotFoundWhenSecretKeyMissing(): void
    {
        $redis    = $this->makeRedisMock();
        $verifier = 'my-verifier';
        $hash     = password_hash($verifier, PASSWORD_DEFAULT);

        $redis->method('get')
            ->willReturnCallback(function (string $key) use ($hash) {
                if (str_starts_with($key, 'json_verifier:')) {
                    return $hash;
                }
                return null;
            });

        // Lua script returns null secret (key missing or views exhausted)
        $redis->method('eval')->willReturn([null, null, null]);

        $service = new SecretService($redis);

        $this->expectException(SecretNotFoundException::class);
        $service->retrieveJson('abc123def456', $verifier);
    }

    public function testRetrieveJsonReturnsDataAndDecrementsViews(): void
    {
        $redis    = $this->makeRedisMock();
        $verifier = 'my-verifier';
        $hash     = password_hash($verifier, PASSWORD_DEFAULT);

        $redis->method('get')
            ->willReturnCallback(function (string $key) use ($hash) {
                if (str_starts_with($key, 'json_verifier:')) {
                    return $hash;
                }
                return null;
            });

        // Lua script atomically decrements and returns [secret, expires, views_remaining]
        $redis->expects($this->once())
            ->method('eval')
            ->willReturn(['encrypted-payload', '9999999999', '2']);

        $service = new SecretService($redis);
        $result  = $service->retrieveJson('abc123def456', $verifier);

        $this->assertSame('encrypted-payload', $result['encrypted_secret']);
        $this->assertSame(2, $result['views_remaining']);
        $this->assertSame(9999999999, $result['expires_at']);
    }

    public function testRetrieveJsonDeletesAllKeysWhenViewsExhausted(): void
    {
        $redis    = $this->makeRedisMock();
        $verifier = 'my-verifier';
        $hash     = password_hash($verifier, PASSWORD_DEFAULT);

        $redis->method('get')
            ->willReturnCallback(function (string $key) use ($hash) {
                if (str_starts_with($key, 'json_verifier:')) {
                    return $hash;
                }
                return null;
            });

        // Lua script returns views_remaining=0 and handles deletion internally
        $redis->expects($this->once())
            ->method('eval')
            ->willReturn(['encrypted-payload', '9999999999', '0']);

        $service = new SecretService($redis);
        $result  = $service->retrieveJson('abc123def456', $verifier);

        $this->assertSame(0, $result['views_remaining']);
    }

    public function testRetrieveJsonReturnsNullViewsRemainingForUnlimitedSecret(): void
    {
        $redis    = $this->makeRedisMock();
        $verifier = 'my-verifier';
        $hash     = password_hash($verifier, PASSWORD_DEFAULT);

        $redis->method('get')
            ->willReturnCallback(function (string $key) use ($hash) {
                if (str_starts_with($key, 'json_verifier:')) {
                    return $hash;
                }
                return null;
            });

        // Lua script returns null for views_remaining when no views key exists (unlimited)
        $redis->expects($this->once())
            ->method('eval')
            ->willReturn(['encrypted-payload', '9999999999', null]);

        $service = new SecretService($redis);
        $result  = $service->retrieveJson('abc123def456', $verifier);

        $this->assertSame('encrypted-payload', $result['encrypted_secret']);
        $this->assertNull($result['views_remaining']);
        $this->assertSame(9999999999, $result['expires_at']);
    }

    public function testRetrieveJsonThrowsSecretNotFoundWhenViewsAlreadyExhausted(): void
    {
        $redis    = $this->makeRedisMock();
        $verifier = 'my-verifier';
        $hash     = password_hash($verifier, PASSWORD_DEFAULT);

        $redis->method('get')
            ->willReturnCallback(function (string $key) use ($hash) {
                if (str_starts_with($key, 'json_verifier:')) {
                    return $hash;
                }
                return null;
            });

        // Lua script returns null secret when views counter went negative (race condition)
        $redis->expects($this->once())
            ->method('eval')
            ->willReturn([null, null, null]);

        $service = new SecretService($redis);

        $this->expectException(SecretNotFoundException::class);
        $service->retrieveJson('abc123def456', $verifier);
    }

    // -------------------------------------------------------------------------
    // createJson()
    // -------------------------------------------------------------------------

    public function testCreateJsonStoresVerifierSecretAndExpiresKeys(): void
    {
        $redis = $this->makeRedisMock();

        $capturedCalls = [];
        $redis->expects($this->exactly(3))
            ->method('setex')
            ->willReturnCallback(function (string $key, int $ttl, string $value) use (&$capturedCalls) {
                $capturedCalls[] = ['key' => $key, 'ttl' => $ttl];
            });

        $service = new SecretService($redis);
        $request = new CreateRequest(
            str_repeat('a', 16),
            str_repeat('b', 32),
            'my-secret-payload',
            3600,
            0 // unlimited
        );

        $secretID = $service->createJson($request);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $secretID);

        $keys = array_column($capturedCalls, 'key');
        $this->assertContains("json_verifier:{$secretID}", $keys);
        $this->assertContains("json_secret:{$secretID}", $keys);
        $this->assertContains("json_expires:{$secretID}", $keys);
        $this->assertNotContains("json_views:{$secretID}", $keys);
    }

    public function testCreateJsonStoresViewsKeyForLimitedViews(): void
    {
        $redis = $this->makeRedisMock();

        $capturedCalls = [];
        $redis->expects($this->exactly(4))
            ->method('setex')
            ->willReturnCallback(function (string $key, int $ttl, string $value) use (&$capturedCalls) {
                $capturedCalls[] = ['key' => $key, 'ttl' => $ttl, 'value' => $value];
            });

        $service = new SecretService($redis);
        $request = new CreateRequest(
            str_repeat('a', 16),
            str_repeat('b', 32),
            'my-secret-payload',
            3600,
            5 // 5 views
        );

        $secretID = $service->createJson($request);

        $keys = array_column($capturedCalls, 'key');
        $this->assertContains("json_views:{$secretID}", $keys);

        $viewsCall = array_values(array_filter($capturedCalls, fn($c) => $c['key'] === "json_views:{$secretID}"));
        $this->assertCount(1, $viewsCall);
        $this->assertSame('5', $viewsCall[0]['value']);
        $this->assertSame(3600, $viewsCall[0]['ttl']);
    }

    public function testCreateJsonUsesRequestTtlForVerifierKey(): void
    {
        $redis = $this->makeRedisMock();

        $capturedCalls = [];
        $redis->expects($this->exactly(3))
            ->method('setex')
            ->willReturnCallback(function (string $key, int $ttl, string $value) use (&$capturedCalls) {
                $capturedCalls[] = ['key' => $key, 'ttl' => $ttl];
            });

        $service = new SecretService($redis);
        $request = new CreateRequest(
            str_repeat('a', 16),
            str_repeat('b', 32),
            'payload',
            7200
        );

        $secretID = $service->createJson($request);

        $verifierCall = array_values(array_filter($capturedCalls, fn($c) => $c['key'] === "json_verifier:{$secretID}"));
        $secretCall   = array_values(array_filter($capturedCalls, fn($c) => $c['key'] === "json_secret:{$secretID}"));

        $this->assertSame(7200, $verifierCall[0]['ttl']);
        $this->assertSame(7230, $secretCall[0]['ttl']);
    }
}
