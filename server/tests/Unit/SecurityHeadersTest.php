<?php

declare(strict_types=1);

namespace Swordfish\Server\Tests;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Status;
use League\Uri\Http;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;
use Swordfish\Server\ServerRoutes;

class SecurityHeadersTest extends TestCase
{
    private const EXPECTED_HEADERS = [
        'content-security-policy'   => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
        'x-content-type-options'    => 'nosniff',
        'x-frame-options'           => 'DENY',
        'referrer-policy'           => 'no-referrer',
        'strict-transport-security' => 'max-age=31536000; includeSubDomains',
        'permissions-policy'        => 'camera=(), microphone=(), geolocation=()',
    ];

    private function assertHasSecurityHeaders(Response $response): void
    {
        foreach (self::EXPECTED_HEADERS as $name => $value) {
            $this->assertSame($value, $response->getHeader($name), "Missing or incorrect header: {$name}");
        }
    }

    private function makeGetRequest(string $path = '/'): Request
    {
        $client = $this->createMock(Client::class);
        return new Request($client, 'GET', Http::new('http://localhost' . $path));
    }

    private function makePostRequest(string $path, string $body = ''): Request
    {
        $client  = $this->createMock(Client::class);
        $request = new Request($client, 'POST', Http::new('http://localhost' . $path));
        $request->setBody($body);
        return $request;
    }

    private function makeRedisMock(array $methods = []): RedisClient
    {
        return $this->getMockBuilder(RedisClient::class)
            ->disableOriginalConstructor()
            ->addMethods($methods)
            ->getMock();
    }

    public function testMainContentIncludesSecurityHeaders(): void
    {
        $handler  = ServerRoutes::mainContent(new Logger('test'));
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeGetRequest('/')));

        $this->assertHasSecurityHeaders($response);
    }

    public function testSecretRetrievalIncludesSecurityHeaders(): void
    {
        $client  = $this->createMock(Client::class);
        $request = new Request($client, 'GET', Http::new('http://localhost/secret'));
        $request->setAttribute(Router::class, []);

        $handler  = ServerRoutes::secretRetrieval(new Logger('test'));
        $response = \Amp\Promise\wait($handler->handleRequest($request));

        $this->assertHasSecurityHeaders($response);
    }

    public function testSpaFallbackIncludesSecurityHeadersOnIndexFallback(): void
    {
        $documentRoot = new CallableRequestHandler(function(): Response {
            return new Response(Status::NOT_FOUND, [], '');
        });

        $handler  = ServerRoutes::spaFallback(new Logger('test'), $documentRoot);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeGetRequest('/about')));

        $this->assertHasSecurityHeaders($response);
    }

    public function testSpaFallbackIncludesSecurityHeadersOnPassThrough(): void
    {
        $documentRoot = new CallableRequestHandler(function(): Response {
            return new Response(Status::OK, ['content-type' => 'application/javascript'], 'console.log("app");');
        });

        $handler  = ServerRoutes::spaFallback(new Logger('test'), $documentRoot);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeGetRequest('/assets/index.js')));

        $this->assertHasSecurityHeaders($response);
    }

    public function testRedirectCreateIncludesSecurityHeaders(): void
    {
        $handler  = ServerRoutes::redirectCreate(new Logger('test'));
        $response = \Amp\Promise\wait($handler->handleRequest($this->makePostRequest('/create')));

        $this->assertHasSecurityHeaders($response);
    }

    public function testRedirectRetrieveIncludesSecurityHeaders(): void
    {
        $handler  = ServerRoutes::redirectRetrieve(new Logger('test'));
        $response = \Amp\Promise\wait($handler->handleRequest($this->makePostRequest('/retrieve')));

        $this->assertHasSecurityHeaders($response);
    }

    public function testHealthCheckIncludesSecurityHeadersOnSuccess(): void
    {
        $redis = $this->makeRedisMock(['ping']);
        $redis->expects($this->once())->method('ping');

        $handler  = ServerRoutes::healthCheck(new Logger('test'), $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeGetRequest('/health')));

        $this->assertSame(Status::OK, $response->getStatus());
        $this->assertHasSecurityHeaders($response);
    }

    public function testHealthCheckIncludesSecurityHeadersOnFailure(): void
    {
        $redis = $this->makeRedisMock(['ping']);
        $redis->method('ping')->willThrowException(new \RuntimeException('Connection refused'));

        $handler  = ServerRoutes::healthCheck(new Logger('test'), $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeGetRequest('/health')));

        $this->assertSame(Status::SERVICE_UNAVAILABLE, $response->getStatus());
        $this->assertHasSecurityHeaders($response);
    }

    public function testCreateSecretIncludesSecurityHeadersOnSuccess(): void
    {
        $redis = $this->makeRedisMock(['setex']);
        $redis->expects($this->exactly(3))->method('setex');

        $salt      = bin2hex(str_repeat('a', 16));
        $verifier  = bin2hex(str_repeat('b', 32));
        $secret    = bin2hex('mysecret');
        $payload   = json_encode(['encrypted_secret' => "{$salt}\${$verifier}\${$secret}", 'ttl' => 3600, 'max_views' => 0]);

        $handler  = ServerRoutes::createSecret(new Logger('test'), $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makePostRequest('/api/create', $payload)));

        $this->assertSame(Status::CREATED, $response->getStatus());
        $this->assertHasSecurityHeaders($response);
    }

    public function testCreateSecretIncludesSecurityHeadersOnBadRequest(): void
    {
        $redis   = $this->makeRedisMock([]);
        $handler = ServerRoutes::createSecret(new Logger('test'), $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makePostRequest('/api/create', 'not-json')));

        $this->assertSame(Status::BAD_REQUEST, $response->getStatus());
        $this->assertHasSecurityHeaders($response);
    }

    public function testCreateSecretIncludesSecurityHeadersOnPayloadTooLarge(): void
    {
        $redis   = $this->makeRedisMock([]);
        $handler = ServerRoutes::createSecret(new Logger('test'), $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makePostRequest('/api/create', str_repeat('x', 101 * 1000))));

        $this->assertSame(Status::PAYLOAD_TOO_LARGE, $response->getStatus());
        $this->assertHasSecurityHeaders($response);
    }

    public function testCspAllowsOnlySameOriginScriptsAndStyles(): void
    {
        $handler  = ServerRoutes::mainContent(new Logger('test'));
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeGetRequest('/')));

        $csp = $response->getHeader('content-security-policy');
        $this->assertNotNull($csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringNotContainsString("'unsafe-inline'", $csp);
        $this->assertStringNotContainsString("'unsafe-eval'", $csp);
    }
}
