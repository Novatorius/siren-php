<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests\Resources;

use Siren\Sdk\CollaboratorStatus;
use Siren\Sdk\Resources\Collaborators;
use Siren\Sdk\Tests\TestCase;

final class CollaboratorsTest extends TestCase
{
    public function testListSendsFiltersSearchSortAndPaging(): void
    {
        $siren = $this->hostSiren([self::hostPage([['id' => 3, 'email' => 'a@example.test']], 31, 2, 10)]);

        $result = $siren->collaborators->list([
            'status' => [CollaboratorStatus::ACTIVE, CollaboratorStatus::PENDING],
            'programId' => 4,
            's' => 'ada',
            'fields' => ['id', 'email', 'referralCode'],
            'orderBy' => 'dateCreated',
            'order' => 'DESC',
            'page' => 2,
            'perPage' => 10,
        ]);

        $this->assertSame('GET', $this->sent()->getMethod());
        $this->assertSame('/siren/v1/collaborators', $this->sentPath());
        $this->assertSame([
            'status' => 'active,pending',
            'programId' => '4',
            's' => 'ada',
            'fields' => 'id,email,referralCode',
            'orderBy' => 'dateCreated',
            'order' => 'DESC',
            'number' => '10',
            'offset' => '10',
        ], $this->sentQuery());
        $this->assertSame([['id' => 3, 'email' => 'a@example.test']], $result->getData());
        $this->assertSame(31, $result->getTotal());
        $this->assertSame(4, $result->getTotalPages());
        $this->assertTrue($result->hasMore());
    }

    public function testRetrieveAsksForTheRequestedFields(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, ['id' => 8, 'referralCode' => 'ADA'])]);

        $record = $siren->collaborators->retrieve(8, ['fields' => ['id', 'referralCode']]);

        $this->assertSame('/siren/v1/collaborators/8', $this->sentPath());
        $this->assertSame(['fields' => 'id,referralCode'], $this->sentQuery());
        $this->assertSame(['id' => 8, 'referralCode' => 'ADA'], $record);
    }

    public function testCreatePostsTheRecord(): void
    {
        $siren = $this->hostSiren([self::hostJson(201, ['id' => 9])]);

        $params = [
            'fullName' => 'Ada Lovelace',
            'nickname' => 'ada',
            'email' => 'ada@example.test',
            'status' => CollaboratorStatus::ACTIVE,
            'trackingId' => 'ADA',
            'programs' => [1, 2],
        ];

        $this->assertSame(['id' => 9], $siren->collaborators->create($params));
        $this->assertSame('POST', $this->sent()->getMethod());
        $this->assertSame('/siren/v1/collaborators', $this->sentPath());
        $this->assertSame($params, $this->sentJson());
    }

    public function testUpdatePutsOnlyTheChangedFields(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, ['id' => 9, 'nickname' => 'countess'])]);

        $siren->collaborators->update(9, ['nickname' => 'countess']);

        $this->assertSame('PUT', $this->sent()->getMethod());
        $this->assertSame('/siren/v1/collaborators/9', $this->sentPath());
        $this->assertSame(['nickname' => 'countess'], $this->sentJson());
    }

    public function testDeleteReturnsTheSoftDeletedRecordOrNothing(): void
    {
        $siren = $this->hostSiren([
            self::hostJson(200, ['id' => 9, 'status' => CollaboratorStatus::DELETED]),
            self::hostJson(204),
        ]);

        $this->assertSame(['id' => 9, 'status' => 'deleted'], $siren->collaborators->delete(9));
        $this->assertSame([], $siren->collaborators->delete(9));
        $this->assertSame('DELETE', $this->sent()->getMethod());
        $this->assertSame('/siren/v1/collaborators/9', $this->sentPath());
    }

    public function testADeleteIsNeverRetriedBecauseASecondCallWouldRemoveTheRecord(): void
    {
        $siren = $this->hostSiren([self::hostJson(503, ['error' => 'down']), self::hostJson(204)]);

        try {
            $siren->collaborators->delete(9);
            $this->fail('Expected an ApiException.');
        } catch (\Siren\Sdk\Exception\ApiException) {
        }

        $this->assertCount(1, $this->http->requests);
    }

    public function testBulkPostsTheActionIdsAndExtras(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, ['success' => true])]);

        $siren->collaborators->bulk(Collaborators::BULK_ADD_TO_DISTRIBUTOR, [1, 2], ['distributorId' => 5]);

        $this->assertSame('POST', $this->sent()->getMethod());
        $this->assertSame('/siren/v1/collaborators/bulk', $this->sentPath());
        $this->assertSame(['action' => 'addToDistributor', 'ids' => [1, 2], 'distributorId' => 5], $this->sentJson());
    }

    public function testBulkNeedsIds(): void
    {
        $siren = $this->hostSiren([]);

        $this->expectException(\InvalidArgumentException::class);

        $siren->collaborators->bulk(Collaborators::BULK_ACTIVATE, []);
    }

    public function testStatusTypesAndReferralCodeHelpersReadTheirRoutes(): void
    {
        $siren = $this->hostSiren([
            self::hostJson(200, ['active' => 'Active']),
            self::hostJson(200, ['code' => 'XK3P']),
        ]);

        $this->assertSame(['active' => 'Active'], $siren->collaborators->statusTypes());
        $this->assertSame('/siren/v1/collaborators/status-types', (string) parse_url($this->http->requests[0]->getUrl(), PHP_URL_PATH));

        $this->assertSame(['code' => 'XK3P'], $siren->collaborators->generateReferralCode());
        $this->assertSame('/siren/v1/collaborators/generate-referral-code', $this->sentPath());
    }

    public function testRecordIdsMustBePositive(): void
    {
        $siren = $this->hostSiren([]);

        $this->expectException(\InvalidArgumentException::class);

        $siren->collaborators->retrieve(0);
    }
}
