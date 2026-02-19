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

class CreateSecretJsonTest extends TestCase
{
    private function makeRequest(string $body): Request
    {
        $client = $this->createMock(Client::class);
        return new Request($client, 'POST', Http::new('http://localhost/api/create'), [], $body);
    }

    private function makeRedisMock(): RedisClient
    {
        return $this->getMockBuilder(RedisClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['setex'])
            ->getMock();
    }

    public function testCreateSecretJsonReturns201WithJsonBody(): void
    {
        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();
        $redis->expects($this->exactly(4))->method('setex');

        $handler  = ServerRoutes::createSecretJson($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest(
            $this->makeRequest(json_encode(['encrypted_secret' => 'enc-payload', 'verifier' => 'v', 'ttl' => 3600, 'max_views' => 3]))
        ));

        $this->assertSame(Status::CREATED, $response->getStatus());
        $this->assertSame('application/json', $response->getHeader('content-type'));

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);

        $this->assertArrayHasKey('id', $parsed);
        $this->assertArrayHasKey('expires_at', $parsed);
        $this->assertArrayHasKey('max_views', $parsed);
        $this->assertSame(3, $parsed['max_views']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $parsed['id']);
    }

    public function testCreateSecretJsonUsesDefaultTtlAndMaxViews(): void
    {
        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();
        $redis->expects($this->exactly(3))->method('setex'); // no views key when max_views=0

        $handler  = ServerRoutes::createSecretJson($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest(
            $this->makeRequest(json_encode(['encrypted_secret' => 'enc-payload', 'verifier' => 'v']))
        ));

        $this->assertSame(Status::CREATED, $response->getStatus());

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);

        $this->assertSame(0, $parsed['max_views']);
        $this->assertGreaterThan(time(), $parsed['expires_at']);
    }

    public function testCreateSecretJsonReturns400OnInvalidJson(): void
    {
        $logger  = new Logger('test');
        $redis   = $this->makeRedisMock();
        $handler = ServerRoutes::createSecretJson($logger, $redis);

        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest('not-json')));

        $this->assertSame(Status::BAD_REQUEST, $response->getStatus());
        $this->assertSame('application/json', $response->getHeader('content-type'));

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);
        $this->assertArrayHasKey('error', $parsed);
        $this->assertArrayHasKey('message', $parsed);
    }

    public function testCreateSecretJsonReturns400WhenEncryptedSecretMissing(): void
    {
        $logger  = new Logger('test');
        $redis   = $this->makeRedisMock();
        $handler = ServerRoutes::createSecretJson($logger, $redis);

        $response = \Amp\Promise\wait($handler->handleRequest(
            $this->makeRequest(json_encode(['verifier' => 'v', 'ttl' => 3600]))
        ));

        $this->assertSame(Status::BAD_REQUEST, $response->getStatus());

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);
        $this->assertArrayHasKey('error', $parsed);
        $this->assertArrayHasKey('message', $parsed);
    }

    public function testCreateSecretJsonReturns400WhenVerifierMissing(): void
    {
        $logger  = new Logger('test');
        $redis   = $this->makeRedisMock();
        $handler = ServerRoutes::createSecretJson($logger, $redis);

        $response = \Amp\Promise\wait($handler->handleRequest(
            $this->makeRequest(json_encode(['encrypted_secret' => 'enc', 'ttl' => 3600]))
        ));

        $this->assertSame(Status::BAD_REQUEST, $response->getStatus());

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);
        $this->assertArrayHasKey('error', $parsed);
        $this->assertArrayHasKey('message', $parsed);
    }

    public function testCreateSecretJsonReturns422OnTtlTooLow(): void
    {
        $logger  = new Logger('test');
        $redis   = $this->makeRedisMock();
        $handler = ServerRoutes::createSecretJson($logger, $redis);

        $response = \Amp\Promise\wait($handler->handleRequest(
            $this->makeRequest(json_encode(['encrypted_secret' => 'enc', 'verifier' => 'v', 'ttl' => 0]))
        ));

        $this->assertSame(Status::UNPROCESSABLE_ENTITY, $response->getStatus());
        $this->assertSame('application/json', $response->getHeader('content-type'));

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);
        $this->assertArrayHasKey('error', $parsed);
        $this->assertArrayHasKey('message', $parsed);
    }

    public function testCreateSecretJsonReturns422OnTtlTooHigh(): void
    {
        $logger  = new Logger('test');
        $redis   = $this->makeRedisMock();
        $handler = ServerRoutes::createSecretJson($logger, $redis);

        $response = \Amp\Promise\wait($handler->handleRequest(
            $this->makeRequest(json_encode(['encrypted_secret' => 'enc', 'verifier' => 'v', 'ttl' => 999999]))
        ));

        $this->assertSame(Status::UNPROCESSABLE_ENTITY, $response->getStatus());

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);
        $this->assertArrayHasKey('error', $parsed);
        $this->assertArrayHasKey('message', $parsed);
    }

    public function testCreateSecretJsonReturns422OnNegativeMaxViews(): void
    {
        $logger  = new Logger('test');
        $redis   = $this->makeRedisMock();
        $handler = ServerRoutes::createSecretJson($logger, $redis);

        $response = \Amp\Promise\wait($handler->handleRequest(
            $this->makeRequest(json_encode(['encrypted_secret' => 'enc', 'verifier' => 'v', 'max_views' => -1]))
        ));

        $this->assertSame(Status::UNPROCESSABLE_ENTITY, $response->getStatus());

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);
        $this->assertArrayHasKey('error', $parsed);
        $this->assertArrayHasKey('message', $parsed);
    }

    public function testCreateSecretJsonReturns413OnOversizedPayload(): void
    {
        $logger  = new Logger('test');
        $redis   = $this->makeRedisMock();
        $handler = ServerRoutes::createSecretJson($logger, $redis);

        $oversized = str_repeat('x', 101 * 1000);
        $response  = \Amp\Promise\wait($handler->handleRequest($this->makeRequest($oversized)));

        $this->assertSame(Status::PAYLOAD_TOO_LARGE, $response->getStatus());
        $this->assertSame('application/json', $response->getHeader('content-type'));

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);
        $this->assertArrayHasKey('error', $parsed);
        $this->assertArrayHasKey('message', $parsed);
    }
}
