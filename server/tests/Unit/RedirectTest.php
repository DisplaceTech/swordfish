<?php

declare(strict_types=1);

namespace Swordfish\Server\Tests;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Status;
use League\Uri\Http;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Swordfish\Server\ServerRoutes;

class RedirectTest extends TestCase
{
    private function makeRequest(string $path): Request
    {
        $client = $this->createMock(Client::class);
        return new Request($client, 'POST', Http::new('http://localhost' . $path));
    }

    public function testRedirectCreateReturns308ToApiCreate(): void
    {
        $logger   = new Logger('test');
        $handler  = ServerRoutes::redirectCreate($logger);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest('/create')));

        $this->assertSame(Status::PERMANENT_REDIRECT, $response->getStatus());
        $this->assertSame('/api/create', $response->getHeader('location'));
    }

    public function testRedirectRetrieveReturns308ToApiRetrieve(): void
    {
        $logger   = new Logger('test');
        $handler  = ServerRoutes::redirectRetrieve($logger);
        $response = \Amp\Promise\wait($handler->handleRequest($this->makeRequest('/retrieve')));

        $this->assertSame(Status::PERMANENT_REDIRECT, $response->getStatus());
        $this->assertSame('/api/retrieve', $response->getHeader('location'));
    }
}
