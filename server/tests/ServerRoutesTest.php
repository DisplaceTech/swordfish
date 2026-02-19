<?php

namespace Swordfish\Server\Tests;

use PHPUnit\Framework\TestCase;
use Swordfish\Server\ServerRoutes;

class ServerRoutesTest extends TestCase
{
    // -------------------------------------------------------------------------
    // parseJsonCreateBody
    // -------------------------------------------------------------------------

    public function testParseJsonCreateBodyReturnsArrayForValidInput(): void
    {
        $body = json_encode([
            'encrypted_secret' => 'abc123',
            'ttl'              => 3600,
            'max_views'        => 5,
        ]);

        $result = ServerRoutes::parseJsonCreateBody($body);

        $this->assertNotNull($result);
        $this->assertSame('abc123', $result['encrypted_secret']);
        $this->assertSame(3600, $result['ttl']);
        $this->assertSame(5, $result['max_views']);
    }

    public function testParseJsonCreateBodyAppliesDefaultTtl(): void
    {
        $body = json_encode(['encrypted_secret' => 'abc123']);

        $result = ServerRoutes::parseJsonCreateBody($body);

        $this->assertNotNull($result);
        $this->assertSame(86400, $result['ttl']);
    }

    public function testParseJsonCreateBodyAppliesDefaultMaxViews(): void
    {
        $body = json_encode(['encrypted_secret' => 'abc123']);

        $result = ServerRoutes::parseJsonCreateBody($body);

        $this->assertNotNull($result);
        $this->assertSame(1, $result['max_views']);
    }

    public function testParseJsonCreateBodyReturnsNullForInvalidJson(): void
    {
        $result = ServerRoutes::parseJsonCreateBody('not-json');

        $this->assertNull($result);
    }

    public function testParseJsonCreateBodyReturnsNullWhenEncryptedSecretMissing(): void
    {
        $body = json_encode(['ttl' => 3600, 'max_views' => 1]);

        $result = ServerRoutes::parseJsonCreateBody($body);

        $this->assertNull($result);
    }

    public function testParseJsonCreateBodyReturnsNullForEmptyBody(): void
    {
        $result = ServerRoutes::parseJsonCreateBody('');

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // parseJsonRetrieveBody
    // -------------------------------------------------------------------------

    public function testParseJsonRetrieveBodyReturnsIdForValidInput(): void
    {
        $body = json_encode(['id' => 'abc123def456']);

        $result = ServerRoutes::parseJsonRetrieveBody($body);

        $this->assertSame('abc123def456', $result);
    }

    public function testParseJsonRetrieveBodyReturnsNullForInvalidJson(): void
    {
        $result = ServerRoutes::parseJsonRetrieveBody('not-json');

        $this->assertNull($result);
    }

    public function testParseJsonRetrieveBodyReturnsNullWhenIdMissing(): void
    {
        $body = json_encode(['secret' => 'abc123']);

        $result = ServerRoutes::parseJsonRetrieveBody($body);

        $this->assertNull($result);
    }

    public function testParseJsonRetrieveBodyReturnsNullForEmptyBody(): void
    {
        $result = ServerRoutes::parseJsonRetrieveBody('');

        $this->assertNull($result);
    }
}
