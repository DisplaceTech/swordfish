<?php
namespace Swordfish\CLI\Tests;

use PHPUnit\Framework\TestCase;
use Swordfish\CLI\RetrieveSecretCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class RetrieveSecretCommandTest extends TestCase
{
    private function makeCommand(string|false $httpResponse): CommandTester
    {
        $command = new class($httpResponse) extends RetrieveSecretCommand {
            public function __construct(private string|false $stubbedResponse) {
                parent::__construct();
            }
            protected function httpPost(string $url, array $data): string|false {
                return $this->stubbedResponse;
            }
        };
        return new CommandTester($command);
    }

    private function buildEncryptedHex(string $secret, string $password): string
    {
        $salt = str_repeat("\x01", 16);
        $key = hash_pbkdf2('sha256', $password, $salt, 10000, 32, true);
        $nonce = str_repeat("\x02", SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_aes256gcm_encrypt($secret, '', $nonce, $key);
        return bin2hex($salt . $nonce . $ciphertext);
    }

    public function testSuccessfulJsonResponse(): void
    {
        $encryptedHex = $this->buildEncryptedHex('my secret', 'pass');
        $tester = $this->makeCommand(json_encode(['encrypted_secret' => $encryptedHex]));
        $status = $tester->execute(['secret-id' => 'abc123', 'password' => 'pass']);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('my secret', $tester->getDisplay());
    }

    public function testErrorJsonResponse(): void
    {
        $tester = $this->makeCommand(json_encode(['error' => true, 'message' => 'Not found or expired']));
        $status = $tester->execute(['secret-id' => 'abc123', 'password' => 'pass']);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Server error: Not found or expired', $tester->getDisplay());
    }

    public function testErrorJsonResponseWithAuthFailure(): void
    {
        $tester = $this->makeCommand(json_encode(['error' => true, 'message' => 'Invalid authorization']));
        $status = $tester->execute(['secret-id' => 'abc123', 'password' => 'pass']);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Server error: Invalid authorization', $tester->getDisplay());
    }

    public function testMissingEncryptedSecretInJsonResponse(): void
    {
        $tester = $this->makeCommand(json_encode(['status' => 'ok']));
        $status = $tester->execute(['secret-id' => 'abc123', 'password' => 'pass']);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Unexpected server response', $tester->getDisplay());
    }

    public function testV1PlainTextFallback(): void
    {
        $encryptedHex = $this->buildEncryptedHex('my secret', 'pass');
        $tester = $this->makeCommand($encryptedHex);
        $status = $tester->execute(['secret-id' => 'abc123', 'password' => 'pass']);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('my secret', $tester->getDisplay());
    }

    public function testV1NotFoundResponse(): void
    {
        $tester = $this->makeCommand('Not found or expired');
        $status = $tester->execute(['secret-id' => 'abc123', 'password' => 'pass']);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Secret is either not found or expired!', $tester->getDisplay());
    }

    public function testV1InvalidAuthResponse(): void
    {
        $tester = $this->makeCommand('Invalid authorization');
        $status = $tester->execute(['secret-id' => 'abc123', 'password' => 'pass']);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Invalid password!', $tester->getDisplay());
    }

    public function testMalformedJsonFallsBackToV1(): void
    {
        $encryptedHex = $this->buildEncryptedHex('my secret', 'pass');
        // Wrap in broken JSON so it falls back to v1 path
        $tester = $this->makeCommand($encryptedHex);
        $status = $tester->execute(['secret-id' => 'abc123', 'password' => 'pass']);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('my secret', $tester->getDisplay());
    }

    public function testInvalidHexReturnsError(): void
    {
        $tester = $this->makeCommand(json_encode(['encrypted_secret' => 'not-valid-hex!!!']));
        $status = $tester->execute(['secret-id' => 'abc123', 'password' => 'pass']);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Invalid encrypted secret format received.', $tester->getDisplay());
    }

    public function testNetworkError(): void
    {
        $tester = $this->makeCommand(false);
        $status = $tester->execute(['secret-id' => 'abc123', 'password' => 'pass']);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Network error', $tester->getDisplay());
    }
}
