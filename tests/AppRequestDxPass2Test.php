<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Siren\Sdk\AppItemKind;
use Siren\Sdk\AppRequest;
use Siren\Sdk\AppRequestHandler;
use Siren\Sdk\Exception\ValidationException;
use Siren\Sdk\Testing\AppRequests;
use Siren\Sdk\Testing\SignedRequest;

/**
 * DX pass 2, the mental model (review/sdk-dx/pass-2-review.md and
 * pass-2-builder-spec.md in the run folder): an unknown kind is refused when
 * it is registered, "answer later" is something the function says, a failed
 * ask says when Siren sends it again, one class names the kinds, and
 * `simulate()` checks an answer as Siren does.
 */
final class AppRequestDxPass2Test extends TestCase
{
    private const SECRET = 'whsec_dx_pass_two_test_secret';

    /** The README block, word for word, as pass-2-builder-spec.md gives it for all three SDKs. */
    private const HOW_SIREN_TALKS = <<<'MD'
        ## How Siren talks to your app

        - **An app** is your own code that works with Siren. It holds a key, which it calls Siren with, and an address, where Siren sends it signed requests.
        - **The signing secret** belongs to the address. Siren signs every request with it, and the handler checks each one.
        - **A feature** is what the app adds to one of Siren's pickers. Its kind says what it does, such as `distributionResolver`, and its key names it in your app.
        - **A tell** lets the app know something happened, such as an approved conversion. The handler runs your function and replies 200.
        - **An ask** needs an answer, and the work it's about waits for it. The handler replies 200 at once, runs your function and posts what it returns.
        - **A custom event** goes the other way: the app sends it to Siren.
        MD;

    /** @var list<array{0: \Throwable, 1: object|null}> */
    private array $errors = [];

    /**
     * @param list<\Psr\Http\Message\ResponseInterface|\Throwable> $responses
     * @param array<string, mixed> $options
     */
    private function handler(array $responses = [], array $options = []): AppRequestHandler
    {
        $this->errors = [];

        return $this->mockSiren($responses)->apps->requestHandler(self::SECRET, $options + ['onError' => function (\Throwable $error, ?object $about): void {
            $this->errors[] = [$error, $about];
        }]);
    }

    private static function request(string $kind, string $key = 'the-key'): SignedRequest
    {
        return AppRequests::make($kind, $key, ['secret' => self::SECRET]);
    }

    private static function deliver(AppRequestHandler $handler, SignedRequest $request): \Siren\Sdk\AppRequestReply
    {
        $reply = $handler->answer($request->method, $request->rawBody, $request->headers);

        if ($reply->after !== null) {
            ($reply->after)();
        }

        return $reply;
    }

    /** @return array<string, mixed> */
    private static function kindsFixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/Fixtures/apps-kinds/app-kinds.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** What the default `onError` writes for the request, read from a temporary error log. */
    private function logged(AppRequestHandler $handler, SignedRequest $request): string
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'siren-log');
        $previous = ini_set('error_log', $log);

        try {
            self::deliver($handler, $request);
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $logged = (string) file_get_contents($log);
        unlink($log);

        return $logged;
    }

    // Finding 1: an unknown kind is refused when its function is registered.

    /** @return array<string, array{string, string}> */
    public static function unknownKinds(): array
    {
        return [
            'a near miss' => ['distributionResolvr', 'distributionResolvr is not a feature kind. Did you mean distributionResolver?'],
            'another case' => ['DistributionResolver', 'DistributionResolver is not a feature kind. Did you mean distributionResolver?'],
            'a screen name in camelCase' => ['attributionModel', "Attribution model's kind is incentiveResolver."],
            'a screen name as the screen writes it' => ['Attribution model', "Attribution model's kind is incentiveResolver."],
            'a screen name ending in s' => ['customerFilterForPrograms', "Customer filter for programs' kind is engagementFilter."],
            'snake_case' => ['distribution_resolver', 'Kinds are camelCase, as Siren sends them: distributionResolver.'],
            'a constant name' => ['PAYOUT_METHOD', 'Kinds are camelCase, as Siren sends them: payoutMethod.'],
            'a custom event' => ['customEvent', 'Siren never sends a custom event. Your app sends one with events->ingest().'],
            'a webhook event' => ['conversion.approved', 'conversion.approved is a webhook event. Register it with onEvent().'],
            'the old payout name' => ['fulfillmentMethod', 'fulfillmentMethod is the old name. Use payoutMethod.'],
            'anything else' => ['banana', 'banana is not a feature kind. The kinds are: ' . implode(', ', AppItemKind::SENT) . '.'],
        ];
    }

    #[DataProvider('unknownKinds')]
    public function testOnRefusesAnUnknownKindForOneKeyAndSaysWhatToWrite(string $kind, string $words): void
    {
        try {
            $this->handler()->on($kind, 'the-key', static fn () => []);
            $this->fail('on() took ' . $kind);
        } catch (\InvalidArgumentException $e) {
            $this->assertSame($words, $e->getMessage());
        }
    }

    #[DataProvider('unknownKinds')]
    public function testOnRefusesAnUnknownKindForEveryKeyAndSaysWhatToWrite(string $kind, string $words): void
    {
        try {
            $this->handler()->on($kind, static fn () => []);
            $this->fail('on() took ' . $kind);
        } catch (\InvalidArgumentException $e) {
            $this->assertSame($words, $e->getMessage());
        }
    }

    public function testEveryKindSirenSendsIsTaken(): void
    {
        $handler = $this->handler();

        foreach (AppItemKind::SENT as $kind) {
            $handler->on($kind, 'the-key', static fn () => $handler->later());
            $handler->on($kind, static fn () => $handler->later());
        }

        $this->assertCount(13, AppItemKind::SENT);
    }

    public function testExtraKindsLetsOnTakeAKindANewerSirenAdds(): void
    {
        $ran = false;
        $handler = $this->handler([], ['extraKinds' => ['payoutReview']])
            ->on('payoutReview', 'check-it', function () use (&$ran): void {
                $ran = true;
            });

        $request = AppRequests::make('postConversionAction', 'check-it', ['secret' => self::SECRET, 'body' => [
            'version' => 1,
            'id' => 'apr_' . str_repeat('ab', 16),
            'type' => 'app.request',
            'mode' => 'tell',
            'app' => 'acme-crm',
            'item' => ['kind' => 'payoutReview', 'key' => 'check-it', 'id' => 'app:9'],
            'createdAt' => '2026-11-17T12:20:03+00:00',
            'data' => [],
        ]]);

        $this->assertSame(200, self::deliver($handler, $request)->status);
        $this->assertTrue($ran);
    }

    /** @return array<string, array{mixed}> */
    public static function badExtraKinds(): array
    {
        return [
            'text' => ['payoutReview'],
            'a list with an empty kind' => [['']],
            'a list with a number' => [[7]],
            'a map' => [['a' => 'payoutReview']],
        ];
    }

    #[DataProvider('badExtraKinds')]
    public function testExtraKindsMustBeAListOfKinds(mixed $extraKinds): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('apps->requestHandler() takes extraKinds as a list of kinds, such as [\'payoutReview\'].');

        $this->mockSiren([])->apps->requestHandler(self::SECRET, ['extraKinds' => $extraKinds]);
    }

    public function testTheKindsAreSirensAppKinds(): void
    {
        $fixture = self::kindsFixture();
        $kinds = array_column($fixture['appKinds'], 'kind');

        $this->assertSame($kinds, AppItemKind::SENT, 'The kinds on() takes are Siren\'s APP_KINDS, in its order.');
        $this->assertNotContains(AppItemKind::CUSTOM_EVENT, AppItemKind::SENT);
        $this->assertSame($fixture['customEvent']['kind'], AppItemKind::CUSTOM_EVENT);
        $this->assertSame(
            array_column($fixture['appKinds'], 'name', 'kind') + [$fixture['customEvent']['kind'] => $fixture['customEvent']['name']],
            AppItemKind::NAMES,
        );

        $modes = array_column($fixture['appKinds'], 'mode', 'kind');
        $this->assertSame(array_keys(array_filter($modes, static fn (string $mode): bool => $mode === 'tell')), AppItemKind::TELLS);
        $this->assertSame(array_keys(array_filter($modes, static fn (string $mode): bool => $mode === 'ask')), AppItemKind::ASKS);
    }

    // Finding 3: "answer later" is something the function says.

    public function testAnAskWhoseFunctionReturnsLaterPostsNothingAndHearsNothing(): void
    {
        $handler = $this->handler();
        $handler->on('distributionResolver', 'split-by-score', static fn () => $handler->later());

        $reply = self::deliver($handler, self::request('distributionResolver', 'split-by-score'));

        $this->assertSame(200, $reply->status);
        $this->assertSame([], $this->history);
        $this->assertSame([], $this->errors);

        $result = $handler->simulate(self::request('distributionResolver', 'split-by-score'));
        $this->assertNull($result->answer);
        $this->assertNull($result->error);
    }

    public function testAnAskWhoseFunctionReturnsNothingIsAnErrorAndPostsNothing(): void
    {
        $words = 'The function for distributionResolver split-by-score returned nothing. '
            . 'Return the answer, or return $answers->later() when you will answer with apps->requests->answer().';
        $handler = $this->handler()->on('distributionResolver', 'split-by-score', static function (): void {
        });

        $reply = self::deliver($handler, self::request('distributionResolver', 'split-by-score'));

        $this->assertSame(200, $reply->status);
        $this->assertSame([], $this->history, 'Nothing is posted.');
        $this->assertCount(1, $this->errors);
        $this->assertSame($words, $this->errors[0][0]->getMessage());
        $this->assertInstanceOf(AppRequest::class, $this->errors[0][1]);

        $result = $handler->simulate(self::request('distributionResolver', 'split-by-score'));
        $this->assertSame(200, $result->status);
        $this->assertNull($result->answer);
        $this->assertInstanceOf(\Throwable::class, $result->error);
        $this->assertSame($words, $result->error->getMessage());
    }

    public function testATellsReturnValueIsIgnored(): void
    {
        $handler = $this->handler();
        $handler->on('postConversionAction', static fn () => null)
            ->on('postAllocationAction', static fn () => $handler->later());

        $this->assertSame(200, self::deliver($handler, self::request('postConversionAction'))->status);
        $this->assertSame(200, self::deliver($handler, self::request('postAllocationAction'))->status);
        $this->assertSame([], $this->errors);
    }

    // Finding 4: the default onError line says when Siren sends a failed ask again.

    private static function nextSend(SignedRequest $request): string
    {
        $body = json_decode($request->rawBody, true);

        return sprintf(
            'Siren sends it again after %s UTC, when its answer window closes. Resend it sooner from Needs attention in Siren Cloud, or with `siren apps resend %s`.',
            gmdate('H:i', (int) strtotime($body['answerBy'])),
            $body['id'],
        );
    }

    public function testTheDefaultLineForAnAskThatThrowsSaysWhenSirenSendsItAgain(): void
    {
        $handler = $this->mockSiren([])->apps->requestHandler(self::SECRET)
            ->on('distributionResolver', static function (): never {
                throw new \RuntimeException('The ledger is down.');
            });
        $request = self::request('distributionResolver', 'split-by-score');

        $logged = $this->logged($handler, $request);

        $this->assertStringContainsString('The ledger is down. ' . self::nextSend($request), $logged);
    }

    public function testTheDefaultLineForAnAskThatReturnsNothingSaysWhenSirenSendsItAgain(): void
    {
        $handler = $this->mockSiren([])->apps->requestHandler(self::SECRET)
            ->on('distributionResolver', static function (): void {
            });
        $request = self::request('distributionResolver', 'split-by-score');

        $logged = $this->logged($handler, $request);

        $this->assertStringContainsString('returned nothing.', $logged);
        $this->assertStringContainsString(self::nextSend($request), $logged);
    }

    public function testTheDefaultLineForAnAnswerTheCheckRefusesSaysWhenSirenSendsItAgain(): void
    {
        $handler = $this->mockSiren([])->apps->requestHandler(self::SECRET)
            ->on('distributionResolver', static fn () => ['units' => 'USD']);
        $request = self::request('distributionResolver', 'split-by-score');

        $this->assertStringContainsString(self::nextSend($request), $this->logged($handler, $request));
    }

    public function testTheDefaultLineForAPostSirenRefusesSaysWhenSirenSendsItAgain(): void
    {
        $handler = $this->mockSiren([self::errorResponse(422, 'outcome must be allocated', 'app_answer_invalid')])->apps->requestHandler(self::SECRET)
            ->on('distributionResolver', static fn () => ['outcome' => 'allocated', 'units' => 'USD', 'shares' => ['41' => 1, '43' => 1]]);
        $request = self::request('distributionResolver', 'split-by-score');

        $this->assertStringContainsString(self::nextSend($request), $this->logged($handler, $request));
    }

    public function testTheDefaultLineForARequestSirenStoppedWaitingForSaysNothingAboutASend(): void
    {
        $handler = $this->mockSiren([self::errorResponse(409, 'Siren stopped waiting.', 'app_request_dropped')])->apps->requestHandler(self::SECRET)
            ->on('distributionResolver', static fn () => ['outcome' => 'allocated', 'units' => 'USD', 'shares' => ['41' => 1, '43' => 1]]);
        $request = self::request('distributionResolver', 'split-by-score');

        $logged = $this->logged($handler, $request);

        $this->assertStringContainsString('Siren stopped waiting.', $logged);
        $this->assertStringNotContainsString('sends it again', $logged);
    }

    public function testTheDefaultLineForATellThatThrowsSaysAboutAMinute(): void
    {
        $handler = $this->mockSiren([])->apps->requestHandler(self::SECRET)
            ->on('postConversionAction', static function (): never {
                throw new \RuntimeException('The CRM is down.');
            });

        $logged = $this->logged($handler, self::request('postConversionAction'));

        $this->assertStringContainsString('The CRM is down. Siren sends it again in about a minute.', $logged);
        $this->assertStringNotContainsString('answer window', $logged);
    }

    public function testTheReadmeTableSaysWhatHappensWhenAFunctionFails(): void
    {
        $readme = (string) file_get_contents(__DIR__ . '/../README.md');

        $this->assertStringContainsString(
            '| When your function fails | A tell gets 500, and Siren tries again about 1, 2, 4 and 8 minutes later. '
            . 'An ask already got its 200, so Siren sends it again when its answer window closes, 60 minutes by default. '
            . 'Resend it sooner from Needs attention in Siren Cloud, or with `siren apps resend <id>`. `onError` hears why either way. |',
            $readme,
        );
    }

    // Finding 5, the part that changes no wire value.

    public function testOneClassNamesTheKinds(): void
    {
        $this->assertFalse(class_exists('Siren\\Sdk\\AppFeatureKind'), 'AppItemKind holds what AppFeatureKind held.');
        $this->assertSame(['postConversionAction', 'postAllocationAction'], AppItemKind::TELLS);
        $this->assertStringContainsString('@phpstan-type AppDistributionAnswer', (string) (new \ReflectionClass(AppItemKind::class))->getDocComment());
    }

    public function testTheRequestNamesTheIdToDedupeOn(): void
    {
        $doc = (string) (new \ReflectionClass(AppRequest::class))->getConstructor()?->getDocComment();

        $this->assertStringContainsString('the same on every send of this request, so write once by `id`', $doc);
        $this->assertStringContainsString('`item.id` names the feature', $doc);
        $this->assertStringContainsString('changes on every send', $doc);
    }

    // Carried item 2: simulate() checks an answer as Siren does.

    /** @return array<string, array{string, array<string, mixed>, string}> */
    public static function answersSirenRefuses(): array
    {
        $shares = static fn (array $shares): array => ['outcome' => 'allocated', 'units' => 'USD', 'shares' => $shares];
        $rewards = static fn (array $rewards): array => ['outcome' => 'rewards', 'units' => 'USD', 'rewards' => $rewards];

        return [
            'distribution: another outcome' => ['distributionResolver', ['outcome' => 'allocate'], 'outcome must be allocated'],
            'distribution: other units' => ['distributionResolver', ['outcome' => 'allocated', 'units' => 'EUR', 'shares' => []], 'units must be USD, the units of the pool'],
            'distribution: shares as a list' => ['distributionResolver', $shares([6000, 4000]), 'shares names metric 0, which this distribution does not hold'],
            'distribution: a metric not asked' => ['distributionResolver', $shares(['41' => 1, '43' => 1, '99' => 1]), 'shares names metric 99, which this distribution does not hold'],
            'distribution: a fraction' => ['distributionResolver', $shares(['41' => 60.5, '43' => 1]), 'the share for metric 41 must be a whole number of the smallest unit'],
            'distribution: below 0' => ['distributionResolver', $shares(['41' => -1, '43' => 1]), 'the share for metric 41 must not be below 0'],
            'distribution: a metric with no share' => ['distributionResolver', $shares(['41' => 6000]), 'shares has no amount for metric 43, and every metric needs one, 0 included'],
            'distribution: over the pool' => ['distributionResolver', $shares(['41' => 6000, '43' => 5000]), 'the shares add up to 11000, more than the pool of 10000'],
            'reward: a conversion with no key' => ['incentiveResolver', $rewards(['1042' => 900]), 'The answer has no reward for conversion 1043. Give every conversion of the question a key, null for one the app did not choose.'],
            'reward: over the pool' => ['incentiveResolver', $rewards(['1042' => 2000, '1043' => null]), 'The reward for conversion 1042 is 2000, more than the pool of 1500 Siren offered.'],
            'reward: a conversion not asked' => ['incentiveResolver', $rewards(['7' => 1]), 'The answer names conversion 7, which the question does not hold.'],
            'incentive type: an engagement not asked' => ['incentiveType', ['outcome' => 'award', 'units' => 'USD', 'pool' => 1500, 'engagementIds' => [999]], 'The answer names engagement 999, which the question does not hold.'],
            'incentive type: none with more' => ['incentiveType', ['outcome' => 'none', 'pool' => 0], 'An answer of "none" carries nothing else, and this one has pool.'],
            'payout: paid with no reference' => ['payoutMethod', ['outcome' => 'paid'], 'A paid answer names the payment\'s reference in your system, as "reference".'],
            'payout: another outcome' => ['payoutMethod', ['outcome' => 'done'], 'The outcome is "paid" or "failed".'],
            'metric calculation: a negative score' => ['metricCalculation', ['outcome' => 'scores', 'results' => [['collaboratorId' => 42, 'score' => -1]]], 'results[0].score must be a whole number of 0 or more.'],
            'metric filter: markup in the reason' => ['metricFilter', ['outcome' => 'suppress', 'reason' => '<b>no</b>'], 'reason must be plain text, with no markup or control characters.'],
            'engagement calculation: another outcome' => ['engagementCalculation', ['outcome' => 'scores', 'results' => []], 'outcome must be engagements.'],
            'engagement filter: no reason' => ['engagementFilter', ['outcome' => 'suppress'], 'A suppress answer gives a reason, in plain text.'],
            'group order: a program left out' => ['programGroupSorter', ['outcome' => 'sorted', 'programIds' => [12]], 'The answer leaves out program 9. List every program Siren asked about.'],
            'transaction filter: a line not asked' => ['transactionFilter', ['outcome' => 'filtered', 'include' => [9999]], 'The answer includes line 9999, which is not a line of the sale Siren asked about.'],
            'group structure: a member left out' => ['collaboratorGroupStructure', ['outcome' => 'structure', 'shape' => 'chain', 'order' => [63, 57]], 'order leaves out member 42. List every member of the question once.'],
            'group structure: a cycle' => ['collaboratorGroupStructure', ['outcome' => 'structure', 'shape' => 'tree', 'parents' => ['63' => 57, '57' => 63, '42' => 63]], 'parents form a cycle through member 63. Every member must reach the top.'],
        ];
    }

    /** @param array<string, mixed> $answer */
    #[DataProvider('answersSirenRefuses')]
    public function testSimulateRefusesAnAnswerAsSirenDoesWithSirensWords(string $kind, array $answer, string $words): void
    {
        $handler = $this->handler()->on($kind, static fn () => $answer);

        $result = $handler->simulate(self::request($kind));

        $this->assertSame(200, $result->status);
        $this->assertNull($result->answer);
        $this->assertInstanceOf(ValidationException::class, $result->error);
        $this->assertSame($words, $result->error->getMessage());
        $this->assertSame('app_answer_invalid', $result->error->getErrorCode());
        $this->assertSame(422, $result->error->getStatusCode());
        $this->assertSame([], $this->history, 'simulate() posts nothing.');
    }

    public function testTheLiveHandlerStillPostsAndSirensRefusalReachesOnError(): void
    {
        $handler = $this->handler([self::errorResponse(422, 'outcome must be allocated', 'app_answer_invalid')])
            ->on('distributionResolver', static fn () => ['outcome' => 'allocate']);

        self::deliver($handler, self::request('distributionResolver'));

        $this->assertCount(1, $this->history, 'The live handler leaves the per-kind check to Siren.');
        $this->assertCount(1, $this->errors);
        $this->assertInstanceOf(ValidationException::class, $this->errors[0][0]);
    }

    // Lower findings: the README.

    public function testTheReadmeOpensWithHowSirenTalksToYourApp(): void
    {
        $readme = (string) file_get_contents(__DIR__ . '/../README.md');
        $start = strpos($readme, '## How Siren talks to your app');
        $this->assertNotFalse($start);
        $end = strpos($readme, "\n## ", $start + 3);
        $this->assertNotFalse($end);

        $this->assertSame(self::HOW_SIREN_TALKS, trim(substr($readme, $start, $end - $start)));
        $this->assertLessThan(strpos($readme, '## What you can do'), $start, 'The block comes before What you can do.');
    }

    public function testTheReadmeSaysAnAsksFunctionCanRunMoreThanOnce(): void
    {
        $readme = (string) file_get_contents(__DIR__ . '/../README.md');

        $this->assertStringContainsString(
            "An ask's function can also run more than once for one request: on a repeat\ndelivery, and again on each send after its answer window closes.",
            $readme,
        );
        $this->assertStringContainsString('refuses a different one with 409', $readme);
    }
}
