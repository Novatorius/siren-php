<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use Psr\Log\LogLevel;
use Siren\Sdk\AppRequest;
use Siren\Sdk\AppRequestHandler;
use Siren\Sdk\AppRequestReply;
use Siren\Sdk\Exception\AppHandlerException;
use Siren\Sdk\Exception\AppSlowTellException;
use Siren\Sdk\Exception\SignatureVerificationException;
use Siren\Sdk\Exception\SirenException;
use Siren\Sdk\Testing\AppRequests;
use Siren\Sdk\Testing\SignedRequest;
use Siren\Sdk\Tests\Support\CollectingLogger;
use Siren\Sdk\WebhookEvent;

/**
 * DX pass 4, errors, testing and local development
 * (review/sdk-dx/pass-4-review.md and pass-4-builder-spec.md in the run
 * folder): a `logger` option with levels and short lines, a refused
 * signature that reaches the app's own log, a tell slower than Siren's 8
 * seconds, signed webhook events for onEvent tests, a stored request
 * replayed as a test input, and the README's local development story.
 */
final class AppRequestDxPass4Test extends TestCase
{
    private const SECRET = 'whsec_dx_pass_four_test_secret';

    /** @var list<array{0: \Throwable, 1: object|null}> */
    private array $errors = [];

    /** Seconds added to the real clock, so a test moves time without waiting. */
    private float $offset = 0.0;

    /**
     * @param list<\Psr\Http\Message\ResponseInterface|\Throwable> $responses
     * @param array<string, mixed> $options
     */
    private function handler(array $responses = [], array $options = [], bool $collectErrors = true): AppRequestHandler
    {
        $this->errors = [];
        $this->offset = 0.0;
        $options += ['clock' => fn (): float => microtime(true) + $this->offset];

        if ($collectErrors) {
            $options += ['onError' => function (\Throwable $error, ?object $about): void {
                $this->errors[] = [$error, $about];
            }];
        }

        return $this->mockSiren($responses)->apps->requestHandler(self::SECRET, $options);
    }

    private static function deliver(AppRequestHandler $handler, SignedRequest $request): AppRequestReply
    {
        $reply = $handler->answer($request->method, $request->rawBody, $request->headers);

        if ($reply->after !== null) {
            ($reply->after)();
        }

        return $reply;
    }

    /**
     * What the handler writes to PHP's error log while `$run` runs.
     *
     * @return list<string> The lines, with error_log()'s date prefix left on.
     */
    private static function errorLog(callable $run): array
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'siren-log');
        $previous = ini_set('error_log', $log);

        try {
            $run();
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($log)), static fn (string $line): bool => $line !== ''));
        unlink($log);

        return $lines;
    }

    private static function wrongSecret(string $kind = 'postConversionAction', string $key = 'tell-the-crm'): SignedRequest
    {
        return AppRequests::make($kind, $key, ['secret' => 'whsec_the_old_secret']);
    }

    // Finding 5: a logger option, with levels and short lines.

    public function testTheLoggerOptionTakesAPsr3LoggerAndNothingElse(): void
    {
        $this->expectException(AppHandlerException::class);
        $this->expectExceptionMessage('apps->requestHandler() takes logger as a PSR-3 Psr\Log\LoggerInterface, such as Monolog or your framework\'s logger.');

        $this->mockSiren([])->apps->requestHandler(self::SECRET, ['logger' => 'error_log']);
    }

    public function testEachHandledRequestIsOneDebugLine(): void
    {
        $logger = new CollectingLogger();
        $handler = $this->handler([], ['logger' => $logger])
            ->on('postConversionAction', 'tell-the-crm', static fn () => null)
            ->onEvent('conversion.approved', static fn () => null);

        $tell = AppRequests::make('postConversionAction', 'tell-the-crm', ['secret' => self::SECRET]);
        $event = AppRequests::event('conversion.approved', null, ['secret' => self::SECRET]);
        self::deliver($handler, $tell);
        self::deliver($handler, $event);

        $debug = $logger->at(LogLevel::DEBUG);
        $this->assertCount(2, $debug);
        $this->assertMatchesRegularExpression('/^Siren request handler: postConversionAction tell-the-crm ' . $tell->id() . ' replied 200 in \d+ ms$/', $debug[0]);
        $this->assertMatchesRegularExpression('/^Siren request handler: conversion\.approved evt_[0-9a-f]{32} replied 200 in \d+ ms$/', $debug[1]);
        $this->assertSame([], $this->errors);
    }

    public function testTheDefaultOnErrorWritesThroughTheLoggerAtErrorWithTheException(): void
    {
        $logger = new CollectingLogger();
        $handler = $this->handler([], ['logger' => $logger], false)
            ->on('distributionResolver', 'split-by-score', static function (): never {
                throw new \RuntimeException('ledger down');
            });

        $request = AppRequests::make('distributionResolver', 'split-by-score', ['secret' => self::SECRET]);
        self::deliver($handler, $request);

        $errors = array_values(array_filter($logger->lines, static fn (array $line): bool => $line['level'] === LogLevel::ERROR));
        $this->assertCount(1, $errors);
        $this->assertStringStartsWith('Siren request handler for distributionResolver split-by-score, request ' . $request->id() . ': RuntimeException: ledger down. Siren sends it again', $errors[0]['message']);
        $this->assertInstanceOf(\RuntimeException::class, $errors[0]['context']['exception'] ?? null);
    }

    public function testACustomOnErrorReplacesTheDefaultLineButTheDebugLinesStay(): void
    {
        $logger = new CollectingLogger();
        $handler = $this->handler([], ['logger' => $logger])
            ->on('postConversionAction', 'tell-the-crm', static function (): never {
                throw new \RuntimeException('crm down');
            });

        self::deliver($handler, AppRequests::make('postConversionAction', 'tell-the-crm', ['secret' => self::SECRET]));

        $this->assertCount(1, $this->errors);
        $this->assertSame([], $logger->at(LogLevel::ERROR));
        $this->assertCount(1, $logger->at(LogLevel::DEBUG));
        $this->assertStringContainsString(' replied 500 in ', $logger->at(LogLevel::DEBUG)[0]);
    }

    public function testWithNoLoggerAHandledRequestWritesNothingAndAFailureWritesOneLine(): void
    {
        $handler = $this->handler([], [], false)
            ->on('postConversionAction', 'tell-the-crm', static fn () => null)
            ->on('postAllocationAction', 'tell-the-ledger', static function (): never {
                throw new \RuntimeException('ledger down');
            });

        $this->assertSame([], self::errorLog(static fn () => self::deliver($handler, AppRequests::make('postConversionAction', 'tell-the-crm', ['secret' => self::SECRET]))));
        $lines = self::errorLog(static fn () => self::deliver($handler, AppRequests::make('postAllocationAction', 'tell-the-ledger', ['secret' => self::SECRET])));
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('RuntimeException: ledger down', $lines[0]);
    }

    public function testASingle401OnTheAnswerPostWritesAtMostTwoLinesAndNoStack(): void
    {
        $handler = $this->handler([self::jsonResponse(401, ['error' => 'Unauthorized.'])], [], false)
            ->on('distributionResolver', 'split-by-score', static fn (AppRequest $request): array => [
                'outcome' => 'allocated', 'units' => 'USD', 'shares' => ['41' => 6000, '43' => 4000],
            ]);

        $lines = self::errorLog(static fn () => self::deliver($handler, AppRequests::make('distributionResolver', 'split-by-score', ['secret' => self::SECRET])));

        $this->assertGreaterThanOrEqual(1, count($lines));
        $this->assertLessThanOrEqual(2, count($lines), implode("\n", $lines));
        $this->assertStringContainsString('app_answer_unauthorized', implode("\n", $lines));
        $this->assertStringNotContainsString('#0 ', implode("\n", $lines));
        $this->assertStringNotContainsString('.php:', implode("\n", $lines));
    }

    public function testAFunctionThatThrowsNamesWhereItThrew(): void
    {
        $handler = $this->handler([], [], false)
            ->on('postConversionAction', 'tell-the-crm', static function (): never {
                throw new \RuntimeException('crm down');
            });

        $lines = self::errorLog(static fn () => self::deliver($handler, AppRequests::make('postConversionAction', 'tell-the-crm', ['secret' => self::SECRET])));

        $this->assertCount(1, $lines);
        $this->assertMatchesRegularExpression('/ \(thrown at \/\S+\/tests\/AppRequestDxPass4Test\.php:\d+\)$/', $lines[0]);
    }

    // Finding 1: a refused signature reaches the app's own log.

    public function testAWrongSecretStillGets401AndReachesOnError(): void
    {
        $handler = $this->handler()->on('postConversionAction', 'tell-the-crm', static fn () => null);

        $reply = self::deliver($handler, self::wrongSecret());

        $this->assertSame(401, $reply->status);
        $this->assertStringContainsString('The request signature does not match.', $reply->body);
        $this->assertCount(1, $this->errors);
        $this->assertInstanceOf(SignatureVerificationException::class, $this->errors[0][0]);
        $this->assertSame('signature_mismatch', $this->errors[0][0]->getErrorCode());
        $this->assertNull($this->errors[0][1]);
    }

    /** @return array<string, array{0: \Closure(): SignedRequest, 1: string, 2: string}> */
    public static function refusals(): array
    {
        return [
            'a wrong secret' => [
                static fn (): SignedRequest => self::wrongSecret(),
                'signature_mismatch',
                "Siren request handler: the request signature does not match. Check SIREN_SIGNING_SECRET: it must be this app's signing secret, and it changes when the secret is rotated. Pass the raw body exactly as received.",
            ],
            'an old time' => [
                static fn (): SignedRequest => AppRequests::make('postConversionAction', 'tell-the-crm', ['secret' => self::SECRET, 'timestamp' => time() - 600]),
                'timestamp_out_of_tolerance',
                "Siren request handler: the request's signed time is more than 300 seconds from this machine's clock. Check the clock.",
            ],
            'no V2 header' => [
                static function (): SignedRequest {
                    $request = AppRequests::make('postConversionAction', 'tell-the-crm', ['secret' => self::SECRET]);
                    $headers = $request->headers;
                    unset($headers['X-Siren-Signature-V2']);

                    return new SignedRequest('POST', $headers, $request->rawBody);
                },
                'signature_missing',
                "Siren request handler: the request has no X-Siren-Signature-V2 header. Check that a proxy passes Siren's X-Siren-* headers through.",
            ],
            'a timestamp header that differs' => [
                static function (): SignedRequest {
                    $request = AppRequests::make('postConversionAction', 'tell-the-crm', ['secret' => self::SECRET]);

                    return new SignedRequest('POST', ['X-Siren-Timestamp' => '1'] + $request->headers, $request->rawBody);
                },
                'timestamp_mismatch',
                "Siren request handler: the X-Siren-Timestamp header differs from the time X-Siren-Signature-V2 signed. Check that a proxy passes Siren's X-Siren-* headers through unchanged.",
            ],
        ];
    }

    /**
     * @param \Closure(): SignedRequest $request
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testEachRefusalIsOneWarningThatSaysWhatToCheck(\Closure $request, string $code, string $line): void
    {
        $logger = new CollectingLogger();
        $handler = $this->handler([], ['logger' => $logger], false)->on('postConversionAction', 'tell-the-crm', static fn () => null);

        $reply = self::deliver($handler, $request());

        $this->assertSame(401, $reply->status);
        $this->assertSame([$line], $logger->at(LogLevel::WARNING));
        $warning = array_values(array_filter($logger->lines, static fn (array $l): bool => $l['level'] === LogLevel::WARNING))[0];
        $exception = $warning['context']['exception'] ?? null;
        $this->assertInstanceOf(SignatureVerificationException::class, $exception);
        $this->assertSame($code, $exception->getErrorCode());
    }

    public function testWithNoLoggerAWrongSecretWritesOneErrorLogLine(): void
    {
        $handler = $this->handler([], [], false)->on('postConversionAction', 'tell-the-crm', static fn () => null);

        $lines = self::errorLog(static fn () => self::deliver($handler, self::wrongSecret()));

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('Check SIREN_SIGNING_SECRET', $lines[0]);
    }

    public function testAFloodOfRefusalsIsFiveLinesAMinuteAndThenACount(): void
    {
        $logger = new CollectingLogger();
        $handler = $this->handler([], ['logger' => $logger], false)->on('postConversionAction', 'tell-the-crm', static fn () => null);

        for ($i = 0; $i < 8; $i++) {
            self::deliver($handler, self::wrongSecret());
            $this->offset += 1;
        }

        $this->assertCount(5, $logger->at(LogLevel::WARNING));

        // The next refusal after the minute writes the count of the three held back, then its own line.
        $this->offset = 61;
        $request = AppRequests::make('postConversionAction', 'tell-the-crm', ['secret' => 'whsec_the_old_secret', 'timestamp' => (int) (microtime(true) + $this->offset)]);
        self::deliver($handler, $request);

        $warnings = $logger->at(LogLevel::WARNING);
        $this->assertCount(7, $warnings);
        $this->assertSame('Siren request handler: 3 more requests failed the signature check in the last minute. When every request fails, SIREN_SIGNING_SECRET is wrong or was rotated.', $warnings[5]);
        $this->assertStringContainsString('the request signature does not match', $warnings[6]);
    }

    public function testACustomOnErrorHearsEveryRefusalInAFlood(): void
    {
        $handler = $this->handler()->on('postConversionAction', 'tell-the-crm', static fn () => null);

        for ($i = 0; $i < 8; $i++) {
            self::deliver($handler, self::wrongSecret());
        }

        $this->assertCount(8, $this->errors);
    }

    public function testSimulateHandsBackARefusedSignatureAsTheError(): void
    {
        $handler = $this->handler()->on('postConversionAction', 'tell-the-crm', static fn () => null);

        $result = $handler->simulate(self::wrongSecret());

        $this->assertSame(401, $result->status);
        $this->assertInstanceOf(SignatureVerificationException::class, $result->error);
    }

    // Finding 3: a tell slower than Siren's 8 seconds.

    public function testTheTellTimeLimitIsSirensEightSeconds(): void
    {
        $this->assertSame(8, AppRequestHandler::TELL_TIME_LIMIT_SECONDS);
    }

    public function testATellOverEightSecondsStillGets200AndOnErrorHearsItRanTwice(): void
    {
        $handler = $this->handler();
        $handler->on('postConversionAction', 'tell-the-crm', function () {
            $this->offset += 9.0;

            return null;
        });

        $request = AppRequests::make('postConversionAction', 'tell-the-crm', ['secret' => self::SECRET]);
        $reply = self::deliver($handler, $request);

        $this->assertSame(200, $reply->status);
        $this->assertCount(1, $this->errors);
        $this->assertInstanceOf(AppSlowTellException::class, $this->errors[0][0]);
        $this->assertInstanceOf(SirenException::class, $this->errors[0][0]);
        $this->assertSame(
            'postConversionAction tell-the-crm took 9.0 s. Siren stops waiting at 8 s and sends the tell again, so it ran twice. Save what you need, hand the slow part to runLater or your own queue, and return.',
            $this->errors[0][0]->getMessage(),
        );
    }

    public function testATellUnderEightSecondsIsQuiet(): void
    {
        $handler = $this->handler();
        $handler->on('postConversionAction', 'tell-the-crm', function () {
            $this->offset += 7.9;

            return null;
        });

        self::deliver($handler, AppRequests::make('postConversionAction', 'tell-the-crm', ['secret' => self::SECRET]));

        $this->assertSame([], $this->errors);
    }

    public function testSimulateReportsASlowTellAsItsError(): void
    {
        $handler = $this->handler();
        $handler->on('postConversionAction', 'tell-the-crm', function () {
            $this->offset += 9.0;

            return null;
        });

        $result = $handler->simulate(AppRequests::make('postConversionAction', 'tell-the-crm', ['secret' => self::SECRET]));

        $this->assertSame(200, $result->status);
        $this->assertInstanceOf(AppSlowTellException::class, $result->error);
    }

    public function testASlowTellIsAWarningInTheLog(): void
    {
        $logger = new CollectingLogger();
        $handler = $this->handler([], ['logger' => $logger], false);
        $handler->on('postConversionAction', 'tell-the-crm', function () {
            $this->offset += 9.0;

            return null;
        });

        self::deliver($handler, AppRequests::make('postConversionAction', 'tell-the-crm', ['secret' => self::SECRET]));

        $this->assertCount(1, $logger->at(LogLevel::WARNING));
        $this->assertStringContainsString('took 9.0 s. Siren stops waiting at 8 s', $logger->at(LogLevel::WARNING)[0]);
        $this->assertSame([], $logger->at(LogLevel::ERROR));
    }

    public function testAWebhookEventOverEightSecondsIsSlowToo(): void
    {
        $handler = $this->handler();
        $handler->onEvent('conversion.approved', function () {
            $this->offset += 9.0;

            return null;
        });

        $result = $handler->simulate(AppRequests::event('conversion.approved', null, ['secret' => self::SECRET]));

        $this->assertSame(200, $result->status);
        $this->assertInstanceOf(AppSlowTellException::class, $result->error);
        $this->assertStringStartsWith('conversion.approved took 9.0 s. Siren stops waiting at 8 s and sends the event again, so it ran twice.', $result->error->getMessage());
    }

    // Finding 4: signed webhook events for onEvent tests.

    public function testEventSignsAWebhookEventAsSirenSignsOneToAnAppsAddress(): void
    {
        $event = AppRequests::event('conversion.approved', null, ['secret' => self::SECRET, 'timestamp' => 1700000000]);

        $this->assertSame('POST', $event->method);
        $this->assertSame('conversion.approved', $event->headers['X-Siren-Event']);
        $this->assertMatchesRegularExpression('/^evt_[0-9a-f]{32}$/', $event->headers['X-Siren-Delivery']);
        $this->assertSame('1700000000', $event->headers['X-Siren-Timestamp']);
        $this->assertSame('t=1700000000,v1=' . hash_hmac('sha256', '1700000000.' . $event->rawBody, self::SECRET), $event->headers['X-Siren-Signature-V2']);
        $this->assertSame('sha256=' . hash_hmac('sha256', $event->rawBody, self::SECRET), $event->headers['X-Siren-Signature']);
        $this->assertSame('Siren-Webhooks/1.0', $event->headers['User-Agent']);
        $this->assertSame(AppRequests::sampleEventData('conversion.approved'), json_decode($event->rawBody, true));
    }

    public function testAnOnEventFunctionGetsTheSampleThroughSimulate(): void
    {
        $seen = [];
        $handler = $this->handler()->onEvent('payout.paid', static function (WebhookEvent $event) use (&$seen): void {
            $seen[] = $event;
        });

        $request = AppRequests::event('payout.paid', ['payoutId' => 7], ['secret' => self::SECRET]);
        $result = $handler->simulate($request);

        $this->assertSame(200, $result->status);
        $this->assertNull($result->error);
        $this->assertCount(1, $seen);
        $this->assertSame(['payoutId' => 7], $seen[0]->getData());
        $this->assertSame($request->headers['X-Siren-Delivery'], $seen[0]->getDeliveryId());
    }

    public function testEventRefusesAnAppRequestAndATypeWithNoSampleWithoutData(): void
    {
        try {
            AppRequests::event('app.request', [], ['secret' => self::SECRET]);
            $this->fail('app.request is not a webhook event.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('An app request is not a webhook event. Build one with AppRequests::make($kind, $key).', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('There is no sample for webhook.test. Pass the payload as data, or use one of ');
        AppRequests::sampleEventData('webhook.test');
    }

    public function testTheSamplesCoverEveryEventSirensDocsDescribe(): void
    {
        $samples = json_decode((string) file_get_contents(dirname(__DIR__) . '/resources/webhook-event-samples.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertCount(60, $samples['events']);
        $this->assertSame(['payoutId' => 101, 'collaboratorId' => 102, 'value' => 25, 'currency' => 'example'], $samples['events']['payout.paid']);

        foreach ($samples['events'] as $type => $data) {
            $this->assertMatchesRegularExpression('/^[a-z_]+\.[a-z_]+$/', $type);
            $this->assertIsArray($data, $type);
            $this->assertNotSame([], $data, $type);
            $this->assertSame($data, AppRequests::sampleEventData($type), $type);
        }
    }

    public function testTheSamplesMatchSirensEventDocs(): void
    {
        $checkout = getenv('SIREN_CHECKOUT');

        if (!is_string($checkout) || $checkout === '') {
            $this->markTestSkipped("Set SIREN_CHECKOUT to a Siren checkout to compare the samples with its docs/api/webhooks-events.md.");
        }

        $command = [PHP_BINARY, dirname(__DIR__) . '/bin/event-samples.php', '--check', $checkout];
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

        $this->assertSame(0, proc_close($process), $out);
    }

    // Finding 2: a stored request replayed as a test input.

    /** @return array<string, mixed> A request row as `GET /apps/requests/{id}` answers it. */
    private static function record(): array
    {
        return [
            'id' => 'apr_0123456789abcdef0123456789abcdef',
            'appId' => 12,
            'feature' => 'app:31',
            'kind' => 'distributionResolver',
            'mode' => 'ask',
            'subject' => ['type' => 'distribution', 'id' => '7'],
            'status' => 'needs_attention',
            'sendCount' => 3,
            'answerBy' => '2026-11-24T18:00:00+00:00',
            'answeredBy' => null,
            'answeredAt' => null,
            'lastError' => 'The app answered 500.',
            'createdAt' => '2026-11-24T17:00:00+00:00',
            'data' => AppRequests::sampleData('distributionResolver'),
            'answer' => null,
            'answeredByUser' => null,
        ];
    }

    public function testFromRecordSignsTheStoredRequestWithItsOwnIdDataAndDeadline(): void
    {
        $request = AppRequests::fromRecord(self::record(), 'split-by-score', ['secret' => self::SECRET]);
        $body = json_decode($request->rawBody, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('apr_0123456789abcdef0123456789abcdef', $body['id']);
        $this->assertSame('ask', $body['mode']);
        $this->assertSame(['kind' => 'distributionResolver', 'key' => 'split-by-score', 'id' => 'app:31'], $body['feature']);
        $this->assertSame('2026-11-24T18:00:00+00:00', $body['answerBy']);
        $this->assertSame(self::record()['data'], $body['data']);

        $seen = null;
        $handler = $this->handler()->on('distributionResolver', 'split-by-score', static function (AppRequest $r) use (&$seen): array {
            $seen = $r;

            return ['outcome' => 'allocated', 'units' => 'USD', 'shares' => ['41' => 6000, '43' => 4000]];
        });
        $result = $handler->simulate($request);

        $this->assertSame(200, $result->status);
        $this->assertNull($result->error);
        $this->assertSame('apr_0123456789abcdef0123456789abcdef', $seen?->id);
    }

    public function testFromRecordTakesTheJsonTextAndAKeyTheRecordHolds(): void
    {
        $record = self::record();
        $record['feature'] = ['kind' => 'distributionResolver', 'key' => 'split-by-score', 'id' => 'app:31'];

        $request = AppRequests::fromRecord(json_encode($record, JSON_THROW_ON_ERROR), null, ['secret' => self::SECRET]);

        $this->assertSame('split-by-score', json_decode($request->rawBody, true)['feature']['key']);
    }

    public function testFromRecordForATellHasNoDeadline(): void
    {
        $record = ['id' => 'apr_0123456789abcdef0123456789abcdee', 'feature' => 'app:4', 'kind' => 'postConversionAction', 'mode' => 'tell',
            'answerBy' => null, 'data' => AppRequests::sampleData('postConversionAction')];

        $body = json_decode(AppRequests::fromRecord($record, 'tell-the-crm', ['secret' => self::SECRET])->rawBody, true);

        $this->assertSame('tell', $body['mode']);
        $this->assertArrayNotHasKey('answerBy', $body);
        $this->assertArrayNotHasKey('answerUrl', $body);
    }

    public function testFromRecordWithNoKeySaysWhereToFindIt(): void
    {
        $this->expectException(\Siren\Sdk\Exception\AppHandlerException::class);
        $this->expectExceptionMessage("The record names its feature as app:31, without the feature's key. Pass the key, such as AppRequests::fromRecord(\$record, 'split-by-score'). `siren apps show <app>` lists each feature's key.");

        AppRequests::fromRecord(self::record(), null, ['secret' => self::SECRET]);
    }

    public function testFromRecordRefusesSomethingThatIsNotARequestRow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('AppRequests::fromRecord() takes a request as `siren apps requests <id>` or GET /apps/requests/{id} gives it, and this one has no ');

        AppRequests::fromRecord(['id' => 'apr_0123456789abcdef0123456789abcdef'], 'split-by-score', ['secret' => self::SECRET]);
    }

    // Test requests: `siren trigger ask` sends a feature's sample marked as a test (contract/devtools-trigger.md section 2).

    public function testATestRequestCarriesTestTrueRightAfterItsMode(): void
    {
        $request = AppRequests::make('payoutMethod', 'acme-ledger', ['secret' => self::SECRET, 'test' => true]);
        $body = json_decode($request->rawBody, true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($body['test']);
        $keys = array_keys($body);
        $this->assertSame('test', $keys[array_search('mode', $keys, true) + 1]);

        $seen = null;
        $handler = $this->handler()->on('payoutMethod', 'acme-ledger', static function (AppRequest $r) use (&$seen): array {
            $seen = $r;

            return ['outcome' => 'paid', 'reference' => 'test-' . $r->id];
        });
        $result = $handler->simulate($request);

        $this->assertSame(200, $result->status);
        $this->assertNull($result->error);
        $this->assertTrue($seen?->test);
    }

    public function testARequestWithNoTestFieldIsNotATest(): void
    {
        $request = AppRequests::make('payoutMethod', 'acme-ledger', ['secret' => self::SECRET]);
        $this->assertArrayNotHasKey('test', json_decode($request->rawBody, true));

        $seen = null;
        $handler = $this->handler()->on('payoutMethod', 'acme-ledger', static function (AppRequest $r) use (&$seen): array {
            $seen = $r;

            return ['outcome' => 'paid', 'reference' => 'TX-1'];
        });
        $handler->simulate($request);

        $this->assertFalse($seen?->test);
    }

    public function testATestThatIsNotTrueOrFalseGets400NamingTheField(): void
    {
        $made = json_decode(AppRequests::make('payoutMethod', 'acme-ledger', ['secret' => self::SECRET])->rawBody, true);
        $made['test'] = 'yes';
        $handler = $this->handler()->on('payoutMethod', 'acme-ledger', static fn () => ['outcome' => 'paid', 'reference' => 'TX-1']);

        $reply = self::deliver($handler, AppRequests::make('payoutMethod', 'acme-ledger', ['secret' => self::SECRET, 'body' => $made]));

        $this->assertSame(400, $reply->status);
        $this->assertStringContainsString('its `test` must be true or false', $reply->body);
    }

    public function testFromRecordCarriesTest(): void
    {
        $record = self::record();
        $record['test'] = true;

        $body = json_decode(AppRequests::fromRecord($record, 'split-by-score', ['secret' => self::SECRET])->rawBody, true);
        $this->assertTrue($body['test']);

        $body = json_decode(AppRequests::fromRecord(self::record(), 'split-by-score', ['secret' => self::SECRET])->rawBody, true);
        $this->assertArrayNotHasKey('test', $body);
    }

    public function testTheReadmeSaysAHandlerMovesNoMoneyOnATestRequest(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');
        $section = self::section($readme, 'Try it without Siren');

        $this->assertStringContainsString('$request->test', $section);
        $this->assertStringContainsString('never move money or write records on a test request', $section);
        $this->assertStringContainsString("if (\$request->test) {", self::readme('answer-payout'));
    }

    // The lower finding: an answer Siren refused with 422 says to fix it, not to wait.

    public function testA422SaysToReproduceAndFixNotToWait(): void
    {
        $handler = $this->handler([self::errorResponse(422, 'The shares add up to 13000, more than the pool of 10000.', 'app_answer_invalid')], [], false)
            ->on('distributionResolver', 'split-by-score', static fn (): array => [
                'outcome' => 'allocated', 'units' => 'USD', 'shares' => ['41' => 9000, '43' => 4000],
            ]);

        $request = AppRequests::make('distributionResolver', 'split-by-score', ['secret' => self::SECRET]);
        $lines = self::errorLog(static fn () => self::deliver($handler, $request));

        $this->assertCount(1, $lines);
        $this->assertStringContainsString(
            "The shares add up to 13000, more than the pool of 10000. Your function's answer broke Siren's rule. Reproduce it with simulate() on this request's data, fix it, then resend with `siren apps resend " . $request->id() . '`.',
            $lines[0],
        );
        $this->assertStringNotContainsString('Siren sends it again', $lines[0]);
    }

    public function testAnAnswerTheSdkCannotSendSaysTheSame(): void
    {
        $handler = $this->handler([], [], false)
            ->on('distributionResolver', 'split-by-score', static fn (): array => ['units' => 'USD']);

        $request = AppRequests::make('distributionResolver', 'split-by-score', ['secret' => self::SECRET]);
        $lines = self::errorLog(static fn () => self::deliver($handler, $request));

        $this->assertCount(1, $lines);
        $this->assertStringContainsString("Your function's answer broke Siren's rule.", $lines[0]);
        $this->assertStringNotContainsString('Siren sends it again', $lines[0]);
    }

    // The README: local development with no tunnel, and the new helpers in samples that run.

    public function testTheReadmeTeachesListenAndTriggerAndNoTunnel(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');

        $this->assertStringContainsString("\n## Develop on your machine\n", $readme);
        $section = self::section($readme, 'Develop on your machine');
        $this->assertStringContainsString('siren listen --forward-to localhost:3000/siren', $section);
        $this->assertStringContainsString('siren trigger ask <app> <feature>', $section);
        $this->assertStringContainsString('npx @novatorius/siren', $section);
        $this->assertStringContainsString('sk_test_', $section);
        $this->assertStringContainsString('.env.sandbox', $section);
        $this->assertDoesNotMatchRegularExpression('/ngrok|cloudflared|tunnel(?! )|localtunnel/i', str_replace('no tunnel', '', $readme));
        $this->assertStringContainsString('npx @novatorius/siren apps rotate-key', $readme);
        $this->assertStringContainsString('A tell must finish within 8 seconds', $readme);
    }

    public function testTheReplayAndEventSamplesPassUnderPhpUnit(): void
    {
        $dir = sys_get_temp_dir() . '/siren-p4-' . bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            foreach (['replay-a-request' => 'ReplayTest.php', 'test-an-event' => 'ConversionApprovedTest.php'] as $name => $file) {
                file_put_contents($dir . '/' . $file, self::readme($name));

                if ($name === 'replay-a-request') {
                    $this->assertSame(1, preg_match('/<!-- readme-test: replay-record -->\n```json\n(.*?)\n```/s', (string) file_get_contents(dirname(__DIR__) . '/README.md'), $record));
                    file_put_contents($dir . '/request.json', $record[1]);
                }

                $command = [PHP_BINARY, dirname(__DIR__) . '/vendor/bin/phpunit', '--no-configuration', '--bootstrap', dirname(__DIR__) . '/vendor/autoload.php', $dir . '/' . $file];
                $env = getenv();
                unset($env['SIREN_API_KEY'], $env['SIREN_SIGNING_SECRET']);
                $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $dir, $env);
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

                $this->assertSame(0, proc_close($process), $name . ': ' . $out);
                $this->assertMatchesRegularExpression('/OK \(1 test, \d+ assertions?\)/', $out, $name);
            }
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }

    private static function readme(string $name): string
    {
        $found = preg_match('/<!-- readme-test: ' . preg_quote($name, '/') . ' -->\n```php\n(.*?)\n```/s', (string) file_get_contents(dirname(__DIR__) . '/README.md'), $matches);
        self::assertSame(1, $found, 'The README has no sample named ' . $name);

        return $matches[1];
    }

    private static function section(string $readme, string $heading): string
    {
        $start = strpos($readme, "\n## " . $heading . "\n");
        self::assertNotFalse($start);
        $end = strpos($readme, "\n## ", $start + 4);

        return substr($readme, $start, $end === false ? null : $end - $start);
    }
}
