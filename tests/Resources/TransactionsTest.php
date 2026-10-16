<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests\Resources;

use Siren\Sdk\Resources\Transactions;
use Siren\Sdk\Tests\TestCase;

final class TransactionsTest extends TestCase
{
    public function testListSendsStatusAndInclude(): void
    {
        $siren = $this->hostSiren([self::hostPage([['id' => 1, 'status' => 'complete']], 1)]);

        $siren->transactions->list(['status' => 'complete', 'include' => 'extended']);

        $this->assertSame('/siren/v1/transactions', $this->sentPath());
        $this->assertSame(['status' => 'complete', 'include' => 'extended'], $this->sentQuery());
    }

    public function testDetailsReadsTheDetailsField(): void
    {
        $details = [['name' => 'Pro Plan', 'value' => 4999, 'quantity' => 1, 'units' => 'USD']];
        $siren = $this->hostSiren([self::hostJson(200, ['id' => 6, 'details' => $details])]);

        $this->assertSame($details, $siren->transactions->details(6));
        $this->assertSame('/siren/v1/transactions/6', $this->sentPath());
        $this->assertSame(['fields' => 'id,details'], $this->sentQuery());
    }

    public function testCreatePostsDetailsAndBinding(): void
    {
        $siren = $this->hostSiren([self::hostJson(201, ['id' => 6])]);
        $params = [
            'details' => [['name' => 'Manual credit', 'type' => 'credit', 'value' => 5000, 'quantity' => 1, 'units' => 'USD']],
            'bindingId' => 'order-1001',
            'bindingDataType' => 'woocommerce_order',
        ];

        $siren->transactions->create($params);

        $this->assertSame(['POST', '/siren/v1/transactions'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame($params, $this->sentJson());
    }

    public function testCreateRequiresDetails(): void
    {
        $siren = $this->hostSiren([]);

        $this->expectException(\InvalidArgumentException::class);

        $siren->transactions->create(['bindingId' => 'x']);
    }

    public function testBulkCreditAndSourcesRoutes(): void
    {
        $siren = $this->hostSiren([
            self::hostJson(200, ['success' => true]),
            self::hostJson(200, ['success' => true]),
            self::hostJson(200, ['success' => true]),
            self::hostJson(200, ['woocommerce' => 'WooCommerce']),
        ]);

        $siren->transactions->bulk(Transactions::BULK_REFUND, [6]);
        $this->assertSame('/siren/v1/transactions/bulk', $this->sentPath());
        $this->assertSame(['action' => 'refund', 'ids' => [6]], $this->sentJson());

        $siren->transactions->creditCollaborator([6, 7], 8, 'sale');
        $this->assertSame(['POST', '/siren/v1/transactions/credit-collaborator'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame(['transactionIds' => [6, 7], 'collaboratorId' => 8, 'type' => 'sale'], $this->sentJson());

        $siren->transactions->creditCollaborator([6], 8);
        $this->assertSame(['transactionIds' => [6], 'collaboratorId' => 8], $this->sentJson());

        $siren->transactions->sources();
        $this->assertSame(['GET', '/siren/v1/transactions/sources'], [$this->sent()->getMethod(), $this->sentPath()]);
    }
}
