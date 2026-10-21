<?php

declare(strict_types=1);

namespace Swordfish\Server\Tests;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Server;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Http\Status;
use Amp\Socket;
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

    public function testSpaFallbackReturns404ForUnknownGetPath(): void
    {
        $logger  = new Logger('test');
        $handler = $this->makeStaticHandler(Status::NOT_FOUND);

        $fallback = ServerRoutes::spaFallback($logger, $handler);
        $response = \Amp\Promise\wait($fallback->handleRequest($this->makeRequest('GET', '/.env')));

        $this->assertSame(Status::NOT_FOUND, $response->getStatus());
        $this->assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
    }

    /**
     * Regression test: with a real DocumentRoot that has been started the way server.php
     * attaches it, a missing file must produce a 404 rather than an Error (which the server
     * turned into a 500).
     */
    public function testRealDocumentRootReturns404ForMissingFileAnd200ForExistingFile(): void
    {
        $root = sys_get_temp_dir() . '/swordfish-docroot-' . bin2hex(random_bytes(4));
        mkdir($root);
        file_put_contents($root . '/app.css', 'body{}');

        $logger       = new Logger('test');
        $socket       = Socket\Server::listen('127.0.0.1:0');
        $documentRoot = new DocumentRoot($root);
        $fallback     = ServerRoutes::spaFallback($logger, $documentRoot);
        $server       = new Server([$socket], $fallback, $logger);

        try {
            \Amp\Promise\wait($documentRoot->onStart($server));

            foreach (['/.env', '/api', '/no/such/file'] as $path) {
                $response = \Amp\Promise\wait($fallback->handleRequest($this->makeRequest('GET', $path)));
                $this->assertSame(Status::NOT_FOUND, $response->getStatus(), $path);
                $this->assertSame('DENY', $response->getHeader('X-Frame-Options'), $path);
            }

            $response = \Amp\Promise\wait($fallback->handleRequest($this->makeRequest('GET', '/app.css')));
            $this->assertSame(Status::OK, $response->getStatus());
        } finally {
            \Amp\Promise\wait($documentRoot->onStop($server));
            $socket->close();
            unlink($root . '/app.css');
            rmdir($root);
        }
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
