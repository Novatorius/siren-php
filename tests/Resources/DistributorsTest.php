<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests\Resources;

use Siren\Sdk\DistributorStatus;
use Siren\Sdk\Resources\Distributors;
use Siren\Sdk\Tests\TestCase;

final class DistributorsTest extends TestCase
{
    public function testRoutes(): void
    {
        $siren = $this->hostSiren([
            self::hostPage([['id' => 5]], 1),
            self::hostJson(200, ['id' => 5]),
            self::hostJson(201, ['id' => 5]),
            self::hostJson(200, ['id' => 5]),
            self::hostJson(200, ['id' => 5]),
            self::hostJson(200, ['success' => true]),
            self::hostJson(200, ['success' => true]),
        ]);

        $siren->distributors->list(['status' => DistributorStatus::ACTIVE, 's' => 'monthly']);
        $this->assertSame('/siren/v1/distributors', $this->sentPath());
        $this->assertSame(['status' => 'active', 's' => 'monthly'], $this->sentQuery());

        $siren->distributors->retrieve(5);
        $this->assertSame('/siren/v1/distributors/5', $this->sentPath());

        $create = [
            'name' => 'Monthly pool',
            'description' => 'Shares revenue monthly',
            'distributionResolver' => 'percentage_based',
            'distributionPoolResolver' => 'revenueSinceLastDistribution',
            'status' => DistributorStatus::ACTIVE,
            'units' => 'USD',
            'schedule' => ['first day of next month'],
            'collaboratorIds' => [8],
        ];
        $siren->distributors->create($create);
        $this->assertSame(['POST', '/siren/v1/distributors'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame($create, $this->sentJson());

        $siren->distributors->update(5, ['status' => DistributorStatus::INACTIVE]);
        $this->assertSame(['PUT', '/siren/v1/distributors/5'], [$this->sent()->getMethod(), $this->sentPath()]);

        $siren->distributors->delete(5);
        $this->assertSame(['DELETE', '/siren/v1/distributors/5'], [$this->sent()->getMethod(), $this->sentPath()]);

        $siren->distributors->bulk(Distributors::BULK_ACTIVATE, [5]);
        $this->assertSame(['action' => 'activate', 'ids' => [5]], $this->sentJson());

        $siren->distributors->creditMetric(8);
        $this->assertSame(['POST', '/siren/v1/distributors/credit-metric'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame(['collaboratorId' => 8], $this->sentJson());
    }
}
