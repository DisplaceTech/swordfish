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
            ->addMethods(['setex', 'get', 'decr', 'del'])
            ->getMock();
    }

    // -------------------------------------------------------------------------
    // create()
    // -------------------------------------------------------------------------

    public function testCreateStoresVerifierAndSecretInRedis(): void
    {
        $redis = $this->makeRedisMock();

        // create() with unlimited views stores 3 keys: verifier:, secret:, expires:
        $redis->expects($this->exactly(3))
            ->method('setex')
            ->willReturnCallback(function (string $key, int $ttl, string $value) {
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
        $verifierID  = substr(array_values($verifierCall)[0]['key'], strlen('verifier:'));
        $secretKeyID = substr(array_values($secretCall)[0]['key'], strlen('secret:'));
        $this->assertSame($secretID, $verifierID);
        $this->assertSame($secretID, $secretKeyID);
    }

    public function testCreateStoresViewsAndExpiresKeysForLimitedViews(): void
    {
        $redis = $this->makeRedisMock();

        $capturedCalls = [];
        // 4 keys: verifier:, secret:, expires:, views:
        $redis->expects($this->exactly(4))
            ->method('setex')
            ->willReturnCallback(function (string $key, int $ttl, string $value) use (&$capturedCalls) {
                $capturedCalls[] = ['key' => $key, 'ttl' => $ttl, 'value' => $value];
            });

        $service = new SecretService($redis);
        $request = new CreateRequest(
            str_repeat('a', 16),
            str_repeat('b', 32),
            'payload',
            7200,
            3
        );

        $secretID = $service->create($request);

        $verifierCall = array_filter($capturedCalls, fn($c) => str_starts_with($c['key'], 'verifier:'));
        $secretCall   = array_filter($capturedCalls, fn($c) => str_starts_with($c['key'], 'secret:'));
        $viewsCall    = array_filter($capturedCalls, fn($c) => str_starts_with($c['key'], 'views:'));
        $expiresCall  = array_filter($capturedCalls, fn($c) => str_starts_with($c['key'], 'expires:'));

        $this->assertCount(1, $verifierCall);
        $this->assertCount(1, $secretCall);
        $this->assertCount(1, $viewsCall);
        $this->assertCount(1, $expiresCall);

        $this->assertSame(7200, array_values($verifierCall)[0]['ttl']);
        $this->assertSame(7230, array_values($secretCall)[0]['ttl']);
        $this->assertSame(7200, array_values($viewsCall)[0]['ttl']);
        $this->assertSame('3', array_values($viewsCall)[0]['value']);

        // All keys must reference the same secretID
        foreach ([$verifierCall, $secretCall, $viewsCall, $expiresCall] as $calls) {
            $key = array_values($calls)[0]['key'];
            $id  = substr($key, strpos($key, ':') + 1);
            $this->assertSame($secretID, $id);
        }
    }

    public function testCreateDoesNotStoreViewsKeyForUnlimitedViews(): void
    {
        $redis = $this->makeRedisMock();

        $capturedCalls = [];
        // 3 keys only: verifier:, secret:, expires: — no views: key
        $redis->expects($this->exactly(3))
            ->method('setex')
            ->willReturnCallback(function (string $key, int $ttl, string $value) use (&$capturedCalls) {
                $capturedCalls[] = ['key' => $key];
            });

        $service = new SecretService($redis);
        $request = new CreateRequest(
            str_repeat('a', 16),
            str_repeat('b', 32),
            'payload',
            3600,
            0  // unlimited
        );

        $service->create($request);

        $viewsCall = array_filter($capturedCalls, fn($c) => str_starts_with($c['key'], 'views:'));
        $this->assertCount(0, $viewsCall);
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
                if (str_starts_with($key, 'verifier:')) {
                    return $hash;
                }
                return null;
            });

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
                if (str_starts_with($key, 'verifier:')) {
                    return $hash;
                }
                if (str_starts_with($key, 'secret:')) {
                    return 'encrypted-payload';
                }
                if (str_starts_with($key, 'expires:')) {
                    return '9999999999';
                }
                if (str_starts_with($key, 'views:')) {
                    return '3'; // limited views present
                }
                return null;
            });

        $redis->expects($this->once())
            ->method('decr')
            ->willReturn(2); // 2 views remaining after decrement

        $redis->expects($this->never())->method('del');

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
                if (str_starts_with($key, 'verifier:')) {
                    return $hash;
                }
                if (str_starts_with($key, 'secret:')) {
                    return 'encrypted-payload';
                }
                if (str_starts_with($key, 'expires:')) {
                    return '9999999999';
                }
                if (str_starts_with($key, 'views:')) {
                    return '1'; // limited views present
                }
                return null;
            });

        $redis->method('decr')->willReturn(0);

        $redis->expects($this->once())->method('del');

        $service = new SecretService($redis);
        $result  = $service->retrieveJson('abc123def456', $verifier);

        $this->assertSame(0, $result['views_remaining']);
    }

    public function testRetrieveJsonReturnsMinusOneForUnlimitedViews(): void
    {
        $redis    = $this->makeRedisMock();
        $verifier = 'my-verifier';
        $hash     = password_hash($verifier, PASSWORD_DEFAULT);

        $redis->method('get')
            ->willReturnCallback(function (string $key) use ($hash) {
                if (str_starts_with($key, 'verifier:')) {
                    return $hash;
                }
                if (str_starts_with($key, 'secret:')) {
                    return 'encrypted-payload';
                }
                if (str_starts_with($key, 'expires:')) {
                    return '9999999999';
                }
                // No views: key — unlimited
                return null;
            });

        $redis->expects($this->never())->method('decr');
        $redis->expects($this->never())->method('del');

        $service = new SecretService($redis);
        $result  = $service->retrieveJson('abc123def456', $verifier);

        $this->assertSame('encrypted-payload', $result['encrypted_secret']);
        $this->assertSame(-1, $result['views_remaining']);
        $this->assertSame(9999999999, $result['expires_at']);
    }
}
