<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\EventResult;
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
 */
final class Events
{
    public function __construct(
        private readonly HttpTransport $transport,
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
     * Ingests a custom event type registered on the organization.
     *
     * The payload is passed through as the JSON body unchanged.
     *
     * @param array<string, mixed> $payload
     */
    public function ingest(string $slug, array $payload): EventResult
    {
        if ($slug === '') {
            throw new \InvalidArgumentException('events->ingest() requires a non-empty event slug.');
        }

        return $this->send($slug, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function send(string $slug, array $payload): EventResult
    {
        $response = $this->transport->request('POST', '/event/' . rawurlencode($slug), $payload);

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
