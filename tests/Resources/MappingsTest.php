<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests\Resources;

use Siren\Sdk\Exception\ConflictException;
use Siren\Sdk\Exception\PolicyException;
use Siren\Sdk\Resources\Mappings;
use Siren\Sdk\Tests\TestCase;

final class MappingsTest extends TestCase
{
    public function testForExternalFindsTheLinksOfOneOutsideRecord(): void
    {
        $siren = $this->hostSiren([self::hostPage([
            ['localId' => 4, 'localType' => 'collaborator', 'externalId' => '12', 'externalType' => 'wordpress_user@property:5'],
        ], 1)]);

        $page = $siren->mappings->forExternal('12', Mappings::EXTERNAL_TYPE_WORDPRESS_USER, 'collaborator');

        $this->assertSame(['GET', '/siren/v1/mappings'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame(['externalId' => '12', 'externalType' => 'wordpress_user', 'localType' => 'collaborator'], $this->sentQuery());
        $this->assertSame(
            [['localId' => 4, 'localType' => 'collaborator', 'externalId' => '12', 'externalType' => 'wordpress_user', 'propertyId' => 5]],
            $page->getData(),
        );
    }

    public function testForExternalLeavesOutAnUnnamedLocalType(): void
    {
        $siren = $this->hostSiren([self::hostPage([])]);

        $siren->mappings->forExternal('1043', 'wc_order');

        $this->assertSame(['externalId' => '1043', 'externalType' => 'wc_order'], $this->sentQuery());
    }

    public function testForLocalFindsACollaboratorsLinksOfOneTypeWithPaging(): void
    {
        $siren = $this->hostSiren([self::hostPage([], 0, 3, 20)]);

        $siren->mappings->forLocal(4, 'collaborator', 'wc_product', ['page' => 3, 'perPage' => 20, 'orderBy' => 'externalId']);

        $this->assertSame(
            ['localId' => '4', 'localType' => 'collaborator', 'externalType' => 'wc_product', 'orderBy' => 'externalId', 'number' => '20', 'offset' => '40'],
            $this->sentQuery(),
        );
    }

    public function testListRequiresAnExternalType(): void
    {
        $siren = $this->hostSiren([]);

        try {
            $siren->mappings->list(['localId' => 4, 'localType' => 'collaborator']);
            $this->fail('A mapping query with no external type would list every link of the organization.');
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame([], $this->http->requests);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedTypes(): array
    {
        return [
            'qualified' => ['wordpress_user@property:5'],
            'comma' => ['user,x'],
            'space' => ['wc product'],
            'trailing newline' => ["wc_product\n"],
            'empty' => [''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedTypes')]
    public function testEveryMethodRefusesAMalformedTypeBeforeSending(string $type): void
    {
        $siren = $this->hostSiren([]);
        $calls = [
            fn () => $siren->mappings->list(['externalType' => $type]),
            fn () => $siren->mappings->list(['externalType' => 'wc_product', 'localType' => $type]),
            fn () => $siren->mappings->forExternal('12', $type),
            fn () => $siren->mappings->forLocal(4, 'collaborator', $type),
            fn () => $siren->mappings->create(4, '12', $type),
            fn () => $siren->mappings->deleteForCollaborator(4, $type),
            fn () => $siren->mappings->deleteForExternal('12', $type),
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

    public function testCreateWritesACollaboratorLink(): void
    {
        $siren = $this->hostSiren([self::hostJson(201, ['localId' => 4, 'localType' => 'collaborator', 'externalId' => '12', 'externalType' => 'wordpress_user@property:5'])]);

        $mapping = $siren->mappings->create(4, ' 12 ', 'wordpress_user');

        $this->assertSame(['POST', '/siren/v1/mappings'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame(['localId' => 4, 'localType' => 'collaborator', 'externalId' => '12', 'externalType' => 'wordpress_user'], $this->sentJson());
        $this->assertSame('wordpress_user', $mapping['externalType']);
        $this->assertSame(5, $mapping['propertyId']);
    }

    public function testCreateRaisesAConflictForALinkThatExists(): void
    {
        $siren = $this->hostSiren([self::hostJson(409, ['error' => 'That link already exists.'])]);

        $this->expectException(ConflictException::class);

        $siren->mappings->create(4, '12', 'wordpress_user');
    }

    public function testTheCloudUserTypeIsAPolicyRefusalForASite(): void
    {
        $siren = $this->hostSiren([self::hostJson(403, ['error' => ['message' => 'A connected site may not use the user type, which names a Cloud account. Use wordpress_user.', 'context' => ['param' => 'externalType']]])]);

        try {
            $siren->mappings->create(4, '12', 'user');
            $this->fail('Expected a PolicyException.');
        } catch (PolicyException $e) {
            $this->assertSame('externalType', $e->getParam());
        }

        $this->assertSame(['localId' => 4, 'localType' => 'collaborator', 'externalId' => '12', 'externalType' => 'user'], $this->sentJson(), 'The SDK sends user as given. A WordPress host translates it.');
    }

    public function testDeleteForCollaboratorSendsTheTypeAndTheCollaborator(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, ['deleted' => true])]);

        $this->assertSame(['deleted' => true], $siren->mappings->deleteForCollaborator(4, 'wordpress_user'));
        $this->assertSame(['DELETE', '/siren/v1/mappings'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame(['localType' => 'collaborator', 'localId' => '4', 'externalType' => 'wordpress_user'], $this->sentQuery());
        $this->assertNull($this->sent()->getBody());
    }

    public function testDeleteForExternalSendsTheOutsideRecord(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, ['deleted' => true])]);

        $siren->mappings->deleteForExternal('12', 'wordpress_user');

        $this->assertSame(['localType' => 'collaborator', 'externalId' => '12', 'externalType' => 'wordpress_user'], $this->sentQuery());
    }

    public function testDeletesAreNeverRetried(): void
    {
        $siren = $this->hostSiren([self::hostJson(502, ['error' => 'bad gateway']), self::hostJson(200, ['deleted' => true])]);

        try {
            $siren->mappings->deleteForExternal('12', 'wordpress_user');
            $this->fail('Expected an ApiException.');
        } catch (\Siren\Sdk\Exception\ApiException) {
        }

        $this->assertCount(1, $this->http->requests);
    }

    public function testEmptyIdsAreRefused(): void
    {
        $siren = $this->hostSiren([]);

        foreach ([fn () => $siren->mappings->create(4, ' ', 'wc_product'), fn () => $siren->mappings->create(0, '12', 'wc_product'), fn () => $siren->mappings->deleteForExternal('', 'wc_product')] as $call) {
            try {
                $call();
                $this->fail('Expected an InvalidArgumentException.');
            } catch (\InvalidArgumentException) {
            }
        }

        $this->assertSame([], $this->http->requests);
    }
}
