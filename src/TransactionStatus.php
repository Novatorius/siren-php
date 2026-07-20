<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * Statuses a transaction can hold (the `transactions` reconciliation reader
 * and `transaction.*` webhook payloads).
 */
final class TransactionStatus
{
    public const COMPLETE = 'complete';
    public const CANCELLED = 'cancelled';
    public const REFUNDED = 'refunded';

    private function __construct()
    {
    }
}
