<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests\Resources;

use Siren\Sdk\ConversionStatus;
use Siren\Sdk\Resources\Conversions;
use Siren\Sdk\Tests\TestCase;

final class ConversionsTest extends TestCase
{
    public function testListSendsColumnAndRelationFilters(): void
    {
        $siren = $this->hostSiren([self::hostPage([], 0)]);

        $siren->conversions->list(['status' => ConversionStatus::APPROVED, 'collaboratorId' => 8, 'programId' => 1, 'transactionId' => 6]);

        $this->assertSame('/siren/v1/conversions', $this->sentPath());
        $this->assertSame(['status' => 'approved', 'collaboratorId' => '8', 'programId' => '1', 'transactionId' => '6'], $this->sentQuery());
    }

    public function testCrudBulkAndTypesRoutes(): void
    {
        $siren = $this->hostSiren([
            self::hostJson(200, ['id' => 3]),
            self::hostJson(201, ['id' => 3]),
            self::hostJson(200),
            self::hostJson(200, ['id' => 3]),
            self::hostJson(200, ['success' => true]),
            self::hostJson(200, ['sale' => 'Sale']),
        ]);

        $siren->conversions->retrieve(3, ['include' => 'extended']);
        $this->assertSame(['GET', '/siren/v1/conversions/3'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame(['include' => 'extended'], $this->sentQuery());

        $siren->conversions->create(['engagementId' => 2, 'type' => 'sale', 'status' => ConversionStatus::PENDING, 'transactionId' => 6]);
        $this->assertSame(['POST', '/siren/v1/conversions'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame(['engagementId' => 2, 'type' => 'sale', 'status' => 'pending', 'transactionId' => 6], $this->sentJson());

        $siren->conversions->update(3, ['status' => ConversionStatus::REJECTED]);
        $this->assertSame(['PUT', '/siren/v1/conversions/3'], [$this->sent()->getMethod(), $this->sentPath()]);

        $siren->conversions->delete(3);
        $this->assertSame(['DELETE', '/siren/v1/conversions/3'], [$this->sent()->getMethod(), $this->sentPath()]);

        $siren->conversions->bulk(Conversions::BULK_APPROVE, [3, 4]);
        $this->assertSame('/siren/v1/conversions/bulk', $this->sentPath());
        $this->assertSame(['action' => 'approve', 'ids' => [3, 4]], $this->sentJson());

        $siren->conversions->types();
        $this->assertSame('/siren/v1/conversions/types', $this->sentPath());
    }
}
