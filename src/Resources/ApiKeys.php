<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\HttpTransport;

/**
 * API key management (`/api-keys`).
 *
 * These routes take a signed-in dashboard session's access token. No API
 * key scope reaches them, so Siren answers an API key with a 401 that the
 * SDK raises as {@see \Siren\Sdk\Exception\ApiKeyNotAcceptedException}.
 */
final class ApiKeys
{
    public function __construct(
        private readonly HttpTransport $transport,
    ) {
    }

    /**
     * Creates a new API key.
     *
     * Required: `scopes`, a non-empty list from {@see \Siren\Sdk\ApiKeyScope}.
     * Event scopes such as `ApiKeyScope::ALL_EVENTS` let the key send events.
     * The ledger scopes, such as `ApiKeyScope::LEDGER_CONVERSIONS_READ`, let
     * it read conversions, transactions, obligations and payouts, and
     * `ApiKeyScope::WEBHOOK_SUBSCRIPTIONS_READ` and
     * `ApiKeyScope::WEBHOOK_SUBSCRIPTIONS_MANAGE` let it read and manage
     * webhook subscriptions. Siren refuses the older resource scopes, such
     * as `programs:read` or `conversions:read`, with a 400.
     *
     * Optional: `label`, `expiresAt` (an ISO 8601 date in the future), and
     * `propertyId`, which binds the key to one of the organization's
     * Properties. Siren stamps that store on the key's events, the key reads
     * only that store's conversions, transactions and obligations, and it is
     * refused on payouts and webhook subscriptions.
     *
     * The returned array includes `rawKey` (`sk_live_...`) — it is returned
     * ONCE and cannot be retrieved later. This call is never auto-retried.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed> The created key, including `rawKey`.
     */
    public function create(array $params): array
    {
        $scopes = $params['scopes'] ?? null;

        if (!is_array($scopes) || $scopes === []) {
            throw new \InvalidArgumentException(
                'The "scopes" field is required for apiKeys->create(). Pass a list of scopes, '
                . 'such as [ApiKeyScope::ALL_EVENTS] for every event or [ApiKeyScope::LEDGER_CONVERSIONS_READ].',
            );
        }

        $response = $this->transport->request('POST', '/api-keys', $params, [], retryable: false);

        return $response->json();
    }

    /**
     * Lists API keys, without raw key material.
     *
     * Siren pages this list with `number` (1 to 200, default 200) and
     * `offset`. The total is in the `x-siren-estimated-count` header.
     *
     * @param array{number?: int, offset?: int} $params
     *
     * @return list<array<string, mixed>>
     */
    public function list(array $params = []): array
    {
        $response = $this->transport->request('GET', '/api-keys', null, ListResource::query($params));

        return array_values($response->json());
    }

    /**
     * The scopes a new key may be given, grouped by resource, from
     * `GET /api-keys/scopes`, as
     * `{"events": [...], "ledger": [...], "webhook-subscriptions": [...]}`.
     * Each entry is `{scope, resource, action, label}`.
     *
     * @return array<string, mixed>
     */
    public function scopes(): array
    {
        /** @var array<string, mixed> */
        return $this->transport->request('GET', '/api-keys/scopes')->json();
    }

    /**
     * Revokes an API key. Requests signed with it fail immediately.
     */
    public function revoke(int $id): void
    {
        $this->transport->request('DELETE', '/api-keys/' . $id);
    }
}
