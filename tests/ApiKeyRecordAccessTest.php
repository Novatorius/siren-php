<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use Siren\Sdk\Exception\ApiKeyNotAcceptedException;
use Siren\Sdk\Exception\AuthenticationException;
use Siren\Sdk\Exception\ErrorCode;
use Siren\Sdk\Exception\MissingScopeException;
use Siren\Sdk\Exception\PermissionException;
use Siren\Sdk\Exception\StoreKeyNotAllowedException;

/**
 * An API key reads the ledger and manages webhook subscriptions when it
 * holds the ledger and webhook scopes. These tests pin what the SDK sends
 * with a key on those routes, and how it reads Siren's refusals.
 */
final class ApiKeyRecordAccessTest extends TestCase
{
    public function testAKeyReadsConversionsAndNarrowsToOneStore(): void
    {
        $siren = $this->hostSiren([self::hostPage([['id' => 9]], 1)]);

        $page = $siren->conversions->list(['propertyId' => 5, 'status' => 'approved']);

        $this->assertSame('Bearer ' . self::API_KEY, $this->sent()->getHeader('Authorization'));
        $this->assertSame('/siren/v1/conversions', $this->sentPath());
        $this->assertSame(['propertyId' => '5', 'status' => 'approved'], $this->sentQuery());
        $this->assertSame([['id' => 9]], $page->getData());
    }

    public function testAReadByIdTakesTheStoreFilter(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, ['id' => 12])]);

        $siren->transactions->retrieve(12, ['propertyId' => 5]);

        $this->assertSame('/siren/v1/transactions/12', $this->sentPath());
        $this->assertSame(['propertyId' => '5'], $this->sentQuery());
    }

    public function testObligationsAndPayoutsTakeTheStoreFilter(): void
    {
        $siren = $this->hostSiren([self::hostPage([]), self::hostPage([])]);

        $siren->obligations->list(['propertyId' => 5]);
        $this->assertSame(['propertyId' => '5'], $this->sentQuery());

        $siren->payouts->list(['status' => 'paid']);
        $this->assertSame('/siren/v1/payouts', $this->sentPath());
        $this->assertSame('Bearer ' . self::API_KEY, $this->sent()->getHeader('Authorization'));
    }

    public function testAKeyManagesWebhookSubscriptions(): void
    {
        $siren = $this->hostSiren([
            self::hostJson(201, ['id' => 4, 'signingSecret' => 'whsec_x']),
            self::hostJson(200, [['id' => 4]]),
        ]);

        $siren->webhooks->subscriptions->create(['targetUrl' => 'https://example.com/hook', 'events' => ['*']]);
        $this->assertSame('POST', $this->sent()->getMethod());
        $this->assertSame('Bearer ' . self::API_KEY, $this->sent()->getHeader('Authorization'));

        $this->assertSame([['id' => 4]], $siren->webhooks->subscriptions->list());
    }

    public function testAMissingLedgerScopeNamesEveryScopeThatOpensTheRoute(): void
    {
        $siren = $this->hostSiren([self::coded(403, "This API key does not have the 'webhook-subscriptions:read' scope.", ErrorCode::MISSING_SCOPE, [
            'scope' => 'webhook-subscriptions:read',
            'anyOf' => ['webhook-subscriptions:read', 'webhook-subscriptions:manage'],
        ])]);

        try {
            $siren->webhooks->subscriptions->list();
            $this->fail('Expected a MissingScopeException.');
        } catch (MissingScopeException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('webhook-subscriptions:read', $e->getRequiredScope());
            $this->assertSame(['webhook-subscriptions:read', 'webhook-subscriptions:manage'], $e->getAnyOf());
        }
    }

    public function testAMissingScopeWithoutAListHasAnEmptyAnyOf(): void
    {
        $siren = $this->hostSiren([self::coded(403, 'This older organization key sends events only. Create an API key with the scope this route needs.', ErrorCode::MISSING_SCOPE)]);

        try {
            $siren->conversions->list();
            $this->fail('Expected a MissingScopeException.');
        } catch (MissingScopeException $e) {
            $this->assertSame([], $e->getAnyOf());
            $this->assertNull($e->getRequiredScope());
        }
    }

    public function testAStoreKeyOnPayoutsIsAStoreKeyNotAllowedException(): void
    {
        $siren = $this->hostSiren([self::coded(403, 'This API key is bound to one store, and these records are not kept by store. Use an organization-wide key.', ErrorCode::STORE_KEY_NOT_ALLOWED, ['propertyId' => 5])]);

        try {
            $siren->payouts->list();
            $this->fail('Expected a StoreKeyNotAllowedException.');
        } catch (StoreKeyNotAllowedException $e) {
            $this->assertInstanceOf(PermissionException::class, $e);
            $this->assertSame(ErrorCode::STORE_KEY_NOT_ALLOWED, $e->getErrorCode());
            $this->assertSame(5, $e->getPropertyId());
        }
    }

    public function testAStoreKeyRefusalWithoutACodeIsReadByItsWording(): void
    {
        $siren = $this->hostSiren([self::hostJson(403, ['error' => ['message' => 'This API key is bound to one store, and these records are not kept by store.']])]);

        $this->expectException(StoreKeyNotAllowedException::class);

        $siren->webhooks->subscriptions->list();
    }

    public function testAKeyOnASessionRouteIsAnApiKeyNotAcceptedException(): void
    {
        $siren = $this->hostSiren([self::coded(401, 'This route does not accept API keys.', ErrorCode::API_KEY_NOT_ACCEPTED)]);

        try {
            $siren->programs->list();
            $this->fail('Expected an ApiKeyNotAcceptedException.');
        } catch (ApiKeyNotAcceptedException $e) {
            $this->assertInstanceOf(AuthenticationException::class, $e);
            $this->assertSame(401, $e->getStatusCode());
        }
    }

    public function testAnApiKeyRefusalWithoutACodeIsReadByItsWording(): void
    {
        $siren = $this->hostSiren([self::hostJson(401, ['error' => ['message' => 'This route does not accept API keys. Sign in to use it.']])]);

        $this->expectException(ApiKeyNotAcceptedException::class);

        $siren->apiKeys->list();
    }

    public function testAPlain401StaysAnAuthenticationException(): void
    {
        $siren = $this->hostSiren([self::hostJson(401, ['error' => ['message' => 'Invalid API key.', 'context' => []]])]);

        try {
            $siren->conversions->list();
            $this->fail('Expected an AuthenticationException.');
        } catch (AuthenticationException $e) {
            $this->assertSame(AuthenticationException::class, $e::class);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function coded(int $status, string $message, string $code, array $context = []): \Siren\Sdk\Http\HttpResponse
    {
        return self::hostJson($status, ['error' => ['message' => $message, 'code' => $code, 'context' => $context]]);
    }
}
