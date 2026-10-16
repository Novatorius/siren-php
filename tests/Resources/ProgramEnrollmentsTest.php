<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests\Resources;

use Siren\Sdk\Tests\TestCase;

final class ProgramEnrollmentsTest extends TestCase
{
    public function testProgramsForReadsTheCollaboratorsProgramsField(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, ['id' => 8, 'programs' => [['id' => 1, 'name' => 'Partners']]])]);

        $this->assertSame([['id' => 1, 'name' => 'Partners']], $siren->collaborators->enrollments->programsFor(8));
        $this->assertSame('/siren/v1/collaborators/8', $this->sentPath());
        $this->assertSame(['fields' => 'id,programs'], $this->sentQuery());
    }

    public function testCollaboratorsInFiltersTheCollaboratorListByProgram(): void
    {
        $siren = $this->hostSiren([self::hostPage([['id' => 8]], 1)]);

        $result = $siren->collaborators->enrollments->collaboratorsIn(4, ['perPage' => 50, 'fields' => ['id']]);

        $this->assertSame('/siren/v1/collaborators', $this->sentPath());
        $this->assertSame(['programId' => '4', 'fields' => 'id', 'number' => '50'], $this->sentQuery());
        $this->assertSame([['id' => 8]], $result->getData());
    }

    public function testEnrollUsesTheAddToProgramBulkAction(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, ['success' => true])]);

        $siren->collaborators->enrollments->enroll(4, [8, 9]);

        $this->assertSame('POST', $this->sent()->getMethod());
        $this->assertSame('/siren/v1/collaborators/bulk', $this->sentPath());
        $this->assertSame(['action' => 'addToProgram', 'ids' => [8, 9], 'programId' => 4], $this->sentJson());
    }

    public function testUnenrollUsesTheRemoveFromProgramBulkAction(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, ['success' => true])]);

        $siren->collaborators->enrollments->unenroll(4, [8]);

        $this->assertSame(['action' => 'removeFromProgram', 'ids' => [8], 'programId' => 4], $this->sentJson());
    }

    public function testReplacePutsTheFullProgramList(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, ['id' => 8]), self::hostJson(200, ['id' => 8])]);

        $siren->collaborators->enrollments->replace(8, [1, 3]);
        $this->assertSame('PUT', $this->sent()->getMethod());
        $this->assertSame('/siren/v1/collaborators/8', $this->sentPath());
        $this->assertSame(['programs' => [1, 3]], $this->sentJson());

        $siren->collaborators->enrollments->replace(8, []);
        $this->assertSame(['programs' => []], $this->sentJson(), 'An empty list removes every enrollment.');
    }

    public function testEnrollNeedsAtLeastOneCollaborator(): void
    {
        $siren = $this->hostSiren([]);

        $this->expectException(\InvalidArgumentException::class);

        $siren->collaborators->enrollments->enroll(4, []);
    }
}
