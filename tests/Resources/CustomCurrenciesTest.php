<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests\Resources;

use Siren\Sdk\CustomCurrencyType;
use Siren\Sdk\Tests\TestCase;

final class CustomCurrenciesTest extends TestCase
{
    public function testRoutesAreKeyedByCode(): void
    {
        $siren = $this->hostSiren([
            self::hostPage([['code' => 'PTS']], 1),
            self::hostJson(201, ['code' => 'PTS']),
            self::hostJson(200, ['code' => 'PTS']),
            self::hostJson(204),
            self::hostJson(204),
        ]);

        $siren->customCurrencies->list(['type' => CustomCurrencyType::INTERNAL]);
        $this->assertSame('/siren/v1/currencies/custom', $this->sentPath());
        $this->assertSame(['type' => 'internal'], $this->sentQuery());

        $create = ['code' => 'PTS', 'name' => 'Points', 'symbol' => 'pts', 'symbolPosition' => 'after', 'type' => CustomCurrencyType::INTERNAL];
        $siren->customCurrencies->create($create);
        $this->assertSame(['POST', '/siren/v1/currencies/custom'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame($create, $this->sentJson());

        $siren->customCurrencies->update('PTS', ['name' => 'Reward points']);
        $this->assertSame(['PUT', '/siren/v1/currencies/custom/PTS'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame(['name' => 'Reward points'], $this->sentJson());

        $siren->customCurrencies->delete('PTS');
        $this->assertSame(['DELETE', '/siren/v1/currencies/custom/PTS'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame([], $this->sentQuery());

        $siren->customCurrencies->delete('PTS', wipeLedger: true);
        $this->assertSame(['wipeLedger' => 'true'], $this->sentQuery());
    }

    public function testCodesAreRequired(): void
    {
        $siren = $this->hostSiren([]);

        $this->expectException(\InvalidArgumentException::class);

        $siren->customCurrencies->update('', ['name' => 'x']);
    }
}
