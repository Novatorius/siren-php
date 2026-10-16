<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Siren\Sdk\Http\HttpRequest;
use Siren\Sdk\Http\HttpResponse;
use Siren\Sdk\Siren;
use Siren\Sdk\Tests\Support\RecordingHttpClient;

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

    protected RecordingHttpClient $http;

    /**
     * Builds a Siren client on a host-supplied HttpClient double, the way a
     * WordPress or Drupal connector plugs in its own HTTP stack.
     *
     * @param list<HttpResponse|\Throwable> $responses Queued responses/exceptions.
     * @param array<string, mixed>          $config    Extra client config overrides.
     */
    protected function hostSiren(array $responses, array $config = []): Siren
    {
        $this->sleeps = [];
        $this->http = new RecordingHttpClient($responses);

        $defaults = [
            'httpClient' => $this->http,
            'sleeper' => function (float $seconds): void {
                $this->sleeps[] = $seconds;
            },
        ];

        if (!array_key_exists('accessToken', $config)) {
            $defaults['apiKey'] = self::API_KEY;
        }

        return new Siren(array_merge($defaults, $config));
    }

    protected function sent(): HttpRequest
    {
        return $this->http->last();
    }

    /** @return array<string, mixed> The query string of the last host request. */
    protected function sentQuery(): array
    {
        parse_str((string) parse_url($this->sent()->getUrl(), PHP_URL_QUERY), $query);

        return $query;
    }

    protected function sentPath(): string
    {
        return (string) parse_url($this->sent()->getUrl(), PHP_URL_PATH);
    }

    /** @return array<int|string, mixed> */
    protected function sentJson(): array
    {
        $body = $this->sent()->getBody();
        $this->assertNotNull($body, 'Expected the request to carry a body.');
        $decoded = json_decode($body, true);
        $this->assertIsArray($decoded, 'Expected the request body to be valid JSON.');

        return $decoded;
    }

    /**
     * @param array<int|string, mixed>|list<mixed> $body
     * @param array<string, string>                $headers
     */
    protected static function hostJson(int $status, array $body = [], array $headers = []): HttpResponse
    {
        return new HttpResponse(
            $status,
            $headers + ['Content-Type' => 'application/json'],
            $body === [] ? '' : json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * A page in Siren's list envelope.
     *
     * @param list<array<string, mixed>> $items
     */
    protected static function hostPage(array $items, int $total = 0, int $page = 1, int $perPage = 10): HttpResponse
    {
        return self::hostJson(200, [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $perPage > 0 && $total > 0 ? (int) ceil($total / $perPage) : 1,
        ]);
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
