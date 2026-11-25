<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `apps->requests->list(['idPrefix' => ...])` finds requests by the start of
 * their id, as ListAppRequests.php on Siren feat/apps 76d5bbdf83 reads it:
 * `apr_` and 4 to 32 hex characters, at most 2 of the organization's matches,
 * newest first, with the other filters ignored.
 */
final class AppRequestIdPrefixTest extends TestCase
{
    private function found(int $total, int $rows): Response
    {
        $row = ['id' => 'apr_9f2c' . str_repeat('0', 28), 'feature' => 'app:12', 'status' => 'applied'];

        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['requests' => array_fill(0, $rows, $row), 'total' => $total]));
    }

    public function testIdPrefixReachesTheQueryAndReturnsTheMatches(): void
    {
        $siren = $this->mockSiren([$this->found(1, 1)], ['accessToken' => 'session-token', 'apiKey' => null]);

        $rows = $siren->apps->requests->list(['idPrefix' => 'apr_9F2c']);

        $this->assertCount(1, $rows);
        $this->assertSame('idPrefix=apr_9F2c', $this->history[0]['request']->getUri()->getQuery());
    }

    public function testTwoRowsMeanThePrefixMatchesMoreThanOne(): void
    {
        $siren = $this->mockSiren([$this->found(7, 2)], ['accessToken' => 'session-token', 'apiKey' => null]);

        $this->assertCount(2, $siren->apps->requests->list(['idPrefix' => 'apr_9f2c']));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function badPrefixes(): array
    {
        $refusal = 'apps->requests->list() takes an idPrefix of apr_ and 4 to 32 hex characters, the start of a request id.';
        $alone = 'apps->requests->list() finds by idPrefix alone. Siren ignores status, limit and before beside it, so leave them out.';

        return [
            'too short' => [['idPrefix' => 'apr_9f2'], $refusal],
            'too long' => [['idPrefix' => 'apr_' . str_repeat('a', 33)], $refusal],
            'not hex' => [['idPrefix' => 'apr_9f2g'], $refusal],
            'no apr_' => [['idPrefix' => '9f2c4e1a'], $refusal],
            'not text' => [['idPrefix' => 9], $refusal],
            'with limit' => [['idPrefix' => 'apr_9f2c', 'limit' => 5], $alone],
            'with before' => [['idPrefix' => 'apr_9f2c', 'before' => 'apr_' . str_repeat('c', 32)], $alone],
            'with status' => [['idPrefix' => 'apr_9f2c', 'status' => 'applied'], $alone],
        ];
    }

    /** @param array<string, mixed> $params */
    #[DataProvider('badPrefixes')]
    public function testABadPrefixIsRefusedBeforeSending(array $params, string $message): void
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

    public function testTheReadmeShowsIdPrefix(): void
    {
        $this->assertStringContainsString("list(['idPrefix' => 'apr_9f2c'])", (string) file_get_contents(dirname(__DIR__) . '/README.md'));
    }
}
