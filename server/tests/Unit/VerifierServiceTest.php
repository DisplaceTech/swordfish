<?php

declare(strict_types=1);

namespace Swordfish\Server\Tests;

use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;
use Swordfish\Server\VerifierService;

class VerifierServiceTest extends TestCase
{
    private function makeRedisMock(): RedisClient
    {
        return $this->getMockBuilder(RedisClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['setex', 'get'])
            ->getMock();
    }

    public function testStoreCallsRedisSetexWithCorrectArguments(): void
    {
        $redis = $this->makeRedisMock();
        $redis->expects($this->once())
            ->method('setex')
            ->with('verifier:abc123', 3600, 'hashed-value');

        $service = new VerifierService($redis);
        $service->store('verifier:abc123', 'hashed-value', 3600);
    }

    public function testGetReturnsStoredHash(): void
    {
        $redis = $this->makeRedisMock();
        $redis->method('get')->with('verifier:abc123')->willReturn('stored-hash');

        $service = new VerifierService($redis);
        $result  = $service->get('verifier:abc123');

        $this->assertSame('stored-hash', $result);
    }

    public function testGetReturnsNullWhenKeyNotFound(): void
    {
        $redis = $this->makeRedisMock();
        $redis->method('get')->willReturn(null);

        $service = new VerifierService($redis);
        $result  = $service->get('verifier:missing');

        $this->assertNull($result);
    }

    public function testVerifyReturnsTrueForMatchingVerifier(): void
    {
        $redis    = $this->makeRedisMock();
        $verifier = 'plain-text-verifier';
        $hash     = password_hash($verifier, PASSWORD_DEFAULT);

        $service = new VerifierService($redis);
        $this->assertTrue($service->verify($verifier, $hash));
    }

    public function testVerifyReturnsFalseForNonMatchingVerifier(): void
    {
        $redis = $this->makeRedisMock();
        $hash  = password_hash('correct-verifier', PASSWORD_DEFAULT);

        $service = new VerifierService($redis);
        $this->assertFalse($service->verify('wrong-verifier', $hash));
    }
}
