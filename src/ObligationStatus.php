<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * Statuses an obligation can hold (the `obligations` reconciliation reader
 * and `obligation.*` webhook payloads).
 *
 * Siren's set is `draft`, `pending`, `approved`, `rejected`, `complete`,
 * `deleted` and `cancelled`. A `draft` obligation is the reward a pending
 * conversion will produce. Siren no longer accepts `fulfilled`, which
 * nothing reads. {@see FULFILLED} stays so code that names it still runs,
 * and an obligation that is paid out is `complete`.
 */
final class ObligationStatus
{
    public const DRAFT = 'draft';
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const COMPLETE = 'complete';
    public const DELETED = 'deleted';
    public const CANCELLED = 'cancelled';

    /** Retired. Siren refuses it, and writes `complete` for a paid-out obligation. */
    public const FULFILLED = 'fulfilled';

    private function __construct()
    {
    }
}
