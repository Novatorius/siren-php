# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

The next release is 0.3.0, a minor bump, because the changes below break
existing callers. Each one follows a rule Siren already enforces.

### Breaking

- `apiKeys->create()` throws `InvalidArgumentException` without a non-empty
  `scopes` list. Siren answers 400 to a key without scopes.
- `collaborators->updateDetails()` throws `InvalidArgumentException` for
  `trackingId`, `referralCode` and every parameter other than `fullName`,
  `nickname`, `fields` and `id`. Siren Cloud refuses them from a connected
  site with a 403.
- `webhooks->constructEvent()` on a bare payload returns the whole body as
  `getData()`, and throws `SignatureVerificationException` when `$eventType`
  is not passed. The three-argument call from 0.2.0 used to return an event
  with an empty type for every bare payload, which no handler matched. Pass
  the `X-Siren-Event` header, or call `constructEventFromHeaders()`. A body
  is read as a connected site's envelope only when its `id` and `type`
  match the `X-Siren-Delivery` and `X-Siren-Event` values, or when neither
  header is passed, so a custom event's payload that holds `version`,
  `type` and `data` keys stays whole.

### Added

- `EventResult` now carries what Siren reports about an ingested event:
  `getHttpStatus()`, `getStatus()`, `isQueued()`, `isAttributed()`,
  `getReason()`, `getMessage()`, `getTransactionId()`, `getConversions()`
  and `toArray()`. `EventStatus` names the statuses and reasons. A sale
  answers `created`, `recorded`, `duplicate`, `unattributed` or `ignored`,
  and a site visit answers `attributed`, `recorded` or `not_attributed`.
- `EventResult::isQueued()` reports the 202 Siren sends when the
  organization's engine is paused and it queues the event instead of
  refusing it. A queued visit's opportunity id is read from the body when
  the `X-Siren-OID` header is absent.
- `Webhooks::constructEventFromHeaders()`, which reads `X-Siren-Signature`,
  `X-Siren-Event` and `X-Siren-Delivery` from the request headers, and
  `WebhookEvent::getBody()`.
- `webhooks->subscriptions->events()` reads the live catalog from
  `GET /webhooks/events`.
- `WebhookEventType::COLLABORATOR_UPDATED`, `COLLABORATOR_DEACTIVATED` and
  `PROGRAM_CREATED`, which Siren now delivers.
- `ApiKeyScope`, the scopes an API key may hold, and `apiKeys->scopes()`
  for `GET /api-keys/scopes`. `apiKeys->list()` takes `number` and `offset`.
- `ObligationStatus::DRAFT`, `APPROVED`, `REJECTED` and `DELETED`, which
  Siren uses. `FULFILLED` stays for compatibility and is documented as
  retired, because Siren no longer accepts it.
- `ListResource::MAX_BULK_IDS`. A bulk action with more than 500 ids is
  refused before it is sent, as Siren refuses it with a 400.
- `ErrorCode` knows the codes Siren Cloud added: `collaborator_not_on_site`,
  `site_filter_required` and `report_not_allowed`, which raise a
  `PolicyException`, `migration_closed`, which stays a plain
  `PermissionException`, `reserved_address` (422) and `plan_unavailable`
  (503).

### Changed

- `apiKeys->create()` requires `scopes` and no longer requires `label`,
  as Siren does. Siren answers 400 to a key without scopes and to a record
  scope such as `programs:read`, because an API key only sends events.
- The README and `openapi.yaml` say which credential each call takes. Siren
  Cloud accepts an API key on the event routes only. API keys and webhook
  subscriptions are managed with a signed-in dashboard session's token, and
  the record routes take that or a connected site's token.
- `collaborators->updateDetails()` sends only `fullName` and `nickname`,
  plus `fields` to choose the returned fields. Siren Cloud now refuses `trackingId` and every other
  field from a connected site with a 403, so the SDK refuses them before
  sending. A site changes codes through `aliases->create()` and
  `aliases->free()`.
- Docblocks, the README and `openapi.yaml` describe what Siren Cloud now
  holds a connected site to: the fields it may read, the filters
  opportunity and payout lists need, the held record a create returns for
  someone the organization already has, the `held` amount on credit
  balances, the `platformId` length after Cloud adds the Property, and the
  empty body `conversions->update()` returns.
- `openapi.yaml` lists the obligation and payout filters, answers 204 for
  group deletes, and says a page size above 200 is lowered to 200. The page
  size schema no longer sets a maximum, `externalId` on a sale, a refund
  and a line item takes a string or a number, and `GET /ping` is listed
  with the credentials it takes.
- `HoldLimitException` and `ErrorCode::HOLD_LIMIT` are documented as
  retired. Siren no longer caps credit holds, so it never sends `hold_limit`.

### Fixed

- `constructEvent()` read the event type and delivery id from the body, but
  a subscription made with `subscriptions->create()` receives the flat event
  payload, with the type in `X-Siren-Event` and the delivery id in
  `X-Siren-Delivery`. Such events came back with an empty type and empty
  data. `constructEvent()` now takes the two header values as arguments,
  returns the payload as the data, and refuses a payload that arrives
  without the event type. The versioned envelope a connected site's
  subscription receives is read from the body and needs no headers.
- `BadRequestException::getFieldErrors()` is documented as the list Siren
  sends, one `{field, message, type, context}` entry per broken rule. It
  said the messages were keyed by field name.
- The `sale()` docblock and `openapi.yaml` said a sale without `currency`
  defaults to USD. Siren records it in the organization's default currency.
- `openapi.yaml` described event validation failures as 422. Siren answers
  400 with `error.context.failedValidations`, which `BadRequestException`
  reads.

## [0.2.0] - 2026-10-17

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
  not an error. Never release the hold of a checkout that became an order,
  because settlement consumes it.
- `ErrorCode`, the stable `error.code` values Siren Cloud sends with a
  connector's 401, 403, 409 and hold cap 429, and with a plan refusal
  (`plan_required`) or an admin-only refusal (`admin_required`). Those two
  stay a plain `PermissionException`.

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

[Unreleased]: https://github.com/Novatorius/siren-php/compare/0.2.0...HEAD
[0.2.0]: https://github.com/Novatorius/siren-php/compare/0.1.0...0.2.0
[0.1.0]: https://github.com/Novatorius/siren-php/releases/tag/0.1.0
