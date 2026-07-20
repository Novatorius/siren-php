<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * Statuses a payout can hold (the `payouts` reconciliation reader and
 * `payout.*` webhook payloads).
 */
final class PayoutStatus
{
    public const UNPAID = 'unpaid';
    public const PROCESSING = 'processing';
    public const PAID = 'paid';
    public const FAILED = 'failed';

    private function __construct()
    {
    }
}
