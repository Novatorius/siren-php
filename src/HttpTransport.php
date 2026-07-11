<?php

declare(strict_types=1);

namespace Siren\Sdk;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Siren\Sdk\Exception\ConnectionException;
use Siren\Sdk\Exception\ExceptionFactory;

/**
 * Internal HTTP layer: signs requests, joins URLs, retries, and maps errors.
 *
 * Retries use exponential backoff and only apply to retryable operations
 * (reads and event ingestion) on network failures, 429s, and 5xx responses.
 * Management writes (create key, create subscription) never auto-retry.
 *
 * @internal
 */
final class HttpTransport
{
    public const SDK_VERSION = '0.1.0';

    private ClientInterface $client;
    private string $baseUrl;
    /** @var callable(float): void */
    private $sleeper;

    public function __construct(
        private readonly string $apiKey,
        string $baseUrl,
        private readonly float $timeout,
        private readonly int $maxRetries,
        ?ClientInterface $httpClient = null,
        ?callable $sleeper = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->client = $httpClient ?? new Client();
        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
    }

    /**
     * @param array<string, mixed>|null $json  JSON request body, or null for none.
     * @param array<string, mixed>      $query Query string parameters.
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

        $options = [
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::TIMEOUT => $this->timeout,
            RequestOptions::CONNECT_TIMEOUT => $this->timeout,
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept' => 'application/json',
                'User-Agent' => 'siren-php-sdk/' . self::SDK_VERSION,
            ],
        ];

        if ($json !== null) {
            $options[RequestOptions::JSON] = $json;
        }

        if ($query !== []) {
            $options[RequestOptions::QUERY] = $query;
        }

        $attempt = 0;

        while (true) {
            try {
                $response = $this->client->request($method, $url, $options);
            } catch (GuzzleException $e) {
                if ($retryable && $attempt < $this->maxRetries) {
                    $this->backOff($attempt++);
                    continue;
                }

                throw new ConnectionException(
                    sprintf('Could not connect to the Siren API (%s %s): %s', $method, $url, $e->getMessage()),
                    0,
                    null,
                    null,
                    $e,
                );
            }

            $status = $response->getStatusCode();

            if ($status < 400) {
                return new ApiResponse($status, $response->getHeaders(), (string) $response->getBody());
            }

            if ($retryable && $attempt < $this->maxRetries && ($status === 429 || $status >= 500)) {
                $retryAfterHeader = $response->getHeaderLine('Retry-After');
                $retryAfter = is_numeric($retryAfterHeader) ? (float) $retryAfterHeader : null;
                $this->backOff($attempt++, $retryAfter);
                continue;
            }

            throw ExceptionFactory::fromResponse($response);
        }
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
