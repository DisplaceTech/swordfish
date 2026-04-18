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
use Swordfish\Server\Tests\Support\RedisClientStub;

class CreateSecretJsonTest extends TestCase
{
    private function makeRedisMock(): RedisClient
    {
        return $this->getMockBuilder(RedisClientStub::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setex'])
            ->getMock();
    }

    private function makeRequest(string $body): Request
    {
        $client = $this->createMock(Client::class);
        $request = new Request($client, 'POST', Http::new('http://localhost/api/create'));
        $request->setBody($body);
        return $request;
    }

    private function validPayload(int $ttl = 3600, int $maxViews = 0): string
    {
        $salt     = bin2hex(str_repeat('a', 16));
        $verifier = bin2hex(str_repeat('b', 32));
        $secret   = bin2hex('mysecret');
        $encrypted = "{$salt}\${$verifier}\${$secret}";
        return json_encode(['encrypted_secret' => $encrypted, 'ttl' => $ttl, 'max_views' => $maxViews]);
    }

    public function testReturns201WithJsonOnSuccess(): void
    {
        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();
        $redis->expects($this->exactly(3))->method('setex');

        $handler  = ServerRoutes::createSecret($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest($this->validPayload())));

        $this->assertSame(Status::CREATED, $response->getStatus());
        $this->assertSame('application/json', $response->getHeader('content-type'));

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);

        $this->assertArrayHasKey('id', $parsed);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $parsed['id']);
        $this->assertArrayHasKey('expires_at', $parsed);
        $this->assertIsInt($parsed['expires_at']);
        $this->assertGreaterThan(time(), $parsed['expires_at']);
        $this->assertArrayHasKey('max_views', $parsed);
        $this->assertSame(0, $parsed['max_views']);
    }

    public function testReturns201WithMaxViewsInResponse(): void
    {
        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();
        $redis->expects($this->exactly(4))->method('setex'); // verifier, secret, expires, views

        $handler  = ServerRoutes::createSecret($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest($this->validPayload(3600, 5))));

        $this->assertSame(Status::CREATED, $response->getStatus());

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);

        $this->assertSame(5, $parsed['max_views']);
    }

    public function testReturns400OnMissingEncryptedSecret(): void
    {
        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();

        $handler  = ServerRoutes::createSecret($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest(json_encode(['ttl' => 3600]))));

        $this->assertSame(Status::BAD_REQUEST, $response->getStatus());
        $this->assertSame('application/json', $response->getHeader('content-type'));

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);
        $this->assertSame('Bad Request', $parsed['error']);
        $this->assertArrayHasKey('message', $parsed);
    }

    public function testReturns400OnInvalidJson(): void
    {
        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();

        $handler  = ServerRoutes::createSecret($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest('not-json')));

        $this->assertSame(Status::BAD_REQUEST, $response->getStatus());
        $this->assertSame('application/json', $response->getHeader('content-type'));

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);
        $this->assertSame('Bad Request', $parsed['error']);
        $this->assertArrayHasKey('message', $parsed);
    }

    public function testReturns422OnInvalidTtl(): void
    {
        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();

        $salt     = bin2hex(str_repeat('a', 16));
        $verifier = bin2hex(str_repeat('b', 32));
        $secret   = bin2hex('mysecret');
        $body     = json_encode([
            'encrypted_secret' => "{$salt}\${$verifier}\${$secret}",
            'ttl'              => 9999999,
        ]);

        $handler  = ServerRoutes::createSecret($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest($body)));

        $this->assertSame(Status::UNPROCESSABLE_ENTITY, $response->getStatus());
        $this->assertSame('application/json', $response->getHeader('content-type'));

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);
        $this->assertSame('Unprocessable Entity', $parsed['error']);
        $this->assertArrayHasKey('message', $parsed);
    }

    public function testReturns422OnInvalidMaxViews(): void
    {
        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();

        $salt     = bin2hex(str_repeat('a', 16));
        $verifier = bin2hex(str_repeat('b', 32));
        $secret   = bin2hex('mysecret');
        $body     = json_encode([
            'encrypted_secret' => "{$salt}\${$verifier}\${$secret}",
            'max_views'        => 7,
        ]);

        $handler  = ServerRoutes::createSecret($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest($body)));

        $this->assertSame(Status::UNPROCESSABLE_ENTITY, $response->getStatus());

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);
        $this->assertSame('Unprocessable Entity', $parsed['error']);
        $this->assertArrayHasKey('message', $parsed);
    }

    public function testReturns413OnOversizedPayload(): void
    {
        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();

        $handler  = ServerRoutes::createSecret($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest(str_repeat('x', 101 * 1000))));

        $this->assertSame(Status::PAYLOAD_TOO_LARGE, $response->getStatus());
        $this->assertSame('application/json', $response->getHeader('content-type'));

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);
        $this->assertSame('Payload Too Large', $parsed['error']);
        $this->assertArrayHasKey('message', $parsed);
    }

    public function testExpiresAtReflectsRequestedTtl(): void
    {
        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();
        $redis->method('setex');

        $ttl      = 21600;
        $before   = time();
        $handler  = ServerRoutes::createSecret($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest($this->validPayload($ttl))));
        $after    = time();

        $body      = \Amp\Promise\wait($response->getBody()->read());
        $parsed    = json_decode($body, true);
        $expiresAt = $parsed['expires_at'];

        $this->assertGreaterThanOrEqual($before + $ttl, $expiresAt);
        $this->assertLessThanOrEqual($after + $ttl, $expiresAt);
    }

    public function testReturns422OnDisallowedTtlValue(): void
    {
        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();

        $handler  = ServerRoutes::createSecret($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest($this->validPayload(7200))));

        $this->assertSame(Status::UNPROCESSABLE_ENTITY, $response->getStatus());

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);
        $this->assertSame('Unprocessable Entity', $parsed['error']);
        $this->assertStringContainsString('TTL must be one of', $parsed['message']);
    }
}
