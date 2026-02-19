<?php

namespace Swordfish\Server\Tests;

use PHPUnit\Framework\TestCase;
use Swordfish\Server\RetrievalRequest;

class RetrievalRequestTest extends TestCase
{
    public function testIdReturnsSecretId(): void
    {
        $secretID = 'abc123def456';
        $verifier = random_bytes(32);

        $request = new RetrievalRequest($secretID, $verifier);

        $this->assertSame($secretID, $request->ID());
    }

    public function testVerifyPasswordReturnsTrueForCorrectHash(): void
    {
        $verifier = random_bytes(32);
        $hash     = password_hash($verifier, PASSWORD_DEFAULT);

        $request = new RetrievalRequest('abc123def456', $verifier);

        $this->assertTrue($request->verify_password($hash));
    }

    public function testVerifyPasswordReturnsFalseForWrongHash(): void
    {
        $verifier  = random_bytes(32);
        $wrongHash = password_hash(random_bytes(32), PASSWORD_DEFAULT);

        $request = new RetrievalRequest('abc123def456', $verifier);

        $this->assertFalse($request->verify_password($wrongHash));
    }

    public function testFromStringRoundTrip(): void
    {
        $secretID = 'abc123def456';
        $verifier = random_bytes(32);

        $serialized = $secretID . '$' . bin2hex($verifier);
        $request    = RetrievalRequest::fromString($serialized);

        $this->assertSame($secretID, $request->ID());
    }

    public function testFromStringThrowsOnWrongPartCount(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid serialized request!');

        RetrievalRequest::fromString('noDollarSign');
    }

    public function testFromStringThrowsOnInvalidSecretIdLength(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid secret ID length!');

        $shortID  = 'short';
        $verifier = bin2hex(random_bytes(32));

        RetrievalRequest::fromString("{$shortID}\${$verifier}");
    }

    public function testFromStringThrowsOnInvalidVerifierLength(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid verifier length!');

        $secretID = 'abc123def456';
        $verifier = bin2hex(random_bytes(16));

        RetrievalRequest::fromString("{$secretID}\${$verifier}");
    }
}
