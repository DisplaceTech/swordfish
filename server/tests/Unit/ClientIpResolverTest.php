<?php

declare(strict_types=1);

namespace Swordfish\Server\Tests;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Socket\SocketAddress;
use League\Uri\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swordfish\Server\ClientIpResolver;

class ClientIpResolverTest extends TestCase
{
    private function makeRequest(string $peer, ?string $cfConnectingIp = null): Request
    {
        $client = $this->createStub(Client::class);
        $client->method('getRemoteAddress')->willReturn(new SocketAddress($peer, 45702));

        $headers = $cfConnectingIp === null ? [] : ['CF-Connecting-IP' => $cfConnectingIp];
        return new Request($client, 'GET', Http::new('http://localhost/'), $headers);
    }

    public function testTrustedPeerWithHeaderUsesHeader(): void
    {
        $resolver = new ClientIpResolver(['10.42.0.0/16']);
        $request  = $this->makeRequest('10.42.0.171', '203.0.113.7');

        $this->assertSame('203.0.113.7', $resolver->resolve($request));
        $this->assertSame(
            ['ip' => '203.0.113.7', 'source' => 'header', 'peer' => '10.42.0.171'],
            $resolver->resolveWithSource($request)
        );
    }

    public function testUntrustedPeerWithHeaderUsesPeer(): void
    {
        $resolver = new ClientIpResolver(['10.42.0.0/16']);
        $request  = $this->makeRequest('198.51.100.9', '203.0.113.7');

        $this->assertSame('198.51.100.9', $resolver->resolve($request));
        $this->assertSame('peer', $resolver->resolveWithSource($request)['source']);
    }

    public function testPeerJustOutsideTrustedRangeUsesPeer(): void
    {
        $resolver = new ClientIpResolver(['10.42.0.0/16']);

        $this->assertSame('10.43.0.5', $resolver->resolve($this->makeRequest('10.43.0.5', '203.0.113.7')));
    }

    public function testTrustedPeerWithoutHeaderUsesPeer(): void
    {
        $resolver = new ClientIpResolver(['10.42.0.0/16']);

        $this->assertSame('10.42.0.171', $resolver->resolve($this->makeRequest('10.42.0.171')));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidHeaderProvider(): array
    {
        return [
            'empty'          => [''],
            'garbage'        => ['not-an-ip'],
            'list'           => ['203.0.113.7, 10.0.0.1'],
            'with port'      => ['203.0.113.7:1234'],
            'out of range'   => ['999.1.1.1'],
            'cidr'           => ['203.0.113.0/24'],
        ];
    }

    #[DataProvider('invalidHeaderProvider')]
    public function testTrustedPeerWithInvalidHeaderUsesPeer(string $header): void
    {
        $resolver = new ClientIpResolver(['10.42.0.0/16']);
        $request  = $this->makeRequest('10.42.0.171', $header);

        $this->assertSame('10.42.0.171', $resolver->resolve($request));
        $this->assertSame('peer', $resolver->resolveWithSource($request)['source']);
    }

    public function testIpv6HeaderFromTrustedPeer(): void
    {
        $resolver = new ClientIpResolver(['10.42.0.0/16']);

        $this->assertSame('2001:db8::1', $resolver->resolve($this->makeRequest('10.42.1.2', '2001:db8::1')));
    }

    public function testIpv6TrustedPeer(): void
    {
        $resolver = new ClientIpResolver(['fd00:42::/56']);

        $this->assertSame('203.0.113.7', $resolver->resolve($this->makeRequest('fd00:42::abcd', '203.0.113.7')));
        $this->assertSame('fd00:43::1', $resolver->resolve($this->makeRequest('fd00:43::1', '203.0.113.7')));
    }

    public function testIpv6PeerDoesNotMatchIpv4Cidr(): void
    {
        $resolver = new ClientIpResolver(['10.42.0.0/16']);

        $this->assertSame('2001:db8::5', $resolver->resolve($this->makeRequest('2001:db8::5', '203.0.113.7')));
    }

    public function testIpv4MappedIpv6PeerMatchesIpv4Cidr(): void
    {
        $resolver = new ClientIpResolver(['10.42.0.0/16']);

        $this->assertSame('203.0.113.7', $resolver->resolve($this->makeRequest('::ffff:10.42.0.171', '203.0.113.7')));
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function cidrProvider(): array
    {
        return [
            'v4 /16 inside'        => ['10.42.0.0/16', '10.42.255.255', true],
            'v4 /16 outside'       => ['10.42.0.0/16', '10.41.255.255', false],
            'v4 /20 inside'        => ['172.16.0.0/20', '172.16.15.1', true],
            'v4 /20 outside'       => ['172.16.0.0/20', '172.16.16.1', false],
            'v4 /32 exact'         => ['192.0.2.1/32', '192.0.2.1', true],
            'v4 /32 other'         => ['192.0.2.1/32', '192.0.2.2', false],
            'v4 bare host'         => ['192.0.2.1', '192.0.2.1', true],
            'v4 /0 matches all v4' => ['0.0.0.0/0', '8.8.8.8', true],
            'v6 /64 inside'        => ['2001:db8:1:2::/64', '2001:db8:1:2:ffff::1', true],
            'v6 /64 outside'       => ['2001:db8:1:2::/64', '2001:db8:1:3::1', false],
            'v6 /125 inside'       => ['2001:db8::/125', '2001:db8::7', true],
            'v6 /125 outside'      => ['2001:db8::/125', '2001:db8::8', false],
            'v6 cidr vs v4 addr'   => ['::/0', '10.0.0.1', false],
            'invalid prefix'       => ['10.0.0.0/33', '10.0.0.1', false],
            'invalid network'      => ['nope/8', '10.0.0.1', false],
            'invalid address'      => ['10.0.0.0/8', 'nope', false],
        ];
    }

    #[DataProvider('cidrProvider')]
    public function testIsTrustedProxy(string $cidr, string $ip, bool $expected): void
    {
        $this->assertSame($expected, (new ClientIpResolver([$cidr]))->isTrustedProxy($ip));
    }

    public function testMultipleCidrsWithWhitespaceAndInvalidEntries(): void
    {
        $resolver = new ClientIpResolver([' 10.42.0.0/16 ', 'bogus', '', ' fd00::/8']);

        $this->assertTrue($resolver->isTrustedProxy('10.42.3.4'));
        $this->assertTrue($resolver->isTrustedProxy('fd12::1'));
        $this->assertFalse($resolver->isTrustedProxy('192.168.1.1'));
    }

    public function testFromEnvironmentDefaultsToPodCidr(): void
    {
        putenv('TRUSTED_PROXY_CIDRS');
        $resolver = ClientIpResolver::fromEnvironment();

        $this->assertTrue($resolver->isTrustedProxy('10.42.0.171'));
        $this->assertFalse($resolver->isTrustedProxy('10.43.0.1'));
    }

    public function testFromEnvironmentEmptyFallsBackToDefault(): void
    {
        putenv('TRUSTED_PROXY_CIDRS=');
        try {
            $this->assertTrue(ClientIpResolver::fromEnvironment()->isTrustedProxy('10.42.0.171'));
        } finally {
            putenv('TRUSTED_PROXY_CIDRS');
        }
    }

    public function testFromEnvironmentReadsCommaSeparatedList(): void
    {
        putenv('TRUSTED_PROXY_CIDRS=192.168.0.0/24,fd00::/8');
        try {
            $resolver = ClientIpResolver::fromEnvironment();
            $this->assertTrue($resolver->isTrustedProxy('192.168.0.10'));
            $this->assertTrue($resolver->isTrustedProxy('fd00::1'));
            $this->assertFalse($resolver->isTrustedProxy('10.42.0.171'));
        } finally {
            putenv('TRUSTED_PROXY_CIDRS');
        }
    }
}
