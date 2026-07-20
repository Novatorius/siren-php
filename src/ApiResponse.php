<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * A successful HTTP response from the Siren API.
 *
 * @internal
 */
final class ApiResponse
{
    /**
     * @param array<string, string[]> $headers PSR-7 style header map.
     */
    public function __construct(
        private readonly int $statusCode,
        private readonly array $headers,
        private readonly string $rawBody,
    ) {
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** Case-insensitive header lookup; returns the first value or null. */
    public function getHeaderLine(string $name): ?string
    {
        foreach ($this->headers as $header => $values) {
            if (strcasecmp($header, $name) === 0 && $values !== []) {
                return (string) $values[0];
            }
        }

        return null;
    }

    public function getRawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * The decoded JSON body, or an empty array when the body is empty or not JSON.
     *
     * The Siren API returns bare JSON — a plain array `[{...},{...}]` for list
     * endpoints and a plain object `{...}` for create/read-by-id endpoints.
     * There is no `{ "data": ... }` envelope to unwrap.
     *
     * @return array<int|string, mixed>
     */
    public function json(): array
    {
        if ($this->rawBody === '') {
            return [];
        }

        $decoded = json_decode($this->rawBody, true);

        return is_array($decoded) ? $decoded : [];
    }
}
