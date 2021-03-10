<?php
namespace Swordfish\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RetrieveSecretCommand extends Command
{
    protected static string $defaultName = 'secret:retrieve';

    protected function configure()
    {
        $this->setDescription('Retrieves an encrypted secret from the server.')
            ->setHelp('This command allows you to retrieve a secret from the server and decrypt it locally.')
            ->addArgument('secret-id', InputArgument::REQUIRED, 'ID of the secret to retrieve.')
            ->addArgument('password', InputArgument::REQUIRED, 'User-friendly password used to protect the secret.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $serverUrl = getenv('SWORDFISH_URL') ?: 'http://localhost:8080';

        $password = $input->getArgument('password');
        $secretId = $input->getArgument('secret-id');

        // Authenticate against the server to get the encrypted secret
        $verifier = hash_pbkdf2('sha256', $password, SWORDFISH_PEPPER, 10000);
        $payload = $secretId . '$' . $verifier;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "{$serverUrl}/retrieve");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/plain']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        $response = curl_exec($ch);

        if ($response === false) {
            $output->writeln('Network error: ' . curl_error($ch));
            return Command::FAILURE;
        }

        if ($response === "Not found or expired") {
            $output->writeln('Secret is either not found or expired!');
            return Command::FAILURE;
        }

        if ($response === "Invalid authorization") {
            $output->writeln('Invalid password!');
            return Command::FAILURE;
        }

        $decoded = hex2bin($response);
        $salt = substr($decoded, 0, 16);
        $encrypted = substr($decoded, 16);

        $key = hash_pbkdf2('sha256', $password, $salt, 10000, 32, true);

        $nonce = substr($encrypted, 0, SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES);
        $ciphertext = substr($encrypted, SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES);

        $decrypted = sodium_crypto_aead_aes256gcm_decrypt($ciphertext, '', $nonce, $key);

        if ($decrypted === false) {
            $output->writeln('Unable to decrypt secret!');
            return Command::FAILURE;
        }


        $output->writeln([
            'Secret Decrypted!',
            '==============',
            $decrypted
        ]);

        return Command::SUCCESS;
    }
}