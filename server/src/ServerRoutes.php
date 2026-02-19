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
     * Parse a JSON create request body.
     *
     * Returns an array with keys 'encrypted_secret', 'ttl', and 'max_views',
     * or null if the body is not valid JSON or is missing required fields.
     *
     * @param string $data Raw request body
     * @return array{encrypted_secret: string, ttl: int, max_views: int}|null
     */
    public static function parseJsonCreateBody(string $data): ?array
    {
        $parsed = json_decode($data, true);
        if ($parsed === null || !isset($parsed['encrypted_secret'])) {
            return null;
        }

        return [
            'encrypted_secret' => $parsed['encrypted_secret'],
            'ttl'              => (int) ($parsed['ttl'] ?? 86400),
            'max_views'        => (int) ($parsed['max_views'] ?? 1),
        ];
    }

    /**
     * Parse a JSON retrieve request body.
     *
     * Returns the secret ID string, or null if the body is not valid JSON
     * or is missing the required 'id' field.
     *
     * @param string $data Raw request body
     * @return string|null
     */
    public static function parseJsonRetrieveBody(string $data): ?string
    {
        $parsed = json_decode($data, true);
        if ($parsed === null || !isset($parsed['id'])) {
            return null;
        }

        return $parsed['id'];
    }

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
     * Process a secret creation request and attempt to create the secret.
     *
     * Accepts either:
     *   - application/json: {"encrypted_secret": "...", "ttl": 86400, "max_views": 1}
     *   - text/plain (legacy): {hex_salt}${hex_verifier}${hex_secret}
     *
     * @param Logger $logger
     * @param Client $redisClient
     * @return CallableRequestHandler
     */
    public static function createSecret(Logger $logger, Client $redisClient): CallableRequestHandler
    {
        return new CallableRequestHandler(function(Request $request) use ($logger, $redisClient) {
            $data = yield $request->getBody()->read();
            if (strlen($data) > 100 * 1000) {
                $logger->error('Message payload too large!');
                return new Response(Status::PAYLOAD_TOO_LARGE, ['content-type' => 'text/plain'], 'Payload Too Large');
            }

            $contentType = $request->getHeader('content-type') ?? '';
            if (str_contains($contentType, 'application/json')) {
                $parsed = self::parseJsonCreateBody($data);
                if ($parsed === null) {
                    $logger->error('Unable to decode JSON creation request');
                    return new Response(
                        Status::BAD_REQUEST,
                        ['content-type' => 'application/json'],
                        json_encode(['error' => 'Bad Request'])
                    );
                }

                $secretID  = bin2hex(random_bytes(6));
                $ttl       = $parsed['ttl'];
                $maxViews  = $parsed['max_views'];
                $expiresAt = time() + $ttl;

                $redisClient->setex("json_secret:{$secretID}", $ttl, $parsed['encrypted_secret']);
                $redisClient->setex("json_views:{$secretID}", $ttl, $maxViews);
                $redisClient->setex("json_expires:{$secretID}", $ttl, $expiresAt);

                $logger->info(sprintf('Created JSON secret %s', $secretID));

                return new Response(
                    Status::CREATED,
                    ['content-type' => 'application/json'],
                    json_encode(['id' => $secretID, 'expires_at' => $expiresAt, 'max_views' => $maxViews])
                );
            }

            try {
                $secretRequest = CreateRequest::fromString($data);
            } catch (\Exception $e) {
                $logger->error('Unable to decode creation request');
                return new Response(Status::BAD_REQUEST, ['content-type' => 'text/plain'], 'Bad Request');
            }

            $secretID    = bin2hex(random_bytes(6));
            $secretKey   = "secret:{$secretID}";
            $verifierKey = "verifier:{$secretID}";

            $redisClient->setex($verifierKey, 24 * 60 * 60, $secretRequest->verifier());
            $redisClient->setex($secretKey, (24 * 60 * 60) + 30, $secretRequest->secret());

            $logger->info(sprintf('Created secret %s', $secretID));

            return new Response(Status::CREATED, ['content-type' => 'text/plain'], $secretID);
        });
    }

    /**
     * Handle API requests to retrieve a secret.
     *
     * Accepts either:
     *   - application/json: {"id": "..."}
     *   - text/plain (legacy): {secretID}${hex_verifier}
     *
     * @param Logger $logger
     * @param Client $redisClient
     * @return CallableRequestHandler
     */
    public static function retrieveSecret(Logger $logger, Client $redisClient): CallableRequestHandler
    {
        return new CallableRequestHandler(function (Request $request) use ($logger, $redisClient) {
            $data = yield $request->getBody()->read();

            $contentType = $request->getHeader('content-type') ?? '';
            if (str_contains($contentType, 'application/json')) {
                $secretID = self::parseJsonRetrieveBody($data);
                if ($secretID === null) {
                    $logger->error('Unable to decode JSON retrieval request');
                    return new Response(
                        Status::BAD_REQUEST,
                        ['content-type' => 'application/json'],
                        json_encode(['error' => 'Bad Request'])
                    );
                }

                $encryptedSecret = $redisClient->get("json_secret:{$secretID}");

                if ($encryptedSecret === null) {
                    $logger->error(sprintf('JSON secret %s was requested but was not found.', $secretID));
                    return new Response(
                        Status::NOT_FOUND,
                        ['content-type' => 'application/json'],
                        json_encode(['error' => 'Not found or expired'])
                    );
                }

                $viewsRemaining = (int) $redisClient->get("json_views:{$secretID}");
                $expiresAt      = (int) $redisClient->get("json_expires:{$secretID}");

                if ($viewsRemaining <= 1) {
                    $redisClient->del("json_secret:{$secretID}");
                    $redisClient->del("json_views:{$secretID}");
                    $redisClient->del("json_expires:{$secretID}");
                } else {
                    $redisClient->decr("json_views:{$secretID}");
                }

                $logger->info(sprintf('Retrieved JSON secret %s', $secretID));

                return new Response(
                    Status::OK,
                    ['content-type' => 'application/json'],
                    json_encode([
                        'encrypted_secret' => $encryptedSecret,
                        'views_remaining'  => max(0, $viewsRemaining - 1),
                        'expires_at'       => $expiresAt,
                    ])
                );
            }

            try {
                $retrievalRequest = RetrievalRequest::fromString($data);
            } catch (\Exception $e) {
                $logger->error('Unable to decode retrieval request');
                return new Response(Status::BAD_REQUEST, ['content-type' => 'text/plain'], 'Bad Request');
            }

            $secretID    = $retrievalRequest->ID();
            $secretKey   = "secret:{$secretID}";
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
}