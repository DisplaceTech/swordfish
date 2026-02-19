<?php

declare(strict_types=1);

namespace Swordfish\Server\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;
use Predis\Connection\ConnectionException;
use Swordfish\Server\CreateRequest;
use Swordfish\Server\InvalidVerifierException;
use Swordfish\Server\SecretNotFoundException;
use Swordfish\Server\SecretService;

/**
 * Integration tests for SecretService against a real Redis instance.
 *
 * Requires REDIS_HOST and REDIS_PORT environment variables (defaults: 127.0.0.1 / 6379).
 * Tests are skipped automatically when Redis is unreachable.
 */
class SecretServiceIntegrationTest extends TestCase
{
    private static RedisClient $redis;
    private static bool $redisAvailable = false;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('REDIS_PORT') ?: 6379);

        self::$redis = new RedisClient(['scheme' => 'tcp', 'host' => $host, 'port' => $port]);

        try {
            self::$redis->ping();
            self::$redisAvailable = true;
        } catch (ConnectionException) {
            self::$redisAvailable = false;
        }
    }

    protected function setUp(): void
    {
        if (!self::$redisAvailable) {
            $this->markTestSkipped('Redis is not available.');
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeService(): SecretService
    {
        return new SecretService(self::$redis);
    }

    /**
     * Build a CreateRequest with a short TTL (bypassing API validation) for expiry tests.
     */
    private function makeRequest(
        string $verifier,
        string $secret,
        int $ttl = 3600,
        int $maxViews = 0
    ): CreateRequest {
        return new CreateRequest(
            str_repeat('s', 16),   // 16-byte salt
            $verifier,
            $secret,
            $ttl,
            $maxViews
        );
    }

    // -------------------------------------------------------------------------
    // create() + retrieve() — legacy v1 round-trip
    // -------------------------------------------------------------------------

    public function testLegacyCreateAndRetrieveRoundTrip(): void
    {
        $verifier = str_repeat('v', 32);
        $secret   = 'my-legacy-secret-payload';

        $service  = $this->makeService();
        $request  = $this->makeRequest($verifier, $secret);
        $id       = $service->create($request);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $id);

        $retrieved = $service->retrieve($id, $verifier);
        $this->assertNotEmpty($retrieved);
    }

    public function testLegacyRetrieveWithWrongVerifierThrowsInvalidVerifier(): void
    {
        $verifier = str_repeat('v', 32);
        $service  = $this->makeService();
        $id       = $service->create($this->makeRequest($verifier, 'payload'));

        $this->expectException(InvalidVerifierException::class);
        $service->retrieve($id, str_repeat('x', 32));
    }

    public function testLegacyRetrieveNonExistentSecretThrowsSecretNotFound(): void
    {
        $service = $this->makeService();

        $this->expectException(SecretNotFoundException::class);
        $service->retrieve('000000000000', str_repeat('v', 32));
    }

    // -------------------------------------------------------------------------
    // createJson() + retrieveJson() — JSON API round-trip
    // -------------------------------------------------------------------------

    public function testJsonCreateAndRetrieveRoundTrip(): void
    {
        $verifier = str_repeat('v', 32);
        $secret   = 'my-json-secret-payload';

        $service = $this->makeService();
        $id      = $service->createJson($this->makeRequest($verifier, $secret));

        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $id);

        $result = $service->retrieveJson($id, $verifier);

        $this->assertArrayHasKey('encrypted_secret', $result);
        $this->assertArrayHasKey('views_remaining', $result);
        $this->assertArrayHasKey('expires_at', $result);
        $this->assertNotEmpty($result['encrypted_secret']);
        $this->assertNull($result['views_remaining']);
        $this->assertGreaterThan(time(), $result['expires_at']);
    }

    public function testJsonRetrieveWithWrongVerifierThrowsInvalidVerifier(): void
    {
        $verifier = str_repeat('v', 32);
        $service  = $this->makeService();
        $id       = $service->createJson($this->makeRequest($verifier, 'payload'));

        $this->expectException(InvalidVerifierException::class);
        $service->retrieveJson($id, str_repeat('x', 32));
    }

    public function testJsonRetrieveNonExistentSecretThrowsSecretNotFound(): void
    {
        $service = $this->makeService();

        $this->expectException(SecretNotFoundException::class);
        $service->retrieveJson('000000000000', str_repeat('v', 32));
    }

    // -------------------------------------------------------------------------
    // View limits
    // -------------------------------------------------------------------------

    public function testViewLimitOfOneExhaustsAfterSingleRetrieval(): void
    {
        $verifier = str_repeat('v', 32);
        $service  = $this->makeService();
        $id       = $service->createJson($this->makeRequest($verifier, 'payload', 3600, 1));

        $result = $service->retrieveJson($id, $verifier);
        $this->assertSame(0, $result['views_remaining']);

        $this->expectException(SecretNotFoundException::class);
        $service->retrieveJson($id, $verifier);
    }

    public function testViewLimitOfThreeDecrementsCorrectly(): void
    {
        $verifier = str_repeat('v', 32);
        $service  = $this->makeService();
        $id       = $service->createJson($this->makeRequest($verifier, 'payload', 3600, 3));

        $first  = $service->retrieveJson($id, $verifier);
        $second = $service->retrieveJson($id, $verifier);
        $third  = $service->retrieveJson($id, $verifier);

        $this->assertSame(2, $first['views_remaining']);
        $this->assertSame(1, $second['views_remaining']);
        $this->assertSame(0, $third['views_remaining']);

        $this->expectException(SecretNotFoundException::class);
        $service->retrieveJson($id, $verifier);
    }

    public function testUnlimitedViewsSecretCanBeRetrievedMultipleTimes(): void
    {
        $verifier = str_repeat('v', 32);
        $service  = $this->makeService();
        $id       = $service->createJson($this->makeRequest($verifier, 'payload', 3600, 0));

        for ($i = 0; $i < 5; $i++) {
            $result = $service->retrieveJson($id, $verifier);
            $this->assertNull($result['views_remaining']);
            $this->assertNotEmpty($result['encrypted_secret']);
        }
    }

    // -------------------------------------------------------------------------
    // TTL expiration
    // -------------------------------------------------------------------------

    public function testLegacySecretExpiresAfterTtl(): void
    {
        $verifier = str_repeat('v', 32);
        $service  = $this->makeService();
        $id       = $service->create($this->makeRequest($verifier, 'payload', 1));

        sleep(2);

        $this->expectException(SecretNotFoundException::class);
        $service->retrieve($id, $verifier);
    }

    public function testJsonSecretExpiresAfterTtl(): void
    {
        $verifier = str_repeat('v', 32);
        $service  = $this->makeService();
        $id       = $service->createJson($this->makeRequest($verifier, 'payload', 1));

        sleep(2);

        $this->expectException(SecretNotFoundException::class);
        $service->retrieveJson($id, $verifier);
    }

    // -------------------------------------------------------------------------
    // getInfo()
    // -------------------------------------------------------------------------

    public function testGetInfoReturnsCorrectMetadataForLimitedSecret(): void
    {
        $verifier = str_repeat('v', 32);
        $service  = $this->makeService();
        $id       = $service->createJson($this->makeRequest($verifier, 'payload', 3600, 5));

        $info = $service->getInfo($id);

        $this->assertSame(5, $info['views_remaining']);
        $this->assertGreaterThan(time(), $info['expires_at']);
    }

    public function testGetInfoReturnsNullViewsRemainingForUnlimitedSecret(): void
    {
        $verifier = str_repeat('v', 32);
        $service  = $this->makeService();
        $id       = $service->createJson($this->makeRequest($verifier, 'payload', 3600, 0));

        $info = $service->getInfo($id);

        $this->assertNull($info['views_remaining']);
        $this->assertGreaterThan(time(), $info['expires_at']);
    }

    public function testGetInfoDoesNotConsumeAView(): void
    {
        $verifier = str_repeat('v', 32);
        $service  = $this->makeService();
        $id       = $service->createJson($this->makeRequest($verifier, 'payload', 3600, 1));

        $service->getInfo($id);
        $service->getInfo($id);

        $result = $service->retrieveJson($id, $verifier);
        $this->assertSame(0, $result['views_remaining']);
    }

    public function testGetInfoThrowsSecretNotFoundForNonExistentSecret(): void
    {
        $service = $this->makeService();

        $this->expectException(SecretNotFoundException::class);
        $service->getInfo('000000000000');
    }
}
