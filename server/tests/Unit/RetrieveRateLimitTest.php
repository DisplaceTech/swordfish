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
use Swordfish\Server\ClientIpResolver;
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

    private function makeRequest(string $ip = '127.0.0.1', array $headers = []): Request
    {
        $socketAddress = new SocketAddress($ip, 54321);
        $client        = $this->createStub(Client::class);
        $client->method('getRemoteAddress')->willReturn($socketAddress);
        return new Request($client, 'POST', Http::new('http://localhost/api/retrieve'), $headers);
    }

    /**
     * Send one request through the handler and return the IP the rate limiter keyed on.
     */
    private function rateLimitKeyIpFor(Request $request, ?ClientIpResolver $resolver = null): string
    {
        $keys  = [];
        $redis = $this->getMockBuilder(RedisClientStub::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['incr', 'expire'])
            ->getMock();
        $redis->expects($this->once())->method('incr')->willReturnCallback(function (string $key) use (&$keys) {
            $keys[] = $key;
            return 11;
        });

        $handler  = ServerRoutes::retrieveSecretJson(new Logger('test'), $redis, null, $resolver);
        $response = \Amp\Promise\wait($handler->handleRequest($request));
        $this->assertSame(Status::TOO_MANY_REQUESTS, $response->getStatus());

        $this->assertCount(1, $keys);
        $this->assertMatchesRegularExpression('/^rate_limit:(.+):\d+$/', $keys[0]);
        preg_match('/^rate_limit:(.+):\d+$/', $keys[0], $m);
        return $m[1];
    }

    public function testRateLimitKeyedOnCfConnectingIpFromTrustedProxy(): void
    {
        $resolver = new ClientIpResolver(['10.42.0.0/16']);
        $request  = $this->makeRequest('10.42.0.171', ['CF-Connecting-IP' => '203.0.113.7']);

        $this->assertSame('203.0.113.7', $this->rateLimitKeyIpFor($request, $resolver));
    }

    public function testRateLimitDistinguishesClientsBehindSameProxy(): void
    {
        $resolver = new ClientIpResolver(['10.42.0.0/16']);

        $a = $this->rateLimitKeyIpFor($this->makeRequest('10.42.0.171', ['CF-Connecting-IP' => '203.0.113.7']), $resolver);
        $b = $this->rateLimitKeyIpFor($this->makeRequest('10.42.0.171', ['CF-Connecting-IP' => '198.51.100.20']), $resolver);

        $this->assertNotSame($a, $b);
    }

    public function testRateLimitIgnoresHeaderFromUntrustedPeer(): void
    {
        $resolver = new ClientIpResolver(['10.42.0.0/16']);
        $request  = $this->makeRequest('198.51.100.9', ['CF-Connecting-IP' => '203.0.113.7']);

        $this->assertSame('198.51.100.9', $this->rateLimitKeyIpFor($request, $resolver));
    }

    public function testRateLimitIgnoresInvalidHeaderFromTrustedProxy(): void
    {
        $resolver = new ClientIpResolver(['10.42.0.0/16']);
        $request  = $this->makeRequest('10.42.0.171', ['CF-Connecting-IP' => 'spoofed']);

        $this->assertSame('10.42.0.171', $this->rateLimitKeyIpFor($request, $resolver));
    }

    public function testRateLimitKeyedOnIpv6ClientFromTrustedProxy(): void
    {
        $resolver = new ClientIpResolver(['10.42.0.0/16']);
        $request  = $this->makeRequest('10.42.0.171', ['CF-Connecting-IP' => '2001:db8::42']);

        $this->assertSame('2001:db8::42', $this->rateLimitKeyIpFor($request, $resolver));
    }

    public function testRateLimitUsesEnvironmentDefaultWhenNoResolverGiven(): void
    {
        putenv('TRUSTED_PROXY_CIDRS');
        $request = $this->makeRequest('10.42.0.171', ['CF-Connecting-IP' => '203.0.113.7']);

        $this->assertSame('203.0.113.7', $this->rateLimitKeyIpFor($request));
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
