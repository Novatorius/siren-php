<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use Siren\Sdk\Exception\AuthenticationException;
use Siren\Sdk\ListResult;
use Siren\Sdk\Resources\ListResource;
use Siren\Sdk\Siren;

/**
 * `new Siren(['apiKey' => ...])` and the 0.1.0 surface keep working after
 * the transport seam, the token source, and the record resources landed.
 */
final class BackwardCompatibilityTest extends TestCase
{
    public function testTheFixedKeyConstructorNeedsNothingElse(): void
    {
        $siren = new Siren(['apiKey' => self::API_KEY]);

        $this->assertInstanceOf(ListResource::class, $siren->conversions);
        $this->assertInstanceOf(ListResource::class, $siren->transactions);
        $this->assertInstanceOf(ListResource::class, $siren->obligations);
        $this->assertInstanceOf(ListResource::class, $siren->payouts);
    }

    public function testTheFixedKeyIsSentOnEveryKindOfCall(): void
    {
        $siren = $this->mockSiren([
            self::jsonResponse(200, [], ['X-Siren-OID' => '1']),
            self::jsonResponse(200, []),
            self::jsonResponse(201, ['id' => 3]),
            self::jsonResponse(200, ['items' => [], 'total' => 0, 'page' => 1, 'perPage' => 10, 'totalPages' => 1]),
        ]);

        $siren->events->refund(['source' => 'stripe', 'externalId' => 'o-1']);
        $siren->apiKeys->list();
        $siren->webhooks->subscriptions->create(['targetUrl' => 'https://example.test/hook', 'events' => ['*']]);
        $siren->collaborators->list();

        $this->assertCount(4, $this->history);

        foreach ($this->history as $entry) {
            $this->assertSame('Bearer ' . self::API_KEY, $entry['request']->getHeaderLine('Authorization'));
        }
    }

    public function testAFixedKeyIsNotRefreshedOnA401(): void
    {
        $siren = $this->mockSiren([self::errorResponse(401, 'Invalid API key.'), self::jsonResponse(200, [])]);

        try {
            $siren->apiKeys->list();
            $this->fail('Expected an AuthenticationException.');
        } catch (AuthenticationException $e) {
            $this->assertSame('Invalid API key.', $e->getMessage());
        }

        $this->assertCount(1, $this->history, 'A fixed key cannot refresh, so a 401 is final.');
        $this->assertSame([], $this->sleeps);
    }

    public function testAGuzzleClientPassedAsHttpClientStillCarriesTheRequest(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, [], ['X-Siren-OID' => '9'])]);

        $result = $siren->events->sale([
            'source' => 'stripe',
            'externalId' => 'order-9',
            'total' => 12.5,
            'trackingId' => 9,
            'items' => [['name' => 'Thing', 'amount' => 12.5]],
        ]);

        $this->assertSame(9, $result->getOpportunityId());
        $this->assertSame(
            [
                'source' => 'stripe',
                'externalId' => 'order-9',
                'total' => 12.5,
                'trackingId' => 9,
                'items' => [['name' => 'Thing', 'amount' => 12.5, 'quantity' => 1]],
            ],
            $this->lastRequestJson(),
        );
    }

    public function testReconciliationReadersStillReturnListResults(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, [['id' => 1]], ['x-siren-estimated-count' => '1'])]);

        $result = $siren->obligations->list();

        $this->assertInstanceOf(ListResult::class, $result);
        $this->assertSame([['id' => 1]], $result->getData());
        $this->assertSame(1, $result->getTotal());
    }
}
