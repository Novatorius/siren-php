<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Siren\Sdk\Siren;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    // Obviously-fake placeholder, constructed so no full key literal appears in
    // source (matches the style used elsewhere in the suite). Not a real credential.
    protected const API_KEY = 'sk_live_' . self::FAKE_KEY_BODY;
    private const FAKE_KEY_BODY = 'testtesttesttesttesttesttesttesttesttesttesttesttesttesttesttest';

    /** @var array<int, array{request: RequestInterface, options: array}> */
    protected array $history = [];

    protected MockHandler $mockHandler;

    /** @var list<float> Delays requested from the backoff sleeper. */
    protected array $sleeps = [];

    /**
     * Builds a Siren client whose HTTP layer is a Guzzle MockHandler.
     *
     * @param list<Response|\Throwable> $responses Queued responses/exceptions.
     * @param array<string, mixed>      $config    Extra client config overrides.
     */
    protected function mockSiren(array $responses, array $config = []): Siren
    {
        $this->history = [];
        $this->sleeps = [];
        $this->mockHandler = new MockHandler($responses);

        $stack = HandlerStack::create($this->mockHandler);
        $stack->push(Middleware::history($this->history));

        return new Siren(array_merge([
            'apiKey' => self::API_KEY,
            'httpClient' => new Client(['handler' => $stack]),
            'sleeper' => function (float $seconds): void {
                $this->sleeps[] = $seconds;
            },
        ], $config));
    }

    protected function lastRequest(): RequestInterface
    {
        $this->assertNotEmpty($this->history, 'Expected at least one HTTP request to have been sent.');

        return $this->history[array_key_last($this->history)]['request'];
    }

    /** @return array<string, mixed> */
    protected function lastRequestJson(): array
    {
        $decoded = json_decode((string) $this->lastRequest()->getBody(), true);
        $this->assertIsArray($decoded, 'Expected the request body to be valid JSON.');

        return $decoded;
    }

    protected static function jsonResponse(int $status, array $body = [], array $headers = []): Response
    {
        return new Response(
            $status,
            $headers + ['Content-Type' => 'application/json'],
            $body === [] ? '' : json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * A structured error body: `{"error": {"message", "code"?, "data"?}}`.
     * Used by login/entitlement endpoints.
     */
    protected static function errorResponse(
        int $status,
        string $message = 'Something went wrong.',
        ?string $code = null,
        ?array $data = null,
        array $headers = [],
    ): Response {
        $error = ['message' => $message];

        if ($code !== null) {
            $error['code'] = $code;
        }

        if ($data !== null) {
            $error['data'] = $data;
        }

        return self::jsonResponse($status, ['error' => $error], $headers);
    }

    /**
     * A plain-string error body: `{"error": "<message>"}`.
     * This is what most Siren endpoints emit.
     */
    protected static function stringErrorResponse(
        int $status,
        string $message = 'Something went wrong.',
        array $headers = [],
    ): Response {
        return self::jsonResponse($status, ['error' => $message], $headers);
    }
}
