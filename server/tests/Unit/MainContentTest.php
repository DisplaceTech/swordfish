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
use Swordfish\Server\ServerRoutes;

class MainContentTest extends TestCase
{
    private function makeRequest(string $method = 'GET', string $path = '/'): Request
    {
        $client = $this->createMock(Client::class);
        return new Request($client, $method, Http::new('http://localhost' . $path));
    }

    // -------------------------------------------------------------------------
    // mainContent()
    // -------------------------------------------------------------------------

    public function testMainContentReturns200WithHtml(): void
    {
        $logger   = new Logger('test');
        $handler  = ServerRoutes::mainContent($logger);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest('GET', '/')));

        $this->assertSame(Status::OK, $response->getStatus());
        $this->assertSame('text/html', $response->getHeader('content-type'));

        $body = \Amp\Promise\wait($response->getBody()->read());
        $this->assertStringContainsString('<div id="root">', $body);
    }

    // -------------------------------------------------------------------------
    // secretRetrieval()
    // -------------------------------------------------------------------------

    public function testSecretRetrievalReturns200WithHtml(): void
    {
        $logger  = new Logger('test');
        $handler = ServerRoutes::secretRetrieval($logger);

        $client  = $this->createMock(Client::class);
        $request = new Request($client, 'GET', Http::new('http://localhost/secret'));
        $request->setAttribute(Router::class, []); // no secretID in route args

        $response = \Amp\Promise\wait($handler->handleRequest($request));

        $this->assertSame(Status::OK, $response->getStatus());
        $this->assertSame('text/html', $response->getHeader('content-type'));

        $body = \Amp\Promise\wait($response->getBody()->read());
        $this->assertStringContainsString('<div id="root">', $body);
    }

    public function testSecretRetrievalWithSecretIdReturns200WithHtml(): void
    {
        $logger  = new Logger('test');
        $handler = ServerRoutes::secretRetrieval($logger);

        $client  = $this->createMock(Client::class);
        $request = new Request($client, 'GET', Http::new('http://localhost/secret/abc123def456'));
        $request->setAttribute(Router::class, ['secretID' => 'abc123def456']);

        $response = \Amp\Promise\wait($handler->handleRequest($request));

        $this->assertSame(Status::OK, $response->getStatus());
        $this->assertSame('text/html', $response->getHeader('content-type'));

        $body = \Amp\Promise\wait($response->getBody()->read());
        $this->assertStringContainsString('<div id="root">', $body);
    }
}
