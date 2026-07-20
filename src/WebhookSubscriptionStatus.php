<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * Statuses a webhook subscription can hold (the `webhooks` subscriptions
 * resource).
 */
final class WebhookSubscriptionStatus
{
    public const ACTIVE = 'active';
    public const PAUSED = 'paused';

    private function __construct()
    {
    }
}
