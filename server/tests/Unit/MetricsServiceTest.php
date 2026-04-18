<?php

declare(strict_types=1);

namespace Swordfish\Server\Tests;

use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;
use Swordfish\Server\MetricsService;
use Swordfish\Server\Tests\Support\RedisClientStub;

class MetricsServiceTest extends TestCase
{
    private function makeRedisMock(): RedisClient
    {
        return $this->getMockBuilder(RedisClientStub::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['incrby', 'expire'])
            ->getMock();
    }

    // -------------------------------------------------------------------------
    // recordCreated()
    // -------------------------------------------------------------------------

    public function testRecordCreatedIncrementsCreatedCounter(): void
    {
        $redis = $this->makeRedisMock();

        $capturedCalls = [];
        $redis->method('incrby')
            ->willReturnCallback(function (string $key, int $amount) use (&$capturedCalls) {
                $capturedCalls[] = ['key' => $key, 'amount' => $amount];
                return $amount; // first call returns the amount (new key)
            });

        $service = new MetricsService($redis);
        $service->recordCreated(100);

        $keys = array_column($capturedCalls, 'key');
        $this->assertCount(2, $capturedCalls);

        $createdKey = array_values(array_filter($keys, fn($k) => str_starts_with($k, 'metrics:created:')));
        $bytesKey   = array_values(array_filter($keys, fn($k) => str_starts_with($k, 'metrics:bytes_stored:')));

        $this->assertCount(1, $createdKey);
        $this->assertCount(1, $bytesKey);
    }

    public function testRecordCreatedUsesIncrbyWithCorrectAmounts(): void
    {
        $redis = $this->makeRedisMock();

        $capturedCalls = [];
        $redis->method('incrby')
            ->willReturnCallback(function (string $key, int $amount) use (&$capturedCalls) {
                $capturedCalls[] = ['key' => $key, 'amount' => $amount];
                return $amount;
            });

        $service = new MetricsService($redis);
        $service->recordCreated(512);

        $createdCall = array_values(array_filter($capturedCalls, fn($c) => str_starts_with($c['key'], 'metrics:created:')));
        $bytesCall   = array_values(array_filter($capturedCalls, fn($c) => str_starts_with($c['key'], 'metrics:bytes_stored:')));

        $this->assertSame(1, $createdCall[0]['amount']);
        $this->assertSame(512, $bytesCall[0]['amount']);
    }

    public function testRecordCreatedSetsExpiry(): void
    {
        $redis = $this->makeRedisMock();

        $expireCalls = [];
        $redis->expects($this->exactly(2))
            ->method('expire')
            ->willReturnCallback(function (string $key, int $ttl) use (&$expireCalls) {
                $expireCalls[] = ['key' => $key, 'ttl' => $ttl];
            });

        $service = new MetricsService($redis);
        $service->recordCreated(100);

        $this->assertCount(2, $expireCalls);
        foreach ($expireCalls as $call) {
            $this->assertSame(90 * 24 * 3600, $call['ttl']);
        }
    }

    public function testRecordCreatedKeyUsesHourlyGranularity(): void
    {
        $redis = $this->makeRedisMock();

        $capturedKeys = [];
        $redis->method('incrby')
            ->willReturnCallback(function (string $key, int $amount) use (&$capturedKeys) {
                $capturedKeys[] = $key;
                return $amount;
            });

        $service = new MetricsService($redis);
        $service->recordCreated(10);

        $expectedHour = date('Y-m-d:H');
        foreach ($capturedKeys as $key) {
            $this->assertStringContainsString($expectedHour, $key);
        }
    }

    // -------------------------------------------------------------------------
    // recordRetrieved()
    // -------------------------------------------------------------------------

    public function testRecordRetrievedIncrementsRetrievedCounter(): void
    {
        $redis = $this->makeRedisMock();

        $capturedCalls = [];
        $redis->method('incrby')
            ->willReturnCallback(function (string $key, int $amount) use (&$capturedCalls) {
                $capturedCalls[] = ['key' => $key, 'amount' => $amount];
                return $amount;
            });

        $service = new MetricsService($redis);
        $service->recordRetrieved(200);

        $keys = array_column($capturedCalls, 'key');
        $this->assertCount(2, $capturedCalls);

        $retrievedKey = array_values(array_filter($keys, fn($k) => str_starts_with($k, 'metrics:retrieved:')));
        $bytesKey     = array_values(array_filter($keys, fn($k) => str_starts_with($k, 'metrics:bytes_retrieved:')));

        $this->assertCount(1, $retrievedKey);
        $this->assertCount(1, $bytesKey);
    }

    public function testRecordRetrievedUsesIncrbyWithCorrectAmounts(): void
    {
        $redis = $this->makeRedisMock();

        $capturedCalls = [];
        $redis->method('incrby')
            ->willReturnCallback(function (string $key, int $amount) use (&$capturedCalls) {
                $capturedCalls[] = ['key' => $key, 'amount' => $amount];
                return $amount;
            });

        $service = new MetricsService($redis);
        $service->recordRetrieved(1024);

        $retrievedCall = array_values(array_filter($capturedCalls, fn($c) => str_starts_with($c['key'], 'metrics:retrieved:')));
        $bytesCall     = array_values(array_filter($capturedCalls, fn($c) => str_starts_with($c['key'], 'metrics:bytes_retrieved:')));

        $this->assertSame(1, $retrievedCall[0]['amount']);
        $this->assertSame(1024, $bytesCall[0]['amount']);
    }

    public function testRecordRetrievedSetsExpiry(): void
    {
        $redis = $this->makeRedisMock();

        $expireCalls = [];
        $redis->expects($this->exactly(2))
            ->method('expire')
            ->willReturnCallback(function (string $key, int $ttl) use (&$expireCalls) {
                $expireCalls[] = ['key' => $key, 'ttl' => $ttl];
            });

        $service = new MetricsService($redis);
        $service->recordRetrieved(200);

        $this->assertCount(2, $expireCalls);
        foreach ($expireCalls as $call) {
            $this->assertSame(90 * 24 * 3600, $call['ttl']);
        }
    }

    public function testRecordRetrievedKeyUsesHourlyGranularity(): void
    {
        $redis = $this->makeRedisMock();

        $capturedKeys = [];
        $redis->method('incrby')
            ->willReturnCallback(function (string $key, int $amount) use (&$capturedKeys) {
                $capturedKeys[] = $key;
                return $amount;
            });

        $service = new MetricsService($redis);
        $service->recordRetrieved(10);

        $expectedHour = date('Y-m-d:H');
        foreach ($capturedKeys as $key) {
            $this->assertStringContainsString($expectedHour, $key);
        }
    }
}
