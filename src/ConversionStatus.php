<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * Statuses a conversion can hold (the `conversions` reconciliation reader
 * and `conversion.*` webhook payloads).
 */
final class ConversionStatus
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const EXPIRED = 'expired';

    /** Soft-delete bucket written by the bulk delete action. */
    public const DELETED = 'deleted';

    private function __construct()
    {
    }
}
