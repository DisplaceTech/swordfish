<?php

namespace Swordfish\Server\Tests;

use PHPUnit\Framework\TestCase;
use Swordfish\Server\CreateRequest;

class CreateRequestTest extends TestCase
{
    private string $salt;
    private string $verifier;
    private string $secret;

    protected function setUp(): void
    {
        $this->salt     = random_bytes(16);
        $this->verifier = random_bytes(32);
        $this->secret   = 'my-secret-value';
    }

    public function testVerifierReturnsPasswordHash(): void
    {
        $request = new CreateRequest($this->salt, $this->verifier, $this->secret);

        $hash = $request->verifier();

        $this->assertTrue(password_verify(hash('sha256', $this->verifier), $hash));
    }

    public function testSecretReturnsBinHexEncodedSaltAndSecret(): void
    {
        $request = new CreateRequest($this->salt, $this->verifier, $this->secret);

        $encoded = $request->secret();

        $this->assertSame(bin2hex($this->salt . $this->secret), $encoded);
    }

    public function testFromStringRoundTrip(): void
    {
        $salt     = random_bytes(16);
        $verifier = random_bytes(32);
        $secret   = random_bytes(20);

        $serialized = bin2hex($salt) . '$' . bin2hex($verifier) . '$' . bin2hex($secret);
        $request    = CreateRequest::fromString($serialized);

        $this->assertTrue(password_verify(hash('sha256', $verifier), $request->verifier()));
        $this->assertSame(bin2hex($salt . $secret), $request->secret());
    }

    public function testFromStringThrowsOnWrongPartCount(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid serialized request!');

        CreateRequest::fromString('onlyone');
    }

    public function testFromStringThrowsOnInvalidSaltLength(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid salt length!');

        $shortSalt = bin2hex(random_bytes(8));
        $verifier  = bin2hex(random_bytes(32));
        $secret    = bin2hex(random_bytes(10));

        CreateRequest::fromString("{$shortSalt}\${$verifier}\${$secret}");
    }

    public function testFromStringThrowsOnInvalidVerifierLength(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid verifier length!');

        $salt     = bin2hex(random_bytes(16));
        $verifier = bin2hex(random_bytes(16));
        $secret   = bin2hex(random_bytes(10));

        CreateRequest::fromString("{$salt}\${$verifier}\${$secret}");
    }
}
