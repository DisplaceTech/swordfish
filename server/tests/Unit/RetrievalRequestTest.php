<?php

declare(strict_types=1);

namespace Swordfish\Server\Tests;

use PHPUnit\Framework\TestCase;
use Swordfish\Server\RetrievalRequest;

class RetrievalRequestTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Constructor and getters
    // -------------------------------------------------------------------------

    public function testIdReturnsSecretID(): void
    {
        $request = new RetrievalRequest('abc123def456', 'my-verifier');
        $this->assertSame('abc123def456', $request->ID());
    }

    public function testVerifierReturnsPlainTextVerifier(): void
    {
        $request = new RetrievalRequest('abc123def456', 'my-verifier');
        $this->assertSame('my-verifier', $request->verifier());
    }

    // -------------------------------------------------------------------------
    // verify_password()
    // -------------------------------------------------------------------------

    public function testVerifyPasswordReturnsTrueForMatchingHash(): void
    {
        $verifier = 'correct-verifier';
        $hash     = password_hash($verifier, PASSWORD_DEFAULT);
        $request  = new RetrievalRequest('abc123def456', $verifier);

        $this->assertTrue($request->verify_password($hash));
    }

    public function testVerifyPasswordReturnsFalseForWrongVerifier(): void
    {
        $hash    = password_hash('correct-verifier', PASSWORD_DEFAULT);
        $request = new RetrievalRequest('abc123def456', 'wrong-verifier');

        $this->assertFalse($request->verify_password($hash));
    }

    // -------------------------------------------------------------------------
    // fromString() — happy path
    // -------------------------------------------------------------------------

    public function testFromStringParsesValidRequest(): void
    {
        $secretID = 'abc123def456';
        $verifier = str_repeat('b', 32);
        $raw      = $secretID . '$' . bin2hex($verifier);

        $request = RetrievalRequest::fromString($raw);

        $this->assertSame($secretID, $request->ID());
        $this->assertSame($verifier, $request->verifier());
    }

    // -------------------------------------------------------------------------
    // fromString() — error paths
    // -------------------------------------------------------------------------

    public function testFromStringThrowsOnTooFewSegments(): void
    {
        $this->expectException(\Exception::class);
        RetrievalRequest::fromString('onlyone');
    }

    public function testFromStringThrowsOnTooManySegments(): void
    {
        $secretID = 'abc123def456';
        $verifier = bin2hex(str_repeat('b', 32));

        $this->expectException(\Exception::class);
        RetrievalRequest::fromString("{$secretID}\${$verifier}\$extra");
    }

    public function testFromStringThrowsOnShortSecretID(): void
    {
        $verifier = bin2hex(str_repeat('b', 32));

        $this->expectException(\Exception::class);
        RetrievalRequest::fromString("short\${$verifier}");
    }

    public function testFromStringThrowsOnLongSecretID(): void
    {
        $verifier = bin2hex(str_repeat('b', 32));

        $this->expectException(\Exception::class);
        RetrievalRequest::fromString("abc123def456extra\${$verifier}");
    }

    public function testFromStringThrowsOnInvalidVerifierLength(): void
    {
        $secretID = 'abc123def456';
        $verifier = bin2hex(str_repeat('b', 16)); // 16 bytes instead of 32

        $this->expectException(\Exception::class);
        RetrievalRequest::fromString("{$secretID}\${$verifier}");
    }

    public function testFromStringVerifyPasswordWorksAfterDeserialization(): void
    {
        $secretID = 'abc123def456';
        $verifier = str_repeat('c', 32);
        $hash     = password_hash($verifier, PASSWORD_DEFAULT);
        $raw      = $secretID . '$' . bin2hex($verifier);

        $request = RetrievalRequest::fromString($raw);

        $this->assertTrue($request->verify_password($hash));
    }
}
