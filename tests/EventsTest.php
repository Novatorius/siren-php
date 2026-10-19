<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Siren\Sdk\EventResult;
use Siren\Sdk\EventStatus;

final class EventsTest extends TestCase
{
    public function testSalePostsToEventSaleWithTheExactPayload(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, [], ['X-Siren-OID' => '4021'])]);

        $result = $siren->events->sale([
            'source' => 'stripe',
            'externalId' => 'cs_test_a1b2c3',
            'total' => 49.99,
            'trackingId' => 4021,
            'currency' => 'EUR',
        ]);

        $request = $this->lastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.sirenaffiliates.com/siren/v1/event/sale', (string) $request->getUri());
        $this->assertSame([
            'source' => 'stripe',
            'externalId' => 'cs_test_a1b2c3',
            'total' => 49.99,
            'trackingId' => 4021,
            'currency' => 'EUR',
        ], $this->lastRequestJson());
        $this->assertInstanceOf(EventResult::class, $result);
        $this->assertSame(4021, $result->getOpportunityId());
    }

    public function testSaleKeepsTotalInMajorUnits(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200)]);

        $siren->events->sale([
            'source' => 'woocommerce',
            'externalId' => 'order-77',
            'total' => 9.99,
            'trackingId' => 1,
        ]);

        // 9.99 means $9.99 — the SDK must never convert to minor units.
        $this->assertSame(9.99, $this->lastRequestJson()['total']);
    }

    public function testSaleDefaultsMissingLineItemQuantityToOne(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200)]);

        $siren->events->sale([
            'source' => 'stripe',
            'externalId' => 'order-1',
            'total' => 129.97,
            'trackingId' => 7,
            'items' => [
                ['name' => 'Pro Plan (annual)', 'amount' => 49.99],
                ['name' => 'Add-on seat', 'amount' => 39.99, 'quantity' => 2],
            ],
        ]);

        $items = $this->lastRequestJson()['items'];
        $this->assertSame(1, $items[0]['quantity'], 'A missing quantity must default to 1.');
        $this->assertSame(2, $items[1]['quantity'], 'An explicit quantity must be preserved.');
        $this->assertSame(49.99, $items[0]['amount'], 'amount is per-unit in major units.');
    }

    #[DataProvider('saleRequiredFieldProvider')]
    public function testSaleRequiresEachRequiredField(string $missing): void
    {
        $params = [
            'source' => 'stripe',
            'externalId' => 'order-1',
            'total' => 9.99,
            'trackingId' => 42,
        ];
        unset($params[$missing]);

        $siren = $this->mockSiren([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($missing);

        $siren->events->sale($params);
    }

    public static function saleRequiredFieldProvider(): array
    {
        return [
            'source' => ['source'],
            'externalId' => ['externalId'],
            'total' => ['total'],
            'trackingId' => ['trackingId'],
        ];
    }

    public function testRefundPostsToEventRefund(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, [], ['X-Siren-OID' => '99'])]);

        $result = $siren->events->refund(['source' => 'stripe', 'externalId' => 'cs_test_a1b2c3']);

        $request = $this->lastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.sirenaffiliates.com/siren/v1/event/refund', (string) $request->getUri());
        $this->assertSame(['source' => 'stripe', 'externalId' => 'cs_test_a1b2c3'], $this->lastRequestJson());
        $this->assertSame(99, $result->getOpportunityId());
    }

    public function testRefundRequiresSourceAndExternalId(): void
    {
        $siren = $this->mockSiren([]);

        $this->expectException(\InvalidArgumentException::class);

        $siren->events->refund(['source' => 'stripe']);
    }

    public function testSiteVisitedPostsToEventSiteVisited(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, [], ['X-Siren-OID' => '311'])]);

        $result = $siren->events->siteVisited(['collaboratorId' => 88, 'userId' => 12345]);

        $request = $this->lastRequest();
        $this->assertSame('https://api.sirenaffiliates.com/siren/v1/event/site-visited', (string) $request->getUri());
        $this->assertSame(['collaboratorId' => 88, 'userId' => 12345], $this->lastRequestJson());
        $this->assertSame(311, $result->getOpportunityId());
    }

    public function testSiteVisitedRequiresCollaboratorId(): void
    {
        $siren = $this->mockSiren([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('collaboratorId');

        $siren->events->siteVisited(['userId' => 12345]);
    }

    public function testIngestPostsToTheCustomSlugAndPassesThePayloadThrough(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, [], ['X-Siren-OID' => '5'])]);

        $payload = ['points' => 250, 'reason' => 'review-submitted'];
        $result = $siren->events->ingest('loyalty-points-earned', $payload);

        $request = $this->lastRequest();
        $this->assertSame(
            'https://api.sirenaffiliates.com/siren/v1/event/loyalty-points-earned',
            (string) $request->getUri(),
        );
        $this->assertSame($payload, $this->lastRequestJson());
        $this->assertSame(5, $result->getOpportunityId());
    }

    public function testIngestUrlEncodesTheSlug(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200)]);

        $siren->events->ingest('weird/slug', []);

        $this->assertSame(
            'https://api.sirenaffiliates.com/siren/v1/event/weird%2Fslug',
            (string) $this->lastRequest()->getUri(),
        );
    }

    public function testIngestRejectsAnEmptySlug(): void
    {
        $siren = $this->mockSiren([]);

        $this->expectException(\InvalidArgumentException::class);

        $siren->events->ingest('', []);
    }

    public function testOpportunityIdIsNullWhenTheHeaderIsAbsent(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200)]);

        $result = $siren->events->sale([
            'source' => 'stripe',
            'externalId' => 'order-1',
            'total' => 9.99,
            'trackingId' => 42,
        ]);

        $this->assertNull($result->getOpportunityId());
    }

    /** A sale answers `{status, created, transactionId, conversions, message}` (Siren's SaleIngestion). */
    public function testSaleReadsTheOutcomeSirenReports(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, [
            'status' => 'created',
            'created' => true,
            'transactionId' => 77,
            'conversions' => [['id' => 501, 'status' => 'pending']],
            'message' => 'The sale was recorded.',
        ], ['X-Siren-OID' => '4021'])]);

        $result = $siren->events->sale(['source' => 'stripe', 'externalId' => 'o-1', 'total' => 10, 'trackingId' => 4021]);

        $this->assertSame(200, $result->getHttpStatus());
        $this->assertSame(EventStatus::CREATED, $result->getStatus());
        $this->assertFalse($result->isQueued());
        $this->assertSame(77, $result->getTransactionId());
        $this->assertSame([['id' => 501, 'status' => 'pending']], $result->getConversions());
        $this->assertSame('The sale was recorded.', $result->getMessage());
        $this->assertNull($result->getReason());
    }

    public function testSaleThatEarnedNothingCarriesItsReason(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, [
            'status' => 'recorded',
            'created' => true,
            'transactionId' => 78,
            'conversions' => [],
            'reason' => 'engagement_ended',
            'message' => 'The sale was recorded and earned nothing.',
        ])]);

        $result = $siren->events->sale(['source' => 'stripe', 'externalId' => 'o-2', 'total' => 10, 'trackingId' => 9]);

        $this->assertSame(EventStatus::RECORDED, $result->getStatus());
        $this->assertSame(EventStatus::REASON_ENGAGEMENT_ENDED, $result->getReason());
        $this->assertSame([], $result->getConversions());
    }

    /** While the org's engine is paused, Siren queues the event and answers 202. */
    public function testAQueuedEventIsReportedAsQueued(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(202, [
            'status' => 'queued',
            'queued' => true,
            'message' => 'Engine activity is paused for this organization.',
        ])]);

        $result = $siren->events->sale(['source' => 'stripe', 'externalId' => 'o-3', 'total' => 10, 'trackingId' => 9]);

        $this->assertSame(202, $result->getHttpStatus());
        $this->assertTrue($result->isQueued());
        $this->assertSame(EventStatus::QUEUED, $result->getStatus());
        $this->assertNull($result->getTransactionId());
        $this->assertCount(1, $this->history, 'A queued event is a success and is never retried.');
    }

    /** A queued visit names the opportunity it will be recorded on in its body. */
    public function testAQueuedVisitReturnsItsOpportunityFromTheBody(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(202, [
            'status' => 'queued',
            'queued' => true,
            'attributed' => false,
            'opportunityId' => 318,
            'message' => 'Engine activity is paused for this organization.',
        ])]);

        $result = $siren->events->siteVisited(['collaboratorId' => 42]);

        $this->assertTrue($result->isQueued());
        $this->assertSame(318, $result->getOpportunityId());
        $this->assertFalse($result->isAttributed());
    }

    public function testAVisitThatWasNotAttributedSaysWhy(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, [
            'status' => 'not_attributed',
            'attributed' => false,
            'opportunityId' => null,
            'reason' => 'collaborator_not_active',
            'message' => 'The visit was not attributed. The collaborator is not active.',
        ])]);

        $result = $siren->events->siteVisited(['collaboratorId' => 42]);

        $this->assertSame(EventStatus::NOT_ATTRIBUTED, $result->getStatus());
        $this->assertSame(EventStatus::REASON_COLLABORATOR_NOT_ACTIVE, $result->getReason());
        $this->assertFalse($result->isAttributed());
        $this->assertNull($result->getOpportunityId());
    }

    public function testTheHeaderOpportunityWinsOverTheBody(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, [
            'status' => 'attributed',
            'attributed' => true,
            'opportunityId' => 318,
        ], ['X-Siren-OID' => '318'])]);

        $result = $siren->events->siteVisited(['collaboratorId' => 42]);

        $this->assertTrue($result->isAttributed());
        $this->assertSame(318, $result->getOpportunityId());
    }

    public function testARefundWithNoBodyHasNoStatus(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200)]);

        $result = $siren->events->refund(['source' => 'stripe', 'externalId' => 'o-1']);

        $this->assertNull($result->getStatus());
        $this->assertSame([], $result->toArray());
        $this->assertFalse($result->isQueued());
    }

    public function testANonNumericOpportunityHeaderIsIgnored(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, [], ['X-Siren-OID' => '1e3'])]);

        $result = $siren->events->refund(['source' => 'stripe', 'externalId' => 'o-1']);

        $this->assertNull($result->getOpportunityId());
    }
}
