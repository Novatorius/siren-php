<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * What {@see AppRequestHandler::simulate()} found: the reply the handler
 * gives Siren, and for an ask the answer it would have posted. The Node and
 * Python SDKs answer the same four fields.
 */
final class AppRequestSimulation
{
    /**
     * @param int                       $status The reply's HTTP status.
     * @param array<string, mixed>      $body   The reply's JSON body, decoded: `[]` for `{}`, or `['error' => '...']`.
     * @param array<string, mixed>|null $answer The answer an ask's function gave, checked as Siren would check it. Null for a tell,
     *     after {@see AppRequestHandler::later()}, beside a refused answer, and on any failure.
     * @param \Throwable|null           $error  What `onError` would have heard, or null.
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly ?array $answer,
        public readonly ?\Throwable $error,
    ) {
    }
}
