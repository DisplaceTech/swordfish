<?php
namespace Swordfish\CLI\Tests;

use PHPUnit\Framework\TestCase;
use Swordfish\CLI\SecretInfoCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class SecretInfoCommandTest extends TestCase
{
    private function makeCommand(string|false $httpResponse): CommandTester
    {
        $command = new class($httpResponse) extends SecretInfoCommand {
            public function __construct(private string|false $stubbedResponse) {
                parent::__construct();
            }
            protected function httpGet(string $url): string|false {
                return $this->stubbedResponse;
            }
        };
        return new CommandTester($command);
    }

    public function testSuccessfulResponseWithLimitedViews(): void
    {
        $expiresAt = mktime(12, 0, 0, 6, 15, 2025);
        $tester = $this->makeCommand(json_encode([
            'views_remaining' => 3,
            'expires_at'      => $expiresAt,
        ]));
        $status = $tester->execute(['secret-id' => 'abc123']);

        $this->assertSame(Command::SUCCESS, $status);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Secret ID:       abc123', $display);
        $this->assertStringContainsString('Views Remaining: 3', $display);
        $this->assertStringContainsString('Expires At:', $display);
    }

    public function testSuccessfulResponseWithUnlimitedViews(): void
    {
        $expiresAt = mktime(12, 0, 0, 6, 15, 2025);
        $tester = $this->makeCommand(json_encode([
            'views_remaining' => null,
            'expires_at'      => $expiresAt,
        ]));
        $status = $tester->execute(['secret-id' => 'abc123']);

        $this->assertSame(Command::SUCCESS, $status);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Views Remaining: unlimited', $display);
    }

    public function testNotFoundErrorResponse(): void
    {
        $tester = $this->makeCommand(json_encode([
            'error'   => 'Not Found',
            'message' => 'Not found or expired',
        ]));
        $status = $tester->execute(['secret-id' => 'abc123']);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Server error: Not found or expired', $tester->getDisplay());
    }

    public function testErrorResponseWithoutMessage(): void
    {
        $tester = $this->makeCommand(json_encode(['error' => 'Bad Request']));
        $status = $tester->execute(['secret-id' => 'abc123']);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Server error: Bad Request', $tester->getDisplay());
    }

    public function testMissingExpiresAtInResponse(): void
    {
        $tester = $this->makeCommand(json_encode(['status' => 'ok']));
        $status = $tester->execute(['secret-id' => 'abc123']);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Unexpected server response', $tester->getDisplay());
    }

    public function testMalformedJsonResponse(): void
    {
        $tester = $this->makeCommand('not-json');
        $status = $tester->execute(['secret-id' => 'abc123']);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Unexpected server response', $tester->getDisplay());
    }

    public function testNetworkError(): void
    {
        $tester = $this->makeCommand(false);
        $status = $tester->execute(['secret-id' => 'abc123']);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Network error', $tester->getDisplay());
    }
}
