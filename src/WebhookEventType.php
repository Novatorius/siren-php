<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * Every subscribable webhook event type.
 *
 * Use these constants when creating subscriptions or dispatching on
 * `WebhookEvent::getType()` — they give you autocomplete and typo protection.
 * `WebhookEventType::ALL` (`*`) subscribes to every event.
 */
final class WebhookEventType
{
    /** Subscribe to all event types. */
    public const ALL = '*';

    public const ALLOCATION_COMPLETED = 'allocation.completed';
    public const COLLABORATOR_CREATED = 'collaborator.created';
    public const COLLABORATOR_REGISTERED = 'collaborator.registered';
    public const CONVERSION_APPROVED = 'conversion.approved';
    public const CONVERSION_CREATED = 'conversion.created';
    public const CONVERSION_REJECTED = 'conversion.rejected';
    public const CONVERSION_RENEWED = 'conversion.renewed';
    public const COUPON_APPLIED = 'coupon.applied';
    public const CREDIT_ISSUED = 'credit.issued';
    public const CREDIT_REDEEMED = 'credit.redeemed';
    public const CURRENCY_CREATED = 'currency.created';
    public const CURRENCY_DELETED = 'currency.deleted';
    public const DISTRIBUTION_COMPLETED = 'distribution.completed';
    public const ENGAGEMENT_AWARDED = 'engagement.awarded';
    public const ENGAGEMENT_COMPLETED = 'engagement.completed';
    public const ENGAGEMENT_CREATED = 'engagement.created';
    public const FULFILLMENT_CREATED = 'fulfillment.created';
    public const FULFILLMENT_UPDATED = 'fulfillment.updated';
    public const LEAD_CREATED = 'lead.created';
    public const METRICS_UPDATED = 'metrics.updated';
    public const OBLIGATION_COMPLETED = 'obligation.completed';
    public const OBLIGATION_CREATED = 'obligation.created';
    public const OPPORTUNITY_CREATED = 'opportunity.created';
    public const OPPORTUNITY_INVALIDATED = 'opportunity.invalidated';
    public const PAYOUT_CREATED = 'payout.created';
    public const PAYOUT_PAID = 'payout.paid';
    public const REFUND_CREATED = 'refund.created';
    public const RENEWAL_CREATED = 'renewal.created';
    public const SALE_CREATED = 'sale.created';
    public const TRANSACTION_COMPLETED = 'transaction.completed';
    public const TRANSACTION_CREATED = 'transaction.created';

    private function __construct()
    {
    }
}
