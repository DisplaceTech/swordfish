<?php

declare(strict_types=1);

namespace Swordfish\Server\Tests;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Socket\SocketAddress;
use League\Uri\Http;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Swordfish\Server\ClientIpResolver;
use Swordfish\Server\RequestLogMiddleware;

class RequestLogMiddlewareTest extends TestCase
{
    private TestHandler $logs;
    private RequestLogMiddleware $middleware;

    protected function setUp(): void
    {
        $this->logs       = new TestHandler();
        $logger           = new Logger('server', [$this->logs]);
        $this->middleware = new RequestLogMiddleware($logger, new ClientIpResolver(['10.42.0.0/16']));
    }

    private function makeRequest(string $method, string $uri, string $peer, array $headers = []): Request
    {
        $client = $this->createStub(Client::class);
        $client->method('getRemoteAddress')->willReturn(new SocketAddress($peer, 45702));
        return new Request($client, $method, Http::new($uri), $headers);
    }

    private function handlerReturning(int $status): CallableRequestHandler
    {
        return new CallableRequestHandler(fn () => new Response($status, [], 'body'));
    }

    private function messages(): array
    {
        return array_map(fn ($r) => [$r['level_name'], $r['message']], $this->logs->getRecords());
    }

    public function testLogsResolvedClientIpPathAndStatus(): void
    {
        $request  = $this->makeRequest('POST', 'http://localhost/api/retrieve?token=abc', '10.42.0.171', [
            'CF-Connecting-IP' => '203.0.113.7',
            'Authorization'    => 'Bearer s3cret',
        ]);
        $response = \Amp\Promise\wait($this->middleware->handleRequest($request, $this->handlerReturning(Status::OK)));

        $this->assertSame(Status::OK, $response->getStatus());
        $records = $this->messages();
        $this->assertCount(1, $records);
        [$level, $message] = $records[0];
        $this->assertSame('INFO', $level);
        $this->assertMatchesRegularExpression(
            '#^POST /api/retrieve 200 client=203\.0\.113\.7 via=header peer=10\.42\.0\.171 \d+\.\dms$#',
            $message
        );
        $this->assertStringNotContainsString('token', $message);
        $this->assertStringNotContainsString('s3cret', $message);
    }

    public function testLogsPeerWhenUntrusted(): void
    {
        $request = $this->makeRequest('GET', 'http://localhost/about', '198.51.100.9', ['CF-Connecting-IP' => '203.0.113.7']);
        \Amp\Promise\wait($this->middleware->handleRequest($request, $this->handlerReturning(Status::OK)));

        $this->assertStringStartsWith('GET /about 200 client=198.51.100.9 via=peer peer=198.51.100.9 ', $this->messages()[0][1]);
    }

    public function testClientErrorsLogAtWarning(): void
    {
        $request = $this->makeRequest('POST', 'http://localhost/api/retrieve', '10.42.0.1', ['CF-Connecting-IP' => '2001:db8::1']);
        \Amp\Promise\wait($this->middleware->handleRequest($request, $this->handlerReturning(Status::TOO_MANY_REQUESTS)));

        [$level, $message] = $this->messages()[0];
        $this->assertSame('WARNING', $level);
        $this->assertStringStartsWith('POST /api/retrieve 429 client=2001:db8::1 via=header', $message);
    }

    public function testExceptionsLogAs500AndRethrow(): void
    {
        $request = $this->makeRequest('GET', 'http://localhost/boom', '10.42.0.1');
        $handler = new CallableRequestHandler(function () {
            throw new \RuntimeException('boom');
        });

        try {
            \Amp\Promise\wait($this->middleware->handleRequest($request, $handler));
            $this->fail('Expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        [$level, $message] = $this->messages()[0];
        $this->assertSame('ERROR', $level);
        $this->assertStringStartsWith('GET /boom 500 client=10.42.0.1 via=peer', $message);
    }

    public function testSuccessfulHealthChecksAreNotLogged(): void
    {
        $request = $this->makeRequest('GET', 'http://localhost/health', '10.42.0.1');
        \Amp\Promise\wait($this->middleware->handleRequest($request, $this->handlerReturning(Status::OK)));

        $this->assertSame([], $this->logs->getRecords());
    }

    public function testFailedHealthChecksAreLogged(): void
    {
        $request = $this->makeRequest('GET', 'http://localhost/health', '10.42.0.1');
        \Amp\Promise\wait($this->middleware->handleRequest($request, $this->handlerReturning(Status::SERVICE_UNAVAILABLE)));

        $this->assertStringStartsWith('GET /health 503 ', $this->messages()[0][1]);
    }
}
