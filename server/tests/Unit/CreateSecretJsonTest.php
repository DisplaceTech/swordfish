<?php

declare(strict_types=1);

namespace Swordfish\Server\Tests;

use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;
use Swordfish\Server\SecretService;

class CreateSecretJsonTest extends TestCase
{
    private function makeRedisMock(): RedisClient
    {
        return $this->getMockBuilder(RedisClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['setex', 'get', 'decr', 'del'])
            ->getMock();
    }

    // -------------------------------------------------------------------------
    // createJson() — happy path
    // -------------------------------------------------------------------------

    public function testCreateJsonReturnsIdExpiresAtAndMaxViews(): void
    {
        $redis = $this->makeRedisMock();
        $redis->method('setex')->willReturn(null);

        $service = new SecretService($redis);
        $result  = $service->createJson('encrypted-payload', 'my-verifier', 86400, 5);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('expires_at', $result);
        $this->assertArrayHasKey('max_views', $result);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $result['id']);
        $this->assertSame(5, $result['max_views']);
    }

    public function testCreateJsonExpiresAtIsIso8601(): void
    {
        $redis = $this->makeRedisMock();
        $redis->method('setex')->willReturn(null);

        $service = new SecretService($redis);
        $result  = $service->createJson('encrypted-payload', 'my-verifier', 3600, null);

        // ISO 8601 / RFC 3339 pattern
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $result['expires_at']
        );
    }

    public function testCreateJsonExpiresAtReflectsTtl(): void
    {
        $redis = $this->makeRedisMock();
        $redis->method('setex')->willReturn(null);

        $before  = time();
        $service = new SecretService($redis);
        $result  = $service->createJson('encrypted-payload', 'my-verifier', 3600, null);
        $after   = time();

        $ts = (new \DateTimeImmutable($result['expires_at']))->getTimestamp();
        $this->assertGreaterThanOrEqual($before + 3600, $ts);
        $this->assertLessThanOrEqual($after + 3600, $ts);
    }

    public function testCreateJsonNullMaxViewsIsPreserved(): void
    {
        $redis = $this->makeRedisMock();
        $redis->method('setex')->willReturn(null);

        $service = new SecretService($redis);
        $result  = $service->createJson('encrypted-payload', 'my-verifier', 86400, null);

        $this->assertNull($result['max_views']);
    }

    // -------------------------------------------------------------------------
    // createJson() — Redis key storage
    // -------------------------------------------------------------------------

    public function testCreateJsonStoresFourKeysWhenMaxViewsSet(): void
    {
        $redis = $this->makeRedisMock();

        $capturedKeys = [];
        $redis->expects($this->exactly(4))
            ->method('setex')
            ->willReturnCallback(function (string $key) use (&$capturedKeys) {
                $capturedKeys[] = $key;
            });

        $service  = new SecretService($redis);
        $result   = $service->createJson('encrypted-payload', 'my-verifier', 86400, 10);
        $secretID = $result['id'];

        $this->assertContains("json_verifier:{$secretID}", $capturedKeys);
        $this->assertContains("json_secret:{$secretID}", $capturedKeys);
        $this->assertContains("json_expires:{$secretID}", $capturedKeys);
        $this->assertContains("json_views:{$secretID}", $capturedKeys);
    }

    public function testCreateJsonStoresThreeKeysWhenMaxViewsNull(): void
    {
        $redis = $this->makeRedisMock();

        $capturedKeys = [];
        $redis->expects($this->exactly(3))
            ->method('setex')
            ->willReturnCallback(function (string $key) use (&$capturedKeys) {
                $capturedKeys[] = $key;
            });

        $service  = new SecretService($redis);
        $result   = $service->createJson('encrypted-payload', 'my-verifier', 86400, null);
        $secretID = $result['id'];

        $this->assertContains("json_verifier:{$secretID}", $capturedKeys);
        $this->assertContains("json_secret:{$secretID}", $capturedKeys);
        $this->assertContains("json_expires:{$secretID}", $capturedKeys);
        $this->assertNotContains("json_views:{$secretID}", $capturedKeys);
    }

    public function testCreateJsonVerifierKeyUsesRequestedTtl(): void
    {
        $redis = $this->makeRedisMock();

        $capturedCalls = [];
        $redis->method('setex')
            ->willReturnCallback(function (string $key, int $ttl) use (&$capturedCalls) {
                $capturedCalls[$key] = $ttl;
            });

        $service = new SecretService($redis);
        $result  = $service->createJson('encrypted-payload', 'my-verifier', 3600, 1);

        $secretID = $result['id'];
        $this->assertSame(3600, $capturedCalls["json_verifier:{$secretID}"]);
        $this->assertSame(3630, $capturedCalls["json_secret:{$secretID}"]);
        $this->assertSame(3630, $capturedCalls["json_expires:{$secretID}"]);
        $this->assertSame(3600, $capturedCalls["json_views:{$secretID}"]);
    }

    public function testCreateJsonVerifierIsHashedBeforeStorage(): void
    {
        $redis = $this->makeRedisMock();

        $storedVerifier = null;
        $redis->method('setex')
            ->willReturnCallback(function (string $key, int $ttl, string $value) use (&$storedVerifier) {
                if (str_starts_with($key, 'json_verifier:')) {
                    $storedVerifier = $value;
                }
            });

        $service = new SecretService($redis);
        $service->createJson('encrypted-payload', 'plain-verifier', 86400, null);

        $this->assertNotNull($storedVerifier);
        $this->assertNotSame('plain-verifier', $storedVerifier);
        $this->assertTrue(password_verify('plain-verifier', $storedVerifier));
    }

    public function testCreateJsonGeneratesUniqueIds(): void
    {
        $redis = $this->makeRedisMock();
        $redis->method('setex')->willReturn(null);

        $service = new SecretService($redis);
        $ids     = [];
        for ($i = 0; $i < 10; $i++) {
            $result = $service->createJson('payload', 'verifier', 86400, null);
            $ids[]  = $result['id'];
        }

        $this->assertSame(count($ids), count(array_unique($ids)));
    }
}
