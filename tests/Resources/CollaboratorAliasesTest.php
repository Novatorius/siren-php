<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests\Resources;

use Siren\Sdk\Tests\TestCase;

final class CollaboratorAliasesTest extends TestCase
{
    public function testListReadsTheCollaboratorsAliasesField(): void
    {
        $aliases = [
            ['code' => 'ADA', 'type' => 'tracking', 'issuedDate' => '2026-01-02T00:00:00+00:00'],
            ['code' => 'ADA-OLD', 'type' => 'tracking', 'issuedDate' => '2025-01-02T00:00:00+00:00'],
        ];
        $siren = $this->hostSiren([self::hostJson(200, ['id' => 8, 'aliases' => $aliases])]);

        $this->assertSame($aliases, $siren->collaborators->aliases->list(8));
        $this->assertSame('GET', $this->sent()->getMethod());
        $this->assertSame('/siren/v1/collaborators/8', $this->sentPath());
        $this->assertSame(['fields' => 'id,aliases'], $this->sentQuery());
    }

    public function testListIsEmptyWhenTheFieldIsMissing(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, ['id' => 8])]);

        $this->assertSame([], $siren->collaborators->aliases->list(8));
    }

    public function testTrackingCodeReadsTheReferralCodeField(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, ['id' => 8, 'referralCode' => 'ADA']), self::hostJson(200, ['id' => 8, 'referralCode' => null])]);

        $this->assertSame('ADA', $siren->collaborators->aliases->trackingCode(8));
        $this->assertSame(['fields' => 'id,referralCode'], $this->sentQuery());
        $this->assertNull($siren->collaborators->aliases->trackingCode(8));
    }

    public function testSetTrackingCodePutsTrackingId(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, ['id' => 8])]);

        $siren->collaborators->aliases->setTrackingCode(8, 'ADA2');

        $this->assertSame('PUT', $this->sent()->getMethod());
        $this->assertSame('/siren/v1/collaborators/8', $this->sentPath());
        $this->assertSame(['trackingId' => 'ADA2'], $this->sentJson());
    }

    public function testSetTrackingCodeRefusesAnEmptyCode(): void
    {
        $siren = $this->hostSiren([]);

        $this->expectException(\InvalidArgumentException::class);

        $siren->collaborators->aliases->setTrackingCode(8, '');
    }
}
