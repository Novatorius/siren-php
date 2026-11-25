<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * The scopes an app's API key may be given, in the app file that
 * `siren apps apply` reads or with `apps->createKey()`.
 *
 * A key sends events with event scopes: {@see ALL_EVENTS}, or
 * {@see event()} for one event type. The events a connected site forwards,
 * such as `sale-triggered`, need their exact `event:{slug}` scope, because
 * no wildcard reaches them. {@see FULL_ACCESS} is still accepted, and grants
 * what {@see ALL_EVENTS} grants.
 *
 * A key also reads the ledger with the ledger scopes below.
 * {@see LEDGER_CONVERSIONS_READ}, {@see LEDGER_TRANSACTIONS_READ},
 * {@see LEDGER_OBLIGATIONS_READ} and {@see LEDGER_PAYOUTS_READ} open
 * `list()` and the read by id on `conversions`, `transactions`,
 * `obligations` and `payouts`.
 *
 * Siren grants each of these four only by its exact name. {@see FULL_ACCESS},
 * {@see ALL_EVENTS}, wildcards such as `ledger:*`, and the older names such
 * as `conversions:read` grant none of them. Siren refuses those older names
 * on a new key with a 400.
 *
 * No scope reaches API key management or webhook subscriptions. Those take
 * a signed-in dashboard session.
 *
 * `apps->scopes()` returns the scopes an app's key may hold.
 */
final class ApiKeyScope
{
    /** Every event type the key may send. */
    public const ALL_EVENTS = 'event:*';

    /** The full wildcard. Siren treats it as {@see ALL_EVENTS}, and it opens no ledger route. */
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
