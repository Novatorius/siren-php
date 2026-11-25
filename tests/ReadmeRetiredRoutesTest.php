<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The README documents no route Siren Cloud no longer serves, and no API key
 * scope that opens nothing.
 *
 * Siren's docs on feat/apps: docs/api/api-keys.md (an app owns its key, and
 * `/api-keys` answers 404), docs/api/webhooks-api.md (no `POST /webhooks`,
 * which answers 405, and the two webhook scopes are retired) and
 * docs/api/auth-api-keys.md (every `/apps` and `/webhooks` route refuses an
 * API key with 401 `api_key_not_accepted`).
 */
final class ReadmeRetiredRoutesTest extends TestCase
{
    private static function readme(): string
    {
        $text = file_get_contents(dirname(__DIR__) . '/README.md');
        self::assertIsString($text);

        return $text;
    }

    /** @return iterable<string, array{string}> */
    public static function retired(): iterable
    {
        yield 'the /api-keys resource' => ['->apiKeys'];
        yield 'POST /webhooks' => ['subscriptions->create('];
        yield 'the retired read scope' => ['webhook-subscriptions:read'];
        yield 'the retired manage scope' => ['webhook-subscriptions:manage'];
        yield 'ApiKeyScope::WEBHOOK_SUBSCRIPTIONS_*' => ['WEBHOOK_SUBSCRIPTIONS_'];
        yield 'ApiKeyRecordScope::WEBHOOKS_READ' => ['WEBHOOKS_READ'];
        yield 'ApiKeyRecordScope::WEBHOOKS_MANAGE' => ['WEBHOOKS_MANAGE'];
    }

    #[DataProvider('retired')]
    public function testTheReadmeNamesNoRetiredRouteOrScope(string $needle): void
    {
        self::assertStringNotContainsString($needle, self::readme());
    }

    public function testTheReadmeSaysWebhookAndAppsRoutesTakeASessionOnly(): void
    {
        $readme = self::readme();

        self::assertStringContainsString('api_key_not_accepted', $readme);
        self::assertStringContainsString('siren apps apply', $readme);
        self::assertStringContainsString('siren apps rotate-key', $readme);
    }
}
