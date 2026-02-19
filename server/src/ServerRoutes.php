<?php

namespace Swordfish\Server;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Status;
use Monolog\Logger;
use Predis\Client;

class ServerRoutes
{
    /**
     * Create a callback that renders the main landing page for the site.
     *
     * @param Logger $logger
     * @return CallableRequestHandler
     */
    public static function mainContent(Logger $logger): CallableRequestHandler
    {
        return new CallableRequestHandler(function() use ($logger): Response {
            $logger->info('Main page load');

            $fp = fopen(__DIR__ . '/../content/main.html', 'r');
            $stream = stream_get_contents($fp);

            return new Response(Status::OK, ['content-type' => 'text/html'], $stream);
        });
    }

    /**
     * Create a callback that renders the secret retrieval landing page.
     *
     * @param Logger $logger
     * @return CallableRequestHandler
     */
    public static function secretRetrieval(Logger $logger): CallableRequestHandler
    {
        return new CallableRequestHandler(function(Request $request) use ($logger): Response {
            $args = $request->getAttribute(Router::class);
            $secretId = $args['secretID'] ?? '';
            if ($secretId === '') {
                $logger->info('Secret retrieval attempt...');
            } else {
                $logger->info(sprintf('Direct request for secret %s', $secretId));
            }

            $fp = fopen(__DIR__ . '/../content/retrieve.html', 'r');
            $stream = stream_get_contents($fp);

            return new Response(Status::OK, ['content-type' => 'text/html'], $stream);
        });
    }

    /**
     * Redirect POST /create to POST /api/create with a 307 Temporary Redirect.
     *
     * @param Logger $logger
     * @return CallableRequestHandler
     */
    public static function redirectCreate(Logger $logger): CallableRequestHandler
    {
        return new CallableRequestHandler(function() use ($logger): Response {
            $logger->info('Redirecting POST /create to POST /api/create');
            return new Response(Status::TEMPORARY_REDIRECT, ['location' => '/api/create'], '');
        });
    }

    /**
     * Process a JSON secret creation request and store the secret.
     *
     * Accepts: {"encrypted_secret": "...", "ttl": 86400, "max_views": 3}
     * Returns: {"id": "...", "expires_at": <unix timestamp>, "max_views": 3}
     *
     * @param Logger $logger
     * @param Client $redisClient
     * @return CallableRequestHandler
     */
    public static function createSecretJson(Logger $logger, Client $redisClient): CallableRequestHandler
    {
        return new CallableRequestHandler(function (Request $request) use ($logger, $redisClient) {
            $data = yield $request->getBody()->read();
            if (strlen($data) > 100 * 1000) {
                $logger->error('Message payload too large!');
                return new Response(
                    Status::PAYLOAD_TOO_LARGE,
                    ['content-type' => 'application/json'],
                    json_encode(['error' => 'Payload Too Large'])
                );
            }

            $parsed = json_decode($data, true);
            if ($parsed === null || !isset($parsed['encrypted_secret']) || !is_string($parsed['encrypted_secret']) || $parsed['encrypted_secret'] === '') {
                $logger->error('Invalid JSON creation request: missing or empty encrypted_secret');
                return new Response(
                    Status::BAD_REQUEST,
                    ['content-type' => 'application/json'],
                    json_encode(['error' => 'Bad Request'])
                );
            }

            $ttl = isset($parsed['ttl']) ? (int) $parsed['ttl'] : CreateRequest::DEFAULT_TTL;
            if ($ttl < 1 || $ttl > CreateRequest::MAX_TTL) {
                $logger->error(sprintf('Invalid TTL in JSON creation request: %d', $ttl));
                return new Response(
                    Status::UNPROCESSABLE_ENTITY,
                    ['content-type' => 'application/json'],
                    json_encode(['error' => sprintf('TTL must be between 1 and %d seconds.', CreateRequest::MAX_TTL)])
                );
            }

            $maxViews = isset($parsed['max_views']) ? (int) $parsed['max_views'] : 1;
            if ($maxViews < 1) {
                $logger->error(sprintf('Invalid max_views in JSON creation request: %d', $maxViews));
                return new Response(
                    Status::UNPROCESSABLE_ENTITY,
                    ['content-type' => 'application/json'],
                    json_encode(['error' => 'max_views must be at least 1.'])
                );
            }

            $secretID   = bin2hex(random_bytes(6));
            $secretKey  = "json_secret:{$secretID}";
            $viewsKey   = "json_views:{$secretID}";
            $expiresKey = "json_expires:{$secretID}";
            $expiresAt  = time() + $ttl;

            $redisClient->setex($secretKey, $ttl, $parsed['encrypted_secret']);
            $redisClient->setex($viewsKey, $ttl, (string) $maxViews);
            $redisClient->setex($expiresKey, $ttl, (string) $expiresAt);

            $logger->info(sprintf('Created JSON secret %s (ttl=%d, max_views=%d)', $secretID, $ttl, $maxViews));

            return new Response(
                Status::CREATED,
                ['content-type' => 'application/json'],
                json_encode([
                    'id'        => $secretID,
                    'expires_at' => $expiresAt,
                    'max_views' => $maxViews,
                ])
            );
        });
    }

    /**
     * Check Redis connectivity and return service health status.
     *
     * @param Logger $logger
     * @param Client $redisClient
     * @return CallableRequestHandler
     */
    public static function healthCheck(Logger $logger, Client $redisClient): CallableRequestHandler
    {
        return new CallableRequestHandler(function() use ($logger, $redisClient): Response {
            try {
                $redisClient->ping();
                $logger->info('Health check: Redis reachable');
                return new Response(
                    Status::OK,
                    ['content-type' => 'application/json'],
                    json_encode(['status' => 'ok'])
                );
            } catch (\Exception $e) {
                $logger->error('Health check: Redis unreachable - ' . $e->getMessage());
                return new Response(
                    Status::SERVICE_UNAVAILABLE,
                    ['content-type' => 'application/json'],
                    json_encode(['status' => 'error', 'message' => $e->getMessage()])
                );
            }
        });
    }

    /**
     * Handle API requests to retrieve a secret.
     *
     * @param Logger $logger
     * @param Client $redisClient
     * @return CallableRequestHandler
     */
    public static function retrieveSecret(Logger $logger, Client $redisClient): CallableRequestHandler
    {
        return new CallableRequestHandler(function (Request $request) use ($logger, $redisClient) {
            $data = yield $request->getBody()->read();

            try {
                $retrievalRequest = RetrievalRequest::fromString($data);
            } catch (\Exception $e) {
                $logger->error('Unable to decode retrieval request');
                return new Response(Status::BAD_REQUEST, ['content-type' => 'text/plain'], 'Bad Request');
            }

            $secretID = $retrievalRequest->ID();
            $secretKey = "secret:{$secretID}";
            $verifierKey = "verifier:{$secretID}";

            $hash = $redisClient->get($verifierKey);
            if ($hash === null) {
                $logger->error(sprintf('Secret %s was requested but verification code was not found.', $secretID));
                return new Response(Status::NOT_FOUND, ['content-type' => 'text/plain'], 'Not found or expired');
            }

            if (!$retrievalRequest->verify_password($hash)) {
                $logger->error(sprintf('Secret %s requested with an invalid password.', $secretID));
                return new Response(Status::UNAUTHORIZED, ['content-type' => 'text/plain'], 'Invalid authorization');
            }

            $secret = $redisClient->get($secretKey);
            if ($secret === null) {
                $logger->error(sprintf('Secret %s was requested but was not found.', $secretID));
                return new Response(Status::NOT_FOUND, ['content-type' => 'text/plain'], 'Not found or expired');
            }

            return new Response(Status::OK, ['content-type' => 'text/plain'], $secret);
        });
    }

    /**
     * Handle JSON API requests to retrieve a secret.
     *
     * Accepts: {"id": "...", "verifier": "..."}
     * Returns: {"encrypted_secret": "...", "views_remaining": N, "expires_at": T}
     *
     * Verifies the bcrypt-hashed verifier, decrements the view counter, and
     * deletes all keys for the secret when views are exhausted.
     *
     * @param Logger $logger
     * @param Client $redisClient
     * @return CallableRequestHandler
     */
    public static function retrieveSecretJson(Logger $logger, Client $redisClient): CallableRequestHandler
    {
        return new CallableRequestHandler(function (Request $request) use ($logger, $redisClient) {
            $data = yield $request->getBody()->read();

            $parsed = json_decode($data, true);
            if ($parsed === null || !isset($parsed['id'], $parsed['verifier'])) {
                $logger->error('Unable to decode JSON retrieval request');
                return new Response(
                    Status::BAD_REQUEST,
                    ['content-type' => 'application/json'],
                    json_encode(['error' => 'Bad Request'])
                );
            }

            $secretID    = $parsed['id'];
            $verifier    = $parsed['verifier'];
            $verifierKey = "json_verifier:{$secretID}";
            $secretKey   = "json_secret:{$secretID}";
            $viewsKey    = "json_views:{$secretID}";
            $expiresKey  = "json_expires:{$secretID}";

            $hash = $redisClient->get($verifierKey);
            if ($hash === null) {
                $logger->error(sprintf('JSON secret %s was requested but verification code was not found.', $secretID));
                return new Response(
                    Status::NOT_FOUND,
                    ['content-type' => 'application/json'],
                    json_encode(['error' => 'Not found or expired'])
                );
            }

            if (!password_verify($verifier, $hash)) {
                $logger->error(sprintf('JSON secret %s requested with an invalid verifier.', $secretID));
                return new Response(
                    Status::UNAUTHORIZED,
                    ['content-type' => 'application/json'],
                    json_encode(['error' => 'Invalid authorization'])
                );
            }

            $encryptedSecret = $redisClient->get($secretKey);
            if ($encryptedSecret === null) {
                $logger->error(sprintf('JSON secret %s was requested but was not found.', $secretID));
                return new Response(
                    Status::NOT_FOUND,
                    ['content-type' => 'application/json'],
                    json_encode(['error' => 'Not found or expired'])
                );
            }

            $expiresAt  = (int) $redisClient->get($expiresKey);
            $viewsAfter = (int) $redisClient->decr($viewsKey);

            if ($viewsAfter <= 0) {
                $redisClient->del($secretKey, $verifierKey, $viewsKey, $expiresKey);
                $logger->info(sprintf('JSON secret %s views exhausted; deleted all keys.', $secretID));
            }

            $logger->info(sprintf('Retrieved JSON secret %s (%d views remaining).', $secretID, max(0, $viewsAfter)));

            return new Response(
                Status::OK,
                ['content-type' => 'application/json'],
                json_encode([
                    'encrypted_secret' => $encryptedSecret,
                    'views_remaining'  => max(0, $viewsAfter),
                    'expires_at'       => $expiresAt,
                ])
            );
        });
    }
}