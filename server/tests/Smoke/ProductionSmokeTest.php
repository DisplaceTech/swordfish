<?php

declare(strict_types=1);

namespace Swordfish\Server\Tests\Smoke;

use PHPUnit\Framework\TestCase;

/**
 * Post-deployment production smoke tests for the Swordfish API.
 *
 * Exercises every acceptance criterion from DIS-923 against a live server:
 *   - Create secret via the JSON API (SPA/CLI compatible format)
 *   - Retrieve secret via the JSON API
 *   - View-limit enforcement (max_views=1 exhausts after one retrieval)
 *   - TTL display (expires_at is in the future and within the requested window)
 *   - GET /health returns {"status":"ok"}
 *   - GET /api/metrics returns Prometheus-format counters
 *   - v1 backward-compatibility redirects (POST /create → 308, POST /retrieve → 308)
 *   - Wrong-password retrieval is rejected
 *   - Non-existent secret retrieval is rejected
 *
 * Configure the target server via the SWORDFISH_URL environment variable
 * (default: http://localhost:8080). All tests are skipped automatically when
 * the server is unreachable.
 */
class ProductionSmokeTest extends TestCase
{
    private static string $baseUrl;
    private static bool $serverAvailable = false;

    /**
     * Shared pepper used by both the SPA (crypto.ts) and the CLI (CreateSecretCommand).
     * Must match the value baked into the client implementations.
     */
    private const PEPPER = 'd783eff0523c8fa7336bc768c5950f63';

    // -------------------------------------------------------------------------
    // Suite setup
    // -------------------------------------------------------------------------

    public static function setUpBeforeClass(): void
    {
        self::$baseUrl = rtrim((string) (getenv('SWORDFISH_URL') ?: 'http://localhost:8080'), '/');

        $ch = curl_init(self::$baseUrl . '/health');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        self::$serverAvailable = ($body !== false && $httpCode === 200);
    }

    protected function setUp(): void
    {
        if (!self::$serverAvailable) {
            $this->markTestSkipped(
                sprintf('Swordfish server is not available at %s', self::$baseUrl)
            );
        }
    }

    // -------------------------------------------------------------------------
    // HTTP helpers
    // -------------------------------------------------------------------------

    /**
     * POST JSON to $path and return [httpCode, parsedBody].
     *
     * @return array{int, array<string, mixed>}
     */
    private function postJson(string $path, array $payload): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $body     = (string) curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$httpCode, json_decode($body, true) ?? []];
    }

    /**
     * GET $path and return [httpCode, rawBody, headers].
     *
     * @param array<string, string> $headers
     * @return array{int, string, string}
     */
    private function get(string $path, array $headers = []): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HEADER, true);
        if ($headers) {
            $formatted = [];
            foreach ($headers as $name => $value) {
                $formatted[] = "{$name}: {$value}";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $formatted);
        }
        $raw      = (string) curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $responseHeaders = substr($raw, 0, $headerSize);
        $body            = substr($raw, $headerSize);

        return [$httpCode, $body, $responseHeaders];
    }

    /**
     * POST to $path without following redirects; return [httpCode, locationHeader].
     */
    private function postNoFollow(string $path, array $payload): array
    {
        $ch = curl_init(self::$baseUrl . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HEADER, true);
        $raw      = (string) curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $responseHeaders = substr($raw, 0, $headerSize);
        preg_match('/^location:\s*(.+)$/im', $responseHeaders, $m);
        $location = isset($m[1]) ? trim($m[1]) : '';

        return [$httpCode, $location];
    }

    // -------------------------------------------------------------------------
    // Crypto helpers (mirrors CLI CreateSecretCommand / SPA crypto.ts)
    // -------------------------------------------------------------------------

    /**
     * Build the wire-format encrypted_secret string accepted by POST /api/create.
     *
     * Format: hex(salt) $ hex(verifier) $ hex(nonce || ciphertext)
     *
     * @return string
     */
    private function buildEncryptedSecret(string $plaintext, string $password): string
    {
        $salt     = random_bytes(16);
        $nonce    = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES);
        $key      = hash_pbkdf2('sha256', $password, $salt, 10000, 32, true);
        $cipher   = sodium_crypto_aead_aes256gcm_encrypt($plaintext, '', $nonce, $key);
        $verifier = hash_pbkdf2('sha256', $password, self::PEPPER, 10000);

        return bin2hex($salt) . '$' . $verifier . '$' . bin2hex($nonce . $cipher);
    }

    /**
     * Derive the verifier string sent with POST /api/retrieve.
     */
    private function buildVerifier(string $password): string
    {
        return hash_pbkdf2('sha256', $password, self::PEPPER, 10000);
    }

    /**
     * Decrypt the hex blob returned by POST /api/retrieve.
     *
     * Blob format: hex(salt || nonce || ciphertext)
     */
    private function decryptBlob(string $hexBlob, string $password): string
    {
        $raw        = hex2bin($hexBlob);
        $salt       = substr($raw, 0, 16);
        $nonce      = substr($raw, 16, SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES);
        $ciphertext = substr($raw, 16 + SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES);
        $key        = hash_pbkdf2('sha256', $password, $salt, 10000, 32, true);

        $plaintext = sodium_crypto_aead_aes256gcm_decrypt($ciphertext, '', $nonce, $key);
        $this->assertNotFalse($plaintext, 'AES-256-GCM decryption failed — wrong key or corrupted ciphertext');

        return (string) $plaintext;
    }

    // -------------------------------------------------------------------------
    // Health check
    // -------------------------------------------------------------------------

    public function testHealthEndpointReturns200WithOkStatus(): void
    {
        [$code, $body] = $this->get('/health');

        $this->assertSame(200, $code);
        $parsed = json_decode($body, true);
        $this->assertIsArray($parsed);
        $this->assertSame('ok', $parsed['status']);
    }

    // -------------------------------------------------------------------------
    // Metrics endpoint
    // -------------------------------------------------------------------------

    public function testMetricsEndpointIsAccessibleAndReturnsPrometheusFormat(): void
    {
        [$code, $body] = $this->get('/api/metrics');

        // When no METRICS_TOKEN is configured the endpoint is open; if a token
        // is required the test environment must set METRICS_TOKEN accordingly.
        $this->assertContains($code, [200, 401], 'Expected 200 (open) or 401 (token required)');

        if ($code === 200) {
            $this->assertStringContainsString('secrets_created_total', $body);
            $this->assertStringContainsString('secrets_retrieved_total', $body);
            $this->assertStringContainsString('bytes_stored_total', $body);
            $this->assertStringContainsString('bytes_retrieved_total', $body);
            $this->assertStringContainsString('# HELP', $body);
            $this->assertStringContainsString('# TYPE', $body);
        }
    }

    public function testMetricsEndpointAcceptsValidBearerToken(): void
    {
        $token = (string) getenv('METRICS_TOKEN');
        if ($token === '') {
            $this->markTestSkipped('METRICS_TOKEN not set; skipping token-auth metrics test');
        }

        [$code, $body] = $this->get('/api/metrics', ['Authorization' => 'Bearer ' . $token]);

        $this->assertSame(200, $code);
        $this->assertStringContainsString('secrets_created_total', $body);
    }

    // -------------------------------------------------------------------------
    // Secret creation
    // -------------------------------------------------------------------------

    public function testCreateSecretReturns201WithIdAndExpiresAt(): void
    {
        $before = time();
        [$code, $body] = $this->postJson('/api/create', [
            'encrypted_secret' => $this->buildEncryptedSecret('smoke-test-secret', 'smoke-pass'),
            'ttl'              => 3600,
            'max_views'        => 0,
        ]);

        $this->assertSame(201, $code);
        $this->assertArrayHasKey('id', $body);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $body['id']);
        $this->assertArrayHasKey('expires_at', $body);
        $this->assertGreaterThan($before, $body['expires_at']);
    }

    // -------------------------------------------------------------------------
    // Full create → retrieve round-trip (SPA / CLI compatible format)
    // -------------------------------------------------------------------------

    public function testCreateAndRetrieveRoundTrip(): void
    {
        $plaintext = 'Hello from the smoke test!';
        $password  = 'smoke-test-password';

        [$createCode, $createBody] = $this->postJson('/api/create', [
            'encrypted_secret' => $this->buildEncryptedSecret($plaintext, $password),
            'ttl'              => 3600,
            'max_views'        => 0,
        ]);

        $this->assertSame(201, $createCode, 'Create should return 201');
        $secretId = $createBody['id'];

        [$retrieveCode, $retrieveBody] = $this->postJson('/api/retrieve', [
            'id'       => $secretId,
            'verifier' => $this->buildVerifier($password),
        ]);

        $this->assertSame(200, $retrieveCode, 'Retrieve should return 200');
        $this->assertArrayHasKey('encrypted_secret', $retrieveBody);
        $this->assertArrayHasKey('views_remaining', $retrieveBody);
        $this->assertArrayHasKey('expires_at', $retrieveBody);

        $decrypted = $this->decryptBlob($retrieveBody['encrypted_secret'], $password);
        $this->assertSame($plaintext, $decrypted);
    }

    // -------------------------------------------------------------------------
    // TTL display
    // -------------------------------------------------------------------------

    public function testTtlIsReflectedInExpiresAt(): void
    {
        $ttl    = 3600;
        $before = time();

        [$code, $body] = $this->postJson('/api/create', [
            'encrypted_secret' => $this->buildEncryptedSecret('ttl-test', 'ttl-pass'),
            'ttl'              => $ttl,
            'max_views'        => 0,
        ]);

        $after = time();

        $this->assertSame(201, $code);
        $expiresAt = (int) $body['expires_at'];

        // expires_at must be within [before+ttl, after+ttl] (±1 s clock skew)
        $this->assertGreaterThanOrEqual($before + $ttl - 1, $expiresAt);
        $this->assertLessThanOrEqual($after + $ttl + 1, $expiresAt);
    }

    public function testExpiresAtIsReturnedOnRetrieval(): void
    {
        $password = 'ttl-retrieve-pass';

        [, $createBody] = $this->postJson('/api/create', [
            'encrypted_secret' => $this->buildEncryptedSecret('ttl-retrieve-test', $password),
            'ttl'              => 86400,
            'max_views'        => 0,
        ]);

        [, $retrieveBody] = $this->postJson('/api/retrieve', [
            'id'       => $createBody['id'],
            'verifier' => $this->buildVerifier($password),
        ]);

        $this->assertArrayHasKey('expires_at', $retrieveBody);
        $this->assertGreaterThan(time(), $retrieveBody['expires_at']);
    }

    // -------------------------------------------------------------------------
    // View limits
    // -------------------------------------------------------------------------

    public function testViewLimitOfOneExhaustsAfterSingleRetrieval(): void
    {
        $password = 'view-limit-pass';

        [, $createBody] = $this->postJson('/api/create', [
            'encrypted_secret' => $this->buildEncryptedSecret('view-limited-secret', $password),
            'ttl'              => 3600,
            'max_views'        => 1,
        ]);

        $secretId = $createBody['id'];
        $verifier = $this->buildVerifier($password);

        // First retrieval: should succeed and report 0 views remaining
        [$firstCode, $firstBody] = $this->postJson('/api/retrieve', [
            'id'       => $secretId,
            'verifier' => $verifier,
        ]);

        $this->assertSame(200, $firstCode, 'First retrieval should succeed');
        $this->assertSame(0, $firstBody['views_remaining'], 'views_remaining should be 0 after last view');

        // Second retrieval: secret is exhausted, must return 4xx
        [$secondCode] = $this->postJson('/api/retrieve', [
            'id'       => $secretId,
            'verifier' => $verifier,
        ]);

        $this->assertGreaterThanOrEqual(400, $secondCode, 'Second retrieval should fail');
        $this->assertLessThan(500, $secondCode, 'Second retrieval should return a 4xx, not 5xx');
    }

    public function testViewLimitOfThreeDecrementsCorrectly(): void
    {
        $password = 'view-limit-3-pass';

        [, $createBody] = $this->postJson('/api/create', [
            'encrypted_secret' => $this->buildEncryptedSecret('three-view-secret', $password),
            'ttl'              => 3600,
            'max_views'        => 3,
        ]);

        $secretId = $createBody['id'];
        $verifier = $this->buildVerifier($password);

        [, $first]  = $this->postJson('/api/retrieve', ['id' => $secretId, 'verifier' => $verifier]);
        [, $second] = $this->postJson('/api/retrieve', ['id' => $secretId, 'verifier' => $verifier]);
        [, $third]  = $this->postJson('/api/retrieve', ['id' => $secretId, 'verifier' => $verifier]);

        $this->assertSame(2, $first['views_remaining']);
        $this->assertSame(1, $second['views_remaining']);
        $this->assertSame(0, $third['views_remaining']);

        [$exhaustedCode] = $this->postJson('/api/retrieve', ['id' => $secretId, 'verifier' => $verifier]);
        $this->assertGreaterThanOrEqual(400, $exhaustedCode);
    }

    public function testUnlimitedViewsSecretCanBeRetrievedMultipleTimes(): void
    {
        $password = 'unlimited-pass';

        [, $createBody] = $this->postJson('/api/create', [
            'encrypted_secret' => $this->buildEncryptedSecret('unlimited-secret', $password),
            'ttl'              => 3600,
            'max_views'        => 0,
        ]);

        $secretId = $createBody['id'];
        $verifier = $this->buildVerifier($password);

        for ($i = 0; $i < 3; $i++) {
            [$code, $body] = $this->postJson('/api/retrieve', ['id' => $secretId, 'verifier' => $verifier]);
            $this->assertSame(200, $code, "Retrieval #{$i} should succeed");
            $this->assertNull($body['views_remaining'], 'views_remaining should be null for unlimited secrets');
        }
    }

    // -------------------------------------------------------------------------
    // Authentication / error paths
    // -------------------------------------------------------------------------

    public function testRetrieveWithWrongPasswordFails(): void
    {
        $password = 'correct-password';

        [, $createBody] = $this->postJson('/api/create', [
            'encrypted_secret' => $this->buildEncryptedSecret('auth-test-secret', $password),
            'ttl'              => 3600,
            'max_views'        => 0,
        ]);

        [$code] = $this->postJson('/api/retrieve', [
            'id'       => $createBody['id'],
            'verifier' => $this->buildVerifier('wrong-password'),
        ]);

        $this->assertGreaterThanOrEqual(400, $code, 'Wrong password should be rejected');
        $this->assertLessThan(500, $code);
    }

    public function testRetrieveNonExistentSecretFails(): void
    {
        [$code] = $this->postJson('/api/retrieve', [
            'id'       => '000000000000',
            'verifier' => $this->buildVerifier('any-password'),
        ]);

        $this->assertGreaterThanOrEqual(400, $code, 'Non-existent secret should return 4xx');
        $this->assertLessThan(500, $code);
    }

    // -------------------------------------------------------------------------
    // CLI backward-compatibility: v1 redirect endpoints
    // -------------------------------------------------------------------------

    public function testPostCreateRedirectsTo308(): void
    {
        [$code, $location] = $this->postNoFollow('/create', [
            'encrypted_secret' => $this->buildEncryptedSecret('redirect-test', 'redirect-pass'),
            'ttl'              => 3600,
            'max_views'        => 0,
        ]);

        $this->assertSame(308, $code, 'POST /create should return 308 Permanent Redirect');
        $this->assertStringContainsString('/api/create', $location);
    }

    public function testPostRetrieveRedirectsTo308(): void
    {
        [$code, $location] = $this->postNoFollow('/retrieve', [
            'id'       => '000000000000',
            'verifier' => $this->buildVerifier('any-password'),
        ]);

        $this->assertSame(308, $code, 'POST /retrieve should return 308 Permanent Redirect');
        $this->assertStringContainsString('/api/retrieve', $location);
    }

    // -------------------------------------------------------------------------
    // Secret info endpoint
    // -------------------------------------------------------------------------

    public function testSecretInfoEndpointReturnsMetadataWithoutConsumingView(): void
    {
        $password = 'info-pass';

        [, $createBody] = $this->postJson('/api/create', [
            'encrypted_secret' => $this->buildEncryptedSecret('info-test-secret', $password),
            'ttl'              => 3600,
            'max_views'        => 1,
        ]);

        $secretId = $createBody['id'];

        // Fetch info twice — neither call should consume the single allowed view
        [$infoCode1, $info1] = $this->get("/api/secret/{$secretId}/info");
        [$infoCode2, $info2Raw] = $this->get("/api/secret/{$secretId}/info");
        $info2 = json_decode($info2Raw, true) ?? [];

        $this->assertSame(200, $infoCode1);
        $this->assertSame(200, $infoCode2);

        $info1Parsed = json_decode($info1, true) ?? [];
        $this->assertSame(1, $info1Parsed['views_remaining']);
        $this->assertSame(1, $info2['views_remaining']);

        // The single view should still be available
        [$retrieveCode] = $this->postJson('/api/retrieve', [
            'id'       => $secretId,
            'verifier' => $this->buildVerifier($password),
        ]);
        $this->assertSame(200, $retrieveCode);
    }
}
