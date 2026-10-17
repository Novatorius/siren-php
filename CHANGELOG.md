# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Typed taxonomy exports so integrations use SDK constants instead of magic
  strings: `EventSlug` (built-in ingestion slugs) and the status vocabularies
  `ConversionStatus`, `TransactionStatus`, `ObligationStatus`, `PayoutStatus`,
  `FulfillmentStatus`, `OpportunityStatus`, `ApiKeyStatus`, and
  `WebhookSubscriptionStatus`.
- `WebhookEventType`: added the missing `CREDIT_ISSUED`, `CREDIT_REDEEMED`,
  `CURRENCY_CREATED`, and `CURRENCY_DELETED` events (also added to
  `openapi.yaml`), matching the full set Siren dispatches.
- PHPStan static analysis (level 6) with the PHPNomad stack-elevator ruleset,
  wired as `composer lint` and a CI lint job.
- Record resources for the routes a connector needs: `collaborators` (with
  `aliases` for tracking codes and `enrollments` for program enrollment),
  `programs`, `programGroups`, `opportunities`, `transactions` (create,
  details, bulk, credit to a collaborator), `conversions` (create, update,
  delete, bulk), `engagements`, `distributors`, `customCurrencies`,
  `credits`, and `collaboratorGroups` with their `members`. Each resource
  has a method only where Siren has the route.
- A host-supplied HTTP seam, `Siren\Sdk\Http\HttpClient`, passed as the
  `httpClient` option. `GuzzleHttpClient` wraps Guzzle and
  `StreamHttpClient` uses PHP streams with no dependencies.
- A host-supplied access token, `Siren\Sdk\Auth\AccessTokenSource`, passed
  as the `accessToken` option. After a 401 the SDK asks the source for a
  fresh token once and repeats the request. `CallbackAccessTokenSource`
  builds a source from two callables.
- `CollaboratorStatus`, `ProgramStatus`, `DistributorStatus`,
  `ProgramGroupSorter`, and `CustomCurrencyType`, plus a `BULK_*` constant for
  every bulk action.
- `ListResult::getTotalPages()` and `ListResult::hasMore()`.
- `BadRequestException::getFieldErrors()`. Siren's record routes answer a
  failed validation with 400 and `error.context.failedValidations`, which the
  SDK now reads, with `VALIDATION_FAILED` as the error code.

- The record routes Siren Cloud opens to a connected site's token (Siren
  PR 384), each with the route's own filters, paging and input rules:
  - `collaborators->aliases`: `findByCode()`, `forCollaborator()`,
    `query()`, `create()` and `free()` on `/collaborator-aliases`.
  - `collaborators->enrollments`: `list()`, `add()` and `remove()` on
    `/collaborator-programs`.
  - `$siren->mappings`, a new resource: `list()`, `forExternal()`,
    `forLocal()`, `create()`, `deleteForCollaborator()` and
    `deleteForExternal()`. Every call names an external type.
  - `$siren->settings`, a new resource: `collaborator()` and `value()` on
    `/configs`.
  - `credits`: `checkoutBalances()` and `checkoutBalance()` on
    `/credit-balances`, `entries()`, `holds()`, `placeHold()` and the
    `holdReference()` helper.
  - `customCurrencies->retrieve()` by code.
  - `collaborators->createPending()` and `collaborators->updateDetails()`,
    which leave out the fields Siren refuses from a connected site.
- `ConnectorScope`, the OAuth scopes a connected site asks for, including the
  explicit `connect:records:read` and `connect:records:write`.
- `PropertyQualifier`. Codes and mappings now come back with the plain type
  and the site's Property in `propertyId`, and every type, platform and
  token is checked against Siren's name rule before it is sent.
- Typed refusals: `MissingScopeException` and `PolicyException` for 403,
  `SiteDisconnectedException` for a revoked site's 401,
  `CodeConflictException` and `HoldConflictException` for 409, and
  `HoldLimitException` for the per-site hold cap's 429. Each extends the
  exception the status already raised.
- `ListResult::map()`.
- `Credits::releaseHold()` releases a store credit hold early with
  `DELETE /credit-holds/{id}`, so an abandoned checkout stops reserving
  credit before its `ttl` runs out. Releasing a hold that already ended is
  not an error.
- `ErrorCode`, the stable `error.code` values Siren Cloud sends with a
  connector's 401, 403, 409 and hold cap 429.

### Changed

- The typed refusals now follow Siren Cloud's `error.code` first, so a
  reworded message no longer changes which exception a host catches. A
  reply with no code, from a Cloud deployment older than the codes, is still
  read by its wording. `MissingScopeException::getRequiredScope()` reads
  `error.context.scope` when Cloud sends it.

- Guzzle is no longer required. It moved to `suggest`, and the SDK uses it
  when installed and PHP streams when not. Code that passes its own
  `GuzzleHttp\ClientInterface` as `httpClient` works as before but must
  require Guzzle itself.
- `$siren->conversions` and `$siren->transactions` are now `Conversions` and
  `Transactions` resources. Both still extend `ListResource`, so `list()`
  behaves as before.

### Fixed

- List readers sent `page` and `perPage`, which Siren ignores, so every call
  returned the first ten rows. The SDK now turns them into Siren's `number`
  and `offset`, and passes `number` and `offset` through when given.
- List readers now read Siren's list envelope
  (`{items, total, page, perPage, totalPages}`). Before, `getData()`
  returned the envelope's values instead of the records.
- A page size above 200 is lowered to 200 before a page-based offset is
  worked out, as Siren lowers it, so no rows are skipped between pages.
- `openapi.yaml` described list responses as bare arrays and every response
  as wrapped in `data`. It now describes the list envelope and bare record
  bodies, and uses OpenAPI 3.1 null types for the API key dates.

## [0.1.0] - 2026-07-11

### Added

- Initial public release of the Siren SDK for PHP.
- `Siren` client with configurable base URL, timeout, and retry policy.
- Event ingestion: `events->sale()`, `events->refund()`, `events->siteVisited()`, and custom event types via `events->ingest()`.
- Webhook signature verification: `webhooks->constructEvent()` and the lower-level constant-time `webhooks->verifySignature()`.
- Webhook subscription management: create, list, and delete.
- API key management: create, list, and revoke.
- Reconciliation readers with pagination over conversions, transactions, obligations, and payouts.
- Automatic retries with exponential backoff on idempotent operations.
- Typed exception hierarchy under `Siren\Sdk\Exception`.

[Unreleased]: https://github.com/Novatorius/siren-php/compare/0.1.0...HEAD
[0.1.0]: https://github.com/Novatorius/siren-php/releases/tag/0.1.0
