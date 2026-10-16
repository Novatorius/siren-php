<?php

declare(strict_types=1);

namespace Siren\Sdk\Http;

/**
 * One outgoing HTTP request, fully built by the SDK.
 *
 * The URL already carries the query string, and the headers already carry
 * authorization, content type, and the user agent. An {@see HttpClient}
 * sends it as it is.
 */
final class HttpRequest
{
    /**
     * @param array<string, string> $headers Header name to value.
     * @param string|null           $body    Raw request body, or null for none.
     * @param float                 $timeout Seconds to allow. Guzzle applies it to the whole request, PHP streams to each read.
     */
    public function __construct(
        private readonly string $method,
        private readonly string $url,
        private readonly array $headers = [],
        private readonly ?string $body = null,
        private readonly float $timeout = 30.0,
    ) {
    }

    /** The HTTP method in upper case, such as `GET` or `POST`. */
    public function getMethod(): string
    {
        return $this->method;
    }

    /** The absolute URL, including any query string. */
    public function getUrl(): string
    {
        return $this->url;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /** Case-insensitive header lookup. Returns null when the header is absent. */
    public function getHeader(string $name): ?string
    {
        foreach ($this->headers as $header => $value) {
            if (strcasecmp($header, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function getTimeout(): float
    {
        return $this->timeout;
    }

    /** A copy of this request with one header replaced. */
    public function withHeader(string $name, string $value): self
    {
        $headers = [];

        foreach ($this->headers as $header => $existing) {
            if (strcasecmp($header, $name) !== 0) {
                $headers[$header] = $existing;
            }
        }

        $headers[$name] = $value;

        return new self($this->method, $this->url, $headers, $this->body, $this->timeout);
    }
}
