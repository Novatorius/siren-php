<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * The scopes an API key may be given (`apiKeys->create()`).
 *
 * An API key sends events to Siren and does nothing else, so a new key
 * holds event scopes only: {@see ALL_EVENTS}, or {@see event()} for one
 * event type. Siren refuses a scope that names a record type, such as
 * `programs:read`, with a 400. {@see FULL_ACCESS} is still accepted, and on
 * the event routes it grants what {@see ALL_EVENTS} grants. The events a
 * connected site forwards, such as `sale-triggered`, need their exact
 * `event:{slug}` scope, because no wildcard reaches them.
 *
 * `apiKeys->scopes()` returns the scopes the organization offers.
 */
final class ApiKeyScope
{
    /** Every event type the key may send. */
    public const ALL_EVENTS = 'event:*';

    /** The full wildcard. Siren treats it as {@see ALL_EVENTS} on the event routes. */
    public const FULL_ACCESS = '*';

    public const SALE = 'event:' . EventSlug::SALE;
    public const REFUND = 'event:' . EventSlug::REFUND;
    public const SITE_VISITED = 'event:' . EventSlug::SITE_VISITED;

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
