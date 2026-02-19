<?php
namespace Swordfish\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CreateSecretCommand extends Command
{
    protected static $defaultName = 'secret:create';

    private const TTL_MAP = [
        '1h'  => 3600,
        '6h'  => 21600,
        '24h' => 86400,
        '3d'  => 259200,
        '7d'  => 604800,
    ];

    protected function configure()
    {
        $this->setDescription('Creates an encrypted secret on the server.')
            ->setHelp('This command allows you to create a secret, fully encrypted on the server.')
            ->addArgument('secret', InputArgument::REQUIRED, 'Plaintext secret to protect.')
            ->addArgument('password', InputArgument::REQUIRED, 'User-friendly password used to protect the secret.')
            ->addOption('ttl', null, InputOption::VALUE_REQUIRED, 'Time-to-live for the secret (1h, 6h, 24h, 3d, 7d).', '24h');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $serverUrl = getenv('SWORDFISH_URL') ?: 'https://swordfish.displace.tech';

        $ttlString = $input->getOption('ttl');
        if (!array_key_exists($ttlString, self::TTL_MAP)) {
            $output->writeln(sprintf(
                'Invalid TTL "%s". Allowed values: %s.',
                $ttlString,
                implode(', ', array_keys(self::TTL_MAP))
            ));
            return Command::FAILURE;
        }
        $ttl = self::TTL_MAP[$ttlString];

        $password = $input->getArgument('password');
        $secret = $input->getArgument('secret');
        $salt = random_bytes(16);

        $key = hash_pbkdf2('sha256', $password, $salt, 10000, 0, true);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_aes256gcm_encrypt($secret, '', $nonce, $key);

        $encrypted = bin2hex($nonce . $ciphertext);

        $verifier = hash_pbkdf2('sha256', $password, SWORDFISH_PEPPER, 10000);

        $encryptedSecret = bin2hex($salt) . '$' . $verifier . '$' . $encrypted;

        $response = $this->httpPost("{$serverUrl}/api/create", ['encrypted_secret' => $encryptedSecret, 'ttl' => $ttl]);

        if ($response === false) {
            $output->writeln('<error>Network error: could not reach server</error>');
            return Command::FAILURE;
        }

        $parsed = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (isset($parsed['error'])) {
                $output->writeln('<error>Server error: ' . ($parsed['message'] ?? $parsed['error']) . '</error>');
                return Command::FAILURE;
            }
            if (!isset($parsed['id'])) {
                $output->writeln('<error>Unexpected server response</error>');
                return Command::FAILURE;
            }
            $secretId = $parsed['id'];
        } else {
            // v1 fallback: plain-text response is the secret ID
            $secretId = $response;
        }

        $output->writeln([
            '<info>Secret Created</info>',
            '<info>==============</info>',
            'Secret ID: ' . $secretId,
            'Password:  ' . $password,
            '',
            'URL:       ' . $serverUrl . '/secret/' . $secretId
        ]);

        return Command::SUCCESS;
    }

    protected function httpPost(string $url, array $data): string|false
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Swordfish CLI');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }
}
