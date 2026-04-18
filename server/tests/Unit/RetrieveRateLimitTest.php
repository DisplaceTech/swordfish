<?php

declare(strict_types=1);

namespace Swordfish\Server\Tests;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Status;
use Amp\Socket\SocketAddress;
use League\Uri\Http;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;
use Swordfish\Server\ServerRoutes;
use Swordfish\Server\Tests\Support\RedisClientStub;

class RetrieveRateLimitTest extends TestCase
{
    private function makeRedisMock(int $incrReturn): RedisClient
    {
        $redis = $this->getMockBuilder(RedisClientStub::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['incr', 'expire'])
            ->getMock();
        $redis->method('incr')->willReturn($incrReturn);
        return $redis;
    }

    private function makeRequest(string $ip = '127.0.0.1'): Request
    {
        $socketAddress = new SocketAddress($ip, 54321);
        $client        = $this->createMock(Client::class);
        $client->method('getRemoteAddress')->willReturn($socketAddress);
        return new Request($client, 'POST', Http::new('http://localhost/api/retrieve'));
    }

    public function testReturns429WhenRateLimitExceeded(): void
    {
        $logger  = new Logger('test');
        $redis   = $this->makeRedisMock(11);

        $handler  = ServerRoutes::retrieveSecretJson($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest()));

        $this->assertSame(Status::TOO_MANY_REQUESTS, $response->getStatus());
        $this->assertSame('application/json', $response->getHeader('content-type'));

        $body    = \Amp\Promise\wait($response->getBody()->read());
        $decoded = json_decode($body, true);
        $this->assertSame('Too Many Requests', $decoded['error']);
        $this->assertArrayHasKey('message', $decoded);

        $this->assertSame('10', $response->getHeader('x-ratelimit-limit'));
        $this->assertSame('0', $response->getHeader('x-ratelimit-remaining'));
        $this->assertNotNull($response->getHeader('x-ratelimit-reset'));
    }

    public function testReturns429AtLimitPlusOne(): void
    {
        $logger  = new Logger('test');
        $redis   = $this->makeRedisMock(11);

        $handler  = ServerRoutes::retrieveSecretJson($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest('10.0.0.1')));

        $this->assertSame(Status::TOO_MANY_REQUESTS, $response->getStatus());
        $this->assertSame('10', $response->getHeader('x-ratelimit-limit'));
    }

    public function testDoesNotReturn429WhenUnderLimit(): void
    {
        $logger = new Logger('test');
        $redis  = $this->getMockBuilder(RedisClientStub::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['incr', 'expire', 'get'])
            ->getMock();
        $redis->method('incr')->willReturn(1);
        $redis->method('get')->willReturn(null); // secret not found → 400 bad request path

        $handler  = ServerRoutes::retrieveSecretJson($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest()));

        $this->assertNotSame(Status::TOO_MANY_REQUESTS, $response->getStatus());
        $this->assertSame('10', $response->getHeader('x-ratelimit-limit'));
        $this->assertSame('9', $response->getHeader('x-ratelimit-remaining'));
        $this->assertNotNull($response->getHeader('x-ratelimit-reset'));
    }
}
