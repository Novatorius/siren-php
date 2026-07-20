<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * Statuses an opportunity can hold (`opportunity.created` /
 * `opportunity.invalidated` webhook payloads; the `trackingId` on a sale
 * refers to an opportunity).
 */
final class OpportunityStatus
{
    public const ACTIVE = 'active';
    public const INACTIVE = 'inactive';

    /** Set by Siren's invalidation service; never operator-settable. */
    public const INVALID = 'invalid';

    private function __construct()
    {
    }
}
