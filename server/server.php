<?php

require __DIR__ . '/vendor/autoload.php';

use Amp\ByteStream\ResourceOutputStream;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\Server;
use Amp\Http\Status;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket;
use Monolog\Logger;

Amp\Loop::run(function () {
    $servers = [
        Socket\Server::listen("0.0.0.0:8080"),
        Socket\Server::listen("[::]:8080"),
    ];

    $redisClient = new Predis\Client([
        'scheme' => 'tcp',
        'host'   => 'redis',
        'port'   => 6379
    ]);

    $logHandler = new StreamHandler(new ResourceOutputStream(\STDOUT));
    $logHandler->setFormatter(new ConsoleFormatter);
    $logger = new Logger('server');
    $logger->pushHandler($logHandler);

    $router = new Router();

    /***********************************************************/
    /**  Web front-end API                                    **/
    /***********************************************************/

    $router->addRoute('GET', '/', new CallableRequestHandler(function () {
        return new Response(Status::OK, ['content-type' => 'text/plain'], 'Hello, world!');
    }));

    $router->addRoute('GET', '/secret', new CallableRequestHandler(function () {
        return new Response(Status::OK, ['content-type' => 'text/plain'], 'Moo, unknown');
    }));

    $router->addRoute('GET', '/secret/{secretId}', new CallableRequestHandler(function (Request $request) {
        $args = $request->getAttribute(Router::class);
        $secretId = $args['secretId'];

        return new Response(Status::CREATED, ['content-type' => 'text/plain'], 'Moo, ' . $secretId);
    }));

    /***********************************************************/
    /**  Server back-end API                                  **/
    /***********************************************************/

    $router->addRoute('POST', '/create', new CallableRequestHandler(function (Request $request) use ($logger, $redisClient) {
        $data = yield $request->getBody()->read();
        try {
          $secretRequest = \Swordfish\Server\CreateRequest::fromString($data);
        } catch (\Exception $e) {
            $logger->error('Unable to decode creation request');
            return new Response(Status::BAD_REQUEST, ['content-type' => 'text/plain'], 'Bad Request');
        }

        $secretID = bin2hex(random_bytes(6));
        $secretKey = "secret:{$secretID}";
        $verifierKey = "verifier:{$secretID}";

        $redisClient->setex($verifierKey, 24 * 60 * 60, $secretRequest->verifier());
        $redisClient->setex($secretKey, (24 * 60 * 60) + 30, $secretRequest->secret());

        return new Response(Status::CREATED, ['content-type' => 'text/plain'], $secretID);
    }));

    $router->addRoute('POST', '/retrieve', new CallableRequestHandler(function (Request $request) use ($logger, $redisClient) {
        $data = yield $request->getBody()->read();

        try {
            $retrievalRequest = \Swordfish\Server\RetrievalRequest::fromString($data);
        } catch (\Exception $e) {
            $logger->error('Unable to decode retrieval request');
            return new Response(Status::BAD_REQUEST, ['content-type' => 'text/plain'], 'Bad Request');
        }

        $secretID = $retrievalRequest->ID();
        $secretKey = "secret:{$secretID}";
        $verifierKey = "verifier:{$secretID}";

        $hash = $redisClient->get($verifierKey);
        if ($hash === null) {
            return new Response(Status::NOT_FOUND, ['content-type' => 'text/plain'], 'Not found or expired');
        }

        if (!$retrievalRequest->verify_password($hash)) {
            return new Response(Status::UNAUTHORIZED, ['content-type' => 'text/plain'], 'Invalid authorization');
        }

        $secret = $redisClient->get($secretKey);
        if ($secret === null) {
            return new Response(Status::NOT_FOUND, ['content-type' => 'text/plain'], 'Not found or expired');
        }

        return new Response(Status::OK, ['content-type' => 'text/plain'], $secret);
    }));

    $server = new Server($servers, $router, $logger);
    yield $server->start();

    // Stop the server when SIGINT is received (this is technically optional, but it is best to call Server::stop()).
    Amp\Loop::onSignal(SIGINT, function (string $watcherId) use ($server) {
        Amp\Loop::cancel($watcherId);
        yield $server->stop();
    });
});
