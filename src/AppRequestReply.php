<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * What {@see AppRequestHandler::answer()} gives back for one request: the
 * reply to send Siren, and the work to run once it has gone.
 *
 * In a framework, send `$body` with `$status` and `$headers`, then run
 * `$after` when it is not null, after the response has gone:
 *
 * ```php
 * $reply = $answers->answer($request->method(), $request->getContent(), $request->headers->all());
 *
 * if ($reply->after !== null) {
 *     app()->terminating($reply->after);
 * }
 *
 * return response($reply->body, $reply->status, $reply->headers);
 * ```
 */
final class AppRequestReply
{
    /**
     * @param int                   $status  The HTTP status: 200, or 400, 401, 404, 405 or 500 with `{"error": "..."}`.
     * @param array<string, string> $headers `Content-Type: application/json` and `Cache-Control: no-store`, and `Allow` with a 405.
     * @param string                $body    The JSON to send: `{}`, or `{"error": "..."}`.
     * @param (\Closure(): void)|null $after An ask's work: run its function and post the answer it returns.
     *     Null for every other request, and when the handler's `runLater` already took the work.
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly ?\Closure $after = null,
    ) {
    }
}
