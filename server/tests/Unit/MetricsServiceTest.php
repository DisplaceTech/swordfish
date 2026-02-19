<?php

declare(strict_types=1);

namespace Swordfish\Server\Tests;

use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;
use Swordfish\Server\MetricsService;

class MetricsServiceTest extends TestCase
{
    private function makeRedisMock(): RedisClient
    {
        return $this->getMockBuilder(RedisClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['hincrby', 'hgetall'])
            ->getMock();
    }

    // -------------------------------------------------------------------------
    // recordCreated()
    // -------------------------------------------------------------------------

    public function testRecordCreatedIncrementsCreatedAndBytesStoredCounters(): void
    {
        $redis = $this->makeRedisMock();

        $capturedCalls = [];
        $redis->expects($this->exactly(2))
            ->method('hincrby')
            ->willReturnCallback(function (string $key, string $field, int $value) use (&$capturedCalls) {
                $capturedCalls[] = ['key' => $key, 'field' => $field, 'value' => $value];
            });

        $service = new MetricsService($redis);
        $service->recordCreated(512);

        $createdCall    = array_filter($capturedCalls, fn($c) => str_starts_with($c['key'], 'metrics:created:'));
        $bytesCall      = array_filter($capturedCalls, fn($c) => str_starts_with($c['key'], 'metrics:bytes_stored:'));

        $this->assertCount(1, $createdCall);
        $this->assertCount(1, $bytesCall);

        $this->assertSame(1, array_values($createdCall)[0]['value']);
        $this->assertSame(512, array_values($bytesCall)[0]['value']);
    }

    public function testRecordCreatedUsesTodaysDateInKey(): void
    {
        $redis = $this->makeRedisMock();

        $capturedKeys = [];
        $redis->method('hincrby')
            ->willReturnCallback(function (string $key) use (&$capturedKeys) {
                $capturedKeys[] = $key;
            });

        $service = new MetricsService($redis);
        $service->recordCreated(100);

        $today = date('Y-m-d');
        foreach ($capturedKeys as $key) {
            $this->assertStringContainsString($today, $key);
        }
    }

    public function testRecordCreatedUsesCurrentHourAsField(): void
    {
        $redis = $this->makeRedisMock();

        $capturedFields = [];
        $redis->method('hincrby')
            ->willReturnCallback(function (string $key, string $field) use (&$capturedFields) {
                $capturedFields[] = $field;
            });

        $service = new MetricsService($redis);
        $service->recordCreated(100);

        $hour = date('H');
        foreach ($capturedFields as $field) {
            $this->assertSame($hour, $field);
        }
    }

    // -------------------------------------------------------------------------
    // recordRetrieved()
    // -------------------------------------------------------------------------

    public function testRecordRetrievedIncrementsRetrievedAndBytesRetrievedCounters(): void
    {
        $redis = $this->makeRedisMock();

        $capturedCalls = [];
        $redis->expects($this->exactly(2))
            ->method('hincrby')
            ->willReturnCallback(function (string $key, string $field, int $value) use (&$capturedCalls) {
                $capturedCalls[] = ['key' => $key, 'value' => $value];
            });

        $service = new MetricsService($redis);
        $service->recordRetrieved(256);

        $retrievedCall  = array_filter($capturedCalls, fn($c) => str_starts_with($c['key'], 'metrics:retrieved:'));
        $bytesCall      = array_filter($capturedCalls, fn($c) => str_starts_with($c['key'], 'metrics:bytes_retrieved:'));

        $this->assertCount(1, $retrievedCall);
        $this->assertCount(1, $bytesCall);

        $this->assertSame(1, array_values($retrievedCall)[0]['value']);
        $this->assertSame(256, array_values($bytesCall)[0]['value']);
    }

    // -------------------------------------------------------------------------
    // recordExpired()
    // -------------------------------------------------------------------------

    public function testRecordExpiredIncrementsExpiredCounter(): void
    {
        $redis = $this->makeRedisMock();

        $capturedCalls = [];
        $redis->expects($this->once())
            ->method('hincrby')
            ->willReturnCallback(function (string $key, string $field, int $value) use (&$capturedCalls) {
                $capturedCalls[] = ['key' => $key, 'value' => $value];
            });

        $service = new MetricsService($redis);
        $service->recordExpired();

        $this->assertCount(1, $capturedCalls);
        $this->assertStringStartsWith('metrics:expired:', $capturedCalls[0]['key']);
        $this->assertSame(1, $capturedCalls[0]['value']);
    }

    // -------------------------------------------------------------------------
    // getMetrics()
    // -------------------------------------------------------------------------

    public function testGetMetricsReturnsAllCountersForGivenDate(): void
    {
        $redis = $this->makeRedisMock();

        $redis->method('hgetall')
            ->willReturnCallback(function (string $key) {
                if (str_starts_with($key, 'metrics:created:')) {
                    return ['09' => '5'];
                }
                if (str_starts_with($key, 'metrics:bytes_stored:')) {
                    return ['09' => '2048'];
                }
                if (str_starts_with($key, 'metrics:retrieved:')) {
                    return ['09' => '3'];
                }
                if (str_starts_with($key, 'metrics:bytes_retrieved:')) {
                    return ['09' => '1024'];
                }
                if (str_starts_with($key, 'metrics:expired:')) {
                    return ['09' => '1'];
                }
                return [];
            });

        $service = new MetricsService($redis);
        $result  = $service->getMetrics('2025-01-15');

        $this->assertSame(['09' => '5'], $result['created']);
        $this->assertSame(['09' => '2048'], $result['bytes_stored']);
        $this->assertSame(['09' => '3'], $result['retrieved']);
        $this->assertSame(['09' => '1024'], $result['bytes_retrieved']);
        $this->assertSame(['09' => '1'], $result['expired']);
    }

    public function testGetMetricsDefaultsToTodayWhenNoDateGiven(): void
    {
        $redis = $this->makeRedisMock();

        $capturedKeys = [];
        $redis->method('hgetall')
            ->willReturnCallback(function (string $key) use (&$capturedKeys) {
                $capturedKeys[] = $key;
                return [];
            });

        $service = new MetricsService($redis);
        $service->getMetrics();

        $today = date('Y-m-d');
        foreach ($capturedKeys as $key) {
            $this->assertStringContainsString($today, $key);
        }
    }

    public function testGetMetricsReturnsEmptyArraysWhenNoDataExists(): void
    {
        $redis = $this->makeRedisMock();
        $redis->method('hgetall')->willReturn(null);

        $service = new MetricsService($redis);
        $result  = $service->getMetrics('2025-01-01');

        $this->assertSame([], $result['created']);
        $this->assertSame([], $result['bytes_stored']);
        $this->assertSame([], $result['retrieved']);
        $this->assertSame([], $result['bytes_retrieved']);
        $this->assertSame([], $result['expired']);
    }
}
