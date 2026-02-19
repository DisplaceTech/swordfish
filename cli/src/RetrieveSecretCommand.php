<?php
namespace Swordfish\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RetrieveSecretCommand extends Command
{
    protected static $defaultName = 'secret:retrieve';

    protected function configure()
    {
        $this->setDescription('Retrieves an encrypted secret from the server.')
            ->setHelp('This command allows you to retrieve a secret from the server and decrypt it locally.')
            ->addArgument('secret-id', InputArgument::REQUIRED, 'ID of the secret to retrieve.')
            ->addArgument('password', InputArgument::REQUIRED, 'User-friendly password used to protect the secret.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as machine-readable JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $serverUrl = getenv('SWORDFISH_URL') ?: 'https://swordfish.displace.tech';
        $jsonMode = $input->getOption('json');

        $password = $input->getArgument('password');
        $secretId = $input->getArgument('secret-id');

        // Authenticate against the server to get the encrypted secret
        $verifier = hash_pbkdf2('sha256', $password, SWORDFISH_PEPPER, 10000);

        $response = $this->httpPost("{$serverUrl}/api/retrieve", ['id' => $secretId, 'verifier' => $verifier]);

        if ($response === false) {
            $this->writeError($output, $jsonMode, 'Network error: could not reach server');
            return Command::FAILURE;
        }

        $parsed = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (isset($parsed['error'])) {
                $message = $parsed['message'] ?? $parsed['error'];
                if ($jsonMode) {
                    $this->writeError($output, true, 'Server error: ' . $message);
                } else {
                    $friendlyMessage = match(true) {
                        str_contains($message, 'not found') || str_contains($message, 'expired') => 'Secret not found or has expired.',
                        str_contains($message, 'authorization') => 'Wrong password: the password you entered is incorrect.',
                        default => 'Server error: ' . $message,
                    };
                    $this->writeError($output, false, $friendlyMessage);
                }
                return Command::FAILURE;
            }
            if (!isset($parsed['encrypted_secret'])) {
                $this->writeError($output, $jsonMode, 'Unexpected server response');
                return Command::FAILURE;
            }
            $encryptedHex = $parsed['encrypted_secret'];
        } else {
            // v1 fallback: plain-text response is the hex-encoded encrypted secret
            if ($response === "Not found or expired") {
                $this->writeError($output, $jsonMode, 'Secret not found or has expired.');
                return Command::FAILURE;
            }

            if ($response === "Invalid authorization") {
                $this->writeError($output, $jsonMode, 'Wrong password: the password you entered is incorrect.');
                return Command::FAILURE;
            }

            $encryptedHex = $response;
        }

        if (strlen($encryptedHex) % 2 !== 0 || !ctype_xdigit($encryptedHex)) {
            $this->writeError($output, $jsonMode, 'Invalid encrypted secret format received.');
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
            $this->writeError($output, $jsonMode, 'Unable to decrypt secret. Check your password and try again.');
            return Command::FAILURE;
        }

        if ($jsonMode) {
            $output->writeln(json_encode(['secret' => $decrypted]));
        } else {
            $output->writeln([
                '<info>Secret Decrypted!</info>',
                '<info>==============</info>',
                $decrypted
            ]);
        }

        return Command::SUCCESS;
    }

    private function writeError(OutputInterface $output, bool $jsonMode, string $message): void
    {
        if ($jsonMode && $output instanceof ConsoleOutputInterface) {
            $output->getErrorOutput()->writeln($message);
        } else {
            $output->writeln('<error>' . $message . '</error>');
        }
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
