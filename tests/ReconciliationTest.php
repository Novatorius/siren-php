<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

final class ReconciliationTest extends TestCase
{
    /**
     * Until 0.2.0 this test expected `page` and `perPage` to reach the API
     * unchanged. Siren never read them: every list route runs
     * PageSizeMiddleware, which reads `number` (page size) and `offset`
     * (first row), so `page=2&perPage=50` silently returned the first ten
     * rows. The behavior changed on purpose: the SDK keeps accepting
     * `page`/`perPage` and translates them to the parameters Siren reads.
     */
    public function testListTranslatesPagingToNumberAndOffsetAndPassesFiltersThrough(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, [])]);

        $siren->conversions->list(['page' => 2, 'perPage' => 50, 'status' => 'approved']);

        $request = $this->lastRequest();
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/siren/v1/conversions', $request->getUri()->getPath());

        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame(['status' => 'approved', 'number' => '50', 'offset' => '50'], $query);
    }

    public function testListPassesNumberAndOffsetThroughUnchanged(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, [])]);

        $siren->obligations->list(['number' => 200, 'offset' => 400]);

        parse_str($this->lastRequest()->getUri()->getQuery(), $query);
        $this->assertSame(['number' => '200', 'offset' => '400'], $query);
    }

    public function testAPageSizeAbove200IsClampedBeforeTheOffsetIsWorkedOut(): void
    {
        // Siren lowers number to 200. Without the clamp, page 2 of perPage 500
        // would start at offset 500 and rows 200 to 499 would never come back.
        $siren = $this->mockSiren([self::jsonResponse(200, [])]);

        $siren->conversions->list(['page' => 2, 'perPage' => 500]);

        parse_str($this->lastRequest()->getUri()->getQuery(), $query);
        $this->assertSame(['number' => '200', 'offset' => '200'], $query);
    }

    public function testListReadsSirensPaginatedEnvelope(): void
    {
        // Every Siren list route wraps its rows with ListResponseWrapperInterceptor.
        $siren = $this->mockSiren([
            self::jsonResponse(200, [
                'items' => [['id' => 7], ['id' => 8]],
                'total' => 42,
                'page' => 3,
                'perPage' => 2,
                'totalPages' => 21,
            ]),
        ]);

        $result = $siren->payouts->list(['page' => 3, 'perPage' => 2]);

        $this->assertSame([['id' => 7], ['id' => 8]], $result->getData());
        $this->assertSame(3, $result->getPage());
        $this->assertSame(2, $result->getPerPage());
        $this->assertSame(42, $result->getTotal());
        $this->assertSame(21, $result->getTotalPages());
        $this->assertTrue($result->hasMore());
    }

    public function testAnEmptyEnvelopeIsAnEmptyPage(): void
    {
        $siren = $this->mockSiren([
            self::jsonResponse(200, ['items' => [], 'total' => 0, 'page' => 1, 'perPage' => 10, 'totalPages' => 1]),
        ]);

        $result = $siren->conversions->list();

        $this->assertSame([], $result->getData());
        $this->assertSame(0, $result->getTotal());
        $this->assertFalse($result->hasMore());
    }

    public function testListReturnsTheBareArrayBodyWithPagingEchoedFromParams(): void
    {
        // The body is a bare JSON array — no envelope. The total (when known)
        // comes from the x-siren-estimated-count response header; page/perPage
        // reflect the requested params.
        $siren = $this->mockSiren([
            self::jsonResponse(200, [['id' => 10], ['id' => 11]], ['x-siren-estimated-count' => '120']),
        ]);

        $result = $siren->transactions->list(['page' => 1, 'perPage' => 25]);

        $this->assertSame([['id' => 10], ['id' => 11]], $result->getData());
        $this->assertSame(1, $result->getPage());
        $this->assertSame(25, $result->getPerPage());
        $this->assertSame(120, $result->getTotal());
        $this->assertCount(2, $result);
        $this->assertSame([['id' => 10], ['id' => 11]], iterator_to_array($result));
    }

    public function testTotalComesFromTheEstimatedCountHeader(): void
    {
        $siren = $this->mockSiren([
            self::jsonResponse(200, [['id' => 1]], ['X-Siren-Estimated-Count' => '4200']),
        ]);

        // Header lookup is case-insensitive.
        $this->assertSame(4200, $siren->payouts->list()->getTotal());
    }

    public function testListToleratesAMissingEstimatedCountHeader(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, [['id' => 1]])]);

        $result = $siren->obligations->list();

        $this->assertSame([['id' => 1]], $result->getData());
        $this->assertNull($result->getPage());
        $this->assertNull($result->getPerPage());
        $this->assertNull($result->getTotal());
    }

    public function testEachReaderTargetsItsOwnPath(): void
    {
        $paths = [
            'conversions' => '/siren/v1/conversions',
            'transactions' => '/siren/v1/transactions',
            'obligations' => '/siren/v1/obligations',
            'payouts' => '/siren/v1/payouts',
        ];

        foreach ($paths as $property => $expectedPath) {
            $siren = $this->mockSiren([self::jsonResponse(200, [])]);
            $siren->{$property}->list();
            $this->assertSame($expectedPath, $this->lastRequest()->getUri()->getPath());
        }
    }
}
