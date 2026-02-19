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

    private function makeCapturingCommand(): array
    {
        $command = new class extends CreateSecretCommand {
            public ?array $capturedData = null;
            protected function httpPost(string $url, array $data): string|false {
                $this->capturedData = $data;
                return json_encode(['id' => 'abc123']);
            }
        };
        return [$command, new CommandTester($command)];
    }

    public function testDefaultTtlIs24h(): void
    {
        [$command, $tester] = $this->makeCapturingCommand();
        $status = $tester->execute(['secret' => 'my secret', 'password' => 'pass']);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame(86400, $command->capturedData['ttl']);
    }

    /**
     * @dataProvider validTtlProvider
     */
    public function testValidTtlValues(string $ttlString, int $expectedSeconds): void
    {
        [$command, $tester] = $this->makeCapturingCommand();
        $status = $tester->execute(['secret' => 'my secret', 'password' => 'pass', '--ttl' => $ttlString]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame($expectedSeconds, $command->capturedData['ttl']);
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

    public function testMaxViewsDefaultIsUnlimited(): void
    {
        [$command, $tester] = $this->makeCapturingCommand();
        $status = $tester->execute(['secret' => 'my secret', 'password' => 'pass']);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame(0, $command->capturedData['max_views']);
    }

    public function testMaxViewsUnlimitedExplicit(): void
    {
        [$command, $tester] = $this->makeCapturingCommand();
        $status = $tester->execute(['secret' => 'my secret', 'password' => 'pass', '--max-views' => 'unlimited']);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame(0, $command->capturedData['max_views']);
    }

    /**
     * @dataProvider validMaxViewsProvider
     */
    public function testMaxViewsValidValues(int $maxViews): void
    {
        [$command, $tester] = $this->makeCapturingCommand();
        $status = $tester->execute(['secret' => 'my secret', 'password' => 'pass', '--max-views' => (string) $maxViews]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame($maxViews, $command->capturedData['max_views']);
    }

    public static function validMaxViewsProvider(): array
    {
        return [[1], [3], [5], [10]];
    }

    /**
     * @dataProvider invalidMaxViewsProvider
     */
    public function testMaxViewsInvalidValues(string $value): void
    {
        $tester = $this->makeCommand(json_encode(['id' => 'abc123']));
        $status = $tester->execute(['secret' => 'my secret', 'password' => 'pass', '--max-views' => $value]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('--max-views must be one of: 1, 3, 5, 10, or unlimited', $tester->getDisplay());
    }

    public static function invalidMaxViewsProvider(): array
    {
        return [['0'], ['2'], ['7'], ['100'], ['none'], ['all'], ['']];
    }

    public function testJsonFlagOutputsValidJson(): void
    {
        $tester = $this->makeCommand(json_encode(['id' => 'abc123']));
        $status = $tester->execute(['secret' => 'my secret', 'password' => 'pass', '--json' => true]);

        $this->assertSame(Command::SUCCESS, $status);
        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertSame('abc123', $decoded['id']);
        $this->assertSame('pass', $decoded['password']);
        $this->assertStringContainsString('/secret/abc123', $decoded['url']);
    }

    public function testJsonFlagErrorGoesToStderr(): void
    {
        $tester = $this->makeCommand(json_encode(['error' => true, 'message' => 'Storage full']));
        $status = $tester->execute(
            ['secret' => 'my secret', 'password' => 'pass', '--json' => true],
            ['capture_stderr_separately' => true]
        );

        $this->assertSame(Command::FAILURE, $status);
        $this->assertEmpty(trim($tester->getDisplay()));
        $this->assertStringContainsString('Server error: Storage full', $tester->getErrorOutput());
    }

    public function testJsonFlagNetworkErrorGoesToStderr(): void
    {
        $tester = $this->makeCommand(false);
        $status = $tester->execute(
            ['secret' => 'my secret', 'password' => 'pass', '--json' => true],
            ['capture_stderr_separately' => true]
        );

        $this->assertSame(Command::FAILURE, $status);
        $this->assertEmpty(trim($tester->getDisplay()));
        $this->assertStringContainsString('Network error', $tester->getErrorOutput());
    }
}
