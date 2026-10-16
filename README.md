# Siren SDK for PHP

Affiliate and incentive tracking for any commerce stack — record sales, verify signed webhooks, and reconcile your ledger in a few lines of PHP.

[![Packagist Version](https://img.shields.io/packagist/v/siren/sdk.svg)](https://packagist.org/packages/siren/sdk)
[![CI](https://github.com/Novatorius/siren-php/actions/workflows/ci.yml/badge.svg)](https://github.com/Novatorius/siren-php/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](./LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/siren/sdk.svg)](https://packagist.org/packages/siren/sdk)

## What is Siren?

[Siren](https://sirenaffiliates.com) is an affiliate, referral, and incentive
platform. You track the events that matter — sales, refunds, referred visits —
and Siren attributes them to collaborators, calculates rewards, and manages
payouts.

This SDK is the official PHP client for the Siren API. It lets you:

- Record commerce and tracking events (`sale`, `refund`, `siteVisited`, and custom event types)
- Verify signed webhooks in a single, constant-time call
- Manage API keys and webhook subscriptions
- Reconcile Siren's ledger (conversions, transactions, obligations, payouts) against your own
- Read and write the records a connector needs: collaborators and their tracking codes and program enrollments, programs, program groups, opportunities, transactions, conversions, engagements, distributors, custom currencies, store credit, and collaborator groups
- Run inside any host: bring your own HTTP client and your own OAuth access token

The full API surface is described in the [OpenAPI spec](./openapi.yaml).

## Requirements

- PHP 8.1+
- `ext-json`
- Optional: `guzzlehttp/guzzle` ^7.8. The SDK uses Guzzle when it is installed
  and PHP's own HTTP streams when it is not, so a host that already ships its
  own HTTP stack never has to load a second copy of Guzzle.

## Install

```bash
composer require siren/sdk
```

## Quickstart

### Record a sale

Mint an API key in the Siren dashboard (Settings → API Keys), then:

```php
use Siren\Sdk\Siren;

$siren = new Siren(['apiKey' => 'sk_live_...']);

$result = $siren->events->sale([
    'source'     => 'stripe',            // your commerce source
    'externalId' => 'cs_test_a1b2c3',    // your order id — used to match refunds later
    'total'      => 49.99,               // major units: 49.99 = $49.99
    'trackingId' => 4021,                // opportunity id from the Siren tracking cookie
    'currency'   => 'USD',               // optional, defaults to USD
    'items'      => [                    // optional; omit to treat total as one line
        ['name' => 'Pro Plan (annual)', 'amount' => 49.99, 'quantity' => 1],
    ],
]);

echo $result->getOpportunityId(); // read from the X-Siren-OID response header
```

Refunds reverse a sale by `(externalId, source)`:

```php
$siren->events->refund([
    'source'     => 'stripe',
    'externalId' => 'cs_test_a1b2c3',
]);
```

### Verify a webhook

Siren signs every delivery with `X-Siren-Signature: sha256=<hex hmac>` — an
HMAC-SHA256 of the **raw request body** keyed by your subscription's signing
secret.

> ⚠️ **Pass the raw body bytes.** The HMAC is computed over the exact bytes
> Siren sent. Read `php://input` directly — if you `json_decode` and re-encode
> the payload, verification **will fail**.

```php
use Siren\Sdk\Siren;
use Siren\Sdk\WebhookEventType;
use Siren\Sdk\Exception\SignatureVerificationException;

$siren = new Siren(['apiKey' => 'sk_live_...']);

$rawBody   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_SIREN_SIGNATURE'] ?? null;

try {
    $event = $siren->webhooks->constructEvent($rawBody, $signature, $signingSecret);
} catch (SignatureVerificationException $e) {
    http_response_code(400);
    exit;
}

match ($event->getType()) {
    WebhookEventType::CONVERSION_APPROVED => handleConversion($event->getData()),
    WebhookEventType::PAYOUT_PAID         => handlePayout($event->getData()),
    default                               => null,
};
```

Create a subscription (the `signingSecret` is returned **once** — store it):

```php
$subscription = $siren->webhooks->subscriptions->create([
    'targetUrl' => 'https://example.com/webhooks/siren',
    'events'    => [WebhookEventType::CONVERSION_APPROVED, WebhookEventType::PAYOUT_PAID],
    // or [WebhookEventType::ALL] to subscribe to everything
]);

$secret = $subscription['signingSecret'];
```

## Features

- **Event ingestion** — `sale`, `refund`, `siteVisited`, and custom event types via `ingest`.
- **Webhook verification** — one-call `constructEvent`, plus a lower-level constant-time `verifySignature` boolean check.
- **Subscription management** — create, list, and delete webhook subscriptions.
- **API key management** — create, list, and revoke keys.
- **Reconciliation readers** — paginated iterators over conversions, transactions, obligations, and payouts.
- **Record resources**: collaborators (with tracking codes and program enrollments), programs, program groups, opportunities, transactions and their details, conversions, engagements, distributors, custom currencies, store credit, and collaborator groups. See [Records](#records).
- **Host-supplied HTTP and tokens**: plug in your platform's HTTP client and an OAuth access token that refreshes after a 401. See [Connecting a host](#connecting-a-host).
- **Automatic retries** — network errors, 429s, and 5xx are retried with exponential backoff on idempotent operations; management writes are never auto-retried.
- **Typed exceptions** — every error extends `Siren\Sdk\Exception\SirenException` and carries the status code, error code, and error data.
- **Typed taxonomy** — Siren's domain vocabulary as constants, so no magic strings cross the boundary: `WebhookEventType`, `EventSlug`, and the status vocabularies (`ConversionStatus`, `TransactionStatus`, `ObligationStatus`, `PayoutStatus`, `FulfillmentStatus`, `OpportunityStatus`, `ApiKeyStatus`, `WebhookSubscriptionStatus`, `CollaboratorStatus`, `ProgramStatus`, `DistributorStatus`), plus `ProgramGroupSorter`, `CustomCurrencyType`, and a `BULK_*` constant for every bulk action.

```php
use Siren\Sdk\ConversionStatus;

$approved = $siren->conversions->list(['status' => ConversionStatus::APPROVED]);
```

### Configuration

```php
$siren = new Siren([
    'apiKey'     => 'sk_live_...',                              // or 'accessToken', see below
    'baseUrl'    => 'https://api.sirenaffiliates.com/siren/v1', // default
    'timeout'    => 30,                                         // seconds, default 30
    'maxRetries' => 2,                                          // default 2
    'httpClient' => $client,                                    // optional, see below
]);
```

Pass `apiKey` or `accessToken`, not both. `httpClient` takes a
`Siren\Sdk\Http\HttpClient` or a preconfigured `GuzzleHttp\ClientInterface`.

### Errors

Every exception extends `Siren\Sdk\Exception\SirenException` and carries
`getStatusCode()`, `getErrorCode()`, and `getErrorData()`.

| Status          | Exception                                  |
|-----------------|--------------------------------------------|
| 400             | `BadRequestException`                      |
| 401             | `AuthenticationException`                  |
| 403             | `PermissionException`                      |
| 404             | `NotFoundException`                        |
| 409             | `ConflictException`                        |
| 422             | `ValidationException` — `getFieldErrors()` |
| 429             | `RateLimitException` — `getRetryAfter()`   |
| 5xx / other     | `ApiException`                             |
| network/timeout | `ConnectionException`                      |

Webhook verification failures throw `SignatureVerificationException`.

## Records

> **Credentials on record routes.** As of Siren 3.6, Siren Cloud accepts an
> API key or a connected site's OAuth token only on event ingestion. The
> record routes below, and the 0.1.0 reconciliation readers, accept a
> signed-in dashboard session and answer 401 to an API key or a connector
> token. The SDK's record resources follow the routes as Siren defines them
> and start working for connectors once Cloud accepts their credentials
> there.

Every record resource follows the same shape: `list()`, `retrieve()`,
`create()`, `update()`, `delete()`, and `bulk()`, where Siren has the route.
A resource has no method for a route Siren lacks. Engagements, for example,
are read only.

```php
use Siren\Sdk\CollaboratorStatus;
use Siren\Sdk\Resources\Collaborators;

// A page of active collaborators in one program, 50 at a time.
$page = $siren->collaborators->list([
    'status'    => CollaboratorStatus::ACTIVE,
    'programId' => 12,
    'fields'    => ['id', 'email', 'referralCode'],
    'page'      => 2,
    'perPage'   => 50,
]);

foreach ($page as $collaborator) {
    // ...
}

$page->getTotal();      // records across all pages
$page->getTotalPages();
$page->hasMore();

$ada = $siren->collaborators->create([
    'fullName'   => 'Ada Lovelace',
    'nickname'   => 'ada',
    'email'      => 'ada@example.com',
    'status'     => CollaboratorStatus::ACTIVE,
    'trackingId' => 'ADA',  // omit to let Siren generate one
]);

$siren->collaborators->update($ada['id'], ['nickname' => 'countess']);
$siren->collaborators->bulk(Collaborators::BULK_DEACTIVATE, [$ada['id']]);
```

Lists page with `page` and `perPage`, or with Siren's own `number` (page
size, 1 to 200) and `offset`. Any other key is a filter. A list value is
sent comma-separated, which Siren reads as "any of". Each resource's
docblock names the filters and sort columns its route accepts.

Deletes are two-stage for collaborators, programs, conversions, and
distributors. The first call marks the record `deleted` and returns it, and
a second call removes it. Program groups and collaborator groups are
removed on the first call. Writes and deletes are never retried
automatically.

| Resource | Routes |
|----------|--------|
| `$siren->collaborators` | list, retrieve, create, update, delete, bulk, `statusTypes`, `generateReferralCode` |
| `$siren->collaborators->aliases` | `list`, `trackingCode`, `setTrackingCode` |
| `$siren->collaborators->enrollments` | `programsFor`, `collaboratorsIn`, `enroll`, `unenroll`, `replace` |
| `$siren->programs` | list, retrieve, create, update, delete, bulk, `statusTypes`, `incentiveTypes`, `currencyTypes` |
| `$siren->programGroups` | list, retrieve, create, update, delete, bulk |
| `$siren->opportunities` | list, retrieve, update |
| `$siren->transactions` | list, retrieve, `details`, create, bulk, `creditCollaborator`, `sources` |
| `$siren->conversions` | list, retrieve, create, update, delete, bulk, `types` |
| `$siren->engagements` | list, retrieve, `types`, `calculations` |
| `$siren->distributors` | list, retrieve, create, update, delete, bulk, `creditMetric` |
| `$siren->customCurrencies` | list, create, update, delete (keyed by currency code) |
| `$siren->credits` | `adjust`, `balances` |
| `$siren->collaboratorGroups` | list, retrieve, create, update, delete, `structures` |
| `$siren->collaboratorGroups->members` | `list`, `add`, `set`, `remove` |

Tracking codes and enrollments have no routes of their own in Siren. They
live on the collaborator, and the `aliases` and `enrollments` helpers read
and write them there:

```php
$siren->collaborators->aliases->list(42);              // [['code' => 'ADA', 'type' => 'tracking', ...]]
$siren->collaborators->aliases->setTrackingCode(42, 'ADA2');

$siren->collaborators->enrollments->enroll(12, [42, 43]);     // keeps other enrollments
$siren->collaborators->enrollments->replace(42, [12, 15]);    // exactly these programs
$siren->collaborators->enrollments->programsFor(42);          // [['id' => 12, 'name' => ...]]

$siren->transactions->details(901);                    // line items
$siren->credits->adjust(42, 'PTS', -500, 'Redeemed at checkout');
$siren->credits->balances(42);
```

## Connecting a host

A connector, such as the Siren WordPress plugin connected to Siren Cloud,
usually has its own HTTP functions and its own OAuth grant. The SDK takes
both.

### Bring your own HTTP client

Implement `Siren\Sdk\Http\HttpClient`. Send the request as given, return an
`HttpResponse` for every status (including 4xx and 5xx), and throw
`HttpClientException` only when no response arrived. The SDK keeps the
retries, error mapping, and token refresh.

```php
use Siren\Sdk\Http\HttpClient;
use Siren\Sdk\Http\HttpClientException;
use Siren\Sdk\Http\HttpRequest;
use Siren\Sdk\Http\HttpResponse;

final class WordPressHttpClient implements HttpClient
{
    public function send(HttpRequest $request): HttpResponse
    {
        $response = wp_remote_request($request->getUrl(), [
            'method'  => $request->getMethod(),
            'headers' => $request->getHeaders(),
            'body'    => $request->getBody(),
            'timeout' => $request->getTimeout(),
        ]);

        if (is_wp_error($response)) {
            throw new HttpClientException($response->get_error_message());
        }

        return new HttpResponse(
            (int) wp_remote_retrieve_response_code($response),
            wp_remote_retrieve_headers($response)->getAll(),
            (string) wp_remote_retrieve_body($response),
        );
    }
}

$siren = new Siren(['apiKey' => 'sk_live_...', 'httpClient' => new WordPressHttpClient()]);
```

### Bring your own access token

Pass an `accessToken` instead of an `apiKey`. A plain string works for a
token that never changes. For an OAuth grant, implement
`Siren\Sdk\Auth\AccessTokenSource`, or build one from two callables:

```php
use Siren\Sdk\Auth\CallbackAccessTokenSource;

$siren = new Siren([
    'accessToken' => new CallbackAccessTokenSource(
        fn (): string => $grant->accessToken(),
        fn (string $rejected): ?string => $grant->refresh($rejected), // store the new pair, return the new access token
    ),
    'httpClient' => new WordPressHttpClient(),
]);
```

The SDK reads the token before every request. When Siren answers 401, it
calls the refresh callable once, with the token Siren rejected, and repeats
the request with the new token. A request that was rejected with a 401 did
nothing, so this repeat applies to writes as well. If the refresh returns
null, or the repeated request gets another 401, the SDK raises
`AuthenticationException`, which means the site needs to reconnect.

## Other SDKs

Siren also ships official clients for other stacks:

- **Node.js** — [Novatorius/siren-node](https://github.com/Novatorius/siren-node)
- **Python** — [Novatorius/siren-python](https://github.com/Novatorius/siren-python)

## Links

- Website: [sirenaffiliates.com](https://sirenaffiliates.com)
- API reference: [openapi.yaml](./openapi.yaml)

## Contributing

Contributions are welcome. See [CONTRIBUTING.md](./CONTRIBUTING.md) for how to
set up the project, run the tests, and open a pull request. By participating you
agree to the [Code of Conduct](./CODE_OF_CONDUCT.md).

```bash
composer install
composer test
```

Tests mock the HTTP layer — no live network calls. The `StreamHttpClient`
tests start PHP's built-in web server on 127.0.0.1 for the length of the
test class.

## License

Released under the [MIT License](./LICENSE). Copyright © 2026 Novatorius LLC.
