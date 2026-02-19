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
        $hexVerifier = bin2hex(str_repeat("\xab", 32));
        $hash        = password_hash($hexVerifier, PASSWORD_DEFAULT);

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
        $hexVerifier = bin2hex(str_repeat("\xab", 32));
        $hash        = password_hash($hexVerifier, PASSWORD_DEFAULT);

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
        $hexVerifier = bin2hex(str_repeat("\xab", 32));
        $hash        = password_hash($hexVerifier, PASSWORD_DEFAULT);

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
        $correctHex     = bin2hex(str_repeat("\xab", 32));
        $wrongHex       = bin2hex(str_repeat("\xcd", 32));
        $hash           = password_hash($correctHex, PASSWORD_DEFAULT);

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
        $hexVerifier = bin2hex(str_repeat("\xab", 32));
        $hash        = password_hash($hexVerifier, PASSWORD_DEFAULT);

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
    // Wire-format create→retrieve round trip (verifier encoding contract)
    // -------------------------------------------------------------------------

    /**
     * Drive both the create and retrieve route handlers with the same Redis
     * mock to verify the verifier encoding is compatible end-to-end.
     *
     * Both sides now operate on the hex-encoded verifier string:
     * create stores bcrypt(bin2hex(rawVerifier)), retrieve calls
     * password_verify(hexVerifier, hash). This avoids the bcrypt null-byte
     * limitation while keeping the encoding consistent.
     */
    public function testCreateThenRetrieveRoundTripSucceeds(): void
    {
        $saltHex     = bin2hex(random_bytes(16));
        $verifierHex = bin2hex(random_bytes(32));
        $payloadHex  = bin2hex('test-nonce-ciphertext');

        $createBody = json_encode([
            'encrypted_secret' => "{$saltHex}\${$verifierHex}\${$payloadHex}",
            'ttl'              => 3600,
            'max_views'        => 1,
        ]);

        $store = [];
        $redis = $this->makeRedisMock(['setex', 'incr', 'expire', 'get', 'eval']);
        $redis->method('incr')->willReturn(1);
        $redis->method('setex')
            ->willReturnCallback(function (string $key, int $ttl, string $value) use (&$store) {
                $store[$key] = $value;
            });
        $redis->method('get')
            ->willReturnCallback(function (string $key) use (&$store) {
                return $store[$key] ?? null;
            });
        $redis->method('eval')
            ->willReturnCallback(function () use (&$store) {
                $secret  = null;
                $expires = null;
                foreach ($store as $k => $v) {
                    if (str_starts_with($k, 'json_secret:'))  $secret  = $v;
                    if (str_starts_with($k, 'json_expires:')) $expires = $v;
                }
                return [$secret, $expires, '0'];
            });

        $logger = new Logger('test');

        // Step 1: Create
        $createClient  = $this->createMock(\Amp\Http\Server\Driver\Client::class);
        $createRequest = new Request($createClient, 'POST', Http::new('http://localhost/api/create'));
        $createRequest->setBody($createBody);

        $createHandler  = ServerRoutes::createSecret($logger, $redis);
        $createResponse = \Amp\Promise\wait($createHandler->handleRequest($createRequest));

        $this->assertSame(Status::CREATED, $createResponse->getStatus());

        $createParsed = json_decode(\Amp\Promise\wait($createResponse->getBody()->read()), true);
        $secretID     = $createParsed['id'];

        // Step 2: Retrieve with the same hex verifier
        $retrieveBody    = json_encode(['id' => $secretID, 'verifier' => $verifierHex]);
        $retrieveRequest = $this->makeRequest($retrieveBody);

        $retrieveHandler  = ServerRoutes::retrieveSecretJson($logger, $redis);
        $retrieveResponse = \Amp\Promise\wait($retrieveHandler->handleRequest($retrieveRequest));

        $this->assertSame(Status::OK, $retrieveResponse->getStatus());

        $retrieveParsed = json_decode(\Amp\Promise\wait($retrieveResponse->getBody()->read()), true);
        $this->assertArrayHasKey('encrypted_secret', $retrieveParsed);
        $this->assertNotEmpty($retrieveParsed['encrypted_secret']);
    }

    /**
     * Create a secret then attempt retrieval with a different verifier hex
     * string. Must return 401.
     */
    public function testCreateThenRetrieveWithWrongVerifierReturns401(): void
    {
        $saltHex     = bin2hex(random_bytes(16));
        $verifierHex = bin2hex(random_bytes(32));
        $payloadHex  = bin2hex('test-nonce-ciphertext');

        $createBody = json_encode([
            'encrypted_secret' => "{$saltHex}\${$verifierHex}\${$payloadHex}",
            'ttl'              => 3600,
            'max_views'        => 0,
        ]);

        $store = [];
        $redis = $this->makeRedisMock(['setex', 'incr', 'expire', 'get', 'eval']);
        $redis->method('incr')->willReturn(1);
        $redis->method('setex')
            ->willReturnCallback(function (string $key, int $ttl, string $value) use (&$store) {
                $store[$key] = $value;
            });
        $redis->method('get')
            ->willReturnCallback(function (string $key) use (&$store) {
                return $store[$key] ?? null;
            });

        $logger = new Logger('test');

        // Step 1: Create
        $createClient  = $this->createMock(\Amp\Http\Server\Driver\Client::class);
        $createRequest = new Request($createClient, 'POST', Http::new('http://localhost/api/create'));
        $createRequest->setBody($createBody);

        $createHandler  = ServerRoutes::createSecret($logger, $redis);
        $createResponse = \Amp\Promise\wait($createHandler->handleRequest($createRequest));

        $this->assertSame(Status::CREATED, $createResponse->getStatus());

        $createParsed = json_decode(\Amp\Promise\wait($createResponse->getBody()->read()), true);
        $secretID     = $createParsed['id'];

        // Step 2: Retrieve with a WRONG verifier
        $wrongHex        = bin2hex(random_bytes(32));
        $retrieveBody    = json_encode(['id' => $secretID, 'verifier' => $wrongHex]);
        $retrieveRequest = $this->makeRequest($retrieveBody);

        $retrieveHandler  = ServerRoutes::retrieveSecretJson($logger, $redis);
        $retrieveResponse = \Amp\Promise\wait($retrieveHandler->handleRequest($retrieveRequest));

        $this->assertSame(Status::UNAUTHORIZED, $retrieveResponse->getStatus());
    }

    // -------------------------------------------------------------------------
    // Rate-limit headers are present on successful responses
    // -------------------------------------------------------------------------

    public function testRateLimitHeadersPresentOnSuccessResponse(): void
    {
        $secretID    = 'abc123def456';
        $hexVerifier = bin2hex(str_repeat("\xab", 32));
        $hash        = password_hash($hexVerifier, PASSWORD_DEFAULT);

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
