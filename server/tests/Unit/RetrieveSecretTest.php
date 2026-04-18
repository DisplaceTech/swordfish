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

/**
 * Tests for the v1 (backward-compatible) POST /retrieve handler.
 *
 * Format: hex(secretID)$hex(verifier)
 */
class RetrieveSecretTest extends TestCase
{
    private function makeRedisMock(): RedisClient
    {
        return $this->getMockBuilder(RedisClientStub::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
    }

    private function makeRequest(string $body): Request
    {
        $client  = $this->createMock(Client::class);
        $request = new Request($client, 'POST', Http::new('http://localhost/retrieve'));
        $request->setBody($body);
        return $request;
    }

    private function validPayload(string $secretID, string $verifier): string
    {
        return $secretID . '$' . bin2hex($verifier);
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function testReturns200WithSecretOnValidRequest(): void
    {
        $secretID = 'abc123def456';
        $verifier = str_repeat('v', 32);
        $hash     = password_hash(bin2hex($verifier), PASSWORD_DEFAULT);
        $payload  = 'the-encrypted-secret';

        $redis = $this->makeRedisMock();
        $redis->method('get')
            ->willReturnCallback(function (string $key) use ($hash, $payload) {
                if (str_starts_with($key, 'verifier:')) {
                    return $hash;
                }
                return $payload;
            });

        $logger   = new Logger('test');
        $handler  = ServerRoutes::retrieveSecret($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest($this->validPayload($secretID, $verifier))));

        $this->assertSame(Status::OK, $response->getStatus());
        $this->assertSame('text/plain', $response->getHeader('content-type'));

        $body = \Amp\Promise\wait($response->getBody()->read());
        $this->assertSame($payload, $body);
    }

    // -------------------------------------------------------------------------
    // Invalid request format
    // -------------------------------------------------------------------------

    public function testReturns400OnMalformedBody(): void
    {
        $redis  = $this->makeRedisMock();
        $logger = new Logger('test');

        $handler  = ServerRoutes::retrieveSecret($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest('not-valid-format')));

        $this->assertSame(Status::BAD_REQUEST, $response->getStatus());
    }

    public function testReturns400OnEmptyBody(): void
    {
        $redis  = $this->makeRedisMock();
        $logger = new Logger('test');

        $handler  = ServerRoutes::retrieveSecret($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest('')));

        $this->assertSame(Status::BAD_REQUEST, $response->getStatus());
    }

    public function testReturns400OnShortSecretID(): void
    {
        $redis  = $this->makeRedisMock();
        $logger = new Logger('test');

        $verifier = bin2hex(str_repeat('v', 32));
        $handler  = ServerRoutes::retrieveSecret($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest("short\${$verifier}")));

        $this->assertSame(Status::BAD_REQUEST, $response->getStatus());
    }

    // -------------------------------------------------------------------------
    // Authentication errors
    // -------------------------------------------------------------------------

    public function testReturns401OnInvalidVerifier(): void
    {
        $secretID = 'abc123def456';
        $verifier = str_repeat('v', 32);
        $hash     = password_hash(bin2hex('different-verifier'), PASSWORD_DEFAULT);

        $redis = $this->makeRedisMock();
        $redis->method('get')->willReturn($hash);

        $logger   = new Logger('test');
        $handler  = ServerRoutes::retrieveSecret($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest($this->validPayload($secretID, $verifier))));

        $this->assertSame(Status::UNAUTHORIZED, $response->getStatus());
    }

    // -------------------------------------------------------------------------
    // Not found / expired
    // -------------------------------------------------------------------------

    public function testReturns404WhenVerifierKeyMissing(): void
    {
        $secretID = 'abc123def456';
        $verifier = str_repeat('v', 32);

        $redis = $this->makeRedisMock();
        $redis->method('get')->willReturn(null);

        $logger   = new Logger('test');
        $handler  = ServerRoutes::retrieveSecret($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest($this->validPayload($secretID, $verifier))));

        $this->assertSame(Status::NOT_FOUND, $response->getStatus());
    }

    public function testReturns404WhenSecretKeyMissing(): void
    {
        $secretID = 'abc123def456';
        $verifier = str_repeat('v', 32);
        $hash     = password_hash(bin2hex($verifier), PASSWORD_DEFAULT);

        $redis = $this->makeRedisMock();
        $redis->method('get')
            ->willReturnCallback(function (string $key) use ($hash) {
                if (str_starts_with($key, 'verifier:')) {
                    return $hash;
                }
                return null; // secret key expired/missing
            });

        $logger   = new Logger('test');
        $handler  = ServerRoutes::retrieveSecret($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest($this->validPayload($secretID, $verifier))));

        $this->assertSame(Status::NOT_FOUND, $response->getStatus());
    }
}
