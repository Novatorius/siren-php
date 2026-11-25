<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Siren\Sdk\Resources\AppRequests;

/**
 * `apps->requests->list()` pages with Siren's `limit` (1 to 100, 50 when
 * absent) and `before` (a request id: only older requests), as
 * ListAppRequests.php on Siren feat/apps 0383186437 reads them.
 */
final class AppRequestListPagingTest extends TestCase
{
    private function emptyList(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], '{"requests": []}');
    }

    public function testTheListTakesLimitAndBefore(): void
    {
        $this->assertContains('limit', AppRequests::LIST_FILTERS);
        $this->assertContains('before', AppRequests::LIST_FILTERS);
        $this->assertSame(100, AppRequests::MAX_LIST_LIMIT);
    }

    public function testLimitAndBeforeReachTheQuery(): void
    {
        $before = 'apr_' . str_repeat('c', 32);
        $siren = $this->mockSiren([$this->emptyList()], ['accessToken' => 'session-token', 'apiKey' => null]);

        $siren->apps->requests->list(['status' => 'applied', 'limit' => 20, 'before' => $before]);

        parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
        $this->assertEqualsCanonicalizing(['status' => 'applied', 'limit' => '20', 'before' => $before], $query);
    }

    public function testAListWithNoParamsSendsNoQuery(): void
    {
        $siren = $this->mockSiren([$this->emptyList()], ['accessToken' => 'session-token', 'apiKey' => null]);

        $siren->apps->requests->list();

        $this->assertSame('', $this->history[0]['request']->getUri()->getQuery());
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function badPages(): array
    {
        return [
            'a limit of 0' => [['limit' => 0], 'whole number from 1 to 100'],
            'a limit past 100' => [['limit' => 101], 'whole number from 1 to 100'],
            'a limit as text' => [['limit' => '20'], 'whole number from 1 to 100'],
            'a before that is not a request id' => [['before' => 'req_1'], 'request id'],
        ];
    }

    /** @param array<string, mixed> $params */
    #[DataProvider('badPages')]
    public function testABadPageIsRefusedBeforeSending(array $params, string $words): void
    {
        $siren = $this->mockSiren([], ['accessToken' => 'session-token', 'apiKey' => null]);

        try {
            $siren->apps->requests->list($params);
            $this->fail('The list was not refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($words, $e->getMessage());
        }

        $this->assertSame([], $this->history);
    }

    public function testTheReadmeShowsPaging(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');

        $this->assertStringContainsString("'before' =>", $readme);
        $this->assertStringContainsString("'limit' =>", $readme);
    }
}
