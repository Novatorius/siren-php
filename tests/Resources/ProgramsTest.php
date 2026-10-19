<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests\Resources;

use Siren\Sdk\ProgramStatus;
use Siren\Sdk\Resources\Programs;
use Siren\Sdk\Tests\TestCase;

final class ProgramsTest extends TestCase
{
    public function testListSendsFiltersAndGroupFilter(): void
    {
        $siren = $this->hostSiren([self::hostPage([['id' => 1]], 1)]);

        $siren->programs->list(['status' => ProgramStatus::ACTIVE, 'units' => 'USD', 'programGroupId' => 2, 'number' => 50]);

        $this->assertSame('/siren/v1/programs', $this->sentPath());
        $this->assertSame(['status' => 'active', 'units' => 'USD', 'programGroupId' => '2', 'number' => '50'], $this->sentQuery());
    }

    public function testCrudRoutes(): void
    {
        $siren = $this->hostSiren([
            self::hostJson(200, ['id' => 4]),
            self::hostJson(201, ['id' => 4]),
            self::hostJson(200),
            self::hostJson(200, ['id' => 4, 'status' => 'deleted']),
        ]);

        $siren->programs->retrieve(4, ['fields' => 'id,name']);
        $this->assertSame(['GET', '/siren/v1/programs/4'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame(['fields' => 'id,name'], $this->sentQuery());

        $create = [
            'name' => 'Partners',
            'incentiveType' => 'saleTotalPercentage',
            'incentiveResolverType' => 'firstEngagementWins',
            'units' => 'USD',
            'status' => ProgramStatus::ACTIVE,
            'engagementTypes' => ['sale' => ['calculationType' => 'percentage', 'calculationArgs' => ['percentage' => 10]]],
        ];
        $siren->programs->create($create);
        $this->assertSame(['POST', '/siren/v1/programs'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame($create, $this->sentJson());

        $this->assertSame([], $siren->programs->update(4, ['status' => ProgramStatus::INACTIVE]));
        $this->assertSame(['PUT', '/siren/v1/programs/4'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame(['status' => 'inactive'], $this->sentJson());

        $siren->programs->delete(4);
        $this->assertSame(['DELETE', '/siren/v1/programs/4'], [$this->sent()->getMethod(), $this->sentPath()]);
    }

    public function testBulkAndVocabularyRoutes(): void
    {
        $siren = $this->hostSiren([
            self::hostJson(200, ['success' => true]),
            self::hostJson(200, ['active' => 'Active']),
            self::hostJson(200, ['saleTotalPercentage' => 'Sale total percentage']),
            self::hostJson(200, ['USD' => 'US Dollar']),
        ]);

        $siren->programs->bulk(Programs::BULK_DEACTIVATE, [1, 2]);
        $this->assertSame('/siren/v1/programs/bulk', $this->sentPath());
        $this->assertSame(['action' => 'deactivate', 'ids' => [1, 2]], $this->sentJson());

        $siren->programs->statusTypes();
        $this->assertSame('/siren/v1/programs/status-types', $this->sentPath());
        $siren->programs->incentiveTypes();
        $this->assertSame('/siren/v1/programs/incentive-types', $this->sentPath());
        $siren->programs->currencyTypes();
        $this->assertSame('/siren/v1/programs/currency-types', $this->sentPath());
    }

    /** Siren's BulkIdsMiddleware::MAX_IDS refuses more than 500 ids with a 400. */
    public function testBulkRefusesMoreThanFiveHundredIds(): void
    {
        $siren = $this->hostSiren([]);

        try {
            $siren->programs->bulk(Programs::BULK_DEACTIVATE, range(1, 501));
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('500', $e->getMessage());
        }

        $this->assertSame([], $this->http->requests);
    }

    public function testBulkSendsFiveHundredIds(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, ['updated' => 500])]);

        $siren->programs->bulk(Programs::BULK_DEACTIVATE, range(1, 500));

        $this->assertCount(500, $this->sentJson()['ids']);
    }
}
