<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * Statuses an obligation can hold (the `obligations` reconciliation reader
 * and `obligation.*` webhook payloads).
 *
 * Note: Siren's machine paths (fulfillment generation, bulk actions) write
 * `complete`, while its management REST surface accepts `fulfilled` — both
 * appear in the wild, so both are listed here.
 */
final class ObligationStatus
{
    public const PENDING = 'pending';
    public const COMPLETE = 'complete';
    public const FULFILLED = 'fulfilled';
    public const CANCELLED = 'cancelled';

    private function __construct()
    {
    }
}
