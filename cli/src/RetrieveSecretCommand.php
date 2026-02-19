<?php
namespace Swordfish\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RetrieveSecretCommand extends Command
{
    protected static $defaultName = 'secret:retrieve';

    protected function configure()
    {
        $this->setDescription('Retrieves an encrypted secret from the server.')
            ->setHelp('This command allows you to retrieve a secret from the server and decrypt it locally.')
            ->addArgument('secret-id', InputArgument::REQUIRED, 'ID of the secret to retrieve.')
            ->addArgument('password', InputArgument::REQUIRED, 'User-friendly password used to protect the secret.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $serverUrl = getenv('SWORDFISH_URL') ?: 'https://swordfish.displace.tech';

        $password = $input->getArgument('password');
        $secretId = $input->getArgument('secret-id');

        // Authenticate against the server to get the encrypted secret
        $verifier = hash_pbkdf2('sha256', $password, SWORDFISH_PEPPER, 10000);

        $response = $this->httpPost("{$serverUrl}/api/retrieve", ['id' => $secretId, 'verifier' => $verifier]);

        if ($response === false) {
            $output->writeln('<error>Network error: could not reach server</error>');
            return Command::FAILURE;
        }

        $parsed = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (isset($parsed['error'])) {
                $message = $parsed['message'] ?? $parsed['error'];
                $friendlyMessage = match(true) {
                    str_contains($message, 'not found') || str_contains($message, 'expired') => 'Secret not found or has expired.',
                    str_contains($message, 'authorization') => 'Wrong password: the password you entered is incorrect.',
                    default => 'Server error: ' . $message,
                };
                $output->writeln('<error>' . $friendlyMessage . '</error>');
                return Command::FAILURE;
            }
            if (!isset($parsed['encrypted_secret'])) {
                $output->writeln('<error>Unexpected server response</error>');
                return Command::FAILURE;
            }
            $encryptedHex = $parsed['encrypted_secret'];
        } else {
            // v1 fallback: plain-text response is the hex-encoded encrypted secret
            if ($response === "Not found or expired") {
                $output->writeln('<error>Secret not found or has expired.</error>');
                return Command::FAILURE;
            }

            if ($response === "Invalid authorization") {
                $output->writeln('<error>Wrong password: the password you entered is incorrect.</error>');
                return Command::FAILURE;
            }

            $encryptedHex = $response;
        }

        if (strlen($encryptedHex) % 2 !== 0 || !ctype_xdigit($encryptedHex)) {
            $output->writeln('<error>Invalid encrypted secret format received.</error>');
            return Command::FAILURE;
        }
        $decoded = hex2bin($encryptedHex);

        $salt = substr($decoded, 0, 16);
        $encrypted = substr($decoded, 16);

        $key = hash_pbkdf2('sha256', $password, $salt, 10000, 32, true);

        $nonce = substr($encrypted, 0, SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES);
        $ciphertext = substr($encrypted, SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES);

        $decrypted = sodium_crypto_aead_aes256gcm_decrypt($ciphertext, '', $nonce, $key);

        if ($decrypted === false) {
            $output->writeln('<error>Unable to decrypt secret. Check your password and try again.</error>');
            return Command::FAILURE;
        }

        $output->writeln([
            '<info>Secret Decrypted!</info>',
            '<info>==============</info>',
            $decrypted
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
