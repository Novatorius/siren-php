<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * Every scope that lets an API key read or manage an organization's
 * records, one for each constant in Siren's `ApiKeyRecordScopes`, under the
 * same name and in the order the dashboard offers them. {@see ALL} lists
 * them.
 *
 * - The `ledger:` scopes only read what Siren recorded. No scope approves a
 *   reward, moves an obligation along or sends a payout.
 * - {@see WEBHOOKS_READ} and {@see WEBHOOKS_MANAGE} read and manage webhook
 *   subscriptions. They are the same strings as
 *   {@see ApiKeyScope::WEBHOOK_SUBSCRIPTIONS_READ} and
 *   {@see ApiKeyScope::WEBHOOK_SUBSCRIPTIONS_MANAGE}.
 * - The `records:` scopes read and manage the records a merchant sets up.
 *   A manage scope reads everything its read scope reads.
 *
 * Siren grants each only by its exact name. `*` and wildcards such as
 * `records:*` grant none of them. A test loads Siren's
 * ApiKeyRecordScopes.php and fails when this list differs from it.
 *
 * ```php
 * $siren->apiKeys->create([
 *     'label'  => 'Payout app',
 *     'scopes' => [ApiKeyRecordScope::PAYOUTS_READ, ApiKeyRecordScope::FULFILLMENTS_READ],
 * ]);
 * ```
 */
final class ApiKeyRecordScope
{
    /** `engagements` reads. A store's key reads its own store. */
    public const ENGAGEMENTS_READ = 'ledger:engagements:read';
    /** `opportunities` reads. Organization keys only. */
    public const OPPORTUNITIES_READ = 'ledger:opportunities:read';
    /** `conversions->list()` and `retrieve()`. A store's key reads its own store. */
    public const CONVERSIONS_READ = 'ledger:conversions:read';
    /** `transactions->list()` and `retrieve()`. A store's key reads its own store. */
    public const TRANSACTIONS_READ = 'ledger:transactions:read';
    /** `obligations->list()`. A store's key reads its own store. */
    public const OBLIGATIONS_READ = 'ledger:obligations:read';
    /** `fulfillments->list()` and `retrieve()`. Organization keys only. */
    public const FULFILLMENTS_READ = 'ledger:fulfillments:read';
    /** `payouts->list()` and `retrieve()`. Organization keys only. */
    public const PAYOUTS_READ = 'ledger:payouts:read';
    /** Lists and reads webhook subscriptions, the event catalog and deliveries. Organization keys only. */
    public const WEBHOOKS_READ = 'webhook-subscriptions:read';
    /** Everything {@see WEBHOOKS_READ} opens, plus create, update, delete and test. Organization keys only. */
    public const WEBHOOKS_MANAGE = 'webhook-subscriptions:manage';
    public const PRODUCTS_READ = 'records:products:read';
    public const PRODUCTS_MANAGE = 'records:products:manage';
    public const COUPONS_READ = 'records:coupons:read';
    public const COUPONS_MANAGE = 'records:coupons:manage';
    public const COLLABORATORS_READ = 'records:collaborators:read';
    public const COLLABORATORS_MANAGE = 'records:collaborators:manage';
    public const COLLABORATOR_GROUPS_READ = 'records:collaborator-groups:read';
    public const COLLABORATOR_GROUPS_MANAGE = 'records:collaborator-groups:manage';
    public const PROGRAMS_READ = 'records:programs:read';
    public const PROGRAMS_MANAGE = 'records:programs:manage';
    public const PROGRAM_GROUPS_READ = 'records:program-groups:read';
    public const PROGRAM_GROUPS_MANAGE = 'records:program-groups:manage';
    public const DISTRIBUTORS_READ = 'records:distributors:read';
    public const DISTRIBUTORS_MANAGE = 'records:distributors:manage';
    public const APPLICATION_FORMS_READ = 'records:application-forms:read';
    public const APPLICATION_FORMS_MANAGE = 'records:application-forms:manage';
    public const APPLICATIONS_READ = 'records:applications:read';
    public const APPLICATIONS_MANAGE = 'records:applications:manage';
    public const NOTIFICATION_RULES_READ = 'records:notification-rules:read';
    public const NOTIFICATION_RULES_MANAGE = 'records:notification-rules:manage';
    public const PROPERTIES_READ = 'records:properties:read';

    /**
     * Every record scope, in the order Siren lists them.
     *
     * @var list<string>
     */
    public const ALL = [
        self::ENGAGEMENTS_READ,
        self::OPPORTUNITIES_READ,
        self::CONVERSIONS_READ,
        self::TRANSACTIONS_READ,
        self::OBLIGATIONS_READ,
        self::FULFILLMENTS_READ,
        self::PAYOUTS_READ,
        self::WEBHOOKS_READ,
        self::WEBHOOKS_MANAGE,
        self::PRODUCTS_READ,
        self::PRODUCTS_MANAGE,
        self::COUPONS_READ,
        self::COUPONS_MANAGE,
        self::COLLABORATORS_READ,
        self::COLLABORATORS_MANAGE,
        self::COLLABORATOR_GROUPS_READ,
        self::COLLABORATOR_GROUPS_MANAGE,
        self::PROGRAMS_READ,
        self::PROGRAMS_MANAGE,
        self::PROGRAM_GROUPS_READ,
        self::PROGRAM_GROUPS_MANAGE,
        self::DISTRIBUTORS_READ,
        self::DISTRIBUTORS_MANAGE,
        self::APPLICATION_FORMS_READ,
        self::APPLICATION_FORMS_MANAGE,
        self::APPLICATIONS_READ,
        self::APPLICATIONS_MANAGE,
        self::NOTIFICATION_RULES_READ,
        self::NOTIFICATION_RULES_MANAGE,
        self::PROPERTIES_READ,
    ];

    private function __construct()
    {
    }
}
