<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * Statuses a fulfillment can hold (`fulfillment.created` /
 * `fulfillment.updated` webhook payloads).
 */
final class FulfillmentStatus
{
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const COMPLETE = 'complete';
    public const FAILED = 'failed';

    private function __construct()
    {
    }
}
