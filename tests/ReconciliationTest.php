<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

final class ReconciliationTest extends TestCase
{
    public function testListSendsPagingAndPassThroughFiltersAsQueryArgs(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, [])]);

        $siren->conversions->list(['page' => 2, 'perPage' => 50, 'status' => 'approved']);

        $request = $this->lastRequest();
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/siren/v1/conversions', $request->getUri()->getPath());

        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame(['page' => '2', 'perPage' => '50', 'status' => 'approved'], $query);
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
