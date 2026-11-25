<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use GuzzleHttp\Psr7\Response;
use Siren\Sdk\Testing\AppRequests;

/**
 * Pass 5: the testing helpers read `SIREN_BASE_URL` as the client does, a
 * key passed to `fromRecord()` wins over the saved one as in Node, and
 * `needs_attention` alone is still sent as before.
 */
final class AppRequestPass5Test extends TestCase
{
    private const SANDBOX = 'https://sandbox.example.test/siren/v1';

    private string|false $saved;

    protected function setUp(): void
    {
        parent::setUp();
        $this->saved = getenv('SIREN_BASE_URL');
    }

    protected function tearDown(): void
    {
        putenv($this->saved === false ? 'SIREN_BASE_URL' : 'SIREN_BASE_URL=' . $this->saved);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private static function body(string $raw): array
    {
        /** @var array<string, mixed> */
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    private static function record(): string
    {
        return (string) file_get_contents(__DIR__ . '/Fixtures/cli-request-json.json');
    }

    public function testMakeBuildsTheAnswerUrlOnSirenBaseUrl(): void
    {
        putenv('SIREN_BASE_URL=' . self::SANDBOX . '/');

        $body = self::body(AppRequests::make('payoutMethod', 'acme-ledger', ['secret' => 'test-secret', 'id' => 'apr_' . str_repeat('a', 32)])->rawBody);

        $this->assertSame(self::SANDBOX . '/apps/requests/apr_' . str_repeat('a', 32) . '/answer', $body['answerUrl']);
    }

    public function testFromRecordBuildsTheAnswerUrlOnSirenBaseUrl(): void
    {
        putenv('SIREN_BASE_URL=' . self::SANDBOX);

        $body = self::body(AppRequests::fromRecord(self::record(), null, ['secret' => 'test-secret'])->rawBody);

        $this->assertStringStartsWith(self::SANDBOX . '/apps/requests/', (string) $body['answerUrl']);
    }

    public function testABaseUrlOptionWinsAndAnEmptyVariableMeansSirenCloud(): void
    {
        putenv('SIREN_BASE_URL=' . self::SANDBOX);
        $body = self::body(AppRequests::make('payoutMethod', 'acme-ledger', ['secret' => 'test-secret', 'baseUrl' => 'https://other.example.test/siren/v1'])->rawBody);
        $this->assertStringStartsWith('https://other.example.test/siren/v1/apps/requests/', (string) $body['answerUrl']);

        putenv('SIREN_BASE_URL=');
        $body = self::body(AppRequests::make('payoutMethod', 'acme-ledger', ['secret' => 'test-secret'])->rawBody);
        $this->assertStringStartsWith(\Siren\Sdk\Siren::DEFAULT_BASE_URL . '/apps/requests/', (string) $body['answerUrl']);
    }

    public function testAPassedKeyWinsOverTheSavedOne(): void
    {
        $body = self::body(AppRequests::fromRecord(self::record(), 'other-key', ['secret' => 'test-secret'])->rawBody);

        $this->assertSame('other-key', $body['feature']['key']);
    }

    public function testTheSavedKeyIsUsedWhenNoneIsPassed(): void
    {
        $body = self::body(AppRequests::fromRecord(self::record(), null, ['secret' => 'test-secret'])->rawBody);

        $this->assertSame('acme-ledger', $body['feature']['key']);
    }

    public function testNeedsAttentionAloneIsStillSent(): void
    {
        $siren = $this->mockSiren([new Response(200, ['Content-Type' => 'application/json'], '{"requests": []}')], ['accessToken' => 'session-token', 'apiKey' => null]);

        $this->assertSame([], $siren->apps->requests->list(['status' => 'needs_attention']));
        $this->assertSame('status=needs_attention', $this->history[0]['request']->getUri()->getQuery());
    }
}
