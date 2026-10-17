<?php

declare(strict_types=1);

namespace Siren\Sdk;

use Siren\Sdk\Auth\AccessTokenSource;
use Siren\Sdk\Exception\ConnectionException;
use Siren\Sdk\Exception\ExceptionFactory;
use Siren\Sdk\Http\HttpClient;
use Siren\Sdk\Http\HttpClientException;
use Siren\Sdk\Http\HttpRequest;
use Siren\Sdk\Http\HttpResponse;

/**
 * Internal HTTP layer: builds requests, signs them, retries, refreshes the
 * access token after a 401, and maps errors to typed exceptions.
 *
 * Retries use exponential backoff and only apply to retryable operations
 * (reads and event ingestion) on network failures, 429s, and 5xx responses.
 * Writes to records and management calls never auto-retry.
 *
 * A 401 is different: Siren rejected the credential before doing any work,
 * so the request is repeated once with a refreshed token whatever its kind.
 *
 * @internal
 */
final class HttpTransport
{
    public const SDK_VERSION = '0.2.0';

    private string $baseUrl;
    /** @var callable(float): void */
    private $sleeper;

    public function __construct(
        private readonly AccessTokenSource $tokens,
        string $baseUrl,
        private readonly float $timeout,
        private readonly int $maxRetries,
        private readonly HttpClient $client,
        ?callable $sleeper = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
    }

    /**
     * @param array<int|string, mixed>|null $json  JSON request body, or null for none.
     * @param array<string, mixed>          $query Query string parameters.
     * @param bool $retryable Whether network errors, 429s, and 5xx responses may be retried.
     *
     * @throws \Siren\Sdk\Exception\SirenException
     */
    public function request(
        string $method,
        string $path,
        ?array $json = null,
        array $query = [],
        bool $retryable = true,
    ): ApiResponse {
        $url = $this->baseUrl . $path;

        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'siren-php-sdk/' . self::SDK_VERSION,
        ];
        $body = null;

        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($json, JSON_THROW_ON_ERROR);
        }

        $token = $this->tokens->getAccessToken();
        $request = new HttpRequest($method, $url, $headers, $body, $this->timeout);
        $attempt = 0;
        $refreshed = false;

        while (true) {
            try {
                $response = $this->client->send($request->withHeader('Authorization', 'Bearer ' . $token));
            } catch (HttpClientException $e) {
                if ($retryable && $attempt < $this->maxRetries) {
                    $this->backOff($attempt++);
                    continue;
                }

                throw new ConnectionException(
                    sprintf('Could not connect to the Siren API (%s %s): %s', $method, $url, $e->getMessage()),
                    0,
                    null,
                    null,
                    $e->getPrevious() ?? $e,
                );
            }

            $status = $response->getStatusCode();

            if ($status < 400) {
                return new ApiResponse($status, $response->getHeaders(), $response->getBody());
            }

            if ($status === 401 && !$refreshed) {
                $refreshed = true;
                $fresh = $this->tokens->refreshAccessToken($token);

                if ($fresh !== null && $fresh !== '') {
                    $token = $fresh;
                    continue;
                }
            }

            if ($retryable && $attempt < $this->maxRetries && ($status === 429 || $status >= 500)) {
                $this->backOff($attempt++, $this->retryAfter($response));
                continue;
            }

            throw ExceptionFactory::fromResponse($response);
        }
    }

    private function retryAfter(HttpResponse $response): ?float
    {
        $header = $response->getHeaderLine('Retry-After');

        return is_numeric($header) ? (float) $header : null;
    }

    /**
     * Sleeps 0.5s, 1s, 2s, ... per attempt, honoring a larger Retry-After when given.
     */
    private function backOff(int $attempt, ?float $retryAfter = null): void
    {
        $delay = 0.5 * (2 ** $attempt);

        if ($retryAfter !== null && $retryAfter > $delay) {
            $delay = $retryAfter;
        }

        ($this->sleeper)($delay);
    }
}
