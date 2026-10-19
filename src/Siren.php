<?php

declare(strict_types=1);

namespace Siren\Sdk;

use Siren\Sdk\Auth\AccessTokenSource;
use Siren\Sdk\Auth\StaticAccessToken;
use Siren\Sdk\Http\GuzzleHttpClient;
use Siren\Sdk\Http\HttpClient;
use Siren\Sdk\Http\StreamHttpClient;
use Siren\Sdk\Resources\ApiKeys;
use Siren\Sdk\Resources\CollaboratorGroups;
use Siren\Sdk\Resources\Collaborators;
use Siren\Sdk\Resources\Conversions;
use Siren\Sdk\Resources\Credits;
use Siren\Sdk\Resources\CustomCurrencies;
use Siren\Sdk\Resources\Distributors;
use Siren\Sdk\Resources\Engagements;
use Siren\Sdk\Resources\Events;
use Siren\Sdk\Resources\ListResource;
use Siren\Sdk\Resources\Mappings;
use Siren\Sdk\Resources\Opportunities;
use Siren\Sdk\Resources\ProgramGroups;
use Siren\Sdk\Resources\Programs;
use Siren\Sdk\Resources\Settings;
use Siren\Sdk\Resources\Transactions;
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
 * - `apiKey` — the `sk_live_...` key from the Siren dashboard. Required
 *   unless `accessToken` is given.
 * - `accessToken` — an {@see AccessTokenSource} (or a plain token string)
 *   for hosts that hold an OAuth grant. The SDK refreshes it once after a
 *   401 and repeats the request.
 * - `baseUrl` — defaults to `https://api.sirenaffiliates.com/siren/v1`.
 * - `timeout` — request timeout in seconds, default 30.
 * - `maxRetries` — retries for network errors and 429/5xx on idempotent
 *   operations, default 2. Writes and management calls are never
 *   auto-retried.
 * - `httpClient` — the HTTP stack to send requests through: an
 *   {@see HttpClient} the host implements, or a preconfigured
 *   `GuzzleHttp\ClientInterface`. Without one, the SDK uses Guzzle when it
 *   is installed and PHP streams when it is not.
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

    /** Conversions: list, read, create, update, delete, and bulk actions. */
    public readonly Conversions $conversions;

    /** Transactions and their details: list, read, create, and bulk actions. */
    public readonly Transactions $transactions;

    /**
     * Reconciliation reader for `GET /obligations`. Filters: `id`,
     * `collaboratorId`, `status`, `awardType`, `payoutId`, `opportunityId`.
     */
    public readonly ListResource $obligations;

    /**
     * Reconciliation reader for `GET /payouts`. Filters: `id`,
     * `fulfillmentId`, `collaboratorId`, `status`, `currency`. A connected
     * site must send `id`, `collaboratorId` or `fulfillmentId`, or Siren
     * Cloud answers 403 with `site_filter_required`.
     */
    public readonly ListResource $payouts;

    /** Collaborators, with their tracking codes (`->aliases`) and program enrollments (`->enrollments`). */
    public readonly Collaborators $collaborators;

    /** Programs. */
    public readonly Programs $programs;

    /** Program groups. */
    public readonly ProgramGroups $programGroups;

    /** Opportunities: list, read, and update. */
    public readonly Opportunities $opportunities;

    /** Engagements: list and read. */
    public readonly Engagements $engagements;

    /** Distributors. */
    public readonly Distributors $distributors;

    /** Custom currencies, keyed by code. */
    public readonly CustomCurrencies $customCurrencies;

    /** Store credit: balances, ledger entries, holds, and adjustments. */
    public readonly Credits $credits;

    /** Links between collaborators and records outside Siren, such as WordPress users and products. */
    public readonly Mappings $mappings;

    /** The organization settings Siren shares with a connected site. */
    public readonly Settings $settings;

    /** Collaborator groups and their members (`->members`). */
    public readonly CollaboratorGroups $collaboratorGroups;

    /**
     * @param array{
     *     apiKey?: string,
     *     accessToken?: AccessTokenSource|string,
     *     baseUrl?: string,
     *     timeout?: float|int,
     *     maxRetries?: int,
     *     httpClient?: HttpClient|\GuzzleHttp\ClientInterface,
     *     sleeper?: callable(float): void,
     * } $config
     */
    public function __construct(array $config)
    {
        $maxRetries = (int) ($config['maxRetries'] ?? self::DEFAULT_MAX_RETRIES);

        if ($maxRetries < 0) {
            throw new \InvalidArgumentException('The "maxRetries" option must be zero or greater.');
        }

        $transport = new HttpTransport(
            tokens: self::resolveTokens($config),
            baseUrl: (string) ($config['baseUrl'] ?? self::DEFAULT_BASE_URL),
            timeout: (float) ($config['timeout'] ?? self::DEFAULT_TIMEOUT),
            maxRetries: $maxRetries,
            client: self::resolveHttpClient($config['httpClient'] ?? null),
            sleeper: $config['sleeper'] ?? null,
        );

        $this->events = new Events($transport);
        $this->webhooks = new Webhooks($transport);
        $this->apiKeys = new ApiKeys($transport);
        $this->conversions = new Conversions($transport);
        $this->transactions = new Transactions($transport);
        $this->obligations = new ListResource($transport, '/obligations');
        $this->payouts = new ListResource($transport, '/payouts');
        $this->collaborators = new Collaborators($transport);
        $this->programs = new Programs($transport);
        $this->programGroups = new ProgramGroups($transport);
        $this->opportunities = new Opportunities($transport);
        $this->engagements = new Engagements($transport);
        $this->distributors = new Distributors($transport);
        $this->customCurrencies = new CustomCurrencies($transport);
        $this->credits = new Credits($transport);
        $this->mappings = new Mappings($transport);
        $this->settings = new Settings($transport);
        $this->collaboratorGroups = new CollaboratorGroups($transport);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function resolveTokens(array $config): AccessTokenSource
    {
        $accessToken = $config['accessToken'] ?? null;
        $apiKey = $config['apiKey'] ?? null;

        if ($accessToken !== null && $apiKey !== null) {
            throw new \InvalidArgumentException('Pass either "apiKey" or "accessToken", not both.');
        }

        if ($accessToken instanceof AccessTokenSource) {
            return $accessToken;
        }

        if (is_string($accessToken) && $accessToken !== '') {
            return new StaticAccessToken($accessToken);
        }

        if ($accessToken !== null) {
            throw new \InvalidArgumentException(
                'The "accessToken" option must be a non-empty string or a ' . AccessTokenSource::class . '.',
            );
        }

        if (!is_string($apiKey) || $apiKey === '') {
            throw new \InvalidArgumentException(
                'The "apiKey" option is required. Mint one in the Siren dashboard (Settings > API Keys), '
                . 'or pass an "accessToken" source.',
            );
        }

        return new StaticAccessToken($apiKey);
    }

    private static function resolveHttpClient(mixed $httpClient): HttpClient
    {
        if ($httpClient instanceof HttpClient) {
            return $httpClient;
        }

        if ($httpClient instanceof \GuzzleHttp\ClientInterface) {
            return new GuzzleHttpClient($httpClient);
        }

        if ($httpClient !== null) {
            throw new \InvalidArgumentException(
                'The "httpClient" option must be a ' . HttpClient::class . ' or a GuzzleHttp\ClientInterface.',
            );
        }

        return GuzzleHttpClient::isAvailable() ? new GuzzleHttpClient() : new StreamHttpClient();
    }
}
