<?php

declare(strict_types=1);

namespace Swordfish\Server\Tests;

use PHPUnit\Framework\TestCase;
use Swordfish\Server\CreateRequest;

class CreateRequestTest extends TestCase
{
    // -------------------------------------------------------------------------
    // max_views default and getter
    // -------------------------------------------------------------------------

    public function testDefaultMaxViewsIsZero(): void
    {
        $request = new CreateRequest(
            str_repeat('a', 16),
            str_repeat('b', 32),
            'secret'
        );

        $this->assertSame(0, $request->maxViews());
    }

    public function testConstructorAcceptsMaxViews(): void
    {
        foreach ([0, 1, 3, 5, 10] as $maxViews) {
            $request = new CreateRequest(
                str_repeat('a', 16),
                str_repeat('b', 32),
                'secret',
                CreateRequest::DEFAULT_TTL,
                $maxViews
            );
            $this->assertSame($maxViews, $request->maxViews());
        }
    }

    // -------------------------------------------------------------------------
    // fromString() — max_views parsing
    // -------------------------------------------------------------------------

    public function testFromStringWithoutMaxViewsDefaultsToZero(): void
    {
        $salt     = bin2hex(str_repeat('a', 16));
        $verifier = bin2hex(str_repeat('b', 32));
        $secret   = bin2hex('mysecret');

        $request = CreateRequest::fromString("{$salt}\${$verifier}\${$secret}");

        $this->assertSame(0, $request->maxViews());
    }

    public function testFromStringParsesMaxViewsSegment(): void
    {
        $salt     = bin2hex(str_repeat('a', 16));
        $verifier = bin2hex(str_repeat('b', 32));
        $secret   = bin2hex('mysecret');
        $ttl      = 3600;

        foreach ([1, 3, 5, 10] as $maxViews) {
            $request = CreateRequest::fromString("{$salt}\${$verifier}\${$secret}\${$ttl}\${$maxViews}");
            $this->assertSame($maxViews, $request->maxViews());
        }
    }

    public function testFromStringAcceptsZeroMaxViewsForUnlimited(): void
    {
        $salt     = bin2hex(str_repeat('a', 16));
        $verifier = bin2hex(str_repeat('b', 32));
        $secret   = bin2hex('mysecret');

        $request = CreateRequest::fromString("{$salt}\${$verifier}\${$secret}\$3600\$0");

        $this->assertSame(0, $request->maxViews());
    }

    /**
     * @dataProvider invalidMaxViewsProvider
     */
    public function testFromStringRejectsInvalidMaxViews(int $invalid): void
    {
        $salt     = bin2hex(str_repeat('a', 16));
        $verifier = bin2hex(str_repeat('b', 32));
        $secret   = bin2hex('mysecret');

        $this->expectException(\InvalidArgumentException::class);
        CreateRequest::fromString("{$salt}\${$verifier}\${$secret}\$3600\${$invalid}");
    }

    public static function invalidMaxViewsProvider(): array
    {
        return [[2], [4], [6], [7], [11], [100]];
    }

    public function testFromStringRejectsTooManySegments(): void
    {
        $salt     = bin2hex(str_repeat('a', 16));
        $verifier = bin2hex(str_repeat('b', 32));
        $secret   = bin2hex('mysecret');

        $this->expectException(\Exception::class);
        CreateRequest::fromString("{$salt}\${$verifier}\${$secret}\$3600\$5\$extra");
    }
}
