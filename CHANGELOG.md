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
