<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests\Resources;

use Siren\Sdk\OpportunityStatus;
use Siren\Sdk\Tests\TestCase;

final class OpportunitiesTest extends TestCase
{
    public function testListRetrieveAndUpdateRoutes(): void
    {
        $siren = $this->hostSiren([
            self::hostPage([['id' => 4021]], 1),
            self::hostJson(200, ['id' => 4021, 'status' => 'active']),
            self::hostJson(200, ['id' => 4021, 'status' => 'inactive']),
        ]);

        $siren->opportunities->list(['status' => OpportunityStatus::ACTIVE, 'collaboratorId' => 8, 'orderBy' => 'lastTriggered']);
        $this->assertSame('/siren/v1/opportunities', $this->sentPath());
        $this->assertSame(['status' => 'active', 'collaboratorId' => '8', 'orderBy' => 'lastTriggered'], $this->sentQuery());

        $this->assertSame(['id' => 4021, 'status' => 'active'], $siren->opportunities->retrieve(4021));
        $this->assertSame('/siren/v1/opportunities/4021', $this->sentPath());

        $siren->opportunities->update(4021, ['status' => OpportunityStatus::INACTIVE]);
        $this->assertSame(['PUT', '/siren/v1/opportunities/4021'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame(['status' => 'inactive'], $this->sentJson());
    }

    public function testThereIsNoCreateOrDelete(): void
    {
        // Siren opens opportunities from site-visited events and has no
        // create or delete route for them.
        $this->assertFalse(method_exists(\Siren\Sdk\Resources\Opportunities::class, 'create'));
        $this->assertFalse(method_exists(\Siren\Sdk\Resources\Opportunities::class, 'delete'));
    }
}
