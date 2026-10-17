<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests\Resources;

use Siren\Sdk\Exception\CodeConflictException;
use Siren\Sdk\Exception\ConflictException;
use Siren\Sdk\Exception\PolicyException;
use Siren\Sdk\Tests\TestCase;

/**
 * The `/collaborator-aliases` route a connected site uses for tracking and
 * coupon codes (Siren PR 384).
 */
final class CollaboratorAliasRoutesTest extends TestCase
{
    public function testFindByCodeAsksForTheNewestHolderOfThePlainType(): void
    {
        $siren = $this->hostSiren([self::hostPage([
            ['code' => 'SAVE10', 'type' => 'coupon@property:5', 'collaboratorId' => 4, 'dateIssued' => '2026-10-01 00:00:00'],
        ], 1, 1, 1)]);

        $alias = $siren->collaborators->aliases->findByCode('SAVE10', 'coupon');

        $this->assertSame('GET', $this->sent()->getMethod());
        $this->assertSame('/siren/v1/collaborator-aliases', $this->sentPath());
        $this->assertSame(['code' => 'SAVE10', 'type' => 'coupon', 'number' => '1'], $this->sentQuery());
        $this->assertNull($this->sent()->getBody());
        $this->assertSame(
            ['code' => 'SAVE10', 'type' => 'coupon', 'collaboratorId' => 4, 'dateIssued' => '2026-10-01 00:00:00', 'propertyId' => 5],
            $alias,
        );
    }

    public function testFindByCodeIsNullWhenNobodyHoldsTheCode(): void
    {
        $siren = $this->hostSiren([self::hostPage([], 0, 1, 1)]);

        $this->assertNull($siren->collaborators->aliases->findByCode('NOPE', 'coupon'));
    }

    public function testACodeInNoSitesNamespaceKeepsItsTypeAndANullProperty(): void
    {
        $siren = $this->hostSiren([self::hostPage([['code' => 'ADA', 'type' => 'tracking', 'collaboratorId' => 4]], 1, 1, 1)]);

        $alias = $siren->collaborators->aliases->forCollaborator(4, 'tracking');

        $this->assertSame(['collaboratorId' => '4', 'type' => 'tracking', 'number' => '1'], $this->sentQuery());
        $this->assertSame(['code' => 'ADA', 'type' => 'tracking', 'collaboratorId' => 4, 'propertyId' => null], $alias);
    }

    public function testQueryPassesFiltersSortingAndPagingAndStripsEveryRecord(): void
    {
        $siren = $this->hostSiren([self::hostPage([
            ['code' => 'A', 'type' => 'coupon@property:5', 'collaboratorId' => 4],
            ['code' => 'B', 'type' => 'coupon', 'collaboratorId' => 4],
        ], 12, 2, 2)]);

        $page = $siren->collaborators->aliases->query([
            'collaboratorId' => 4,
            'type' => 'coupon',
            'orderBy' => 'code',
            'order' => 'ASC',
            'page' => 2,
            'perPage' => 2,
        ]);

        $this->assertSame(
            ['collaboratorId' => '4', 'type' => 'coupon', 'orderBy' => 'code', 'order' => 'ASC', 'number' => '2', 'offset' => '2'],
            $this->sentQuery(),
        );
        $this->assertSame(['coupon', 'coupon'], array_column($page->getData(), 'type'));
        $this->assertSame([5, null], array_column($page->getData(), 'propertyId'));
        $this->assertSame(12, $page->getTotal());
        $this->assertSame(2, $page->getPage());
        $this->assertTrue($page->hasMore());
    }

    public function testQuerySendsAListOfTypesCommaSeparated(): void
    {
        $siren = $this->hostSiren([self::hostPage([])]);

        $siren->collaborators->aliases->query(['type' => ['coupon', 'credit_coupon_STORE_CREDIT']]);

        $this->assertSame(['type' => 'coupon,credit_coupon_STORE_CREDIT'], $this->sentQuery());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedTypes(): array
    {
        return [
            'qualified' => ['coupon@property:5'],
            'comma' => ['user,x'],
            'space' => ['coupon code'],
            'colon' => ['coupon:5'],
            'trailing newline' => ["coupon\n"],
            'empty' => [''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedTypes')]
    public function testEveryMethodRefusesAMalformedTypeBeforeSending(string $type): void
    {
        $siren = $this->hostSiren([]);
        $calls = [
            fn () => $siren->collaborators->aliases->findByCode('SAVE10', $type),
            fn () => $siren->collaborators->aliases->forCollaborator(4, $type),
            fn () => $siren->collaborators->aliases->query(['type' => $type]),
            fn () => $siren->collaborators->aliases->query(['type' => ['coupon', $type]]),
            fn () => $siren->collaborators->aliases->create('SAVE10', $type, 4),
            fn () => $siren->collaborators->aliases->free('SAVE10', $type),
        ];

        foreach ($calls as $index => $call) {
            try {
                $call();
                $this->fail("Call {$index} sent a malformed type.");
            } catch (\InvalidArgumentException) {
            }
        }

        $this->assertSame([], $this->http->requests);
    }

    public function testCreatePostsThePlainTypeAndReturnsThePlainRecord(): void
    {
        $siren = $this->hostSiren([self::hostJson(201, ['code' => 'SAVE10', 'type' => 'coupon@property:5', 'collaboratorId' => 4, 'dateIssued' => '2026-10-01 00:00:00'])]);

        $alias = $siren->collaborators->aliases->create(' SAVE10 ', 'coupon', 4);

        $this->assertSame(['POST', '/siren/v1/collaborator-aliases'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame(['code' => 'SAVE10', 'type' => 'coupon', 'collaboratorId' => 4], $this->sentJson());
        $this->assertSame('coupon', $alias['type']);
        $this->assertSame(5, $alias['propertyId']);
    }

    public function testCreateRaisesACodeConflictWhenTheCodeIsHeld(): void
    {
        $siren = $this->hostSiren([self::hostJson(409, ['error' => 'That code is already held for this type.'])]);

        try {
            $siren->collaborators->aliases->create('SAVE10', 'coupon', 4);
            $this->fail('Expected a CodeConflictException.');
        } catch (CodeConflictException $e) {
            $this->assertInstanceOf(ConflictException::class, $e);
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('That code is already held for this type.', $e->getMessage());
            $this->assertSame('SAVE10', $e->getConflictingCode());
            $this->assertSame('coupon', $e->getType());
        }

        $this->assertCount(1, $this->http->requests, 'A code write is never repeated.');
    }

    public function testFreeDeletesOneCodeOfOneType(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, ['deleted' => true])]);

        $this->assertSame(['deleted' => true], $siren->collaborators->aliases->free('SAVE10', 'coupon'));
        $this->assertSame(['DELETE', '/siren/v1/collaborator-aliases'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame(['code' => 'SAVE10', 'type' => 'coupon'], $this->sentQuery());
        $this->assertNull($this->sent()->getBody());
    }

    public function testFreeIsNeverRetried(): void
    {
        $siren = $this->hostSiren([self::hostJson(503, ['error' => 'busy']), self::hostJson(200, ['deleted' => true])]);

        try {
            $siren->collaborators->aliases->free('SAVE10', 'coupon');
            $this->fail('Expected an ApiException.');
        } catch (\Siren\Sdk\Exception\ApiException) {
        }

        $this->assertCount(1, $this->http->requests);
    }

    public function testAnotherSitesCodeIsAPolicyRefusal(): void
    {
        $siren = $this->hostSiren([self::hostJson(403, ['error' => ['message' => 'A connected site may not use the user type, which names a Cloud account. Use wordpress_user.', 'context' => ['param' => 'type']]])]);

        try {
            $siren->collaborators->aliases->free('SAVE10', 'user');
            $this->fail('Expected a PolicyException.');
        } catch (PolicyException $e) {
            $this->assertSame('type', $e->getParam());
        }
    }

    public function testAnEmptyCodeIsRefused(): void
    {
        $siren = $this->hostSiren([]);

        $this->expectException(\InvalidArgumentException::class);

        $siren->collaborators->aliases->create('  ', 'coupon', 4);
    }
}
