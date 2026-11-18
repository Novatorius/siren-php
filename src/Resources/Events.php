<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\Auth\AppTokens;
use Siren\Sdk\BrowserToken;
use Siren\Sdk\BrowserTokenEndpoint;
use Siren\Sdk\BrowserTokenHandler;
use Siren\Sdk\EventResult;
use Siren\Sdk\Exception\SirenException;
use Siren\Sdk\HttpTransport;

/**
 * Event ingestion — the primary integration point.
 *
 * Each helper wraps `POST /event/{slug}` and returns an {@see EventResult}.
 * Its opportunity id is read from the `X-Siren-OID` response header, and it
 * carries what Siren reports in the body, such as a sale's status and
 * transaction id.
 *
 * On Siren Cloud an organization API key may send every event its scopes
 * name. A connected site's OAuth token may send only the events its
 * integrations forward and `site-visited`. While the organization's engine
 * is paused, Siren queues the event and answers 202, which
 * {@see EventResult::isQueued()} reports. Do not send a queued event again.
 *
 * A custom event takes only a short-lived app token, which {@see ingest()}
 * gets from the app's key and keeps fresh on its own. For a page that sends
 * a custom event from the browser, see {@see browserToken()}.
 */
final class Events
{
    /**
     * @param AppTokens|null $appTokens Null for a client built with an access token, which sends with that token.
     */
    public function __construct(
        private readonly HttpTransport $transport,
        private readonly ?AppTokens $appTokens = null,
    ) {
    }

    /**
     * Records a completed sale so conversions and payouts compute.
     *
     * Required: `source`, `externalId`, `total` (major currency units,
     * 9.99 = $9.99), `trackingId`. Optional: `currency`, a currency code the
     * organization uses (without it the sale is recorded in the
     * organization's default currency), and `items` (each
     * `{externalId?, name?, quantity?, amount}`, `amount` per-unit in major
     * units; a missing `quantity` defaults to 1).
     *
     * {@see EventResult::getStatus()} says what Siren recorded, one of
     * `EventStatus::CREATED`, `RECORDED`, `DUPLICATE`, `UNATTRIBUTED` or
     * `IGNORED`. Sending the same `(source, externalId)` again is safe and
     * answers `DUPLICATE`.
     *
     * @param array<string, mixed> $params
     */
    public function sale(array $params): EventResult
    {
        $this->requireFields($params, ['source', 'externalId', 'total', 'trackingId'], 'events->sale()');

        if (isset($params['items']) && is_array($params['items'])) {
            $params['items'] = array_map(
                static fn (array $item): array => $item + ['quantity' => 1],
                $params['items'],
            );
        }

        return $this->send('sale', $params);
    }

    /**
     * Reverses a previously recorded sale, matched by `(externalId, source)`.
     *
     * Required: `source`, `externalId` — both must match the original sale.
     * Throws {@see \Siren\Sdk\Exception\NotFoundException} when no matching
     * sale exists. A refund sent while the engine is paused is queued and
     * answers 202 without that check.
     *
     * @param array<string, mixed> $params
     */
    public function refund(array $params): EventResult
    {
        $this->requireFields($params, ['source', 'externalId'], 'events->refund()');

        return $this->send('refund', $params);
    }

    /**
     * Records a referred visit, opening an opportunity for the collaborator.
     *
     * Required: `collaboratorId`, a collaborator id or a tracking code such as
     * `tracking:summer-sale`. Optional: `userId`.
     *
     * {@see EventResult::getStatus()} is `EventStatus::ATTRIBUTED`, `RECORDED`
     * or `NOT_ATTRIBUTED`, and {@see EventResult::getReason()} says why a
     * visit was not attributed. A queued visit still returns the opportunity
     * it will be recorded on.
     *
     * @param array<string, mixed> $params
     */
    public function siteVisited(array $params): EventResult
    {
        $this->requireFields($params, ['collaboratorId'], 'events->siteVisited()');

        return $this->send('site-visited', $params);
    }

    /**
     * Sends a custom event of the app.
     *
     * The payload is passed through as the JSON body unchanged.
     *
     * The send carries a short-lived app token, not the key. The client gets
     * one from the key the first time, keeps it, and gets a new one when
     * fewer than two minutes of its life are left. A send Siren answers with
     * 401 drops the token and goes again once with a new one. A key that
     * belongs to no app sends with the key, and a client built with
     * `accessToken` sends with that token.
     *
     * @param array<string, mixed> $payload
     */
    public function ingest(string $slug, array $payload): EventResult
    {
        if ($slug === '') {
            throw new \InvalidArgumentException('events->ingest() requires a non-empty event slug.');
        }

        $token = $this->appTokens?->serverToken();

        if ($token === null) {
            return $this->send($slug, $payload);
        }

        try {
            return $this->send($slug, $payload, $token);
        } catch (SirenException $e) {
            if ($e->getStatusCode() !== 401 || $this->appTokens === null) {
                throw $e;
            }

            $this->appTokens->dropServerToken($token);
            $fresh = $this->appTokens->serverToken();

            if ($fresh === null) {
                throw $e;
            }

            return $this->send($slug, $payload, $fresh);
        }
    }

    /**
     * A browser token for one custom event of the app, for a page to send
     * that event with. Call it on the app's server, never in the page.
     *
     * The token lasts ten minutes and works only for this event, from a
     * domain the event allows. The client reuses it until fewer than 60
     * seconds are left, so a busy page costs one grant every nine minutes
     * or so.
     *
     * @throws \LogicException When the client was built with an access token, which can't get one.
     * @throws SirenException   When Siren refuses: `invalid_scope` for an event the app lacks or
     *                          one that allows no domain.
     */
    public function browserToken(string $eventKey): BrowserToken
    {
        if ($eventKey === '') {
            throw new \InvalidArgumentException('events->browserToken() requires a non-empty event key.');
        }

        if ($this->appTokens === null) {
            throw new \LogicException('events->browserToken() needs a client built with the app\'s key ("apiKey").');
        }

        return $this->appTokens->browserToken($eventKey);
    }

    /**
     * The browser token address as a PSR-15 handler, mounted in one line:
     *
     * ```php
     * $app->map(['GET', 'POST'], '/siren/token', $siren->events->browserTokenHandler(['events' => ['demo-booked']]));
     * ```
     *
     * It reads the event key from a JSON body `{"event": "..."}` or the
     * query `?event=`, and answers 200 `{token, expiresIn, url}`, 400
     * `{error}` for a missing or refused event, or 502 when Siren can't be
     * reached. See {@see BrowserTokenEndpoint}.
     *
     * @param array{
     *     events?: list<string>|null,
     *     responseFactory?: \Psr\Http\Message\ResponseFactoryInterface,
     *     streamFactory?: \Psr\Http\Message\StreamFactoryInterface,
     * } $options `events`: the only event keys a page may ask for.
     */
    public function browserTokenHandler(array $options = []): BrowserTokenHandler
    {
        return new BrowserTokenHandler(
            new BrowserTokenEndpoint($this, $options),
            $options['responseFactory'] ?? null,
            $options['streamFactory'] ?? null,
        );
    }

    /**
     * Answers the current request as the browser token address, for a site
     * with no framework. Put it in a file of its own, such as
     * `siren-token.php`:
     *
     * ```php
     * $siren->events->serveBrowserToken(['events' => ['demo-booked']]);
     * ```
     *
     * It reads the request body and `$_GET`, sets the status and headers and
     * echoes the JSON, answering as {@see browserTokenHandler()} does.
     *
     * @param array{events?: list<string>|null} $options
     */
    public function serveBrowserToken(array $options = []): void
    {
        (new BrowserTokenEndpoint($this, $options))->serve();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function send(string $slug, array $payload, ?string $token = null): EventResult
    {
        $response = $this->transport->request('POST', '/event/' . rawurlencode($slug), $payload, bearer: $token);

        $oid = $response->getHeaderLine('X-Siren-OID');
        $body = $response->json();

        return new EventResult(
            is_string($oid) && ctype_digit($oid) ? (int) $oid : null,
            $response->getStatusCode(),
            array_is_list($body) ? [] : $body,
        );
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string>         $fields
     */
    private function requireFields(array $params, array $fields, string $method): void
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $params) || $params[$field] === null || $params[$field] === '') {
                throw new \InvalidArgumentException(
                    sprintf('The "%s" field is required for %s.', $field, $method),
                );
            }
        }
    }
}
