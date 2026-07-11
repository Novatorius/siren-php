<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\HttpTransport;

/**
 * API key management (`/api-keys`).
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
     * Required: `label`. Optional: `scopes` (omit for full access).
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
        if (!isset($params['label']) || $params['label'] === '') {
            throw new \InvalidArgumentException('The "label" field is required for apiKeys->create().');
        }

        $response = $this->transport->request('POST', '/api-keys', $params, [], retryable: false);

        return $response->json();
    }

    /**
     * @return list<array<string, mixed>> All API keys (without raw key material).
     */
    public function list(): array
    {
        $response = $this->transport->request('GET', '/api-keys');

        return array_values($response->json());
    }

    /**
     * Revokes an API key. Requests signed with it fail immediately.
     */
    public function revoke(int $id): void
    {
        $this->transport->request('DELETE', '/api-keys/' . $id);
    }
}
