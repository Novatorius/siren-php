<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use GuzzleHttp\Psr7\Response;
use Siren\Sdk\ApiKeyRecordScope;
use Siren\Sdk\ApiKeyScope;
use Siren\Sdk\Exception\AppHandlerException;
use Siren\Sdk\Exception\SignatureVerificationException;
use Siren\Sdk\Resources\WebhookSubscriptions;
use Siren\Sdk\Siren;
use Siren\Sdk\Testing\AppRequests;

/**
 * The pass 4 re-check folded in with the held follow-ups: samples that write
 * check `test` first, the local development steps that work on the first
 * run, a replay from a saved request, the signature codes and event samples
 * the three SDKs share, and the key and webhook methods whose routes Siren
 * dropped, with the app's key methods in their place.
 */
final class AppRequestDxPass4FoldTest extends TestCase
{
    /**
     * The README's handler samples whose function writes a record, calls
     * another system or pays, by readme-test name, with the first call that
     * does it. Each must read `$request->test` before that call.
     */
    private const WRITING_SAMPLES = [
        'answer-tell' => 'addToCrm(',
        'answer-allocation' => 'noteShare(',
        'answer-payout' => 'payOnce(',
    ];

    /** The app's own functions that only read, which a sample may call on a test request. */
    private const READ_ONLY = ['isRefunded', 'isStaff'];

    // Must 1: samples that write or pay check `test` first.

    public function testEveryWritingSampleReadsTestBeforeItsFirstWrite(): void
    {
        foreach (self::WRITING_SAMPLES as $name => $write) {
            $code = self::readme($name);
            $check = strpos($code, 'if ($request->test) {');
            $first = strpos($code, $write);

            $this->assertNotFalse($first, $name . ' calls ' . $write);
            $this->assertNotFalse($check, $name . ' reads $request->test.');
            $this->assertLessThan($first, $check, $name . ' reads $request->test before ' . $write);
        }
    }

    public function testEveryHandlerSampleCallsOnlyFunctionsTheListKnows(): void
    {
        $known = array_merge(array_map(static fn (string $call): string => rtrim($call, '('), array_values(self::WRITING_SAMPLES)), self::READ_ONLY);

        foreach (self::handlerSamples() as $name => $code) {
            foreach (self::ownFunctionCalls($code) as $function) {
                $this->assertContains($function, $known, sprintf('%s calls %s(), which is neither a writing sample\'s call nor listed as read-only.', $name, $function));

                if (!in_array($function, self::READ_ONLY, true)) {
                    $this->assertArrayHasKey($name, self::WRITING_SAMPLES, sprintf('%s calls %s(), so it is a writing sample.', $name, $function));
                }
            }
        }
    }

    public function testTheTellSamplesReturnBeforeWritingOnATestRequest(): void
    {
        $this->assertStringContainsString(
            "    ->on('postConversionAction', 'tell-the-crm', function (\$request) {\n        if (\$request->test) {\n            return; // a test from `siren trigger`: write nothing\n        }\n",
            self::readme('answer-tell'),
        );
        $this->assertStringContainsString(
            "    ->on('postAllocationAction', 'note-the-share', function (\$request) {\n        if (\$request->test) {\n            return; // a test from `siren trigger`: write nothing\n        }\n",
            self::readme('answer-allocation'),
        );
    }

    public function testTheFieldListNamesTestBesideIsAsk(): void
    {
        $section = self::section('Register functions');

        $this->assertStringContainsString('`test`', $section);
        $this->assertStringContainsString('`isAsk()`', $section);
        $this->assertMatchesRegularExpression('/`test`[^.]*`isAsk\(\)`|`isAsk\(\)`[^.]*`test`/', $section);
    }

    // Must 2: the local development steps work on the first run.

    public function testTheDevelopStepsLoadTheFileAndTakeTheSecretFromListen(): void
    {
        $section = self::section('Develop on your machine');

        $this->assertStringContainsString('siren apps apply', $section);
        $this->assertStringContainsString('`SIREN_API_KEY`', $section);
        $this->assertStringContainsString('no signing secret', $section);
        $this->assertStringContainsString('siren listen --forward-to localhost:3000/siren', $section);
        $this->assertStringContainsString('`SIREN_SIGNING_SECRET`', $section);
        $this->assertStringContainsString('set -a; . ./.env.sandbox; set +a; php -S localhost:3000 app.php', $section);
        $this->assertStringContainsString('Restart the app', $section);
        $this->assertStringContainsString('siren trigger ask <app> <feature>', $section);
        $this->assertStringContainsString('`--live`', $section);
        $this->assertStringContainsString('payout method', $section);
        $this->assertMatchesRegularExpression('/next release[^.]*`siren apps apply`|`siren apps apply`[^.]*next release/s', $section);
        $this->assertStringNotContainsString('key and signing secret to `.env.sandbox`', $section);
        $this->assertStringNotContainsString('localhost:8000', $section);
    }

    // Should 1: replay reads a saved request.

    public function testTheReplaySampleReadsASavedRequest(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');
        $replay = self::readme('replay-a-request');

        $this->assertStringContainsString('siren apps requests <id> --json > request.json', $readme);
        $this->assertStringContainsString("AppRequests::fromRecord(file_get_contents(__DIR__ . '/request.json')", $replay);
        $this->assertStringNotContainsString('<<<\'JSON\'', $replay);
        $this->assertStringNotContainsString('the row `siren apps requests <id>` shows', $readme);
        $this->assertSame(1, preg_match('/<!-- readme-test: replay-record -->\n```json\n(.*?)\n```/s', $readme, $record), 'The README shows request.json.');
        $this->assertIsArray(json_decode($record[1], true));
    }

    // Should 2: the signature codes Node and Python use.

    public function testTheSignatureCodesAreTheOtherSdksCodes(): void
    {
        $this->assertSame(
            ['signature_missing', 'signature_malformed', 'timestamp_mismatch', 'signature_mismatch', 'timestamp_out_of_tolerance'],
            array_values((new \ReflectionClass(SignatureVerificationException::class))->getConstants()),
        );

        $request = AppRequests::make('postConversionAction', 'tell-the-crm', ['secret' => 'test-secret', 'timestamp' => time() - 600]);

        try {
            (new Siren(['apiKey' => 'sk_test_unused']))->apps->verifyRequest($request->rawBody, $request->headers, 'test-secret');
            $this->fail('An old time is refused.');
        } catch (SignatureVerificationException $e) {
            $this->assertSame('timestamp_out_of_tolerance', $e->getErrorCode());
        }

        $section = self::section('Options');
        foreach (['signature_missing', 'signature_malformed', 'timestamp_mismatch', 'signature_mismatch', 'timestamp_out_of_tolerance'] as $code) {
            $this->assertStringContainsString('`' . $code . '`', $section);
        }
    }

    // Should 6: the event samples are Node's, value for value.

    public function testTheEventSamplesAreNodesSamples(): void
    {
        $node = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/node-event-samples.json'), true, 512, JSON_THROW_ON_ERROR);
        $php = json_decode((string) file_get_contents(dirname(__DIR__) . '/resources/webhook-event-samples.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($node['events'], $php['events']);
        $this->assertSame('example', AppRequests::sampleEventData('collaborator_group.updated')['current'] ?? null);
    }

    public function testTheSampleScriptReadsASirenCheckout(): void
    {
        $checkout = sys_get_temp_dir() . '/siren-checkout-' . bin2hex(random_bytes(4));
        mkdir($checkout . '/docs/api', 0777, true);
        file_put_contents($checkout . '/docs/api/webhooks-events.md', implode("\n", [
            '### thing.made',
            '',
            '| Field | Type | Description |',
            '|-------|------|-------------|',
            '| `thingId` | integer | The thing. |',
            '| `count` | integer | How many. |',
            '| `ownerId` | integer or null | Who owns it. |',
            '| `kind` | string | Its kind, such as `"widget"`. |',
            '| `label` | string | A label. |',
            '| `ratio` | number | A ratio. |',
            '',
            '### thing.copied',
            '',
            '**Payload:** the same fields as `thing.made`.',
            '',
        ]));

        try {
            $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/event-samples.php', '--print', $checkout], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            fclose($pipes[0]);
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            $this->assertSame(0, proc_close($process), $out . $err);

            $made = ['thingId' => 101, 'count' => 1, 'ownerId' => 102, 'kind' => 'widget', 'label' => 'example', 'ratio' => 25];
            $this->assertSame(['thing.made' => $made, 'thing.copied' => $made], json_decode((string) $out, true));
        } finally {
            unlink($checkout . '/docs/api/webhooks-events.md');
            rmdir($checkout . '/docs/api');
            rmdir($checkout . '/docs');
            rmdir($checkout);
        }
    }

    public function testTheSampleCheckReadsSirenCheckout(): void
    {
        $test = (string) file_get_contents(__DIR__ . '/AppRequestDxPass4Test.php');

        $this->assertStringContainsString("getenv('SIREN_CHECKOUT')", $test);
        $this->assertStringNotContainsString('SIREN_WEBHOOK_EVENTS', $test);
    }

    // One-liners.

    public function testAMissingReplayKeyIsAHandlerError(): void
    {
        $this->expectException(AppHandlerException::class);
        $this->expectExceptionMessage("The record names its feature as app:31, without the feature's key.");

        AppRequests::fromRecord(['id' => 'apr_0123456789abcdef0123456789abcdef', 'feature' => 'app:31', 'kind' => 'distributionResolver', 'mode' => 'ask', 'data' => []], null, ['secret' => 'test-secret']);
    }

    /**
     * `siren apps requests <id> --json` (siren-node 9645ffb,
     * src/cli/commands/requests.ts:112) prints Siren's request detail with
     * `appKey`, `featureKey` and `sends`. The fixture is that output, as
     * Node's test/cli-requests-json.test.ts expects it.
     */
    public function testFromRecordReadsTheCliJsonFileWithNoKeyPassed(): void
    {
        $file = (string) file_get_contents(__DIR__ . '/Fixtures/cli-request-json.json');

        foreach ([$file, json_decode($file, true)] as $record) {
            $body = json_decode(AppRequests::fromRecord($record, null, ['secret' => 'test-secret'])->rawBody, true);

            $this->assertSame('apr_9c60190a4501399d0ae55f041c237b39', $body['id']);
            $this->assertSame(['kind' => 'payoutMethod', 'key' => 'acme-ledger', 'id' => 'app:12'], $body['feature']);
            $this->assertSame('acme-payouts', $body['app']);
            $this->assertSame(['payoutId' => 88, 'amount' => 2500, 'currency' => 'USD'], $body['data']);
        }
    }

    public function testFromRecordTakesTheKeyPassedWhenTheCliFileHasNone(): void
    {
        $record = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/cli-request-json.json'), true);
        $record['appKey'] = null;
        $record['featureKey'] = null;

        $body = json_decode(AppRequests::fromRecord($record, 'acme-ledger', ['secret' => 'test-secret'])->rawBody, true);
        $this->assertSame('acme-ledger', $body['feature']['key']);

        $this->expectException(AppHandlerException::class);
        $this->expectExceptionMessage('Its featureKey is null, as `siren apps requests <id> --json` saves it when the app is gone.');
        AppRequests::fromRecord($record, null, ['secret' => 'test-secret']);
    }

    public function testTheParityTableNamesNodesFourLoggerMethods(): void
    {
        $section = self::section('Parity with the Node SDK');

        $this->assertStringContainsString('anything with `debug`, `info`, `warn` and `error`', $section);
    }

    // The dead methods: Siren dropped their routes.

    public function testTheKeyResourceIsGone(): void
    {
        $this->assertFalse(property_exists(Siren::class, 'apiKeys'));
        $this->assertFalse(class_exists('Siren\\Sdk\\Resources\\ApiKeys'));
    }

    public function testASubscriptionIsNoLongerCreatedThroughWebhooks(): void
    {
        $this->assertFalse(method_exists(WebhookSubscriptions::class, 'create'));
    }

    public function testTheRetiredWebhookScopesAreGone(): void
    {
        $this->assertFalse(defined(ApiKeyScope::class . '::WEBHOOK_SUBSCRIPTIONS_READ'));
        $this->assertFalse(defined(ApiKeyScope::class . '::WEBHOOK_SUBSCRIPTIONS_MANAGE'));
        $this->assertFalse(defined(ApiKeyRecordScope::class . '::WEBHOOKS_READ'));
        $this->assertFalse(defined(ApiKeyRecordScope::class . '::WEBHOOKS_MANAGE'));
        $this->assertNotContains('webhook-subscriptions:read', ApiKeyRecordScope::ALL);
        $this->assertNotContains('webhook-subscriptions:manage', ApiKeyRecordScope::ALL);
    }

    public function testScopesReadsTheAppsScopeList(): void
    {
        $groups = ['events' => [['scope' => 'event:*', 'resource' => 'events', 'action' => '*', 'label' => 'Every event']], 'ledger' => []];
        $siren = $this->mockSiren([new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($groups))]);

        $this->assertSame($groups, $siren->apps->scopes());
        $this->assertSame('GET', $this->history[0]['request']->getMethod());
        $this->assertSame('/siren/v1/apps/scopes', $this->history[0]['request']->getUri()->getPath());
    }

    public function testCreateKeySendsTheScopesOnce(): void
    {
        $app = ['key' => 'acme-crm', 'name' => 'Acme CRM', 'rawKey' => 'sk_test_new'];
        $siren = $this->mockSiren([new Response(201, ['Content-Type' => 'application/json'], (string) json_encode($app))]);

        $this->assertSame($app, $siren->apps->createKey('acme-crm', ['scopes' => ['event:*']]));
        $request = $this->history[0]['request'];
        $this->assertSame(['POST', '/siren/v1/apps/acme-crm/key'], [$request->getMethod(), $request->getUri()->getPath()]);
        $this->assertSame(['scopes' => ['event:*']], json_decode((string) $request->getBody(), true));
    }

    public function testCreateKeyRefusesAnotherField(): void
    {
        $siren = $this->mockSiren([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('apps->createKey() does not take label.');

        $siren->apps->createKey('acme-crm', ['label' => 'x']);
    }

    public function testRotateKeyPostsToTheRotateRoute(): void
    {
        $app = ['key' => 'acme-crm', 'name' => 'Acme CRM', 'rawKey' => 'sk_test_next'];
        $siren = $this->mockSiren([new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($app))]);

        $this->assertSame($app, $siren->apps->rotateKey('acme-crm'));
        $request = $this->history[0]['request'];
        $this->assertSame(['POST', '/siren/v1/apps/acme-crm/key/rotate'], [$request->getMethod(), $request->getUri()->getPath()]);
    }

    public function testRevokeKeyDeletesTheKey(): void
    {
        $app = ['key' => 'acme-crm', 'name' => 'Acme CRM'];
        $siren = $this->mockSiren([new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($app))]);

        $this->assertSame($app, $siren->apps->revokeKey('acme-crm'));
        $request = $this->history[0]['request'];
        $this->assertSame(['DELETE', '/siren/v1/apps/acme-crm/key'], [$request->getMethod(), $request->getUri()->getPath()]);
    }

    public function testTheKeyMethodsRefuseABadAppKeyBeforeSending(): void
    {
        $siren = $this->mockSiren([]);

        foreach (['createKey', 'rotateKey', 'revokeKey'] as $method) {
            try {
                $siren->apps->{$method}('scopes');
                $this->fail($method . ' refuses a reserved key.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('apps->' . $method . '() needs an app key', $e->getMessage());
            }
        }

        $this->assertSame([], $this->history);
    }

    public function testTheKeyRetryDoesNotResendAWrite(): void
    {
        $siren = $this->mockSiren([new Response(503), new Response(503)]);

        try {
            $siren->apps->rotateKey('acme-crm');
        } catch (\Throwable) {
        }

        $this->assertCount(1, $this->history, 'A key write is sent once.');
    }

    public function testOpenapiDropsTheRemovedOperations(): void
    {
        $spec = (string) file_get_contents(dirname(__DIR__) . '/openapi.yaml');

        $this->assertStringNotContainsString('/api-keys', $spec);
        $this->assertDoesNotMatchRegularExpression('/\n  \/webhooks:\n(    .*\n)*?    post:/', $spec);
        $this->assertStringContainsString('  /apps/scopes:', $spec);
        $this->assertStringContainsString('  /apps/{key}/key:', $spec);
        $this->assertStringContainsString('  /apps/{key}/key/rotate:', $spec);
    }

    public function testTheChangelogNamesWhatReplacesTheRemovedMethods(): void
    {
        $changelog = (string) file_get_contents(dirname(__DIR__) . '/CHANGELOG.md');
        $unreleased = substr($changelog, 0, (int) strpos($changelog, "\n## [0."));
        $breaking = substr($unreleased, (int) strpos($unreleased, "\n### Breaking\n"));

        foreach (['`apiKeys`', '`webhooks->subscriptions->create()`', '`siren apps apply`', '`apps->createKey()`', '`apps->rotateKey()`', '`apps->revokeKey()`', '`siren apps rotate-key`', '`apps->scopes()`', 'WEBHOOK_SUBSCRIPTIONS_READ', 'WEBHOOKS_READ'] as $name) {
            $this->assertStringContainsString($name, $breaking);
        }
    }

    /** @return array<string, string> The README's samples that register a function, by name. */
    private static function handlerSamples(): array
    {
        preg_match_all('/<!-- readme-test: ([a-z0-9-]+) -->\n```php\n(.*?)\n```/s', (string) file_get_contents(dirname(__DIR__) . '/README.md'), $matches, PREG_SET_ORDER);
        $samples = [];

        foreach ($matches as [, $name, $code]) {
            if (str_contains($code, '->on(') || str_contains($code, '->onEvent(')) {
                $samples[$name] = $code;
            }
        }

        self::assertGreaterThan(10, count($samples));

        return $samples;
    }

    /** @return list<string> Plain function calls in the code that PHP itself does not define. */
    private static function ownFunctionCalls(string $code): array
    {
        $code = (string) preg_replace(['/(?<=\s)\/\/[^\n]*/', '/\/\*.*?\*\//s'], '', $code);
        preg_match_all('/(?<![\w$>:\\\\])([a-zA-Z_]\w*)\s*\(/', $code, $matches);
        $skip = ['function', 'fn', 'if', 'foreach', 'for', 'while', 'match', 'array', 'isset', 'empty', 'list', 'new', 'return', 'use'];

        return array_values(array_unique(array_filter(
            $matches[1],
            static fn (string $name): bool => !in_array(strtolower($name), $skip, true) && !function_exists($name) && !preg_match('/\bnew\s+' . $name . '\s*\(/', $code) && !preg_match('/\b(class|function)\s+' . $name . '\b/', $code),
        )));
    }

    private static function readme(string $name): string
    {
        $found = preg_match('/<!-- readme-test: ' . preg_quote($name, '/') . ' -->\n```php\n(.*?)\n```/s', (string) file_get_contents(dirname(__DIR__) . '/README.md'), $matches);
        self::assertSame(1, $found, 'The README has no sample named ' . $name);

        return $matches[1];
    }

    private static function section(string $heading): string
    {
        $readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');
        $start = preg_match('/\n(#{2,3}) ' . preg_quote($heading, '/') . '\n/', $readme, $m, PREG_OFFSET_CAPTURE);
        self::assertSame(1, $start, 'The README has no section ' . $heading);
        $from = $m[0][1] + 1;
        $next = preg_match('/\n#{2,' . strlen($m[1][0]) . '} /', $readme, $n, PREG_OFFSET_CAPTURE, $from + 1);

        return substr($readme, $from, $next === 1 ? $n[0][1] - $from : null);
    }
}
