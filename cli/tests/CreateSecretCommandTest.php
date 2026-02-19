<?php
namespace Swordfish\CLI\Tests;

use PHPUnit\Framework\TestCase;
use Swordfish\CLI\CreateSecretCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class CreateSecretCommandTest extends TestCase
{
    private function makeCommand(string|false $httpResponse): CommandTester
    {
        $command = new class($httpResponse) extends CreateSecretCommand {
            public function __construct(private string|false $stubbedResponse) {
                parent::__construct();
            }
            protected function httpPost(string $url, array $data): string|false {
                return $this->stubbedResponse;
            }
        };
        return new CommandTester($command);
    }

    public function testSuccessfulJsonResponse(): void
    {
        $tester = $this->makeCommand(json_encode(['id' => 'abc123']));
        $status = $tester->execute(['secret' => 'my secret', 'password' => 'pass']);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('Secret ID: abc123', $tester->getDisplay());
        $this->assertStringContainsString('Password:  pass', $tester->getDisplay());
    }

    public function testErrorJsonResponse(): void
    {
        $tester = $this->makeCommand(json_encode(['error' => true, 'message' => 'Storage full']));
        $status = $tester->execute(['secret' => 'my secret', 'password' => 'pass']);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Server error: Storage full', $tester->getDisplay());
    }

    public function testMissingIdInJsonResponse(): void
    {
        $tester = $this->makeCommand(json_encode(['status' => 'ok']));
        $status = $tester->execute(['secret' => 'my secret', 'password' => 'pass']);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Unexpected server response', $tester->getDisplay());
    }

    public function testV1PlainTextFallback(): void
    {
        $tester = $this->makeCommand('plain-text-id-xyz');
        $status = $tester->execute(['secret' => 'my secret', 'password' => 'pass']);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('Secret ID: plain-text-id-xyz', $tester->getDisplay());
    }

    public function testMalformedJsonFallsBackToV1(): void
    {
        $tester = $this->makeCommand('{"id":');
        $status = $tester->execute(['secret' => 'my secret', 'password' => 'pass']);

        // Malformed JSON is treated as a v1 plain-text secret ID
        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('Secret ID: {"id":', $tester->getDisplay());
    }

    public function testNetworkError(): void
    {
        $tester = $this->makeCommand(false);
        $status = $tester->execute(['secret' => 'my secret', 'password' => 'pass']);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Network error', $tester->getDisplay());
    }
}
