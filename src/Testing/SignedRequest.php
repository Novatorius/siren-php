<?php

declare(strict_types=1);

namespace Siren\Sdk\Testing;

/**
 * A request signed as Siren signs one, made by {@see AppRequests::make()}.
 * Hand its parts to the handler, `$answers->answer($r->method, $r->rawBody, $r->headers)`,
 * to `$answers->simulate($r)`, or post them to a running app with any HTTP client.
 */
final class SignedRequest
{
    /**
     * @param string                $method  Always `POST`.
     * @param array<string, string> $headers The headers Siren sends, signature included.
     * @param string                $rawBody The exact bytes the signature covers.
     */
    public function __construct(
        public readonly string $method,
        public readonly array $headers,
        public readonly string $rawBody,
    ) {
    }

    /** The request's `id`, `apr_` and 32 hex characters. */
    public function id(): string
    {
        $body = json_decode($this->rawBody, true);

        return is_array($body) && is_string($body['id'] ?? null) ? $body['id'] : '';
    }
}
