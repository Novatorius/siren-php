<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests\Resources;

use Siren\Sdk\Tests\TestCase;

final class CreditsTest extends TestCase
{
    public function testAdjustPostsTheAdjustment(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, ['balance' => 1500])]);

        $result = $siren->credits->adjust(8, 'PTS', -500, 'Redeemed at checkout');

        $this->assertSame(['balance' => 1500], $result);
        $this->assertSame(['POST', '/siren/v1/credits/adjust'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame(['collaboratorId' => 8, 'currency' => 'PTS', 'amount' => -500, 'description' => 'Redeemed at checkout'], $this->sentJson());
    }

    public function testAdjustIsNeverRetried(): void
    {
        $siren = $this->hostSiren([self::hostJson(502, ['error' => 'bad gateway']), self::hostJson(200)]);

        try {
            $siren->credits->adjust(8, 'PTS', 100);
            $this->fail('Expected an ApiException.');
        } catch (\Siren\Sdk\Exception\ApiException) {
        }

        $this->assertCount(1, $this->http->requests, 'Repeating an adjustment would apply it twice.');
    }

    public function testAdjustRefusesAZeroAmount(): void
    {
        $siren = $this->hostSiren([]);

        $this->expectException(\InvalidArgumentException::class);

        $siren->credits->adjust(8, 'PTS', 0);
    }

    public function testBalancesReadTheCreditBalancesField(): void
    {
        $balances = [['currency' => 3, 'balance' => 2000, 'onHold' => 500, 'available' => 1500, 'name' => 'Points']];
        $siren = $this->hostSiren([self::hostJson(200, ['id' => 8, 'creditBalances' => $balances])]);

        $this->assertSame($balances, $siren->credits->balances(8));
        $this->assertSame('/siren/v1/collaborators/8', $this->sentPath());
        $this->assertSame(['fields' => 'id,creditBalances'], $this->sentQuery());
    }
}
