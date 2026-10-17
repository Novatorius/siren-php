<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests\Resources;

use Siren\Sdk\Exception\PolicyException;
use Siren\Sdk\Tests\TestCase;

/**
 * The `/collaborator-programs` route a connected site uses for program
 * enrollments (Siren PR 384).
 */
final class EnrollmentRoutesTest extends TestCase
{
    public function testListReadsTheEnrollmentRouteWithAnyOfFilters(): void
    {
        $siren = $this->hostSiren([self::hostPage([['collaboratorId' => 4, 'programId' => 2]], 1)]);

        $page = $siren->collaborators->enrollments->list(['collaboratorId' => 4, 'programId' => [2, 3], 'orderBy' => 'programId', 'perPage' => 50]);

        $this->assertSame(['GET', '/siren/v1/collaborator-programs'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame(['collaboratorId' => '4', 'programId' => '2,3', 'orderBy' => 'programId', 'number' => '50'], $this->sentQuery());
        $this->assertSame([['collaboratorId' => 4, 'programId' => 2]], $page->getData());
        $this->assertSame(1, $page->getTotal());
    }

    public function testListClampsThePageSizeToWhatSirenServes(): void
    {
        $siren = $this->hostSiren([self::hostPage([])]);

        $siren->collaborators->enrollments->list(['number' => 500]);

        $this->assertSame(['number' => '200'], $this->sentQuery());
    }

    public function testAddPostsOneEnrollment(): void
    {
        $siren = $this->hostSiren([self::hostJson(201, ['collaboratorId' => 4, 'programId' => 2])]);

        $this->assertSame(['collaboratorId' => 4, 'programId' => 2], $siren->collaborators->enrollments->add(4, 2));
        $this->assertSame(['POST', '/siren/v1/collaborator-programs'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame(['collaboratorId' => 4, 'programId' => 2], $this->sentJson());
    }

    public function testRemoveDeletesOneEnrollmentByQuery(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, ['deleted' => true])]);

        $this->assertSame(['deleted' => true], $siren->collaborators->enrollments->remove(4, 2));
        $this->assertSame(['DELETE', '/siren/v1/collaborator-programs'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame(['collaboratorId' => '4', 'programId' => '2'], $this->sentQuery());
        $this->assertNull($this->sent()->getBody());
    }

    public function testAProgramOffTheSiteIsAPolicyRefusal(): void
    {
        $siren = $this->hostSiren([
            self::hostJson(403, ['error' => ['message' => "That program doesn't run on this site.", 'context' => ['param' => 'programId']]]),
            self::hostJson(403, ['error' => ['message' => "That program doesn't run on this site.", 'context' => ['param' => 'programId']]]),
        ]);

        foreach ([fn () => $siren->collaborators->enrollments->add(4, 9), fn () => $siren->collaborators->enrollments->remove(4, 9)] as $call) {
            try {
                $call();
                $this->fail('Expected a PolicyException.');
            } catch (PolicyException $e) {
                $this->assertSame(403, $e->getStatusCode());
                $this->assertSame('programId', $e->getParam());
            }
        }

        $this->assertCount(2, $this->http->requests, 'Neither write is repeated.');
    }

    public function testWritesRefuseIdsBelowOne(): void
    {
        $siren = $this->hostSiren([]);

        foreach ([fn () => $siren->collaborators->enrollments->add(0, 2), fn () => $siren->collaborators->enrollments->remove(4, 0)] as $call) {
            try {
                $call();
                $this->fail('Expected an InvalidArgumentException.');
            } catch (\InvalidArgumentException) {
            }
        }

        $this->assertSame([], $this->http->requests);
    }
}
