<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests\Resources;

use Siren\Sdk\Tests\TestCase;

final class CollaboratorGroupsTest extends TestCase
{
    public function testGroupRoutes(): void
    {
        $siren = $this->hostSiren([
            self::hostPage([['id' => 1]], 1),
            self::hostJson(200, ['id' => 1]),
            self::hostJson(201, ['id' => 1]),
            self::hostJson(200, ['id' => 1]),
            self::hostJson(204),
            self::hostJson(200, ['flat' => 'Flat']),
        ]);

        $siren->collaboratorGroups->list(['structure' => 'flat', 's' => 'vip']);
        $this->assertSame('/siren/v1/collaborator-groups', $this->sentPath());
        $this->assertSame(['structure' => 'flat', 's' => 'vip'], $this->sentQuery());

        $siren->collaboratorGroups->retrieve(1);
        $this->assertSame('/siren/v1/collaborator-groups/1', $this->sentPath());

        $create = ['name' => 'VIP', 'structure' => 'flat', 'members' => [['collaboratorId' => 8]]];
        $siren->collaboratorGroups->create($create);
        $this->assertSame(['POST', '/siren/v1/collaborator-groups'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame($create, $this->sentJson());

        $siren->collaboratorGroups->update(1, ['name' => 'VIPs']);
        $this->assertSame(['PUT', '/siren/v1/collaborator-groups/1'], [$this->sent()->getMethod(), $this->sentPath()]);

        $siren->collaboratorGroups->delete(1);
        $this->assertSame(['DELETE', '/siren/v1/collaborator-groups/1'], [$this->sent()->getMethod(), $this->sentPath()]);

        $siren->collaboratorGroups->structures();
        $this->assertSame('/siren/v1/collaborator-groups/structures', $this->sentPath());
    }

    public function testMemberRoutes(): void
    {
        $siren = $this->hostSiren([
            self::hostPage([['id' => 3, 'collaboratorId' => 8]], 1),
            self::hostJson(200, ['success' => true]),
            self::hostJson(200, ['success' => true]),
            self::hostJson(204),
        ]);

        $result = $siren->collaboratorGroups->members->list(1, ['collaboratorId' => 8, 'perPage' => 100]);
        $this->assertSame('/siren/v1/collaborator-groups/1/members', $this->sentPath());
        $this->assertSame(['collaboratorId' => '8', 'number' => '100'], $this->sentQuery());
        $this->assertSame([['id' => 3, 'collaboratorId' => 8]], $result->getData());

        $siren->collaboratorGroups->members->add(1, [['collaboratorId' => 9, 'metadata' => ['tier' => 'gold']]]);
        $this->assertSame(['POST', '/siren/v1/collaborator-groups/1/members'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame(['members' => [['collaboratorId' => 9, 'metadata' => ['tier' => 'gold']]]], $this->sentJson());

        $siren->collaboratorGroups->members->set(1, [['collaboratorId' => 8]]);
        $this->assertSame(['PUT', '/siren/v1/collaborator-groups/1/members'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame(['members' => [['collaboratorId' => 8]]], $this->sentJson());

        $siren->collaboratorGroups->members->remove(1, 8);
        $this->assertSame(['DELETE', '/siren/v1/collaborator-groups/1/members/8'], [$this->sent()->getMethod(), $this->sentPath()]);
    }
}
