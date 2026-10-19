<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\HttpTransport;

/**
 * API key management (`/api-keys`).
 *
 * These routes take a signed-in dashboard session's access token. An API
 * key cannot manage keys, because Siren Cloud accepts API keys on the event
 * routes only.
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
     * Required: `scopes`, a non-empty list of event scopes such as
     * `[ApiKeyScope::ALL_EVENTS]` (see {@see \Siren\Sdk\ApiKeyScope}). Siren
     * refuses a record scope such as `programs:read`, because a key only
     * sends events. Optional: `label`, `expiresAt` (an ISO 8601 date in the
     * future), and `propertyId`, which stamps the key's events with one of
     * the organization's Properties.
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
                'The "scopes" field is required for apiKeys->create(). Pass a list of event scopes, '
                . 'such as [ApiKeyScope::ALL_EVENTS] for every event.',
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
     * `GET /api-keys/scopes`. Siren offers the event scopes alone, as
     * `{"events": [{scope, resource, action, label}, ...]}`.
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
