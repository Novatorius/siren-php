<?php

declare(strict_types=1);

namespace Siren\Sdk\Http;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

/**
 * An {@see HttpClient} backed by Guzzle.
 *
 * This is the default when `guzzlehttp/guzzle` is installed, and it is what
 * the SDK wraps when a caller passes a Guzzle `ClientInterface` as the
 * `httpClient` option, so existing integrations behave as they did before
 * the transport seam existed.
 */
final class GuzzleHttpClient implements HttpClient
{
    private ClientInterface $client;

    public function __construct(?ClientInterface $client = null)
    {
        $this->client = $client ?? new Client();
    }

    /** Whether Guzzle is installed, so this client can be built without arguments. */
    public static function isAvailable(): bool
    {
        return interface_exists(ClientInterface::class) && class_exists(Client::class);
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $options = [
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::TIMEOUT => $request->getTimeout(),
            RequestOptions::CONNECT_TIMEOUT => $request->getTimeout(),
            RequestOptions::HEADERS => $request->getHeaders(),
        ];

        if ($request->getBody() !== null) {
            $options[RequestOptions::BODY] = $request->getBody();
        }

        try {
            $response = $this->client->request($request->getMethod(), $request->getUrl(), $options);
        } catch (GuzzleException $e) {
            throw new HttpClientException($e->getMessage(), 0, $e);
        }

        $headers = [];

        foreach ($response->getHeaders() as $name => $values) {
            $headers[(string) $name] = array_values(array_map('strval', $values));
        }

        return new HttpResponse($response->getStatusCode(), $headers, (string) $response->getBody());
    }
}
