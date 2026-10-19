<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * The scopes an API key may be given (`apiKeys->create()`).
 *
 * A key sends events with event scopes: {@see ALL_EVENTS}, or
 * {@see event()} for one event type. The events a connected site forwards,
 * such as `sale-triggered`, need their exact `event:{slug}` scope, because
 * no wildcard reaches them. {@see FULL_ACCESS} is still accepted, and grants
 * what {@see ALL_EVENTS} grants.
 *
 * A key also reads the ledger and manages webhook subscriptions with the
 * ledger and webhook scopes below:
 *
 * - {@see LEDGER_CONVERSIONS_READ}, {@see LEDGER_TRANSACTIONS_READ},
 *   {@see LEDGER_OBLIGATIONS_READ} and {@see LEDGER_PAYOUTS_READ} open
 *   `list()` and the read by id on `conversions`, `transactions`,
 *   `obligations` and `payouts`.
 * - {@see WEBHOOK_SUBSCRIPTIONS_READ} lists subscriptions, reads one, and
 *   reads the event catalog and a subscription's deliveries.
 * - {@see WEBHOOK_SUBSCRIPTIONS_MANAGE} does all of that, and creates,
 *   changes and deletes subscriptions and sends a test delivery.
 *
 * Siren grants each of these six only by its exact name. {@see FULL_ACCESS},
 * {@see ALL_EVENTS}, wildcards such as `ledger:*`, and the older names such
 * as `conversions:read` grant none of them. Siren refuses those older names
 * on a new key with a 400.
 *
 * No scope reaches API key management. `apiKeys` takes a signed-in
 * dashboard session.
 *
 * `apiKeys->scopes()` returns the scopes the organization offers.
 */
final class ApiKeyScope
{
    /** Every event type the key may send. */
    public const ALL_EVENTS = 'event:*';

    /** The full wildcard. Siren treats it as {@see ALL_EVENTS}, and it opens no ledger or webhook route. */
    public const FULL_ACCESS = '*';

    public const SALE = 'event:' . EventSlug::SALE;
    public const REFUND = 'event:' . EventSlug::REFUND;
    public const SITE_VISITED = 'event:' . EventSlug::SITE_VISITED;

    /** Lists and reads conversions (`GET /conversions`, `GET /conversions/{id}`). */
    public const LEDGER_CONVERSIONS_READ = 'ledger:conversions:read';

    /** Lists and reads transactions (`GET /transactions`, `GET /transactions/{id}`). */
    public const LEDGER_TRANSACTIONS_READ = 'ledger:transactions:read';

    /** Lists and reads obligations (`GET /obligations`, `GET /obligations/{id}`). */
    public const LEDGER_OBLIGATIONS_READ = 'ledger:obligations:read';

    /**
     * Lists and reads payouts (`GET /payouts`, `GET /payouts/{id}`). Payouts
     * are not kept by store, so a key bound to one store is refused here.
     */
    public const LEDGER_PAYOUTS_READ = 'ledger:payouts:read';

    /**
     * Lists webhook subscriptions, reads one, and reads the event catalog and
     * a subscription's deliveries. A key bound to one store is refused here.
     */
    public const WEBHOOK_SUBSCRIPTIONS_READ = 'webhook-subscriptions:read';

    /**
     * Everything {@see WEBHOOK_SUBSCRIPTIONS_READ} opens, plus creating,
     * changing and deleting subscriptions and sending a test delivery
     * (`POST /webhooks/{id}/test`).
     */
    public const WEBHOOK_SUBSCRIPTIONS_MANAGE = 'webhook-subscriptions:manage';

    /**
     * The four ledger read scopes.
     *
     * @var list<string>
     */
    public const LEDGER_READ = [
        self::LEDGER_CONVERSIONS_READ,
        self::LEDGER_TRANSACTIONS_READ,
        self::LEDGER_OBLIGATIONS_READ,
        self::LEDGER_PAYOUTS_READ,
    ];

    private function __construct()
    {
    }

    /** The scope for one event type, such as `event:loyalty-points-earned`. */
    public static function event(string $slug): string
    {
        if ($slug === '') {
            throw new \InvalidArgumentException('An event scope needs a non-empty event slug.');
        }

        return 'event:' . $slug;
    }
}
