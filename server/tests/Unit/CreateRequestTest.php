<?php

declare(strict_types=1);

namespace Swordfish\Server\Tests;

use PHPUnit\Framework\TestCase;
use Swordfish\Server\CreateRequest;

class CreateRequestTest extends TestCase
{
    private string $salt;
    private string $verifier;
    private string $secret;
    private string $hexSalt;
    private string $hexVerifier;
    private string $hexSecret;

    protected function setUp(): void
    {
        $this->salt     = str_repeat('a', 16);
        $this->verifier = str_repeat('b', 32);
        $this->secret   = 'my-secret-payload';

        $this->hexSalt     = bin2hex($this->salt);
        $this->hexVerifier = bin2hex($this->verifier);
        $this->hexSecret   = bin2hex($this->secret);
    }

    // -------------------------------------------------------------------------
    // fromString() — default TTL
    // -------------------------------------------------------------------------

    public function testFromStringUsesDefaultTtlWhenOmitted(): void
    {
        $raw     = implode('$', [$this->hexSalt, $this->hexVerifier, $this->hexSecret]);
        $request = CreateRequest::fromString($raw);

        $this->assertSame(CreateRequest::DEFAULT_TTL, $request->ttl());
    }

    // -------------------------------------------------------------------------
    // fromString() — valid TTL values
    // -------------------------------------------------------------------------

    /**
     * @dataProvider allowedTtlProvider
     */
    public function testFromStringAcceptsAllowedTtlValues(int $ttl): void
    {
        $raw     = implode('$', [$this->hexSalt, $this->hexVerifier, $this->hexSecret, (string) $ttl]);
        $request = CreateRequest::fromString($raw);

        $this->assertSame($ttl, $request->ttl());
    }

    public static function allowedTtlProvider(): array
    {
        return array_map(fn(int $ttl) => [$ttl], CreateRequest::ALLOWED_TTLS);
    }

    // -------------------------------------------------------------------------
    // fromString() — invalid TTL values
    // -------------------------------------------------------------------------

    /**
     * @dataProvider disallowedTtlProvider
     */
    public function testFromStringRejectsDisallowedTtlValues(int $ttl): void
    {
        $raw = implode('$', [$this->hexSalt, $this->hexVerifier, $this->hexSecret, (string) $ttl]);

        $this->expectException(\InvalidArgumentException::class);
        CreateRequest::fromString($raw);
    }

    public static function disallowedTtlProvider(): array
    {
        return [
            'zero'          => [0],
            'negative'      => [-1],
            'one second'    => [1],
            'arbitrary'     => [1000],
            'just under 1h' => [3599],
            'between 1h-6h' => [7200],
            'over max'      => [604801],
        ];
    }

    // -------------------------------------------------------------------------
    // fromString() — structural validation
    // -------------------------------------------------------------------------

    public function testFromStringThrowsOnTooFewParts(): void
    {
        $this->expectException(\Exception::class);
        CreateRequest::fromString($this->hexSalt . '$' . $this->hexVerifier);
    }

    public function testFromStringThrowsOnTooManyParts(): void
    {
        $raw = implode('$', [$this->hexSalt, $this->hexVerifier, $this->hexSecret, '86400', 'extra']);

        $this->expectException(\Exception::class);
        CreateRequest::fromString($raw);
    }

    public function testFromStringThrowsOnInvalidSaltLength(): void
    {
        $shortSalt = bin2hex(str_repeat('x', 8)); // 8 bytes instead of 16

        $this->expectException(\Exception::class);
        CreateRequest::fromString(implode('$', [$shortSalt, $this->hexVerifier, $this->hexSecret]));
    }

    public function testFromStringThrowsOnInvalidVerifierLength(): void
    {
        $shortVerifier = bin2hex(str_repeat('y', 16)); // 16 bytes instead of 32

        $this->expectException(\Exception::class);
        CreateRequest::fromString(implode('$', [$this->hexSalt, $shortVerifier, $this->hexSecret]));
    }
}
