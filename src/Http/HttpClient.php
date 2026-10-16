<?php

declare(strict_types=1);

namespace Siren\Sdk\Http;

/**
 * The SDK's HTTP seam. A host that brings its own HTTP stack implements this
 * one method and passes the instance as the `httpClient` option.
 *
 * ```php
 * $siren = new Siren([
 *     'apiKey'     => 'sk_live_...',
 *     'httpClient' => new MyHostHttpClient(),
 * ]);
 * ```
 *
 * The contract is deliberately small:
 *
 * - Send the request exactly as given: method, URL (query string already
 *   built), headers, and body. Honor `getTimeout()` where the host can.
 * - Return an {@see HttpResponse} for every HTTP status, including 4xx and
 *   5xx. The SDK maps error statuses to its own exceptions and decides what
 *   to retry.
 * - Throw {@see HttpClientException} only when no HTTP response arrived at
 *   all: DNS failure, refused connection, TLS error, timeout. The SDK may
 *   retry these.
 *
 * The SDK ships two implementations: {@see GuzzleHttpClient} (used by default
 * when `guzzlehttp/guzzle` is installed) and {@see StreamHttpClient} (plain
 * PHP streams, no dependencies).
 */
interface HttpClient
{
    /**
     * @throws HttpClientException When no HTTP response was received.
     */
    public function send(HttpRequest $request): HttpResponse;
}
