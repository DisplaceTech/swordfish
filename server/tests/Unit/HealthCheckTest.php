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
use Predis\Connection\ConnectionException;
use Swordfish\Server\ServerRoutes;
use Swordfish\Server\Tests\Support\RedisClientStub;

class HealthCheckTest extends TestCase
{
    private function makeRequest(): Request
    {
        $client = $this->createMock(Client::class);
        return new Request($client, 'GET', Http::new('http://localhost/health'));
    }

    private function makeRedisMock(): RedisClient
    {
        return $this->getMockBuilder(RedisClientStub::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['ping'])
            ->getMock();
    }

    public function testHealthCheckReturns200WhenRedisIsReachable(): void
    {
        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();
        $redis->expects($this->once())->method('ping');

        $handler  = ServerRoutes::healthCheck($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest()));

        $this->assertSame(Status::OK, $response->getStatus());
        $this->assertSame('application/json', $response->getHeader('content-type'));

        $body = \Amp\Promise\wait($response->getBody()->read());
        $this->assertSame(['status' => 'ok'], json_decode($body, true));
    }

    public function testHealthCheckReturns503WhenRedisIsUnreachable(): void
    {
        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();
        $redis->method('ping')->willThrowException(
            new \RuntimeException('Connection refused')
        );

        $handler  = ServerRoutes::healthCheck($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest()));

        $this->assertSame(Status::SERVICE_UNAVAILABLE, $response->getStatus());
        $this->assertSame('application/json', $response->getHeader('content-type'));

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);
        $this->assertSame('error', $parsed['status']);
    }
}
