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

    public function testDefaultTtlIs24h(): void
    {
        $capturedData = null;
        $command = new class($capturedData) extends CreateSecretCommand {
            public function __construct(private mixed &$captured) {
                parent::__construct();
            }
            protected function httpPost(string $url, array $data): string|false {
                $this->captured = $data;
                return json_encode(['id' => 'abc123']);
            }
        };
        $tester = new CommandTester($command);
        $status = $tester->execute(['secret' => 'my secret', 'password' => 'pass']);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame(86400, $capturedData['ttl']);
    }

    /**
     * @dataProvider validTtlProvider
     */
    public function testValidTtlValues(string $ttlString, int $expectedSeconds): void
    {
        $capturedData = null;
        $command = new class($capturedData) extends CreateSecretCommand {
            public function __construct(private mixed &$captured) {
                parent::__construct();
            }
            protected function httpPost(string $url, array $data): string|false {
                $this->captured = $data;
                return json_encode(['id' => 'abc123']);
            }
        };
        $tester = new CommandTester($command);
        $status = $tester->execute(['secret' => 'my secret', 'password' => 'pass', '--ttl' => $ttlString]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame($expectedSeconds, $capturedData['ttl']);
    }

    public static function validTtlProvider(): array
    {
        return [
            '1h'  => ['1h',  3600],
            '6h'  => ['6h',  21600],
            '24h' => ['24h', 86400],
            '3d'  => ['3d',  259200],
            '7d'  => ['7d',  604800],
        ];
    }

    public function testInvalidTtlShowsError(): void
    {
        $tester = $this->makeCommand(json_encode(['id' => 'abc123']));
        $status = $tester->execute(['secret' => 'my secret', 'password' => 'pass', '--ttl' => '2h']);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Invalid TTL "2h"', $tester->getDisplay());
        $this->assertStringContainsString('1h, 6h, 24h, 3d, 7d', $tester->getDisplay());
    }
}
