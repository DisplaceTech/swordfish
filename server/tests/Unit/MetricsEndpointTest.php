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

class MetricsEndpointTest extends TestCase
{
    private function makeRequest(array $headers = []): Request
    {
        $client  = $this->createMock(Client::class);
        $request = new Request($client, 'GET', Http::new('http://localhost/api/metrics'), $headers);
        return $request;
    }

    private function makeRedisMock(): RedisClient
    {
        return $this->getMockBuilder(RedisClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['keys', 'mget'])
            ->getMock();
    }

    // -------------------------------------------------------------------------
    // Auth checks
    // -------------------------------------------------------------------------

    public function testReturns401WhenTokenConfiguredAndMissing(): void
    {
        putenv('METRICS_TOKEN=secret-token');

        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();
        $redis->expects($this->never())->method('keys');

        $handler  = ServerRoutes::metricsEndpoint($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest()));

        $this->assertSame(Status::UNAUTHORIZED, $response->getStatus());

        putenv('METRICS_TOKEN');
    }

    public function testReturns401WhenTokenConfiguredAndWrong(): void
    {
        putenv('METRICS_TOKEN=secret-token');

        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();
        $redis->expects($this->never())->method('keys');

        $handler  = ServerRoutes::metricsEndpoint($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest(
            $this->makeRequest(['authorization' => 'Bearer wrong-token'])
        ));

        $this->assertSame(Status::UNAUTHORIZED, $response->getStatus());

        putenv('METRICS_TOKEN');
    }

    public function testReturns200WhenTokenConfiguredAndCorrect(): void
    {
        putenv('METRICS_TOKEN=secret-token');

        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();
        $redis->method('keys')->willReturn([]);

        $handler  = ServerRoutes::metricsEndpoint($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest(
            $this->makeRequest(['authorization' => 'Bearer secret-token'])
        ));

        $this->assertSame(Status::OK, $response->getStatus());

        putenv('METRICS_TOKEN');
    }

    public function testReturns200WhenNoTokenConfigured(): void
    {
        putenv('METRICS_TOKEN');

        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();
        $redis->method('keys')->willReturn([]);

        $handler  = ServerRoutes::metricsEndpoint($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest()));

        $this->assertSame(Status::OK, $response->getStatus());
    }

    // -------------------------------------------------------------------------
    // Response format
    // -------------------------------------------------------------------------

    public function testResponseHasPrometheusContentType(): void
    {
        putenv('METRICS_TOKEN');

        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();
        $redis->method('keys')->willReturn([]);

        $handler  = ServerRoutes::metricsEndpoint($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest()));

        $this->assertSame('text/plain; version=0.0.4', $response->getHeader('content-type'));
    }

    public function testResponseContainsAllFourMetrics(): void
    {
        putenv('METRICS_TOKEN');

        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();
        $redis->method('keys')->willReturn([]);

        $handler  = ServerRoutes::metricsEndpoint($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest()));
        $body     = \Amp\Promise\wait($response->getBody()->read());

        $this->assertStringContainsString('secrets_created_total', $body);
        $this->assertStringContainsString('secrets_retrieved_total', $body);
        $this->assertStringContainsString('bytes_stored_total', $body);
        $this->assertStringContainsString('bytes_retrieved_total', $body);
    }

    public function testResponseIncludesHelpAndTypeLines(): void
    {
        putenv('METRICS_TOKEN');

        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();
        $redis->method('keys')->willReturn([]);

        $handler  = ServerRoutes::metricsEndpoint($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest()));
        $body     = \Amp\Promise\wait($response->getBody()->read());

        $this->assertStringContainsString('# HELP secrets_created_total', $body);
        $this->assertStringContainsString('# TYPE secrets_created_total counter', $body);
        $this->assertStringContainsString('# HELP secrets_retrieved_total', $body);
        $this->assertStringContainsString('# TYPE secrets_retrieved_total counter', $body);
        $this->assertStringContainsString('# HELP bytes_stored_total', $body);
        $this->assertStringContainsString('# TYPE bytes_stored_total counter', $body);
        $this->assertStringContainsString('# HELP bytes_retrieved_total', $body);
        $this->assertStringContainsString('# TYPE bytes_retrieved_total counter', $body);
    }

    public function testReturnsZeroCountersWhenNoKeysExist(): void
    {
        putenv('METRICS_TOKEN');

        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();
        $redis->method('keys')->willReturn([]);

        $handler  = ServerRoutes::metricsEndpoint($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest()));
        $body     = \Amp\Promise\wait($response->getBody()->read());

        $this->assertStringContainsString('secrets_created_total 0', $body);
        $this->assertStringContainsString('secrets_retrieved_total 0', $body);
        $this->assertStringContainsString('bytes_stored_total 0', $body);
        $this->assertStringContainsString('bytes_retrieved_total 0', $body);
    }

    public function testSumsMultipleHourlyKeysForEachMetric(): void
    {
        putenv('METRICS_TOKEN');

        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();

        $redis->method('keys')
            ->willReturnCallback(function (string $pattern): array {
                return match (true) {
                    str_contains($pattern, 'metrics:created:')        => ['metrics:created:2024-01-01:10', 'metrics:created:2024-01-01:11'],
                    str_contains($pattern, 'metrics:retrieved:')      => ['metrics:retrieved:2024-01-01:10'],
                    str_contains($pattern, 'metrics:bytes_stored:')   => ['metrics:bytes_stored:2024-01-01:10', 'metrics:bytes_stored:2024-01-01:11'],
                    str_contains($pattern, 'metrics:bytes_retrieved:') => ['metrics:bytes_retrieved:2024-01-01:10'],
                    default => [],
                };
            });

        $redis->method('mget')
            ->willReturnCallback(function (array $keys): array {
                return match (count($keys)) {
                    2 => ['10', '5'],
                    1 => ['7'],
                    default => [],
                };
            });

        $handler  = ServerRoutes::metricsEndpoint($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest()));
        $body     = \Amp\Promise\wait($response->getBody()->read());

        $this->assertStringContainsString('secrets_created_total 15', $body);
        $this->assertStringContainsString('secrets_retrieved_total 7', $body);
    }
}
