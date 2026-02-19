<?php

declare(strict_types=1);

namespace Swordfish\Server\Tests;

use Amp\ByteStream\InMemoryStream;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Status;
use League\Uri\Http;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;
use Swordfish\Server\ServerRoutes;

class CreateSecretJsonTest extends TestCase
{
    private function makeRequest(string $body): Request
    {
        $client = $this->createMock(Client::class);
        return new Request(
            $client,
            'POST',
            Http::new('http://localhost/api/create'),
            ['content-type' => 'application/json'],
            new InMemoryStream($body)
        );
    }

    private function makeRedisMock(): RedisClient
    {
        return $this->getMockBuilder(RedisClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['setex'])
            ->getMock();
    }

    public function testReturns201WithValidPayload(): void
    {
        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();
        $redis->expects($this->exactly(3))->method('setex');

        $handler  = ServerRoutes::createSecretJson($logger, $redis);
        $body     = json_encode(['encrypted_secret' => 'abc123', 'ttl' => 3600, 'max_views' => 2]);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest($body)));

        $this->assertSame(Status::CREATED, $response->getStatus());
        $this->assertSame('application/json', $response->getHeader('content-type'));

        $parsed = json_decode(\Amp\Promise\wait($response->getBody()->read()), true);
        $this->assertArrayHasKey('id', $parsed);
        $this->assertArrayHasKey('expires_at', $parsed);
        $this->assertArrayHasKey('max_views', $parsed);
        $this->assertSame(2, $parsed['max_views']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $parsed['id']);
    }

    public function testDefaultsTtlAndMaxViews(): void
    {
        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();
        $redis->expects($this->exactly(3))->method('setex');

        $handler  = ServerRoutes::createSecretJson($logger, $redis);
        $body     = json_encode(['encrypted_secret' => 'abc123']);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest($body)));

        $this->assertSame(Status::CREATED, $response->getStatus());

        $parsed = json_decode(\Amp\Promise\wait($response->getBody()->read()), true);
        $this->assertSame(1, $parsed['max_views']);
        $this->assertGreaterThan(time(), $parsed['expires_at']);
    }

    public function testReturns400WhenBodyIsNotJson(): void
    {
        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();
        $redis->expects($this->never())->method('setex');

        $handler  = ServerRoutes::createSecretJson($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest('not-json')));

        $this->assertSame(Status::BAD_REQUEST, $response->getStatus());
        $this->assertSame('application/json', $response->getHeader('content-type'));
    }

    public function testReturns400WhenEncryptedSecretIsMissing(): void
    {
        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();
        $redis->expects($this->never())->method('setex');

        $handler  = ServerRoutes::createSecretJson($logger, $redis);
        $body     = json_encode(['ttl' => 3600]);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest($body)));

        $this->assertSame(Status::BAD_REQUEST, $response->getStatus());
    }

    public function testReturns400WhenEncryptedSecretIsEmpty(): void
    {
        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();
        $redis->expects($this->never())->method('setex');

        $handler  = ServerRoutes::createSecretJson($logger, $redis);
        $body     = json_encode(['encrypted_secret' => '']);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest($body)));

        $this->assertSame(Status::BAD_REQUEST, $response->getStatus());
    }

    public function testReturns422WhenTtlIsOutOfRange(): void
    {
        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();
        $redis->expects($this->never())->method('setex');

        $handler  = ServerRoutes::createSecretJson($logger, $redis);
        $body     = json_encode(['encrypted_secret' => 'abc123', 'ttl' => 999999]);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest($body)));

        $this->assertSame(Status::UNPROCESSABLE_ENTITY, $response->getStatus());
        $this->assertSame('application/json', $response->getHeader('content-type'));
    }

    public function testReturns422WhenTtlIsZero(): void
    {
        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();
        $redis->expects($this->never())->method('setex');

        $handler  = ServerRoutes::createSecretJson($logger, $redis);
        $body     = json_encode(['encrypted_secret' => 'abc123', 'ttl' => 0]);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest($body)));

        $this->assertSame(Status::UNPROCESSABLE_ENTITY, $response->getStatus());
    }

    public function testReturns422WhenMaxViewsIsZero(): void
    {
        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();
        $redis->expects($this->never())->method('setex');

        $handler  = ServerRoutes::createSecretJson($logger, $redis);
        $body     = json_encode(['encrypted_secret' => 'abc123', 'max_views' => 0]);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest($body)));

        $this->assertSame(Status::UNPROCESSABLE_ENTITY, $response->getStatus());
    }
}
