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
- Manage webhook subscriptions, with an API key or a signed-in dashboard session's token, and API keys, with a session's token
- Reconcile Siren's ledger (conversions, transactions, obligations, payouts) against your own, with an API key, a dashboard session's token or a connected site's token
- Read and write the records a connector needs: collaborators and their tracking codes and program enrollments, mappings, programs, program groups, opportunities, transactions, conversions, engagements, distributors, custom currencies, store credit and its holds, shared settings, and collaborator groups
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

Mint an API key in the Siren dashboard (Settings → API Keys) with the
`event:*` scope, then:

```php
use Siren\Sdk\Siren;

$siren = new Siren(['apiKey' => 'sk_live_...']);

$result = $siren->events->sale([
    'source'     => 'stripe',            // your commerce source
    'externalId' => 'cs_test_a1b2c3',    // your order id — used to match refunds later
    'total'      => 49.99,               // major units: 49.99 = $49.99
    'trackingId' => 4021,                // opportunity id from the Siren tracking cookie
    'currency'   => 'USD',               // optional, defaults to the organization's currency
    'items'      => [                    // optional; omit to treat total as one line
        ['name' => 'Pro Plan (annual)', 'amount' => 49.99, 'quantity' => 1],
    ],
]);

echo $result->getOpportunityId(); // read from the X-Siren-OID response header
echo $result->getStatus();        // EventStatus::CREATED, RECORDED, DUPLICATE, UNATTRIBUTED or IGNORED
```

Siren answers 202 instead of 200 when the organization's engine is paused,
such as when it is over its usage threshold. The event is queued and runs
once the engine resumes, so `$result->isQueued()` is true and the event must
not be sent again.

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
secret. The body is the event's payload. Siren names the event in the
`X-Siren-Event` header and the delivery in `X-Siren-Delivery`, which stays
the same when a delivery is retried.

> ⚠️ **Pass the raw body bytes.** The HMAC is computed over the exact bytes
> Siren sent. Read `php://input` directly — if you `json_decode` and re-encode
> the payload, verification **will fail**.

```php
use Siren\Sdk\Siren;
use Siren\Sdk\WebhookEventType;
use Siren\Sdk\Exception\SignatureVerificationException;

$siren = new Siren(['apiKey' => 'sk_live_...']);

try {
    $event = $siren->webhooks->constructEventFromHeaders(
        file_get_contents('php://input'),
        getallheaders(),
        $signingSecret,
    );
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

`constructEvent($rawBody, $signature, $secret, $eventType, $deliveryId)`
does the same with the three header values passed one by one. A
delivery whose body is the bare payload is refused without `$eventType`,
because only the `X-Siren-Event` header names its event.

Create a subscription (the `signingSecret` is returned **once** — store it).
An API key that holds `webhook-subscriptions:manage` can do this, and so can
a signed-in dashboard session's access token:

```php
$siren = new Siren(['apiKey' => 'sk_live_...']); // a key with ApiKeyScope::WEBHOOK_SUBSCRIPTIONS_MANAGE

$subscription = $siren->webhooks->subscriptions->create([
    'targetUrl' => 'https://example.com/webhooks/siren',
    'events'    => [WebhookEventType::CONVERSION_APPROVED, WebhookEventType::PAYOUT_PAID],
    // or [WebhookEventType::ALL] to subscribe to everything
]);

$secret = $subscription['signingSecret'];

$siren->webhooks->subscriptions->events(); // the live catalog: [{event, category, label}, ...]
```

### Which credential does each call need?

| Calls | Credential |
|-------|------------|
| `events` | An API key holding the event's scope (`event:*`, or `event:{slug}`). A connected site's token sends only the events its integrations forward and `site-visited`. |
| `conversions`, `transactions`, `obligations` and `payouts`: `list()`, and `retrieve()` where the SDK has it | An API key holding the matching ledger scope, such as `ledger:conversions:read`. A dashboard session's access token, or a connected site's token, also works. |
| `webhooks->subscriptions` | An API key holding `webhook-subscriptions:read` for `list()` and `events()`, or `webhook-subscriptions:manage` for every call. A dashboard session's access token also works. |
| `apiKeys` | A signed-in dashboard session's access token. No API key scope reaches them. |
| Other record resources, and the writes on `conversions` and `transactions` | A dashboard session's access token, or a connected site's token on the routes Siren Cloud opens to connectors. See [Connected sites](#connected-sites). |

`ApiKeyScope` names every scope a new key may hold:

```php
use Siren\Sdk\ApiKeyScope;

$key = $siren->apiKeys->create([
    'label'  => 'Accounting export',
    'scopes' => [
        ApiKeyScope::ALL_EVENTS,
        ApiKeyScope::LEDGER_CONVERSIONS_READ,
        ApiKeyScope::LEDGER_PAYOUTS_READ,
        ApiKeyScope::WEBHOOK_SUBSCRIPTIONS_MANAGE,
    ],
]);
```

| Scope | What the key can do |
|-------|---------------------|
| `ApiKeyScope::ALL_EVENTS`, `ApiKeyScope::event($slug)` | Send events |
| `ApiKeyScope::LEDGER_CONVERSIONS_READ` | `conversions->list()`, `conversions->retrieve()` |
| `ApiKeyScope::LEDGER_TRANSACTIONS_READ` | `transactions->list()`, `transactions->retrieve()` |
| `ApiKeyScope::LEDGER_OBLIGATIONS_READ` | `obligations->list()` |
| `ApiKeyScope::LEDGER_PAYOUTS_READ` | `payouts->list()` |
| `ApiKeyScope::WEBHOOK_SUBSCRIPTIONS_READ` | `webhooks->subscriptions->list()`, `events()` |
| `ApiKeyScope::WEBHOOK_SUBSCRIPTIONS_MANAGE` | Every `webhooks->subscriptions` call |

Siren grants the ledger and webhook scopes only by their exact names. `*`,
`event:*`, wildcards such as `ledger:*`, and older names such as
`conversions:read` grant none of them, so a key made before these scopes
existed reads nothing until you replace it with one that holds them. A key's
requests count against the organization's API rate limit.

A key created with a `propertyId` is bound to that store. It reads only its
own store's conversions, transactions and obligations, and Siren refuses it on
payouts and webhook subscriptions with `StoreKeyNotAllowedException`. An
organization-wide key reads the whole organization, and narrows a ledger read
to one store with the `propertyId` filter:

```php
use Siren\Sdk\ConversionStatus;

$siren = new Siren(['apiKey' => 'sk_live_...']); // organization-wide, with ledger:conversions:read

$page = $siren->conversions->list(['propertyId' => 5, 'status' => ConversionStatus::APPROVED]);
```

## Features

- **Event ingestion** — `sale`, `refund`, `siteVisited`, and custom event types via `ingest`, with what Siren recorded (`EventStatus`) and whether it queued the event.
- **Webhook verification** — one-call `constructEventFromHeaders` or `constructEvent`, plus a lower-level constant-time `verifySignature` boolean check.
- **Subscription management** — create, list, and delete webhook subscriptions, and read the event catalog, with an API key or a dashboard session.
- **API key management** — create, list, and revoke keys, and read the scopes a key may hold.
- **Reconciliation readers** — paginated iterators over conversions, transactions, obligations, and payouts, with an API key that holds the ledger scopes.
- **Record resources**: collaborators (with tracking codes and program enrollments), mappings, settings, programs, program groups, opportunities, transactions and their details, conversions, engagements, distributors, custom currencies, store credit, and collaborator groups. See [Records](#records).
- **Host-supplied HTTP and tokens**: plug in your platform's HTTP client and an OAuth access token that refreshes after a 401. See [Connecting a host](#connecting-a-host).
- **Automatic retries** — network errors, 429s, and 5xx are retried with exponential backoff on idempotent operations; management writes are never auto-retried.
- **Typed exceptions** — every error extends `Siren\Sdk\Exception\SirenException` and carries the status code, error code, and error data.
- **Typed taxonomy** — Siren's domain vocabulary as constants, so no magic strings cross the boundary: `WebhookEventType`, `EventSlug`, and the status vocabularies (`ConversionStatus`, `TransactionStatus`, `ObligationStatus`, `PayoutStatus`, `FulfillmentStatus`, `OpportunityStatus`, `ApiKeyStatus`, `WebhookSubscriptionStatus`, `CollaboratorStatus`, `ProgramStatus`, `DistributorStatus`), `EventStatus`, `ApiKeyScope`, plus `ProgramGroupSorter`, `CustomCurrencyType`, and a `BULK_*` constant for every bulk action.

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
| 401             | `AuthenticationException`, or its subclasses `SiteDisconnectedException` when Siren says the site is no longer connected, and `ApiKeyNotAcceptedException` when the route takes a session and not an API key |
| 403             | `PermissionException`, or its subclasses `MissingScopeException` (`getRequiredScope()`, `getAnyOf()`), `StoreKeyNotAllowedException` (`getPropertyId()`) and `PolicyException` (`getParam()`) |
| 404             | `NotFoundException`                        |
| 409             | `ConflictException`, or `CodeConflictException` and `HoldConflictException` (`getReason()`) from the calls that raise them |
| 422             | `ValidationException`, `getFieldErrors()`  |
| 429             | `RateLimitException` with `getRetryAfter()` |
| 5xx / other     | `ApiException`                             |
| network/timeout | `ConnectionException`                      |

Webhook verification failures throw `SignatureVerificationException`.

## Records

> **Credentials on record routes.** The record routes accept a signed-in
> dashboard session, and a connected site's OAuth token reaches the routes
> Siren Cloud opens to connectors once the site holds the record scopes. See
> [Connected sites](#connected-sites). An API key reads conversions,
> transactions, obligations and payouts with a ledger scope. See
> [Which credential does each call need?](#which-credential-does-each-call-need)

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
| `$siren->collaborators` | list, retrieve, create, update, delete, bulk, `createPending`, `updateDetails`, `statusTypes`, `generateReferralCode` |
| `$siren->collaborators->aliases` | `findByCode`, `forCollaborator`, `query`, `create`, `free`, `list`, `trackingCode`, `setTrackingCode` |
| `$siren->collaborators->enrollments` | `list`, `add`, `remove`, `programsFor`, `collaboratorsIn`, `enroll`, `unenroll`, `replace` |
| `$siren->programs` | list, retrieve, create, update, delete, bulk, `statusTypes`, `incentiveTypes`, `currencyTypes` |
| `$siren->programGroups` | list, retrieve, create, update, delete, bulk |
| `$siren->opportunities` | list, retrieve, update |
| `$siren->transactions` | list, retrieve, `details`, create, bulk, `creditCollaborator`, `sources` |
| `$siren->conversions` | list, retrieve, create, update, delete, bulk, `types` |
| `$siren->engagements` | list, retrieve, `types`, `calculations` |
| `$siren->distributors` | list, retrieve, create, update, delete, bulk, `creditMetric` |
| `$siren->customCurrencies` | list, retrieve, create, update, delete (keyed by currency code) |
| `$siren->credits` | `checkoutBalances`, `checkoutBalance`, `entries`, `holds`, `placeHold`, `releaseHold`, `adjust`, `balances` |
| `$siren->mappings` | list, `forExternal`, `forLocal`, create, `deleteForCollaborator`, `deleteForExternal` |
| `$siren->settings` | `collaborator`, `value` |
| `$siren->collaboratorGroups` | list, retrieve, create, update, delete, `structures` |
| `$siren->collaboratorGroups->members` | `list`, `add`, `set`, `remove` |

Tracking codes and enrollments have their own routes, and the collaborator
record carries them too:

```php
$siren->collaborators->aliases->findByCode('SAVE10', 'coupon');         // who holds this coupon, or null
$siren->collaborators->aliases->forCollaborator(42, 'tracking');        // their newest tracking code, or null
$siren->collaborators->aliases->create('SAVE10', 'coupon', 42);
$siren->collaborators->aliases->free('SAVE10', 'coupon');

$siren->collaborators->enrollments->list(['collaboratorId' => 42, 'programId' => [12, 15]]);
$siren->collaborators->enrollments->add(42, 12);
$siren->collaborators->enrollments->remove(42, 12);

$siren->collaborators->enrollments->replace(42, [12, 15]);    // exactly these programs, through the collaborator
$siren->collaborators->aliases->setTrackingCode(42, 'ADA2');  // through the collaborator's trackingId

$siren->transactions->details(901);                    // line items
$siren->credits->adjust(42, 'PTS', -500, 'Redeemed at checkout');
$siren->credits->balances(42);                         // the collaborator's creditBalances field
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

### Connected sites

A WordPress site connected to Siren Cloud reads and writes the
organization's records with its OAuth token. Ask for the record scopes by
name when the site connects. `connect:*` does not grant them:

```php
use Siren\Sdk\ConnectorScope;

$authorizeUrl .= '&scope=' . rawurlencode(ConnectorScope::CONNECTED_SITE);
// connect:* connect:webhooks connect:records:read connect:records:write
```

`connect:records:read` reads collaborators, codes, enrollments, mappings,
programs, program groups, distributors, opportunities, transactions,
engagements, conversions, obligations, payouts, custom currencies, store
credit and the shared settings. `connect:records:write` writes only the
records a person manages on the site:

```php
use Siren\Sdk\Resources\Credits;
use Siren\Sdk\Resources\Mappings;

// Collaborators are created pending, with no program, distributor or group lists.
$ada = $siren->collaborators->createPending(['fullName' => 'Ada', 'nickname' => 'ada', 'email' => 'ada@example.com']);
$siren->collaborators->updateDetails($ada['id'], ['nickname' => 'countess']);   // fullName and nickname only
$siren->collaborators->enrollments->add($ada['id'], 12);                        // programs that run on this site only

// Codes and mappings, by plain type.
$siren->collaborators->aliases->create('SAVE10', 'coupon', $ada['id']);
$siren->mappings->create($ada['id'], (string) $wpUserId, Mappings::EXTERNAL_TYPE_WORDPRESS_USER);
$siren->mappings->forExternal('77', 'wc_product', 'collaborator');

// Checkout: read what is available, then reserve it.
$reference = Credits::holdReference('woocommerce', $sessionToken);
$row = $siren->credits->checkoutBalance($ada['id'], 'STORE_CREDIT', $reference);

if ($row !== null && $row['available'] > 0) {
    $hold = $siren->credits->placeHold($ada['id'], 'STORE_CREDIT', $row['available'], $sessionToken, 'woocommerce');
    // $hold['amount'] may be lower than asked.
}

// The shopper took the store credit off a cart that is still a cart: give
// it back now, not at the ttl. Never do this once the cart became an order.
$siren->credits->releaseHold($hold['id']);

$siren->settings->value('collaborator', 'urlReference', 'key');   // the referral link's query parameter
$siren->customCurrencies->retrieve('STORE_CREDIT');
```

Cloud keeps each site's codes, mappings and holds apart by filing them
under the site's Property, as in `coupon@property:5`. Send plain names. Every
type, platform and token must be letters, digits, underscores and hyphens,
and the SDK refuses anything else before it sends the request. Codes and
mappings come back with the plain type and the Property in `propertyId`.
Hold references come back as Cloud stores them. `PropertyQualifier` strips
the suffix when a host needs the plain form.

Cloud's own `user` mapping type links a collaborator to a Cloud account, and
a site may not use it. A WordPress user is `wordpress_user`. The SDK serves
every platform and sends the type it is given, so the WordPress host
translates its `user` to `wordpress_user` at its own boundary. A missed
translation fails loudly with a `PolicyException`.

A site reads only the fields its datastores need: computed fields such as
a collaborator's `aliases`, `programs` or `creditBalances`, and `include`,
get a 403. It lists opportunities by `collaboratorId` or `id`, and payouts
by `id`, `collaboratorId` or `fulfillmentId`. Other lists of those get a
403 with `ErrorCode::SITE_FILTER_REQUIRED`.

When a site creates someone the organization already has and the site does
not, Cloud answers with a held record for the site, not the existing
collaborator. The held record is `pending` with no tracking code, and Cloud
emails the person to confirm. Once they confirm, Cloud merges it into their
collaborator and deletes it, so look the collaborator up again by email.

Opportunities, transactions, engagements, conversions, obligations, payouts
and the credit ledger are read only for a site. They reach Cloud as events.
A credit hold lapses at its `ttl`, placing again for the same session
replaces it, and Cloud settles it from the order's redemption event. A site
can also release one of its holds early with `releaseHold()`. Releasing a
hold that already ended returns it unchanged, so a retry is safe.

> **Never release the hold of a checkout that became an order.** Settlement
> consumes that hold when the order reaches Cloud. Released first, the credit
> is free for another checkout to reserve and spend, and the order's
> settlement then finds nothing left to debit, so the store gives the same
> credit twice. WooCommerce empties the cart right after an order is placed,
> so its cart-emptied hook is not a safe place to release. Release only when
> the shopper abandons the credit, or it no longer applies to a cart that
> never became an order.

Each refusal has its own exception, so a host can react without parsing
messages. Cloud names each refusal with a stable `error.code`, listed in
`ErrorCode`, and the SDK picks the exception from the code first. A reply
with no code, from an older Cloud deployment, is read by its wording.

| Exception | Meaning | What to do |
|-----------|---------|------------|
| `MissingScopeException` | The token lacks `getRequiredScope()`, usually because the site connected before the record scopes existed. | Reconnect the site. |
| `SiteDisconnectedException` | The organization revoked the site. The SDK already tried one fresh token. | Stop calling Siren until the site reconnects. |
| `PolicyException` | Cloud's rules refuse this request, such as a field in `getParam()` a site may not send or a program that does not run on the site. | Do not repeat it. |
| `CodeConflictException` | Someone holds the code under that type, possibly another site. | Free it first, or choose another. |
| `HoldConflictException` | `getReason()` is `no_credit` (nothing available) or `token_in_use` (another site's checkout used the token). | Apply no credit, or mint a new token. |
| `RateLimitException` | The site spent its request budget. `getRetryAfter()` says how long to wait. | Back off. |

A page or a checkout should never wait on a slow Siren. Give the client it
uses there a short `timeout` and `maxRetries` of 0, so a timeout or a rate
limit fails at once with an exception the host can fall back from:

```php
$checkoutSiren = new Siren([
    'accessToken' => $tokens,
    'httpClient'  => new WordPressHttpClient(),
    'timeout'     => 3,
    'maxRetries'  => 0,
]);
```

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
