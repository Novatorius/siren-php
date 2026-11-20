<?php

declare(strict_types=1);

namespace Siren\Sdk;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * {@see AppRequestHandler} as a PSR-15 request handler, for Slim, Mezzio
 * and any other framework that routes to one.
 *
 * ```php
 * $app->post('/siren', $answers->psr15());
 * ```
 *
 * It replies as {@see AppRequestHandler} says. It reads the raw body from
 * the request's stream, rewound first, so a middleware that read the stream
 * leaves it usable. A request whose stream is empty and whose parsed body is
 * set gets the 500 that asks for a raw body. For an ask it adds
 * `Content-Length` and `Connection: close` to the response, and runs the
 * work from a shutdown function, once the framework has sent the response,
 * after `fastcgi_finish_request()` where the server has it. With the
 * handler's `runLater`, the work goes there instead.
 *
 * RoadRunner, Swoole, Octane and FrankenPHP's worker mode keep one PHP
 * worker running for many requests, and run shutdown functions only when it
 * stops. On those servers pass `runLater`, a function that runs the work
 * once the response has gone, such as
 * `'runLater' => fn ($work) => app()->terminating($work)` under Octane or
 * `'runLater' => fn ($work) => \Swoole\Coroutine::create($work)` under
 * Swoole. Without it, the handler finds such a server where it can (by
 * `RR_MODE`, `LARAVEL_OCTANE`, `FRANKENPHP_WORKER` or a Swoole coroutine),
 * answers an ask 500 and tells `onError` to pass `runLater`, rather than hold
 * the answer until the worker stops.
 *
 * Needs `psr/http-server-handler`, and builds responses with the PSR-17
 * factories passed or the first of Guzzle's, Nyholm's, Slim's or Laminas's
 * that is installed.
 */
final class AppRequestPsrHandler implements RequestHandlerInterface
{
    private readonly ResponseFactoryInterface $responses;
    private readonly StreamFactoryInterface $streams;

    public function __construct(
        private readonly AppRequestHandler $handler,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        [$foundResponses, $foundStreams] = self::installedFactories();
        $responses = $responseFactory ?? $foundResponses;
        $streams = $streamFactory ?? $foundStreams;

        if ($responses === null || $streams === null) {
            throw new \LogicException(
                'apps->requestHandler()->psr15() found no PSR-17 factory. Install guzzlehttp/psr7 or nyholm/psr7, '
                . 'or pass a response factory and a stream factory.',
            );
        }

        $this->responses = $responses;
        $this->streams = $streams;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $stream = $request->getBody();

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $raw = '';

        // One byte past the limit is enough to refuse the body with 413.
        while ($stream->isReadable() && !$stream->eof() && strlen($raw) <= AppRequestHandler::BODY_LIMIT) {
            $chunk = $stream->read(AppRequestHandler::BODY_LIMIT + 1 - strlen($raw));

            if ($chunk === '') {
                break;
            }

            $raw .= $chunk;
        }

        $parsed = $request->getParsedBody();
        /** @var array<string, list<string>> $headers */
        $headers = $request->getHeaders();

        $reply = $this->handler->answerForShutdown(
            $request->getMethod(),
            $raw === '' && $parsed !== null ? (is_array($parsed) ? $parsed : null) : $raw,
            $headers,
        );

        $response = $this->responses->createResponse($reply->status);

        foreach ($reply->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        $after = $reply->after;

        if ($after !== null) {
            $response = $response
                ->withHeader('Content-Length', (string) strlen($reply->body))
                ->withHeader('Connection', 'close');

            register_shutdown_function(static function () use ($after): void {
                AppRequestHandler::finishThenRun($after);
            });
        }

        return $response->withBody($this->streams->createStream($reply->body));
    }

    /**
     * The first PSR-17 factory installed, from Guzzle, Nyholm, Slim or Laminas.
     *
     * @return array{0: ResponseFactoryInterface|null, 1: StreamFactoryInterface|null}
     */
    private static function installedFactories(): array
    {
        $pairs = [
            ['GuzzleHttp\\Psr7\\HttpFactory', 'GuzzleHttp\\Psr7\\HttpFactory'],
            ['Nyholm\\Psr7\\Factory\\Psr17Factory', 'Nyholm\\Psr7\\Factory\\Psr17Factory'],
            ['Slim\\Psr7\\Factory\\ResponseFactory', 'Slim\\Psr7\\Factory\\StreamFactory'],
            ['Laminas\\Diactoros\\ResponseFactory', 'Laminas\\Diactoros\\StreamFactory'],
        ];

        foreach ($pairs as [$responseClass, $streamClass]) {
            $responses = class_exists($responseClass) ? new $responseClass() : null;
            $streams = class_exists($streamClass) ? new $streamClass() : null;

            if ($responses instanceof ResponseFactoryInterface && $streams instanceof StreamFactoryInterface) {
                return [$responses, $streams];
            }
        }

        return [null, null];
    }
}
