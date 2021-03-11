<?php

require __DIR__ . '/vendor/autoload.php';

use Amp\ByteStream\ResourceOutputStream;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\Server;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Http\Status;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket;
use Monolog\Logger;
use Swordfish\Server\ServerRoutes;

Amp\Loop::run(function () {
    $logHandler = new StreamHandler(new ResourceOutputStream(\STDOUT));
    $logHandler->setFormatter(new ConsoleFormatter);
    $logger = new Logger('server');
    $logger->pushHandler($logHandler);

    $servers = [
        Socket\Server::listen(sprintf('0.0.0.0:%d', getenv('SERVER_PORT'))),
        Socket\Server::listen(sprintf('[::]:%d', getenv('SERVER_PORT'))),
    ];

    $logger->info(sprintf('Connecting to Redis %s:%d', getenv('REDIS_HOST'), getenv('REDIS_PORT')));
    $redisClient = new Predis\Client([
        'scheme' => 'tcp',
        'host'   => getenv('REDIS_HOST'),
        'port'   => getenv('REDIS_PORT')
    ]);

    $documentRoot = new DocumentRoot(__DIR__ . '/static');
    $router = new Router();

    /***********************************************************/
    /**  Web front-end API                                    **/
    /***********************************************************/

    $router->addRoute('GET', '/', ServerRoutes::mainContent($logger));
    $router->addRoute('GET', '/secret', ServerRoutes::secretRetrieval($logger));
    $router->addRoute('GET', '/secret/{secretId}', ServerRoutes::secretRetrieval($logger));

    /***********************************************************/
    /**  Server back-end API                                  **/
    /***********************************************************/

    $router->addRoute('POST', '/create', ServerRoutes::createSecret($logger, $redisClient));
    $router->addRoute('POST', '/retrieve', ServerRoutes::retrieveSecret($logger, $redisClient));

    $router->setFallback($documentRoot);

    $server = new Server($servers, $router, $logger);
    yield $server->start();

    // Stop the server when SIGINT is received (this is technically optional, but it is best to call Server::stop()).
    Amp\Loop::onSignal(SIGINT, function (string $watcherId) use ($server) {
        Amp\Loop::cancel($watcherId);
        yield $server->stop();
    });
});
