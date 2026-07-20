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

The full API surface is described in the [OpenAPI spec](./openapi.yaml).

## Requirements

- PHP 8.1+
- `ext-json`

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
- **Automatic retries** — network errors, 429s, and 5xx are retried with exponential backoff on idempotent operations; management writes are never auto-retried.
- **Typed exceptions** — every error extends `Siren\Sdk\Exception\SirenException` and carries the status code, error code, and error data.
- **Typed taxonomy** — Siren's domain vocabulary as constants, so no magic strings cross the boundary: `WebhookEventType`, `EventSlug`, and the status vocabularies (`ConversionStatus`, `TransactionStatus`, `ObligationStatus`, `PayoutStatus`, `FulfillmentStatus`, `OpportunityStatus`, `ApiKeyStatus`, `WebhookSubscriptionStatus`).

```php
use Siren\Sdk\ConversionStatus;

$approved = $siren->conversions->list(['status' => ConversionStatus::APPROVED]);
```

### Configuration

```php
$siren = new Siren([
    'apiKey'     => 'sk_live_...',                              // required
    'baseUrl'    => 'https://api.sirenaffiliates.com/siren/v1', // default
    'timeout'    => 30,                                         // seconds, default 30
    'maxRetries' => 2,                                          // default 2
]);
```

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

Tests mock the HTTP layer — no live network calls.

## License

Released under the [MIT License](./LICENSE). Copyright © 2026 Novatorius LLC.
