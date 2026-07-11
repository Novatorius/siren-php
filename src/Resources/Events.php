<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\EventResult;
use Siren\Sdk\HttpTransport;

/**
 * Event ingestion — the primary integration point.
 *
 * Each helper wraps `POST /event/{slug}` and returns an {@see EventResult}
 * whose opportunity id is read from the `X-Siren-OID` response header.
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
     * 9.99 = $9.99), `trackingId`. Optional: `currency` (defaults to USD
     * server-side), `items` (each `{externalId?, name, quantity, amount}`,
     * `amount` per-unit in major units; a missing `quantity` defaults to 1).
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
     * sale exists.
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
     * Required: `collaboratorId`. Optional: `userId`.
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

        return new EventResult(is_numeric($oid) ? (int) $oid : null);
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
