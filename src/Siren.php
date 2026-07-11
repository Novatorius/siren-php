<?php

declare(strict_types=1);

namespace Siren\Sdk;

use GuzzleHttp\ClientInterface;
use Siren\Sdk\Resources\ApiKeys;
use Siren\Sdk\Resources\Events;
use Siren\Sdk\Resources\ListResource;
use Siren\Sdk\Resources\Webhooks;

/**
 * The Siren API client.
 *
 * ```php
 * $siren = new Siren(['apiKey' => 'sk_live_...']);
 *
 * $result = $siren->events->sale([
 *     'source'     => 'stripe',
 *     'externalId' => 'cs_test_a1b2c3',
 *     'total'      => 49.99,
 *     'trackingId' => 4021,
 * ]);
 * ```
 *
 * Configuration keys:
 * - `apiKey` (required) — the `sk_live_...` key from the Siren dashboard.
 * - `baseUrl` — defaults to `https://api.sirenaffiliates.com/siren/v1`.
 * - `timeout` — request timeout in seconds, default 30.
 * - `maxRetries` — retries for network errors and 429/5xx on idempotent
 *   operations, default 2. Management writes (create key, create
 *   subscription) are never auto-retried.
 * - `httpClient` — an optional preconfigured `GuzzleHttp\ClientInterface`.
 */
class Siren
{
    public const DEFAULT_BASE_URL = 'https://api.sirenaffiliates.com/siren/v1';
    public const DEFAULT_TIMEOUT = 30.0;
    public const DEFAULT_MAX_RETRIES = 2;

    /** Ingest commerce and tracking events: `sale`, `refund`, `siteVisited`, `ingest`. */
    public readonly Events $events;

    /** Verify inbound webhook signatures and manage subscriptions. */
    public readonly Webhooks $webhooks;

    /** Manage API keys: `create`, `list`, `revoke`. */
    public readonly ApiKeys $apiKeys;

    /** Reconciliation reader for `GET /conversions`. */
    public readonly ListResource $conversions;

    /** Reconciliation reader for `GET /transactions`. */
    public readonly ListResource $transactions;

    /** Reconciliation reader for `GET /obligations`. */
    public readonly ListResource $obligations;

    /** Reconciliation reader for `GET /payouts`. */
    public readonly ListResource $payouts;

    /**
     * @param array{
     *     apiKey: string,
     *     baseUrl?: string,
     *     timeout?: float|int,
     *     maxRetries?: int,
     *     httpClient?: ClientInterface,
     * } $config
     */
    public function __construct(array $config)
    {
        $apiKey = $config['apiKey'] ?? null;

        if (!is_string($apiKey) || $apiKey === '') {
            throw new \InvalidArgumentException(
                'The "apiKey" option is required. Mint one in the Siren dashboard (Settings > API Keys).',
            );
        }

        $maxRetries = (int) ($config['maxRetries'] ?? self::DEFAULT_MAX_RETRIES);

        if ($maxRetries < 0) {
            throw new \InvalidArgumentException('The "maxRetries" option must be zero or greater.');
        }

        $transport = new HttpTransport(
            apiKey: $apiKey,
            baseUrl: (string) ($config['baseUrl'] ?? self::DEFAULT_BASE_URL),
            timeout: (float) ($config['timeout'] ?? self::DEFAULT_TIMEOUT),
            maxRetries: $maxRetries,
            httpClient: $config['httpClient'] ?? null,
            sleeper: $config['sleeper'] ?? null,
        );

        $this->events = new Events($transport);
        $this->webhooks = new Webhooks($transport);
        $this->apiKeys = new ApiKeys($transport);
        $this->conversions = new ListResource($transport, '/conversions');
        $this->transactions = new ListResource($transport, '/transactions');
        $this->obligations = new ListResource($transport, '/obligations');
        $this->payouts = new ListResource($transport, '/payouts');
    }
}
