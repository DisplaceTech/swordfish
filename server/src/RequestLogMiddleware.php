<?php

namespace Swordfish\Server;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Promise;
use Psr\Log\LoggerInterface;

use function Amp\call;

/**
 * Logs one line per request with the resolved client IP, path and status code.
 *
 * Amp's own connection logging only knows the socket peer, which behind
 * Cloudflare Tunnel is always a cloudflared pod. This line records the real
 * client so operators can see who is hitting what.
 *
 * Only the method, path (no query string), status, client IP, IP source and
 * socket peer are logged — never headers or bodies. Successful /health probes
 * are skipped to keep kubelet noise out of the log.
 */
class RequestLogMiddleware implements Middleware
{
    public function __construct(
        private LoggerInterface $logger,
        private ClientIpResolver $resolver
    ) {}

    public function handleRequest(Request $request, RequestHandler $requestHandler): Promise
    {
        return call(function () use ($request, $requestHandler) {
            $start  = hrtime(true);
            $client = $this->resolver->resolveWithSource($request);

            try {
                /** @var Response $response */
                $response = yield $requestHandler->handleRequest($request);
            } catch (\Throwable $e) {
                $this->log($request, 500, $client, $start);
                throw $e;
            }

            $this->log($request, $response->getStatus(), $client, $start);

            return $response;
        });
    }

    /**
     * @param array{ip: string, source: string, peer: string} $client
     */
    private function log(Request $request, int $status, array $client, int $start): void
    {
        $path = $request->getUri()->getPath();
        if ($path === '/health' && $status < 400) {
            return;
        }

        $message = sprintf(
            '%s %s %d client=%s via=%s peer=%s %.1fms',
            $request->getMethod(),
            $path,
            $status,
            $client['ip'],
            $client['source'],
            $client['peer'],
            (hrtime(true) - $start) / 1e6
        );

        if ($status >= 500) {
            $this->logger->error($message);
        } elseif ($status >= 400) {
            $this->logger->warning($message);
        } else {
            $this->logger->info($message);
        }
    }
}
