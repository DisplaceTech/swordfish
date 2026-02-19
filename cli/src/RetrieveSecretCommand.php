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
            ->addArgument('secret-id', InputArgument::REQUIRED, 'Compound secret ID (secretID:verifier) returned at creation time.')
            ->addArgument('password', InputArgument::REQUIRED, 'User-friendly password used to protect the secret.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $serverUrl = getenv('SWORDFISH_URL') ?: 'https://swordfish.displace.tech';

        $password = $input->getArgument('password');
        $compoundId = $input->getArgument('secret-id');

        [$secretId, $verifier] = array_pad(explode(':', $compoundId, 2), 2, '');

        $payload = json_encode(['id' => $secretId, 'verifier' => $verifier]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "{$serverUrl}/api/retrieve");
        curl_setopt($ch, CURLOPT_USERAGENT, 'Swordfish CLI');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $output->writeln('Network error: ' . $curlError);
            return Command::FAILURE;
        }

        $parsed = json_decode($response, true);

        if ($httpCode === 404 || (isset($parsed['error']) && $parsed['error'] === 'Not found or expired')) {
            $output->writeln('Secret is either not found or expired!');
            return Command::FAILURE;
        }

        if ($httpCode === 401 || (isset($parsed['error']) && $parsed['error'] === 'Invalid authorization')) {
            $output->writeln('Invalid password!');
            return Command::FAILURE;
        }

        if ($httpCode !== 200 || !isset($parsed['encrypted_secret'])) {
            $output->writeln('Unexpected server response!');
            return Command::FAILURE;
        }

        $decoded = hex2bin($parsed['encrypted_secret']);
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
