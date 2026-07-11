<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Siren\Sdk\EventResult;

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
}
