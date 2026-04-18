<?php

declare(strict_types=1);

namespace Swordfish\Server\Tests;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\Router;
use Amp\Http\Status;
use League\Uri\Http;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;
use Swordfish\Server\SecretNotFoundException;
use Swordfish\Server\SecretService;
use Swordfish\Server\ServerRoutes;
use Swordfish\Server\Tests\Support\RedisClientStub;

class SecretInfoTest extends TestCase
{
    private function makeRedisMock(): RedisClient
    {
        return $this->getMockBuilder(RedisClientStub::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
    }

    private function makeRequest(string $secretId): Request
    {
        $client  = $this->createMock(Client::class);
        $request = new Request($client, 'GET', Http::new("http://localhost/api/secret/{$secretId}/info"));
        $request->setAttribute(Router::class, ['secretId' => $secretId]);
        return $request;
    }

    // -------------------------------------------------------------------------
    // SecretService::getInfo()
    // -------------------------------------------------------------------------

    public function testGetInfoReturnsMetadataForUnlimitedSecret(): void
    {
        $redis = $this->makeRedisMock();

        $redis->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function (string $key) {
                if (str_starts_with($key, 'json_expires:')) {
                    return '1999999999';
                }
                // No views key → unlimited
                return null;
            });

        $service = new SecretService($redis);
        $result  = $service->getInfo('abc123def456');

        $this->assertSame(1999999999, $result['expires_at']);
        $this->assertNull($result['views_remaining']);
    }

    public function testGetInfoReturnsViewsRemainingForLimitedSecret(): void
    {
        $redis = $this->makeRedisMock();

        $redis->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function (string $key) {
                if (str_starts_with($key, 'json_expires:')) {
                    return '1999999999';
                }
                if (str_starts_with($key, 'json_views:')) {
                    return '3';
                }
                return null;
            });

        $service = new SecretService($redis);
        $result  = $service->getInfo('abc123def456');

        $this->assertSame(3, $result['views_remaining']);
        $this->assertSame(1999999999, $result['expires_at']);
    }

    public function testGetInfoThrowsSecretNotFoundExceptionWhenMissing(): void
    {
        $redis = $this->makeRedisMock();

        $redis->expects($this->once())
            ->method('get')
            ->willReturn(null);

        $service = new SecretService($redis);

        $this->expectException(SecretNotFoundException::class);
        $service->getInfo('doesnotexist');
    }

    // -------------------------------------------------------------------------
    // ServerRoutes::secretInfo() handler
    // -------------------------------------------------------------------------

    public function testSecretInfoHandlerReturns200WithMetadata(): void
    {
        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();

        $redis->method('get')
            ->willReturnCallback(function (string $key) {
                if (str_starts_with($key, 'json_expires:')) {
                    return '1999999999';
                }
                if (str_starts_with($key, 'json_views:')) {
                    return '5';
                }
                return null;
            });

        $handler  = ServerRoutes::secretInfo($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest('abc123def456')));

        $this->assertSame(Status::OK, $response->getStatus());
        $this->assertSame('application/json', $response->getHeader('content-type'));

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);
        $this->assertSame(5, $parsed['views_remaining']);
        $this->assertSame(1999999999, $parsed['expires_at']);
    }

    public function testSecretInfoHandlerReturns200WithNullViewsForUnlimitedSecret(): void
    {
        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();

        $redis->method('get')
            ->willReturnCallback(function (string $key) {
                if (str_starts_with($key, 'json_expires:')) {
                    return '1999999999';
                }
                return null;
            });

        $handler  = ServerRoutes::secretInfo($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest('abc123def456')));

        $this->assertSame(Status::OK, $response->getStatus());

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);
        $this->assertNull($parsed['views_remaining']);
        $this->assertSame(1999999999, $parsed['expires_at']);
    }

    public function testSecretInfoHandlerReturns404ForNonexistentSecret(): void
    {
        $logger = new Logger('test');
        $redis  = $this->makeRedisMock();

        $redis->method('get')->willReturn(null);

        $handler  = ServerRoutes::secretInfo($logger, $redis);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest('doesnotexist')));

        $this->assertSame(Status::NOT_FOUND, $response->getStatus());
        $this->assertSame('application/json', $response->getHeader('content-type'));

        $body   = \Amp\Promise\wait($response->getBody()->read());
        $parsed = json_decode($body, true);
        $this->assertSame('Not Found', $parsed['error']);
    }
}
