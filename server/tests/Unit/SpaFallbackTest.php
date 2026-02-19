<?php

declare(strict_types=1);

namespace Swordfish\Server\Tests;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use League\Uri\Http;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Swordfish\Server\ServerRoutes;

class SpaFallbackTest extends TestCase
{
    private function makeRequest(string $method = 'GET', string $path = '/about'): Request
    {
        $client = $this->createMock(Client::class);
        return new Request($client, $method, Http::new('http://localhost' . $path));
    }

    private function makeStaticHandler(int $status, string $body = ''): CallableRequestHandler
    {
        return new CallableRequestHandler(function() use ($status, $body): Response {
            return new Response($status, [], $body);
        });
    }

    public function testSpaFallbackServesIndexHtmlWhenDocumentRootReturns404(): void
    {
        $logger  = new Logger('test');
        $handler = $this->makeStaticHandler(Status::NOT_FOUND);

        $fallback = ServerRoutes::spaFallback($logger, $handler);
        $response = \Amp\Promise\wait($fallback->handleRequest($this->makeRequest('GET', '/about')));

        $this->assertSame(Status::OK, $response->getStatus());
        $this->assertSame('text/html', $response->getHeader('content-type'));

        $body = \Amp\Promise\wait($response->getBody()->read());
        $this->assertStringContainsString('<div id="root">', $body);
    }

    public function testSpaFallbackPassesThroughSuccessfulStaticAssetResponse(): void
    {
        $logger  = new Logger('test');
        $handler = $this->makeStaticHandler(Status::OK, 'console.log("app");');

        $fallback = ServerRoutes::spaFallback($logger, $handler);
        $response = \Amp\Promise\wait($fallback->handleRequest($this->makeRequest('GET', '/assets/index.js')));

        $this->assertSame(Status::OK, $response->getStatus());

        $body = \Amp\Promise\wait($response->getBody()->read());
        $this->assertSame('console.log("app");', $body);
    }

    public function testSpaFallbackDoesNotOverrideNonGetNotFoundResponses(): void
    {
        $logger  = new Logger('test');
        $handler = $this->makeStaticHandler(Status::NOT_FOUND);

        $fallback = ServerRoutes::spaFallback($logger, $handler);
        $response = \Amp\Promise\wait($fallback->handleRequest($this->makeRequest('POST', '/unknown')));

        $this->assertSame(Status::NOT_FOUND, $response->getStatus());
    }
}
