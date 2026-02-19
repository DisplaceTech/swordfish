<?php
namespace Swordfish\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SecretInfoCommand extends Command
{
    protected static $defaultName = 'secret:info';

    protected function configure()
    {
        $this->setDescription('Checks remaining views and expiration for a secret without decrypting it.')
            ->setHelp('This command returns metadata about a secret (views remaining, expiry) without requiring the passphrase and without consuming a view.')
            ->addArgument('secret-id', InputArgument::REQUIRED, 'ID of the secret to inspect.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $serverUrl = getenv('SWORDFISH_URL') ?: 'https://swordfish.displace.tech';
        $secretId  = $input->getArgument('secret-id');

        $response = $this->httpGet("{$serverUrl}/api/secret/{$secretId}/info");

        if ($response === false) {
            $output->writeln('Network error: could not reach server');
            return Command::FAILURE;
        }

        $parsed = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $output->writeln('Unexpected server response');
            return Command::FAILURE;
        }

        if (isset($parsed['error'])) {
            $output->writeln('Server error: ' . ($parsed['message'] ?? $parsed['error']));
            return Command::FAILURE;
        }

        if (!array_key_exists('expires_at', $parsed)) {
            $output->writeln('Unexpected server response');
            return Command::FAILURE;
        }

        $expiresAt      = $parsed['expires_at'];
        $viewsRemaining = $parsed['views_remaining'];

        $expiryDisplay = date('Y-m-d H:i:s T', $expiresAt);
        $viewsDisplay  = $viewsRemaining === null ? 'unlimited' : (string) $viewsRemaining;

        $output->writeln([
            'Secret Info',
            '===========',
            'Secret ID:       ' . $secretId,
            'Views Remaining: ' . $viewsDisplay,
            'Expires At:      ' . $expiryDisplay,
        ]);

        return Command::SUCCESS;
    }

    protected function httpGet(string $url): string|false
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Swordfish CLI');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }
}
