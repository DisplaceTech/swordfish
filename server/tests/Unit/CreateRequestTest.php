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

    public function testFromStringRejectsDisallowedTtlValue(): void
    {
        $salt     = bin2hex(str_repeat('a', 16));
        $verifier = bin2hex(str_repeat('b', 32));
        $secret   = bin2hex('mysecret');

        $this->expectException(\InvalidArgumentException::class);
        CreateRequest::fromString("{$salt}\${$verifier}\${$secret}\$7200");
    }

    /**
     * @dataProvider allowedTtlProvider
     */
    public function testFromStringAcceptsAllowedTtlValues(int $ttl): void
    {
        $salt     = bin2hex(str_repeat('a', 16));
        $verifier = bin2hex(str_repeat('b', 32));
        $secret   = bin2hex('mysecret');

        $request = CreateRequest::fromString("{$salt}\${$verifier}\${$secret}\${$ttl}");
        $this->assertSame($ttl, $request->ttl());
    }

    // -------------------------------------------------------------------------
    // fromJson()
    // -------------------------------------------------------------------------

    private function validEncryptedSecret(): string
    {
        return bin2hex(str_repeat('a', 16)) . '$' . bin2hex(str_repeat('b', 32)) . '$' . bin2hex('mysecret');
    }

    public function testFromJsonParsesValidRequest(): void
    {
        $json = json_encode([
            'encrypted_secret' => $this->validEncryptedSecret(),
            'ttl'              => 3600,
            'max_views'        => 5,
        ]);

        $request = CreateRequest::fromJson($json);

        $this->assertSame(3600, $request->ttl());
        $this->assertSame(5, $request->maxViews());
    }

    public function testFromJsonDefaultsTtlAndMaxViews(): void
    {
        $json = json_encode(['encrypted_secret' => $this->validEncryptedSecret()]);

        $request = CreateRequest::fromJson($json);

        $this->assertSame(CreateRequest::DEFAULT_TTL, $request->ttl());
        $this->assertSame(0, $request->maxViews());
    }

    public function testFromJsonThrowsOnMissingEncryptedSecret(): void
    {
        $this->expectException(\Exception::class);
        CreateRequest::fromJson(json_encode(['ttl' => 3600]));
    }

    public function testFromJsonThrowsOnInvalidJson(): void
    {
        $this->expectException(\Exception::class);
        CreateRequest::fromJson('not-json');
    }

    /**
     * @dataProvider allowedTtlProvider
     */
    public function testFromJsonAcceptsAllowedTtlValues(int $ttl): void
    {
        $json = json_encode([
            'encrypted_secret' => $this->validEncryptedSecret(),
            'ttl'              => $ttl,
        ]);

        $request = CreateRequest::fromJson($json);
        $this->assertSame($ttl, $request->ttl());
    }

    public static function allowedTtlProvider(): array
    {
        return [[3600], [21600], [86400], [259200], [604800]];
    }

    /**
     * @dataProvider disallowedTtlProvider
     */
    public function testFromJsonThrowsOnDisallowedTtlValue(int $ttl): void
    {
        $json = json_encode([
            'encrypted_secret' => $this->validEncryptedSecret(),
            'ttl'              => $ttl,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        CreateRequest::fromJson($json);
    }

    public static function disallowedTtlProvider(): array
    {
        return [[1], [1800], [7200], [43200], [9999999]];
    }

    public function testFromJsonThrowsOnInvalidTtl(): void
    {
        $json = json_encode([
            'encrypted_secret' => $this->validEncryptedSecret(),
            'ttl'              => 9999999,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        CreateRequest::fromJson($json);
    }

    public function testFromJsonThrowsOnInvalidMaxViews(): void
    {
        $json = json_encode([
            'encrypted_secret' => $this->validEncryptedSecret(),
            'max_views'        => 7,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        CreateRequest::fromJson($json);
    }

    public function testFromJsonThrowsOnWrongEncryptedSecretSegmentCount(): void
    {
        $json = json_encode(['encrypted_secret' => 'onlyone']);

        $this->expectException(\Exception::class);
        CreateRequest::fromJson($json);
    }
}
