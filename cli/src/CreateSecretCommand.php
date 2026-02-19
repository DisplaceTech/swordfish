<?php
namespace Swordfish\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CreateSecretCommand extends Command
{
    protected static $defaultName = 'secret:create';

    protected function configure()
    {
        $this->setDescription('Creates an encrypted secret on the server.')
            ->setHelp('This command allows you to create a secret, fully encrypted on the server.')
            ->addArgument('secret', InputArgument::REQUIRED, 'Plaintext secret to protect.')
            ->addArgument('password', InputArgument::REQUIRED, 'User-friendly password used to protect the secret.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $serverUrl = getenv('SWORDFISH_URL') ?: 'https://swordfish.displace.tech';

        $password = $input->getArgument('password');
        $secret = $input->getArgument('secret');
        $salt = random_bytes(16);

        $key = hash_pbkdf2('sha256', $password, $salt, 10000, 0, true);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_aes256gcm_encrypt($secret, '', $nonce, $key);

        $verifier = hash_pbkdf2('sha256', $password, SWORDFISH_PEPPER, 10000);
        $encryptedSecret = bin2hex($salt . $nonce . $ciphertext);
        $jsonPayload = json_encode(['encrypted_secret' => $encryptedSecret, 'verifier' => $verifier]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "{$serverUrl}/api/create");
        curl_setopt($ch, CURLOPT_USERAGENT, 'Swordfish CLI');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);

        $response = curl_exec($ch);

        if ($response === false) {
            $output->writeln('Network error: ' . curl_error($ch));
            return Command::FAILURE;
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 404) {
            $legacyPayload = bin2hex($salt) . '$' . $verifier . '$' . bin2hex($nonce . $ciphertext);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "{$serverUrl}/create");
            curl_setopt($ch, CURLOPT_USERAGENT, 'Swordfish CLI');
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/plain']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $legacyPayload);

            $response = curl_exec($ch);

            if ($response === false) {
                $output->writeln('Network error: ' . curl_error($ch));
                return Command::FAILURE;
            }

            curl_close($ch);

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

        $parsed = json_decode($response, true);

        if ($httpCode !== 201 || !isset($parsed['id'])) {
            $output->writeln('Unexpected server response!');
            return Command::FAILURE;
        }

        $output->writeln([
            'Secret Created',
            '==============',
            'Secret ID: ' . $parsed['id'],
            'Password:  ' . $password,
            '',
            'URL:       ' . $serverUrl . '/secret/' . $parsed['id']
        ]);

        return Command::SUCCESS;
    }
}
