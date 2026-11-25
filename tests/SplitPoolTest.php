<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Siren\Sdk\Resources\Apps;

/**
 * `Apps::splitPool()`, the helper both resolver samples use. Each share is a
 * whole number of the currency's step, `10 ** (scale - decimalPlaces)` in the
 * stored unit, rounded down so the sum never passes the pool, and every
 * share is even when every weight is 0. The fixture's two resolver answers
 * are Siren's own (tests/Fixtures/apps-feature-contracts/requests.json).
 */
final class SplitPoolTest extends TestCase
{
    private const USD = ['id' => 'USD', 'symbol' => '$', 'position' => 'before', 'name' => 'US Dollar', 'scale' => 2, 'decimalPlaces' => 2, 'type' => 'monetary'];
    private const JPY = ['id' => 'JPY', 'symbol' => '¥', 'position' => 'before', 'name' => 'Japanese Yen', 'scale' => 2, 'decimalPlaces' => 0, 'type' => 'monetary'];

    /** @return array<string, mixed> */
    private static function kind(string $kind): array
    {
        $contracts = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/apps-feature-contracts/requests.json'), true, 512, JSON_THROW_ON_ERROR);

        return $contracts['kinds'][$kind];
    }

    /**
     * @param array<int|string, int> $shares
     * @param array<string, mixed>   $pool
     */
    private function assertFits(array $shares, array $pool): void
    {
        $step = 10 ** ($pool['currency']['scale'] - $pool['currency']['decimalPlaces']);

        foreach ($shares as $id => $share) {
            $this->assertIsInt($share, (string) $id);
            $this->assertGreaterThanOrEqual(0, $share);
            $this->assertSame(0, $share % $step, sprintf('Share %s is a whole number of %d.', $id, $step));
        }

        $this->assertLessThanOrEqual($pool['value'], array_sum($shares), 'The shares never pass the pool.');
    }

    public function testTheDistributionSampleGivesTheFixturesAnswer(): void
    {
        $kind = self::kind('distributionResolver');
        $data = $kind['request']['data'];

        $shares = $this->mockSiren([])->apps->splitPool($data['pool'], array_column($data['metrics'], 'score', 'metricId'));

        $this->assertSame($kind['answer']['shares'], json_decode(json_encode($shares, JSON_THROW_ON_ERROR), true));
        $this->assertSame([41 => 6000, 43 => 4000], $shares);
        $this->assertFits($shares, $data['pool']);
    }

    public function testTheIncentiveSampleGivesTheFixturesAnswer(): void
    {
        $kind = self::kind('incentiveResolver');
        $data = $kind['request']['data'];

        $rewards = $this->mockSiren([])->apps->splitPool($data['pool'], array_column($data['conversions'], 'score', 'conversionId'));

        $this->assertSame($kind['answer']['rewards'], json_decode(json_encode($rewards, JSON_THROW_ON_ERROR), true));
        $this->assertSame([1042 => 900, 1043 => 600], $rewards);
    }

    public function testADollarPoolIsRoundedDownToWholeCents(): void
    {
        $pool = ['value' => 1000, 'units' => 'USD', 'currency' => self::USD];

        $shares = Apps::splitPool($pool, [7 => 1, 8 => 1, 9 => 1]);

        $this->assertSame([7 => 333, 8 => 333, 9 => 333], $shares);
        $this->assertFits($shares, $pool);
        $this->assertSame([7 => 166, 8 => 333, 9 => 500], Apps::splitPool($pool, [7 => 1, 8 => 2, 9 => 3]));
    }

    public function testAYenPoolIsSplitInWholeYen(): void
    {
        // 10000 at scale 2 is 100 yen, and a yen has no smaller part, so every share is a multiple of 100.
        $pool = ['value' => 10000, 'units' => 'JPY', 'currency' => self::JPY];

        $shares = Apps::splitPool($pool, [41 => 1, 43 => 2]);

        $this->assertSame([41 => 3300, 43 => 6600], $shares);
        $this->assertFits($shares, $pool);

        $odd = ['value' => 10050, 'units' => 'JPY', 'currency' => self::JPY];
        $this->assertSame([41 => 10000], Apps::splitPool($odd, [41 => 5]), 'A part of a yen in the pool is left unpaid.');
    }

    public function testAllZeroWeightsSplitEvenly(): void
    {
        $dollars = ['value' => 1500, 'units' => 'USD', 'currency' => self::USD];
        $yen = ['value' => 10000, 'units' => 'JPY', 'currency' => self::JPY];

        $this->assertSame([1042 => 750, 1043 => 750], Apps::splitPool($dollars, [1042 => 0, 1043 => 0]));
        $this->assertSame([1 => 3300, 2 => 3300, 3 => 3300], $shares = Apps::splitPool($yen, [1 => 0, 2 => 0.0, 3 => 0]));
        $this->assertFits($shares, $yen);
    }

    public function testFractionalWeightsNeverPassThePoolAndLoseNoWholeStep(): void
    {
        $pool = ['value' => 300, 'units' => 'USD', 'currency' => self::USD];

        $this->assertSame([1 => 100, 2 => 200], Apps::splitPool($pool, [1 => 0.1, 2 => 0.2]));
        // 90 * 0.7 / (0.7 + 0.1 + 0.2) is 62.99999999999999 in floating point.
        $this->assertSame([1 => 63, 2 => 9, 3 => 18], Apps::splitPool(['value' => 90, 'units' => 'USD', 'currency' => self::USD], [1 => 0.7, 2 => 0.1, 3 => 0.2]));

        $shares = Apps::splitPool(['value' => 1000000, 'units' => 'USD', 'currency' => self::USD], [1 => 1 / 3, 2 => 1 / 3, 3 => 1 / 3]);
        $this->assertSame([1 => 333333, 2 => 333333, 3 => 333333], $shares);
    }

    public function testLargeWholeWeightsStayExact(): void
    {
        $pool = ['value' => 9_000_000_000_000, 'units' => 'USD', 'currency' => self::USD];

        $shares = Apps::splitPool($pool, [1 => PHP_INT_MAX >> 2, 2 => PHP_INT_MAX >> 2]);

        $this->assertSame([1 => 4_500_000_000_000, 2 => 4_500_000_000_000], $shares);
        $this->assertFits($shares, $pool);
    }

    public function testNoWeightsGiveNoShares(): void
    {
        $this->assertSame([], Apps::splitPool(['value' => 1000, 'units' => 'USD', 'currency' => self::USD], []));
    }

    /** @return array<string, array{array<string, mixed>, array<int|string, mixed>, string}> */
    public static function refused(): array
    {
        $pool = ['value' => 1000, 'units' => 'USD', 'currency' => self::USD];

        return [
            'a negative weight' => [$pool, [1 => 5, 2 => -1], 'weight for 2'],
            'an infinite weight' => [$pool, [1 => INF], 'weight for 1'],
            'a weight that is not a number' => [$pool, [1 => NAN], 'weight for 1'],
            'a weight that is text' => [$pool, [1 => '30'], 'weight for 1'],
            'a list of pairs' => [$pool, [[41, 30], [43, 20]], 'weight for 0'],
            'a pool with no value' => [['units' => 'USD', 'currency' => self::USD], [1 => 1], 'pool value'],
            'a negative pool' => [['value' => -1, 'currency' => self::USD], [1 => 1], 'pool value'],
            'a pool with no currency' => [['value' => 1000, 'units' => 'USD'], [1 => 1], 'currency'],
            'decimal places past the scale' => [['value' => 1000, 'currency' => ['scale' => 0, 'decimalPlaces' => 2] + self::USD], [1 => 1], 'currency'],
        ];
    }

    /**
     * @param array<string, mixed>     $pool
     * @param array<int|string, mixed> $weights
     */
    #[DataProvider('refused')]
    public function testABadPoolOrWeightIsRefused(array $pool, array $weights, string $words): void
    {
        try {
            Apps::splitPool($pool, $weights);
            $this->fail('splitPool() took a pool or weight it cannot split.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('apps->splitPool()', $e->getMessage());
            $this->assertStringContainsString($words, $e->getMessage());
        }
    }
}
