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

        $this->assertTrue($limiter->isAllowed('192.168.1.1'));
    }

    public function testAllowsRequestAtExactLimit(): void
    {
        $redis = $this->makeRedisMock();
        $redis->method('incr')->willReturn(30);

        $limiter = new RateLimiter($redis);

        $this->assertTrue($limiter->isAllowed('192.168.1.1'));
    }

    public function testDeniesRequestWhenLimitExceeded(): void
    {
        $redis = $this->makeRedisMock();
        $redis->method('incr')->willReturn(31);

        $limiter = new RateLimiter($redis);

        $this->assertFalse($limiter->isAllowed('192.168.1.1'));
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
