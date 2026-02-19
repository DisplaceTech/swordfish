<?php

namespace Swordfish\Server;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Status;
use Monolog\Logger;
use Predis\Client;

class ServerRoutes
{
    /**
     * Create a callback that serves the SPA index.html for the main landing page.
     *
     * @param Logger $logger
     * @return CallableRequestHandler
     */
    public static function mainContent(Logger $logger): CallableRequestHandler
    {
        return new CallableRequestHandler(function() use ($logger): Response {
            $logger->info('Main page load');
            $html = file_get_contents(__DIR__ . '/../static/index.html');
            return new Response(Status::OK, ['content-type' => 'text/html'], $html);
        });
    }

    /**
     * Create a callback that serves the SPA index.html for secret retrieval routes.
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

            $html = file_get_contents(__DIR__ . '/../static/index.html');
            return new Response(Status::OK, ['content-type' => 'text/html'], $html);
        });
    }

    /**
     * Create a fallback handler that serves static assets from DocumentRoot and falls back
     * to the SPA index.html for any unmatched GET request (client-side routing support).
     *
     * @param Logger $logger
     * @param RequestHandler $documentRoot
     * @return CallableRequestHandler
     */
    public static function spaFallback(Logger $logger, RequestHandler $documentRoot): CallableRequestHandler
    {
        $indexHtml = file_get_contents(__DIR__ . '/../static/index.html');
        return new CallableRequestHandler(function(Request $request) use ($logger, $documentRoot, $indexHtml) {
            $response = yield $documentRoot->handleRequest($request);
            if ($request->getMethod() === 'GET' && $response->getStatus() === Status::NOT_FOUND) {
                $logger->info(sprintf('SPA fallback for %s', $request->getUri()->getPath()));
                return new Response(Status::OK, ['content-type' => 'text/html'], $indexHtml);
            }
            return $response;
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
     * Redirect POST /retrieve to POST /api/retrieve with a 307 Temporary Redirect.
     *
     * @param Logger $logger
     * @return CallableRequestHandler
     */
    public static function redirectRetrieve(Logger $logger): CallableRequestHandler
    {
        return new CallableRequestHandler(function() use ($logger): Response {
            $logger->info('Redirecting POST /retrieve to POST /api/retrieve');
            return new Response(Status::TEMPORARY_REDIRECT, ['location' => '/api/retrieve'], '');
        });
    }

    /**
     * Process a secret creation request and attempt to create the secret.
     *
     * @param Logger $logger
     * @param Client $redisClient
     * @return CallableRequestHandler
     */
    public static function createSecret(Logger $logger, Client $redisClient): CallableRequestHandler
    {
        $service = new SecretService($redisClient);
        return new CallableRequestHandler(function(Request $request) use ($logger, $service) {
            $data = yield $request->getBody()->read();
            if (strlen($data) > 100 * 1000) {
                $logger->error('Message payload too large!');
                return new Response(Status::PAYLOAD_TOO_LARGE, ['content-type' => 'text/plain'], 'Payload Too Large');
            }

            try {
                $secretRequest = CreateRequest::fromString($data);
            } catch (\InvalidArgumentException $e) {
                $logger->error('Invalid TTL in creation request: ' . $e->getMessage());
                return new Response(Status::UNPROCESSABLE_ENTITY, ['content-type' => 'application/json'], json_encode(['error' => $e->getMessage()]));
            } catch (\Exception $e) {
                $logger->error('Unable to decode creation request');
                return new Response(Status::BAD_REQUEST, ['content-type' => 'text/plain'], 'Bad Request');
            }

            $secretID = $service->create($secretRequest);
            $logger->info(sprintf('Created secret %s', $secretID));

            return new Response(Status::CREATED, ['content-type' => 'text/plain'], $secretID);
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
        $service = new SecretService($redisClient);
        return new CallableRequestHandler(function (Request $request) use ($logger, $service) {
            $data = yield $request->getBody()->read();

            try {
                $retrievalRequest = RetrievalRequest::fromString($data);
            } catch (\Exception $e) {
                $logger->error('Unable to decode retrieval request');
                return new Response(Status::BAD_REQUEST, ['content-type' => 'text/plain'], 'Bad Request');
            }

            $secretID = $retrievalRequest->ID();
            $verifier = $retrievalRequest->verifier();

            try {
                $secret = $service->retrieve($secretID, $verifier);
            } catch (InvalidVerifierException $e) {
                $logger->error(sprintf('Secret %s requested with an invalid password.', $secretID));
                return new Response(Status::UNAUTHORIZED, ['content-type' => 'text/plain'], 'Invalid authorization');
            } catch (SecretNotFoundException $e) {
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
        $service = new SecretService($redisClient);
        return new CallableRequestHandler(function (Request $request) use ($logger, $service) {
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

            $secretID = $parsed['id'];
            $verifier = $parsed['verifier'];

            try {
                $result = $service->retrieveJson($secretID, $verifier);
            } catch (InvalidVerifierException $e) {
                $logger->error(sprintf('JSON secret %s requested with an invalid verifier.', $secretID));
                return new Response(
                    Status::UNAUTHORIZED,
                    ['content-type' => 'application/json'],
                    json_encode(['error' => 'Invalid authorization'])
                );
            } catch (SecretNotFoundException $e) {
                $logger->error(sprintf('JSON secret %s was requested but was not found.', $secretID));
                return new Response(
                    Status::NOT_FOUND,
                    ['content-type' => 'application/json'],
                    json_encode(['error' => 'Not found or expired'])
                );
            }

            if ($result['views_remaining'] === 0) {
                $logger->info(sprintf('JSON secret %s views exhausted; deleted all keys.', $secretID));
            }

            $logger->info(sprintf('Retrieved JSON secret %s (%d views remaining).', $secretID, $result['views_remaining']));

            return new Response(
                Status::OK,
                ['content-type' => 'application/json'],
                json_encode($result)
            );
        });
    }
}