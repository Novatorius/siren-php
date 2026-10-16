<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests\Resources;

use Siren\Sdk\Tests\TestCase;

final class EngagementsTest extends TestCase
{
    public function testReadRoutes(): void
    {
        $siren = $this->hostSiren([
            self::hostPage([['id' => 2]], 1),
            self::hostJson(200, ['id' => 2]),
            self::hostJson(200, ['sale' => 'Sale']),
            self::hostJson(200, ['percentage' => 'Percentage']),
        ]);

        $siren->engagements->list(['collaboratorId' => 8, 'programId' => 1, 'opportunityId' => 4021, 'status' => 'active']);
        $this->assertSame('/siren/v1/engagements', $this->sentPath());
        $this->assertSame(['collaboratorId' => '8', 'programId' => '1', 'opportunityId' => '4021', 'status' => 'active'], $this->sentQuery());

        $siren->engagements->retrieve(2);
        $this->assertSame('/siren/v1/engagements/2', $this->sentPath());

        $siren->engagements->types();
        $this->assertSame('/siren/v1/engagements/types', $this->sentPath());

        $siren->engagements->calculations();
        $this->assertSame('/siren/v1/engagements/calculations', $this->sentPath());
    }

    public function testEngagementsAreReadOnly(): void
    {
        foreach (['create', 'update', 'delete', 'bulk'] as $method) {
            $this->assertFalse(method_exists(\Siren\Sdk\Resources\Engagements::class, $method), $method . ' has no Siren route.');
        }
    }
}
