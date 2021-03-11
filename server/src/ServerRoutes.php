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
     * Process a secret creation request and attempt to create the secret.
     *
     * @param Logger $logger
     * @param Client $redisClient
     * @return Callable
     */
    public static function createSecret(Logger $logger, Client $redisClient): CallableRequestHandler
    {
        return new CallableRequestHandler(function(Request $request) use ($logger, $redisClient) {
            $data = yield $request->getBody()->read();
            if (strlen($data) > 100 * 1000) {
                $logger->error('Message payload too large!');
                return new Response(Status::PAYLOAD_TOO_LARGE, ['content-type' => 'text/plain'], 'Payload Too Large');
            }

            try {
                $secretRequest = CreateRequest::fromString($data);
            } catch (\Exception $e) {
                $logger->error('Unable to decode creation request');
                return new Response(Status::BAD_REQUEST, ['content-type' => 'text/plain'], 'Bad Request');
            }

            $secretID = bin2hex(random_bytes(6));
            $secretKey = "secret:{$secretID}";
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
}