<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests\Resources;

use Siren\Sdk\Tests\TestCase;

final class SettingsTest extends TestCase
{
    public function testCollaboratorReadsTheSharedCollaboratorSettings(): void
    {
        $rows = [['type' => 'collaborator', 'subtype' => 'urlReference', 'configKey' => 'key', 'value' => 'ref']];
        $siren = $this->hostSiren([self::hostPage($rows, 1, 1, 200)]);

        $this->assertSame($rows, $siren->settings->collaborator());
        $this->assertSame(['GET', '/siren/v1/configs'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame(['type' => 'collaborator', 'number' => '200'], $this->sentQuery());
    }

    public function testValueReadsOneSettingByTypeSubtypeAndKey(): void
    {
        $siren = $this->hostSiren([self::hostPage([['type' => 'collaborator', 'subtype' => 'urlReference', 'configKey' => 'key', 'value' => 'ref']], 1, 1, 1)]);

        $this->assertSame('ref', $siren->settings->value('collaborator', 'urlReference', 'key'));
        $this->assertSame(['type' => 'collaborator', 'subtype' => 'urlReference', 'configKey' => 'key', 'number' => '1'], $this->sentQuery());
    }

    public function testValueIsNullWhenSirenSharesNone(): void
    {
        $siren = $this->hostSiren([self::hostPage([], 0, 1, 1)]);

        $this->assertNull($siren->settings->value('collaborator', 'urlReference', 'key'));
    }

    public function testValueRefusesAnEmptyName(): void
    {
        $siren = $this->hostSiren([]);

        $this->expectException(\InvalidArgumentException::class);

        $siren->settings->value('collaborator', '', 'key');
    }
}
