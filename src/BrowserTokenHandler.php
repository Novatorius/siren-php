<?php

declare(strict_types=1);

namespace Siren\Sdk;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The browser token address as a PSR-15 request handler, for Slim, Mezzio
 * and any other framework that routes to one.
 *
 * ```php
 * $app->map(['GET', 'POST'], '/siren/token', $siren->events->browserTokenHandler(['events' => ['demo-booked']]));
 * ```
 *
 * It answers as {@see BrowserTokenEndpoint} says. It builds responses with
 * the PSR-17 factories passed as `responseFactory` and `streamFactory`, or
 * finds Guzzle's, Nyholm's, Slim's or Laminas's when one is installed.
 * Needs `psr/http-server-handler`.
 */
final class BrowserTokenHandler implements RequestHandlerInterface
{
    private readonly ResponseFactoryInterface $responses;
    private readonly StreamFactoryInterface $streams;

    public function __construct(
        private readonly BrowserTokenEndpoint $endpoint,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        [$foundResponses, $foundStreams] = self::installedFactories();
        $responses = $responseFactory ?? $foundResponses;
        $streams = $streamFactory ?? $foundStreams;

        if ($responses === null || $streams === null) {
            throw new \LogicException(
                'browserTokenHandler() found no PSR-17 factory. Install guzzlehttp/psr7 or nyholm/psr7, '
                . 'or pass "responseFactory" and "streamFactory".',
            );
        }

        $this->responses = $responses;
        $this->streams = $streams;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $parsed = $request->getParsedBody();
        $answer = $this->endpoint->answer(
            is_array($parsed) ? $parsed : (string) $request->getBody(),
            $request->getQueryParams(),
        );

        return $this->responses->createResponse($answer['status'])
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->streams->createStream(json_encode($answer['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
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
            $responses = self::make($responseClass);
            $streams = self::make($streamClass);

            if ($responses instanceof ResponseFactoryInterface && $streams instanceof StreamFactoryInterface) {
                return [$responses, $streams];
            }
        }

        return [null, null];
    }

    private static function make(string $class): ?object
    {
        return class_exists($class) ? new $class() : null;
    }
}
