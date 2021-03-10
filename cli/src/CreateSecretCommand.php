<?php
namespace Swordfish\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CreateSecretCommand extends Command
{
    protected static string $defaultName = 'secret:create';

    protected function configure()
    {
        $this->setDescription('Creates an encrypted secret on the server.')
            ->setHelp('This command allows you to create a secret, fully encrypted on the server.')
            ->addArgument('secret', InputArgument::REQUIRED, 'Plaintext secret to protect.')
            ->addArgument('password', InputArgument::REQUIRED, 'User-friendly password used to protect the secret.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $serverUrl = getenv('SWORDFISH_URL') ?: 'http://localhost:8080';

        $password = $input->getArgument('password');
        $secret = $input->getArgument('secret');
        $salt = random_bytes(16);

        $key = hash_pbkdf2('sha256', $password, $salt, 10000, 0, true);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_aes256gcm_encrypt($secret, '', $nonce, $key);

        $encrypted = bin2hex($nonce . $ciphertext);

        $verifier = hash_pbkdf2('sha256', $password, SWORDFISH_PEPPER, 10000);

        $payload = bin2hex($salt) . '$' . $verifier . '$' . $encrypted;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "{$serverUrl}/create");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/plain']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        $response = curl_exec($ch);

        if ($response === false) {
            $output->writeln('Network error: ' . curl_error($ch));
            return Command::FAILURE;
        }

        $output->writeln([
            'Secret Created',
            '==============',
            'Secret ID: ' . $response,
            'Password:  ' . $password,
            '',
            'URL:       ' . $serverUrl . '/secret/' . $response
        ]);

        return Command::SUCCESS;
    }
}