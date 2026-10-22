<?php

namespace Swordfish\Server;

use Amp\Http\Server\Request;

/**
 * Resolves the real client IP for a request.
 *
 * In production, Cloudflare Tunnel (cloudflared) connects directly to the
 * ClusterIP Service, so the socket peer is always a cloudflared pod. The real
 * client address arrives in the CF-Connecting-IP header. That header is only
 * honoured when the socket peer falls inside one of the trusted proxy CIDRs;
 * otherwise anyone could spoof it.
 *
 * Trusted CIDRs come from the TRUSTED_PROXY_CIDRS environment variable
 * (comma-separated, IPv4 and/or IPv6), defaulting to the k3s pod CIDR.
 */
class ClientIpResolver
{
    public const HEADER = 'cf-connecting-ip';
    public const DEFAULT_TRUSTED_PROXY_CIDRS = '10.42.0.0/16';

    public const SOURCE_HEADER = 'header';
    public const SOURCE_PEER = 'peer';

    /** @var list<array{0: string, 1: int}> Packed network address and prefix length */
    private array $trusted = [];

    /**
     * @param string[] $trustedCidrs CIDR blocks (e.g. "10.42.0.0/16", "fd00::/8"). A bare
     *                               address is treated as a single host. Invalid entries are ignored.
     */
    public function __construct(array $trustedCidrs)
    {
        foreach ($trustedCidrs as $cidr) {
            $parsed = self::parseCidr($cidr);
            if ($parsed !== null) {
                $this->trusted[] = $parsed;
            }
        }
    }

    /**
     * Build a resolver from TRUSTED_PROXY_CIDRS, falling back to DEFAULT_TRUSTED_PROXY_CIDRS
     * when the variable is unset or empty.
     */
    public static function fromEnvironment(): self
    {
        $value = getenv('TRUSTED_PROXY_CIDRS') ?: self::DEFAULT_TRUSTED_PROXY_CIDRS;
        return new self(explode(',', $value));
    }

    /**
     * Resolve the client IP for a request.
     */
    public function resolve(Request $request): string
    {
        return $this->resolveWithSource($request)['ip'];
    }

    /**
     * Resolve the client IP along with where it came from.
     *
     * @return array{ip: string, source: string, peer: string}
     */
    public function resolveWithSource(Request $request): array
    {
        $peer = $request->getClient()->getRemoteAddress()->getHost();

        if ($this->isTrustedProxy($peer)) {
            $header = trim((string) $request->getHeader(self::HEADER));
            if ($header !== '' && filter_var($header, FILTER_VALIDATE_IP) !== false) {
                return ['ip' => $header, 'source' => self::SOURCE_HEADER, 'peer' => $peer];
            }
        }

        return ['ip' => $peer, 'source' => self::SOURCE_PEER, 'peer' => $peer];
    }

    /**
     * Whether the given address falls inside any trusted proxy CIDR.
     */
    public function isTrustedProxy(string $ip): bool
    {
        $packed = self::pack($ip);
        if ($packed === null) {
            return false;
        }

        foreach ($this->trusted as [$network, $prefix]) {
            if (strlen($network) === strlen($packed) && self::prefixMatches($packed, $network, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    private static function parseCidr(string $cidr): ?array
    {
        $cidr = trim($cidr);
        if ($cidr === '') {
            return null;
        }

        $parts   = explode('/', $cidr, 2);
        $network = self::pack($parts[0]);
        if ($network === null) {
            return null;
        }

        $maxBits = strlen($network) * 8;
        if (!isset($parts[1])) {
            return [$network, $maxBits];
        }

        if (!ctype_digit($parts[1]) || (int) $parts[1] > $maxBits) {
            return null;
        }

        return [$network, (int) $parts[1]];
    }

    /**
     * Pack an IP address into binary form. IPv4-mapped IPv6 addresses (::ffff:a.b.c.d),
     * which a dual-stack listener may report, are reduced to plain IPv4.
     */
    private static function pack(string $ip): ?string
    {
        $ip = trim($ip, " []");
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return substr($packed, 12);
        }

        return $packed;
    }

    private static function prefixMatches(string $address, string $network, int $prefix): bool
    {
        $fullBytes = intdiv($prefix, 8);
        if (strncmp($address, $network, $fullBytes) !== 0) {
            return false;
        }

        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($address[$fullBytes]) & $mask) === (ord($network[$fullBytes]) & $mask);
    }
}
