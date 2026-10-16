<?php

declare(strict_types=1);

namespace Siren\Sdk\Http;

/**
 * The HTTP response an {@see HttpClient} hands back to the SDK.
 */
final class HttpResponse
{
    /**
     * @param array<string, string|list<string>> $headers Header name to one value or a list of values.
     */
    public function __construct(
        private readonly int $statusCode,
        private readonly array $headers = [],
        private readonly string $body = '',
    ) {
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Every header as a list of values, keyed by the name the client gave.
     *
     * @return array<string, list<string>>
     */
    public function getHeaders(): array
    {
        $normalized = [];

        foreach ($this->headers as $name => $value) {
            $normalized[(string) $name] = is_array($value) ? array_values(array_map('strval', $value)) : [(string) $value];
        }

        return $normalized;
    }

    /** Case-insensitive lookup of a header's first value. Returns an empty string when absent. */
    public function getHeaderLine(string $name): string
    {
        foreach ($this->getHeaders() as $header => $values) {
            if (strcasecmp($header, $name) === 0 && $values !== []) {
                return $values[0];
            }
        }

        return '';
    }

    public function getBody(): string
    {
        return $this->body;
    }
}
