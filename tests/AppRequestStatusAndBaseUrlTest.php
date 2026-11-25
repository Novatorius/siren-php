<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Siren\Sdk\Resources\AppRequests;
use Siren\Sdk\Siren;

/**
 * Added after the first pass 4 fold push: every request status Siren keeps,
 * and `SIREN_BASE_URL` as the client's default base URL, the way
 * `SIREN_API_KEY` is its default key.
 */
final class AppRequestStatusAndBaseUrlTest extends TestCase
{
    /** Siren's `AppRequest::STATUS_*` values at feat/apps 0383186437, in its order. */
    private const SIREN_STATUSES = ['pending', 'sent', 'delivered', 'answered', 'applying', 'applied', 'needs_attention', 'dropped'];

    public function testTheStatusListHoldsSirensEightStatuses(): void
    {
        $this->assertSame(self::SIREN_STATUSES, AppRequests::STATUSES);
    }

    public function testTheListFilterTakesEveryStatus(): void
    {
        foreach (self::SIREN_STATUSES as $status) {
            $siren = $this->mockSiren([new Response(200, ['Content-Type' => 'application/json'], '{"requests": []}')], ['accessToken' => 'session-token', 'apiKey' => null]);
            $siren->apps->requests->list(['status' => $status]);

            $this->assertSame('status=' . $status, $this->history[0]['request']->getUri()->getQuery(), $status);
        }
    }

    public function testTheStatusesEqualSirensWhenACheckoutIsNamed(): void
    {
        $checkout = getenv('SIREN_CHECKOUT');

        if (!is_string($checkout) || $checkout === '') {
            self::markTestSkipped("Set SIREN_CHECKOUT to a Siren checkout to compare AppRequests::STATUSES with lib/Apps/Core/Models/AppRequest.php's STATUS_* constants.");
        }

        $file = rtrim($checkout, '/') . '/lib/Apps/Core/Models/AppRequest.php';
        $this->assertFileExists($file);
        preg_match_all("/public const STATUS_[A-Z_]+ = '([a-z_]+)';/", (string) file_get_contents($file), $matches);
        $this->assertNotEmpty($matches[1], 'AppRequest.php holds STATUS_* constants.');

        $this->assertSame([], array_values(array_diff($matches[1], AppRequests::STATUSES)), 'Statuses Siren has and the SDK lacks.');
        $this->assertSame([], array_values(array_diff(AppRequests::STATUSES, $matches[1])), 'Statuses the SDK has and Siren lacks.');
    }

    public function testTheClientReadsSirenBaseUrlFromTheEnvironment(): void
    {
        $this->withBaseUrl('https://sandbox.example.com/siren/v1', function (): void {
            $this->assertSame('https://sandbox.example.com/siren/v1/conversions', $this->firstUrl([]));
        });
    }

    public function testABaseUrlPassedInCodeWins(): void
    {
        $this->withBaseUrl('https://sandbox.example.com/siren/v1', function (): void {
            $this->assertSame('https://other.example.com/siren/v1/conversions', $this->firstUrl(['baseUrl' => 'https://other.example.com/siren/v1']));
        });
    }

    public function testWithNoVariableTheClientUsesSirenCloud(): void
    {
        $this->withBaseUrl(null, function (): void {
            $this->assertSame(Siren::DEFAULT_BASE_URL . '/conversions', $this->firstUrl([]));
        });
        $this->withBaseUrl('', function (): void {
            $this->assertSame(Siren::DEFAULT_BASE_URL . '/conversions', $this->firstUrl([]));
        });
    }

    public function testASandboxAskIsAnsweredOnTheSandboxTheVariableNames(): void
    {
        $sandbox = 'https://sandbox.example.com/siren/v1';

        $this->withBaseUrl($sandbox, function () use ($sandbox): void {
            $history = [];
            $stack = HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], '{"status": "accepted"}')]));
            $stack->push(Middleware::history($history));
            $siren = new Siren(['apiKey' => 'sk_test_unused', 'httpClient' => new Client(['handler' => $stack])]);
            $handler = $siren->apps->requestHandler('test-secret')
                ->on('distributionResolver', 'split-by-score', static fn ($request) => [
                    'outcome' => 'allocated',
                    'units' => $request->data['pool']['units'],
                    'shares' => $siren->apps->splitPool($request->data['pool'], array_column($request->data['metrics'], 'score', 'metricId')),
                ]);
            $request = \Siren\Sdk\Testing\AppRequests::make('distributionResolver', 'split-by-score', ['secret' => 'test-secret', 'baseUrl' => $sandbox]);

            $reply = $handler->answer('POST', $request->rawBody, $request->headers);
            $this->assertSame(200, $reply->status);
            $this->assertNotNull($reply->after);
            ($reply->after)();

            $this->assertCount(1, $history);
            $this->assertStringStartsWith($sandbox . '/apps/requests/', (string) $history[0]['request']->getUri());
        });
    }

    public function testTheReadmeSaysTheClientReadsSirenBaseUrl(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');

        $this->assertStringContainsString('`SIREN_BASE_URL`', substr($readme, (int) strpos($readme, "\n## Develop on your machine\n"), 4000));
        $this->assertStringNotContainsString("getenv('SIREN_BASE_URL')", $readme);
    }

    /** @param array<string, mixed> $config */
    private function firstUrl(array $config): string
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], '[]')]));
        $stack->push(Middleware::history($history));
        (new Siren($config + ['apiKey' => 'sk_test_unused', 'httpClient' => new Client(['handler' => $stack])]))->conversions->list();

        return strtok((string) $history[0]['request']->getUri(), '?');
    }

    private function withBaseUrl(?string $value, \Closure $run): void
    {
        $before = getenv('SIREN_BASE_URL');
        putenv($value === null ? 'SIREN_BASE_URL' : 'SIREN_BASE_URL=' . $value);

        try {
            $run();
        } finally {
            putenv($before === false ? 'SIREN_BASE_URL' : 'SIREN_BASE_URL=' . $before);
        }
    }
}
