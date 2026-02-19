<?php

declare(strict_types=1);

namespace Swordfish\Server\Tests;

use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;
use Swordfish\Server\RateLimiter;

class RateLimiterTest extends TestCase
{
    private function makeRedisMock(): RedisClient
    {
        return $this->getMockBuilder(RedisClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['incr', 'expire'])
            ->getMock();
    }

    public function testAllowsRequestWhenUnderLimit(): void
    {
        $redis = $this->makeRedisMock();
        $redis->method('incr')->willReturn(1);

        $limiter = new RateLimiter($redis);
        $result  = $limiter->isAllowed('192.168.1.1');

        $this->assertTrue($result['allowed']);
        $this->assertSame(10, $result['limit']);
        $this->assertSame(9, $result['remaining']);
    }

    public function testAllowsRequestAtExactLimit(): void
    {
        $redis = $this->makeRedisMock();
        $redis->method('incr')->willReturn(10);

        $limiter = new RateLimiter($redis);
        $result  = $limiter->isAllowed('192.168.1.1');

        $this->assertTrue($result['allowed']);
        $this->assertSame(0, $result['remaining']);
    }

    public function testDeniesRequestWhenLimitExceeded(): void
    {
        $redis = $this->makeRedisMock();
        $redis->method('incr')->willReturn(11);

        $limiter = new RateLimiter($redis);
        $result  = $limiter->isAllowed('192.168.1.1');

        $this->assertFalse($result['allowed']);
        $this->assertSame(0, $result['remaining']);
    }

    public function testSetsExpireOnFirstRequest(): void
    {
        $redis = $this->makeRedisMock();
        $redis->method('incr')->willReturn(1);
        $redis->expects($this->once())->method('expire')->with(
            $this->stringContains('rate_limit:10.0.0.1:'),
            60
        );

        $limiter = new RateLimiter($redis);
        $limiter->isAllowed('10.0.0.1');
    }

    public function testDoesNotSetExpireOnSubsequentRequests(): void
    {
        $redis = $this->makeRedisMock();
        $redis->method('incr')->willReturn(5);
        $redis->expects($this->never())->method('expire');

        $limiter = new RateLimiter($redis);
        $limiter->isAllowed('10.0.0.1');
    }

    public function testKeyIncludesIpAndWindow(): void
    {
        $redis = $this->makeRedisMock();

        $capturedKey = null;
        $redis->method('incr')->willReturnCallback(function (string $key) use (&$capturedKey) {
            $capturedKey = $key;
            return 1;
        });
        $redis->method('expire');

        $limiter = new RateLimiter($redis);
        $limiter->isAllowed('172.16.0.5');

        $this->assertNotNull($capturedKey);
        $this->assertStringStartsWith('rate_limit:172.16.0.5:', $capturedKey);

        $expectedWindow = (int) floor(time() / 60);
        $this->assertStringEndsWith((string) $expectedWindow, $capturedKey);
    }
}
