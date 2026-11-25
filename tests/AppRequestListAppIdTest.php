<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Siren\Sdk\Resources\AppRequests;

/**
 * The request list's rules, the same as Node's at siren-node 5e19832:
 * `appId` keeps to one app on the plain list, a page of it and
 * `needs_attention` (ListAppRequests at Siren feat/apps 76d5bbdf83), never
 * beside `idPrefix`. `limit` and `before` page `needs_attention` too, which
 * Siren reads from pkt/apps-attention-paging, and an older Siren ignores.
 */
final class AppRequestListAppIdTest extends TestCase
{
    private const BEFORE = 'apr_cccccccccccccccccccccccccccccccc';

    private function listed(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], '{"requests": []}');
    }

    public function testTheListReadsAppId(): void
    {
        $this->assertSame(['status', 'limit', 'before', 'idPrefix', 'appId'], AppRequests::LIST_FILTERS);
    }

    /** @return array<string, array{array<string, mixed>, array<string, string>}> */
    public static function appIdLists(): array
    {
        return [
            'the plain list' => [['appId' => 12], ['appId' => '12']],
            'a page of it' => [['appId' => 12, 'limit' => 100, 'before' => self::BEFORE], ['appId' => '12', 'limit' => '100', 'before' => self::BEFORE]],
            'the attention list' => [['appId' => 12, 'status' => 'needs_attention'], ['appId' => '12', 'status' => 'needs_attention']],
        ];
    }

    /**
     * @param array<string, mixed>  $params
     * @param array<string, string> $query
     */
    #[DataProvider('appIdLists')]
    public function testAppIdKeepsToOneApp(array $params, array $query): void
    {
        $siren = $this->mockSiren([$this->listed()], ['accessToken' => 'session-token', 'apiKey' => null]);

        $siren->apps->requests->list($params);

        parse_str($this->history[0]['request']->getUri()->getQuery(), $sent);
        $this->assertEqualsCanonicalizing($query, $sent);
    }

    public function testNeedsAttentionPagesWithLimitAndBefore(): void
    {
        $siren = $this->mockSiren([$this->listed()], ['accessToken' => 'session-token', 'apiKey' => null]);

        $siren->apps->requests->list(['status' => 'needs_attention', 'limit' => 100, 'before' => self::BEFORE]);

        parse_str($this->history[0]['request']->getUri()->getQuery(), $sent);
        $this->assertEqualsCanonicalizing(['status' => 'needs_attention', 'limit' => '100', 'before' => self::BEFORE], $sent);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function refusals(): array
    {
        $appId = 'apps->requests->list() takes an appId of a whole number of 1 or more, an app\'s id, and got ';

        return [
            'an appId of 0' => [['appId' => 0], $appId . '0.'],
            'an appId of 1.5' => [['appId' => 1.5], $appId . '1.5.'],
            'an appId as text' => [['appId' => '12'], $appId . '"12".'],
            'an appId of true' => [['appId' => true], $appId . 'true.'],
            'an appId beside idPrefix' => [['appId' => 12, 'idPrefix' => 'apr_9f2c'], 'apps->requests->list() takes idPrefix alone. Siren ignores status, limit, before and appId beside it, and answers at most two matches.'],
            'a bad idPrefix' => [['idPrefix' => 'apr_9f2'], 'apps->requests->list() takes an idPrefix of apr_ and 4 to 32 hex characters, and got "apr_9f2".'],
            'a limit of 0' => [['limit' => 0], 'apps->requests->list() takes a limit of a whole number from 1 to 100, and got 0.'],
            'a bad before' => [['before' => 'apr_x'], 'apps->requests->list() before needs a request id of apr_ and 32 lowercase hex characters, and got "apr_x".'],
        ];
    }

    /** @param array<string, mixed> $params */
    #[DataProvider('refusals')]
    public function testABadListIsRefusedBeforeSendingInNodesWords(array $params, string $message): void
    {
        $siren = $this->mockSiren([], ['accessToken' => 'session-token', 'apiKey' => null]);

        try {
            $siren->apps->requests->list($params);
            $this->fail('The list was not refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame($message, $e->getMessage());
        }

        $this->assertSame([], $this->history);
    }

    public function testTheReadmeShowsAppId(): void
    {
        $this->assertStringContainsString("list(['appId' => 12])", (string) file_get_contents(dirname(__DIR__) . '/README.md'));
    }
}
