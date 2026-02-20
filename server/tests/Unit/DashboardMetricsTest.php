<?php

declare(strict_types=1);

namespace Swordfish\Server\Tests;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Status;
use League\Uri\Http;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;
use Swordfish\Server\ServerRoutes;

class DashboardMetricsTest extends TestCase
{
    private function makeRedisMock(): RedisClient
    {
        return $this->getMockBuilder(RedisClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['mget'])
            ->getMock();
    }

    private function makeRequest(): Request
    {
        $client = $this->createMock(Client::class);
        return new Request($client, 'GET', Http::new('http://localhost/api/metrics/dashboard'));
    }

    public function testReturns200WithJsonContentType(): void
    {
        $redis = $this->makeRedisMock();
        $redis->method('mget')->willReturn(array_fill(0, 168, null));

        $logger  = new Logger('test');
        $handler = ServerRoutes::dashboardMetrics($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest()));

        $this->assertSame(Status::OK, $response->getStatus());
        $this->assertSame('application/json', $response->getHeader('content-type'));
    }

    public function testReturnsEmptyHoursWhenNoData(): void
    {
        $redis = $this->makeRedisMock();
        $redis->method('mget')->willReturn(array_fill(0, 168, null));

        $logger  = new Logger('test');
        $handler = ServerRoutes::dashboardMetrics($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest()));

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);

        $this->assertArrayHasKey('hours', $parsed);
        $this->assertIsArray($parsed['hours']);
        $this->assertEmpty($parsed['hours']);
    }

    public function testReturnsHourlyBucketsWithData(): void
    {
        $redis = $this->makeRedisMock();

        $createdValues = array_fill(0, 168, null);
        $createdValues[167] = '5';

        $retrievedValues = array_fill(0, 168, null);
        $retrievedValues[167] = '12';

        $bytesStoredValues = array_fill(0, 168, null);
        $bytesStoredValues[167] = '2048';

        $bytesRetrievedValues = array_fill(0, 168, null);
        $bytesRetrievedValues[167] = '8192';

        $redis->method('mget')
            ->willReturnOnConsecutiveCalls(
                $createdValues,
                $retrievedValues,
                $bytesStoredValues,
                $bytesRetrievedValues
            );

        $logger  = new Logger('test');
        $handler = ServerRoutes::dashboardMetrics($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest()));

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);

        $this->assertCount(1, $parsed['hours']);

        $row = $parsed['hours'][0];
        $this->assertSame(5, $row['created']);
        $this->assertSame(12, $row['retrieved']);
        $this->assertSame(2048, $row['bytes_stored']);
        $this->assertSame(8192, $row['bytes_retrieved']);
        $this->assertArrayHasKey('hour', $row);
    }

    public function testOmitsAllZeroHours(): void
    {
        $redis = $this->makeRedisMock();

        $values = array_fill(0, 168, null);
        $values[0] = '3';

        $redis->method('mget')
            ->willReturnOnConsecutiveCalls(
                $values,
                array_fill(0, 168, null),
                array_fill(0, 168, null),
                array_fill(0, 168, null)
            );

        $logger  = new Logger('test');
        $handler = ServerRoutes::dashboardMetrics($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest()));

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);

        $this->assertCount(1, $parsed['hours']);
        $this->assertSame(3, $parsed['hours'][0]['created']);
    }

    public function testCallsMgetFourTimesForEachMetricType(): void
    {
        $redis = $this->makeRedisMock();
        $redis->expects($this->exactly(4))
            ->method('mget')
            ->willReturn(array_fill(0, 168, null));

        $logger  = new Logger('test');
        $handler = ServerRoutes::dashboardMetrics($logger, $redis);
        \Amp\Promise\wait($handler->handleRequest($this->makeRequest()));
    }

    public function testIncludesSecurityHeaders(): void
    {
        $redis = $this->makeRedisMock();
        $redis->method('mget')->willReturn(array_fill(0, 168, null));

        $logger  = new Logger('test');
        $handler = ServerRoutes::dashboardMetrics($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest()));

        $this->assertNotNull($response->getHeader('content-security-policy'));
        $this->assertNotNull($response->getHeader('x-content-type-options'));
    }
}
