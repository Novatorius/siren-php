<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests\Resources;

use Siren\Sdk\ProgramGroupSorter;
use Siren\Sdk\Resources\ProgramGroups;
use Siren\Sdk\Tests\TestCase;

final class ProgramGroupsTest extends TestCase
{
    public function testListAndCrudRoutes(): void
    {
        $siren = $this->hostSiren([
            self::hostPage([['id' => 2]], 1),
            self::hostJson(200, ['id' => 2]),
            self::hostJson(201, ['id' => 2]),
            self::hostJson(200, ['id' => 2]),
            self::hostJson(204),
            self::hostJson(200, ['success' => true]),
        ]);

        $siren->programGroups->list(['sorter' => ProgramGroupSorter::OLDEST_BINDING_WINS, 's' => 'tier']);
        $this->assertSame('/siren/v1/program-groups', $this->sentPath());
        $this->assertSame(['sorter' => 'oldestBindingWins', 's' => 'tier'], $this->sentQuery());

        $siren->programGroups->retrieve(2);
        $this->assertSame(['GET', '/siren/v1/program-groups/2'], [$this->sent()->getMethod(), $this->sentPath()]);

        $create = ['name' => 'Tiers', 'description' => 'Pick one', 'sorter' => ProgramGroupSorter::NEWEST_BINDING_WINS, 'programs' => [1, 4]];
        $siren->programGroups->create($create);
        $this->assertSame(['POST', '/siren/v1/program-groups'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame($create, $this->sentJson());

        $siren->programGroups->update(2, ['programs' => [4]]);
        $this->assertSame(['PUT', '/siren/v1/program-groups/2'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame(['programs' => [4]], $this->sentJson());

        $this->assertSame([], $siren->programGroups->delete(2));
        $this->assertSame(['DELETE', '/siren/v1/program-groups/2'], [$this->sent()->getMethod(), $this->sentPath()]);

        $siren->programGroups->bulk(ProgramGroups::BULK_DELETE, [2, 3]);
        $this->assertSame('/siren/v1/program-groups/bulk', $this->sentPath());
        $this->assertSame(['action' => 'delete', 'ids' => [2, 3]], $this->sentJson());
    }
}
