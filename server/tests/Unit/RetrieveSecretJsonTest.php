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

/**
 * Tests for the JSON API POST /api/retrieve handler (happy path, error paths, view limits).
 *
 * Rate-limiting behaviour is covered separately in RetrieveRateLimitTest.
 */
class RetrieveSecretJsonTest extends TestCase
{
    private function makeRedisMock(array $methods = ['incr', 'expire', 'get', 'eval']): RedisClient
    {
        $redis = $this->getMockBuilder(RedisClient::class)
            ->disableOriginalConstructor()
            ->addMethods($methods)
            ->getMock();

        // Default: rate limiter allows the request (count = 1)
        $redis->method('incr')->willReturn(1);

        return $redis;
    }

    private function makeRequest(string $body, string $ip = '127.0.0.1'): Request
    {
        $socketAddress = new SocketAddress($ip, 54321);
        $client        = $this->createMock(Client::class);
        $client->method('getRemoteAddress')->willReturn($socketAddress);

        $request = new Request($client, 'POST', Http::new('http://localhost/api/retrieve'));
        $request->setBody($body);
        return $request;
    }

    private function validBody(string $secretID, string $hexVerifier): string
    {
        return json_encode(['id' => $secretID, 'verifier' => $hexVerifier]);
    }

    // -------------------------------------------------------------------------
    // Happy path — unlimited views
    // -------------------------------------------------------------------------

    public function testReturns200WithSecretDataForUnlimitedSecret(): void
    {
        $secretID    = 'abc123def456';
        $rawVerifier = str_repeat("\xab", 32);
        $hexVerifier = bin2hex($rawVerifier);
        $hash        = password_hash($rawVerifier, PASSWORD_DEFAULT);

        $redis = $this->makeRedisMock();
        $redis->method('get')
            ->willReturnCallback(function (string $key) use ($hash) {
                if (str_starts_with($key, 'json_verifier:')) {
                    return $hash;
                }
                return null;
            });
        $redis->method('eval')->willReturn(['encrypted-payload', '9999999999', null]);

        $logger   = new Logger('test');
        $handler  = ServerRoutes::retrieveSecretJson($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest($this->validBody($secretID, $hexVerifier))));

        $this->assertSame(Status::OK, $response->getStatus());
        $this->assertSame('application/json', $response->getHeader('content-type'));

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);

        $this->assertSame('encrypted-payload', $parsed['encrypted_secret']);
        $this->assertNull($parsed['views_remaining']);
        $this->assertSame(9999999999, $parsed['expires_at']);
    }

    // -------------------------------------------------------------------------
    // Happy path — view-limited secret
    // -------------------------------------------------------------------------

    public function testReturns200WithViewsRemainingForLimitedSecret(): void
    {
        $secretID    = 'abc123def456';
        $rawVerifier = str_repeat("\xab", 32);
        $hexVerifier = bin2hex($rawVerifier);
        $hash        = password_hash($rawVerifier, PASSWORD_DEFAULT);

        $redis = $this->makeRedisMock();
        $redis->method('get')
            ->willReturnCallback(function (string $key) use ($hash) {
                if (str_starts_with($key, 'json_verifier:')) {
                    return $hash;
                }
                return null;
            });
        $redis->method('eval')->willReturn(['encrypted-payload', '9999999999', '4']);

        $logger   = new Logger('test');
        $handler  = ServerRoutes::retrieveSecretJson($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest($this->validBody($secretID, $hexVerifier))));

        $this->assertSame(Status::OK, $response->getStatus());

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);

        $this->assertSame(4, $parsed['views_remaining']);
    }

    // -------------------------------------------------------------------------
    // Happy path — last view (views_remaining = 0)
    // -------------------------------------------------------------------------

    public function testReturns200WhenLastViewIsConsumed(): void
    {
        $secretID    = 'abc123def456';
        $rawVerifier = str_repeat("\xab", 32);
        $hexVerifier = bin2hex($rawVerifier);
        $hash        = password_hash($rawVerifier, PASSWORD_DEFAULT);

        $redis = $this->makeRedisMock();
        $redis->method('get')
            ->willReturnCallback(function (string $key) use ($hash) {
                if (str_starts_with($key, 'json_verifier:')) {
                    return $hash;
                }
                return null;
            });
        // Lua script returns 0 views remaining and handles deletion internally
        $redis->method('eval')->willReturn(['encrypted-payload', '9999999999', '0']);

        $logger   = new Logger('test');
        $handler  = ServerRoutes::retrieveSecretJson($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest($this->validBody($secretID, $hexVerifier))));

        $this->assertSame(Status::OK, $response->getStatus());

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);

        $this->assertSame(0, $parsed['views_remaining']);
    }

    // -------------------------------------------------------------------------
    // Invalid request body
    // -------------------------------------------------------------------------

    public function testReturns400OnInvalidJson(): void
    {
        $redis  = $this->makeRedisMock();
        $logger = new Logger('test');

        $handler  = ServerRoutes::retrieveSecretJson($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest('not-json')));

        $this->assertSame(Status::BAD_REQUEST, $response->getStatus());
        $this->assertSame('application/json', $response->getHeader('content-type'));

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);
        $this->assertSame('Bad Request', $parsed['error']);
        $this->assertArrayHasKey('message', $parsed);
    }

    public function testReturns400OnMissingIdField(): void
    {
        $redis  = $this->makeRedisMock();
        $logger = new Logger('test');

        $handler  = ServerRoutes::retrieveSecretJson($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest(json_encode(['verifier' => 'v']))));

        $this->assertSame(Status::BAD_REQUEST, $response->getStatus());

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);
        $this->assertSame('Bad Request', $parsed['error']);
    }

    public function testReturns400OnMissingVerifierField(): void
    {
        $redis  = $this->makeRedisMock();
        $logger = new Logger('test');

        $handler  = ServerRoutes::retrieveSecretJson($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest(json_encode(['id' => 'abc123def456']))));

        $this->assertSame(Status::BAD_REQUEST, $response->getStatus());

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);
        $this->assertSame('Bad Request', $parsed['error']);
    }

    public function testReturns400OnEmptyBody(): void
    {
        $redis  = $this->makeRedisMock();
        $logger = new Logger('test');

        $handler  = ServerRoutes::retrieveSecretJson($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest('')));

        $this->assertSame(Status::BAD_REQUEST, $response->getStatus());
    }

    // -------------------------------------------------------------------------
    // Authentication error
    // -------------------------------------------------------------------------

    public function testReturns401OnInvalidVerifier(): void
    {
        $secretID       = 'abc123def456';
        $correctBinary  = str_repeat("\xab", 32);
        $wrongHex       = bin2hex(str_repeat("\xcd", 32));
        $hash           = password_hash($correctBinary, PASSWORD_DEFAULT);

        $redis = $this->makeRedisMock();
        $redis->method('get')->willReturn($hash);

        $logger   = new Logger('test');
        $handler  = ServerRoutes::retrieveSecretJson($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest($this->validBody($secretID, $wrongHex))));

        $this->assertSame(Status::UNAUTHORIZED, $response->getStatus());
        $this->assertSame('application/json', $response->getHeader('content-type'));

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);
        $this->assertSame('Unauthorized', $parsed['error']);
        $this->assertArrayHasKey('message', $parsed);
    }

    // -------------------------------------------------------------------------
    // Not found / expired / views exhausted
    // -------------------------------------------------------------------------

    public function testReturns404WhenVerifierKeyMissing(): void
    {
        $secretID    = 'abc123def456';
        $hexVerifier = bin2hex(str_repeat("\xab", 32));

        $redis = $this->makeRedisMock();
        $redis->method('get')->willReturn(null);

        $logger   = new Logger('test');
        $handler  = ServerRoutes::retrieveSecretJson($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest($this->validBody($secretID, $hexVerifier))));

        $this->assertSame(Status::NOT_FOUND, $response->getStatus());
        $this->assertSame('application/json', $response->getHeader('content-type'));

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);
        $this->assertSame('Not Found', $parsed['error']);
        $this->assertArrayHasKey('message', $parsed);
    }

    public function testReturns404WhenViewsExhausted(): void
    {
        $secretID    = 'abc123def456';
        $rawVerifier = str_repeat("\xab", 32);
        $hexVerifier = bin2hex($rawVerifier);
        $hash        = password_hash($rawVerifier, PASSWORD_DEFAULT);

        $redis = $this->makeRedisMock();
        $redis->method('get')
            ->willReturnCallback(function (string $key) use ($hash) {
                if (str_starts_with($key, 'json_verifier:')) {
                    return $hash;
                }
                return null;
            });
        // Lua script returns null secret when views counter went negative
        $redis->method('eval')->willReturn([null, null, null]);

        $logger   = new Logger('test');
        $handler  = ServerRoutes::retrieveSecretJson($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest($this->validBody($secretID, $hexVerifier))));

        $this->assertSame(Status::NOT_FOUND, $response->getStatus());

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);
        $this->assertSame('Not Found', $parsed['error']);
    }

    // -------------------------------------------------------------------------
    // Rate-limit headers are present on successful responses
    // -------------------------------------------------------------------------

    public function testRateLimitHeadersPresentOnSuccessResponse(): void
    {
        $secretID    = 'abc123def456';
        $rawVerifier = str_repeat("\xab", 32);
        $hexVerifier = bin2hex($rawVerifier);
        $hash        = password_hash($rawVerifier, PASSWORD_DEFAULT);

        $redis = $this->makeRedisMock();
        $redis->method('get')
            ->willReturnCallback(function (string $key) use ($hash) {
                if (str_starts_with($key, 'json_verifier:')) {
                    return $hash;
                }
                return null;
            });
        $redis->method('eval')->willReturn(['encrypted-payload', '9999999999', null]);

        $logger   = new Logger('test');
        $handler  = ServerRoutes::retrieveSecretJson($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest($this->validBody($secretID, $hexVerifier))));

        $this->assertSame(Status::OK, $response->getStatus());
        $this->assertNotNull($response->getHeader('x-ratelimit-limit'));
        $this->assertNotNull($response->getHeader('x-ratelimit-remaining'));
        $this->assertNotNull($response->getHeader('x-ratelimit-reset'));
    }
}
