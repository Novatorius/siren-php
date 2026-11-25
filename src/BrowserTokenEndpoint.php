<?php

declare(strict_types=1);

namespace Siren\Sdk;

use Siren\Sdk\Exception\SirenException;
use Siren\Sdk\Resources\Events;

/**
 * The browser token address on the app's own server, with no framework.
 *
 * A page asks it for a browser token with a JSON body `{"event": "..."}` or
 * the query `?event=`. It answers:
 *
 * - 200 `{token, expiresIn, url}` from {@see Events::browserToken()}.
 * - 400 `{error}` when the request names no event, names one outside the
 *   `events` list, or names one Siren refuses a browser token for
 *   (`invalid_scope`: no such event of the app, or it allows no domain).
 * - 502 `{error}` when Siren can't be reached or refuses the app's key.
 *
 * {@see Events::serveBrowserToken()} runs it on plain PHP and
 * {@see Events::browserTokenHandler()} wraps it as a PSR-15 handler. Call
 * {@see answer()} yourself to mount it in any other framework.
 */
final class BrowserTokenEndpoint
{
    /** @var list<string>|null */
    private readonly ?array $events;

    /**
     * @param array{events?: list<string>|null} $options `events`: the only event keys a page may ask for.
     */
    public function __construct(
        private readonly Events $siren,
        array $options = [],
    ) {
        $events = $options['events'] ?? null;

        if ($events !== null && (!is_array($events) || !array_is_list($events))) {
            throw new \InvalidArgumentException('The "events" option must be a list of event keys.');
        }

        $this->events = $events === null ? null : array_map('strval', $events);
    }

    /**
     * Answers one request.
     *
     * @param string|array<mixed>|null $body  The raw request body, or a body the framework already decoded.
     * @param array<mixed>             $query The query parameters.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function answer(string|array|null $body, array $query = []): array
    {
        $event = self::eventFrom($body, $query);

        if ($event === null) {
            return self::reply(400, ['error' => 'Name the event as {"event": "..."} in a JSON body or as ?event= in the query.']);
        }

        if ($this->events !== null && !in_array($event, $this->events, true)) {
            return self::reply(400, ['error' => 'This server gives no browser token for that event.']);
        }

        try {
            return self::reply(200, $this->siren->browserToken($event)->toArray());
        } catch (SirenException $e) {
            // `invalid_scope` is Siren refusing this event: the app has no
            // such event, or the event allows no domain. Anything else is
            // the server's own trouble, which the page can't fix.
            if ($e->getErrorCode() === 'invalid_scope') {
                return self::reply(400, ['error' => $e->getMessage()]);
            }

            return self::reply(502, ['error' => 'Siren could not be reached for a browser token. Try again shortly.']);
        }
    }

    /**
     * Answers the current plain-PHP request: reads the body and `$_GET`,
     * then sets the status and headers and echoes the JSON.
     */
    public function serve(): void
    {
        $body = file_get_contents('php://input');
        $answer = $this->answer($body === false ? null : $body, $_GET);

        if (!headers_sent()) {
            http_response_code($answer['status']);
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
        }

        echo json_encode($answer['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param string|array<mixed>|null $body
     * @param array<mixed>             $query
     */
    private static function eventFrom(string|array|null $body, array $query): ?string
    {
        if (is_string($body) && trim($body) !== '') {
            $decoded = json_decode($body, true);
            $body = is_array($decoded) ? $decoded : null;
        }

        $event = is_array($body) && is_string($body['event'] ?? null) ? $body['event'] : ($query['event'] ?? null);

        return is_string($event) && trim($event) !== '' ? trim($event) : null;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private static function reply(int $status, array $body): array
    {
        return ['status' => $status, 'body' => $body];
    }
}
