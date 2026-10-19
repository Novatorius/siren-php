<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use Siren\Sdk\ApiKeyScope;
use Siren\Sdk\Exception\ApiException;
use Siren\Sdk\Exception\ApiKeyNotAcceptedException;
use Siren\Sdk\Exception\ErrorCode;

final class ApiKeysTest extends TestCase
{
    public function testCreatePostsAndReturnsTheRawKey(): void
    {
        $siren = $this->mockSiren([
            self::jsonResponse(201, [
                'id' => 3,
                'keyPrefix' => 'sk_live_a1b2c3d4',
                'label' => 'Production server',
                'status' => 'active',
                'rawKey' => 'sk_live_' . str_repeat('f', 64),
            ]),
        ]);

        $key = $siren->apiKeys->create(['label' => 'Production server', 'scopes' => [ApiKeyScope::ALL_EVENTS]]);

        $request = $this->lastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.sirenaffiliates.com/siren/v1/api-keys', (string) $request->getUri());
        $this->assertSame(['label' => 'Production server', 'scopes' => ['event:*']], $this->lastRequestJson());
        $this->assertSame('sk_live_' . str_repeat('f', 64), $key['rawKey']);
    }

    public function testCreateIsNeverAutoRetried(): void
    {
        $siren = $this->mockSiren([
            self::errorResponse(503, 'unavailable'),
            self::jsonResponse(201, ['id' => 1]),
        ]);

        try {
            $siren->apiKeys->create(['label' => 'CI', 'scopes' => [ApiKeyScope::ALL_EVENTS]]);
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }

        $this->assertCount(1, $this->history, 'A failed key creation must not be retried.');
    }

    /**
     * Siren answers 400 "At least one scope is required." to a key with no
     * scopes, so the SDK refuses it before sending.
     */
    public function testCreateRequiresScopes(): void
    {
        $siren = $this->mockSiren([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('scopes');

        $siren->apiKeys->create(['label' => 'Production server']);
    }

    public function testCreateRefusesAnEmptyScopeList(): void
    {
        $siren = $this->mockSiren([]);

        $this->expectException(\InvalidArgumentException::class);

        $siren->apiKeys->create(['scopes' => []]);
    }

    /** Siren makes the label optional, so a key with scopes and no label is sent. */
    public function testCreateSendsAKeyWithoutALabel(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(201, ['id' => 4, 'rawKey' => 'sk_live_x'])]);

        $siren->apiKeys->create(['scopes' => [ApiKeyScope::event('loyalty-points-earned')]]);

        $this->assertSame(['scopes' => ['event:loyalty-points-earned']], $this->lastRequestJson());
    }

    public function testScopesReadsTheOfferedScopes(): void
    {
        $offered = ['events' => [['scope' => 'event:*', 'resource' => 'events', 'action' => '*', 'label' => 'All events']]];
        $siren = $this->mockSiren([self::jsonResponse(200, $offered)]);

        $this->assertSame($offered, $siren->apiKeys->scopes());
        $this->assertSame('https://api.sirenaffiliates.com/siren/v1/api-keys/scopes', (string) $this->lastRequest()->getUri());
    }

    public function testListPassesPaging(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, [])]);

        $siren->apiKeys->list(['number' => 50, 'offset' => 200]);

        $this->assertSame(
            'https://api.sirenaffiliates.com/siren/v1/api-keys?number=50&offset=200',
            (string) $this->lastRequest()->getUri(),
        );
    }

    public function testCreateSendsTheLedgerAndWebhookScopes(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(201, ['id' => 5, 'rawKey' => 'sk_live_x'])]);

        $siren->apiKeys->create([
            'label' => 'Accounting export',
            'scopes' => [...ApiKeyScope::LEDGER_READ, ApiKeyScope::WEBHOOK_SUBSCRIPTIONS_READ, ApiKeyScope::WEBHOOK_SUBSCRIPTIONS_MANAGE],
        ]);

        $this->assertSame([
            'label' => 'Accounting export',
            'scopes' => [
                'ledger:conversions:read',
                'ledger:transactions:read',
                'ledger:obligations:read',
                'ledger:payouts:read',
                'webhook-subscriptions:read',
                'webhook-subscriptions:manage',
            ],
        ], $this->lastRequestJson());
    }

    /**
     * Siren grants these scopes only by their exact names, so the values are
     * a contract. Changing one would mint keys that open nothing.
     */
    public function testTheLedgerAndWebhookScopesAreStable(): void
    {
        $this->assertSame('ledger:conversions:read', ApiKeyScope::LEDGER_CONVERSIONS_READ);
        $this->assertSame('ledger:transactions:read', ApiKeyScope::LEDGER_TRANSACTIONS_READ);
        $this->assertSame('ledger:obligations:read', ApiKeyScope::LEDGER_OBLIGATIONS_READ);
        $this->assertSame('ledger:payouts:read', ApiKeyScope::LEDGER_PAYOUTS_READ);
        $this->assertSame('webhook-subscriptions:read', ApiKeyScope::WEBHOOK_SUBSCRIPTIONS_READ);
        $this->assertSame('webhook-subscriptions:manage', ApiKeyScope::WEBHOOK_SUBSCRIPTIONS_MANAGE);
        $this->assertSame(
            [ApiKeyScope::LEDGER_CONVERSIONS_READ, ApiKeyScope::LEDGER_TRANSACTIONS_READ, ApiKeyScope::LEDGER_OBLIGATIONS_READ, ApiKeyScope::LEDGER_PAYOUTS_READ],
            ApiKeyScope::LEDGER_READ,
        );
    }

    public function testAnApiKeyOnAKeyRouteIsAnApiKeyNotAcceptedException(): void
    {
        $siren = $this->mockSiren([
            self::errorResponse(401, 'This route does not accept API keys. Sign in to use it, or see the API key reference for the routes a key can use.', 'api_key_not_accepted'),
        ]);

        try {
            $siren->apiKeys->list();
            $this->fail('Expected an ApiKeyNotAcceptedException.');
        } catch (ApiKeyNotAcceptedException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame(ErrorCode::API_KEY_NOT_ACCEPTED, $e->getErrorCode());
        }

        $this->assertCount(1, $this->history, 'A key the route does not accept is not sent again.');
    }

    public function testEventScopeNeedsASlug(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ApiKeyScope::event('');
    }

    public function testListReturnsTheBareJsonArray(): void
    {
        $siren = $this->mockSiren([
            self::jsonResponse(200, [['id' => 1, 'keyPrefix' => 'sk_live_a1b2c3d4']]),
        ]);

        $keys = $siren->apiKeys->list();

        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('https://api.sirenaffiliates.com/siren/v1/api-keys', (string) $this->lastRequest()->getUri());
        $this->assertSame([['id' => 1, 'keyPrefix' => 'sk_live_a1b2c3d4']], $keys);
    }

    public function testRevokeTargetsTheId(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200)]);

        $siren->apiKeys->revoke(3);

        $request = $this->lastRequest();
        $this->assertSame('DELETE', $request->getMethod());
        $this->assertSame('https://api.sirenaffiliates.com/siren/v1/api-keys/3', (string) $request->getUri());
    }
}
