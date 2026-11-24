<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * What {@see AppRequestHandler::simulate()} found: the reply the handler
 * gives Siren, and for an ask the answer it would have posted.
 */
final class AppRequestSimulation
{
    /**
     * @param int                       $status  The reply's HTTP status.
     * @param array<string, string>     $headers The reply's headers.
     * @param string                    $body    The reply's JSON body.
     * @param array<string, mixed>|null $answer  The answer an ask's function gave, checked as the post would check it, or null for a tell or a failure.
     * @param \Throwable|null           $error   What `onError` would have heard, or null.
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly ?array $answer,
        public readonly ?\Throwable $error,
    ) {
    }
}
