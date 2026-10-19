<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use ReflectionClass;
use Siren\Sdk\ApiKeyStatus;
use Siren\Sdk\CollaboratorStatus;
use Siren\Sdk\ConversionStatus;
use Siren\Sdk\CustomCurrencyType;
use Siren\Sdk\DistributorStatus;
use Siren\Sdk\EventSlug;
use Siren\Sdk\FulfillmentStatus;
use Siren\Sdk\ObligationStatus;
use Siren\Sdk\OpportunityStatus;
use Siren\Sdk\PayoutStatus;
use Siren\Sdk\ProgramGroupSorter;
use Siren\Sdk\ProgramStatus;
use Siren\Sdk\TransactionStatus;
use Siren\Sdk\WebhookEventType;
use Siren\Sdk\WebhookSubscriptionStatus;

/**
 * Pins the SDK's taxonomy to Siren's canonical vocabulary. If one of these
 * fails, either the SDK drifted or the Siren service changed its domain
 * language — reconcile against the service (the taxonomy owner), not by
 * editing the expectation to match the code.
 */
final class TaxonomyTest extends BaseTestCase
{
    /**
     * @return list<string>
     */
    private function constantValues(string $class): array
    {
        $values = array_values((new ReflectionClass($class))->getConstants());
        sort($values);

        return $values;
    }

    public function testWebhookEventTypeMatchesCanonicalSet(): void
    {
        $expected = [
            '*',
            'allocation.completed',
            'collaborator.created',
            'collaborator.deactivated',
            'collaborator.registered',
            'collaborator.updated',
            'conversion.approved',
            'conversion.created',
            'conversion.rejected',
            'conversion.renewed',
            'coupon.applied',
            'credit.issued',
            'credit.redeemed',
            'currency.created',
            'currency.deleted',
            'distribution.completed',
            'engagement.awarded',
            'engagement.completed',
            'engagement.created',
            'fulfillment.created',
            'fulfillment.updated',
            'lead.created',
            'metrics.updated',
            'obligation.completed',
            'obligation.created',
            'opportunity.created',
            'opportunity.invalidated',
            'payout.created',
            'payout.paid',
            'program.created',
            'refund.created',
            'renewal.created',
            'sale.created',
            'transaction.completed',
            'transaction.created',
        ];
        sort($expected);

        $this->assertSame($expected, $this->constantValues(WebhookEventType::class));
    }

    public function testWebhookEventTypeHasNoDuplicateValues(): void
    {
        $values = $this->constantValues(WebhookEventType::class);

        $this->assertSame($values, array_values(array_unique($values)));
    }

    public function testEventSlugMatchesBuiltInIngestionSlugs(): void
    {
        $this->assertSame(['refund', 'sale', 'site-visited'], $this->constantValues(EventSlug::class));
    }

    public function testConversionStatusMatchesCanonicalSet(): void
    {
        $this->assertSame(
            ['approved', 'deleted', 'expired', 'pending', 'rejected'],
            $this->constantValues(ConversionStatus::class)
        );
    }

    public function testTransactionStatusMatchesCanonicalSet(): void
    {
        $this->assertSame(
            ['cancelled', 'complete', 'refunded'],
            $this->constantValues(TransactionStatus::class)
        );
    }

    public function testObligationStatusMatchesCanonicalSet(): void
    {
        $this->assertSame(
            ['approved', 'cancelled', 'complete', 'deleted', 'draft', 'fulfilled', 'pending', 'rejected'],
            $this->constantValues(ObligationStatus::class)
        );
    }

    public function testPayoutStatusMatchesCanonicalSet(): void
    {
        $this->assertSame(
            ['failed', 'paid', 'processing', 'unpaid'],
            $this->constantValues(PayoutStatus::class)
        );
    }

    public function testFulfillmentStatusMatchesCanonicalSet(): void
    {
        $this->assertSame(
            ['complete', 'failed', 'pending', 'processing'],
            $this->constantValues(FulfillmentStatus::class)
        );
    }

    public function testOpportunityStatusMatchesCanonicalSet(): void
    {
        $this->assertSame(
            ['active', 'inactive', 'invalid'],
            $this->constantValues(OpportunityStatus::class)
        );
    }

    public function testApiKeyStatusMatchesCanonicalSet(): void
    {
        $this->assertSame(['active', 'revoked'], $this->constantValues(ApiKeyStatus::class));
    }

    public function testWebhookSubscriptionStatusMatchesCanonicalSet(): void
    {
        $this->assertSame(['active', 'paused'], $this->constantValues(WebhookSubscriptionStatus::class));
    }
    public function testCollaboratorStatusMatchesCanonicalSet(): void
    {
        // Siren's CollaboratorStatusProvider (create and update statuses)
        // plus `deleted`, which DELETE and the delete bulk action set.
        $this->assertSame(
            ['active', 'deleted', 'inactive', 'pending', 'rejected'],
            $this->constantValues(CollaboratorStatus::class)
        );
    }

    public function testProgramStatusMatchesCanonicalSet(): void
    {
        $this->assertSame(['active', 'deleted', 'inactive'], $this->constantValues(ProgramStatus::class));
    }

    public function testDistributorStatusMatchesCanonicalSet(): void
    {
        $this->assertSame(['active', 'deleted', 'inactive'], $this->constantValues(DistributorStatus::class));
    }

    public function testProgramGroupSorterMatchesCanonicalSet(): void
    {
        $this->assertSame(['newestBindingWins', 'oldestBindingWins'], $this->constantValues(ProgramGroupSorter::class));
    }

    public function testCustomCurrencyTypeMatchesCanonicalSet(): void
    {
        $this->assertSame(['internal', 'monetary'], $this->constantValues(CustomCurrencyType::class));
    }
}
