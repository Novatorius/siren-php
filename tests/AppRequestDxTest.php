<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Siren\Sdk\AppRequestHandler;
use Siren\Sdk\Exception\SirenException;
use Siren\Sdk\Siren;
use Siren\Sdk\Testing\AppRequests;
use Siren\Sdk\Testing\SignedRequest;

/**
 * DX pass 1, first contact (review/sdk-dx/pass-1-review.md in the run
 * folder): where an answer goes, a way to try a handler without Siren, a 400
 * that names the field, and the environment defaults.
 */
final class AppRequestDxTest extends TestCase
{
    private const SECRET = 'whsec_dx_pass_one_test_secret';

    /** @var list<array{0: \Throwable, 1: object|null}> */
    private array $errors = [];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (['SIREN_API_KEY', 'SIREN_SIGNING_SECRET'] as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    /**
     * @param list<Response|\Throwable> $responses
     * @param array<string, mixed> $config
     */
    private function handler(array $responses = [], array $config = []): AppRequestHandler
    {
        $this->errors = [];

        return $this->mockSiren($responses, $config)->apps->requestHandler(self::SECRET, ['onError' => function (\Throwable $error, ?object $about): void {
            $this->errors[] = [$error, $about];
        }]);
    }

    private static function split(): \Closure
    {
        return static fn ($request) => [
            'outcome' => 'allocated',
            'units' => $request->data['pool']['units'],
            'shares' => ['41' => 6000, '43' => 4000],
        ];
    }

    private static function accepted(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], '{"status":"accepted"}');
    }

    /** @param array<string, mixed> $options */
    private static function ask(array $options = []): SignedRequest
    {
        return AppRequests::make('distributionResolver', 'split-by-score', $options + ['secret' => self::SECRET]);
    }

    private static function runRequest(AppRequestHandler $handler, SignedRequest $request): \Siren\Sdk\AppRequestReply
    {
        $reply = $handler->answer($request->method, $request->rawBody, $request->headers);

        if ($reply->after !== null) {
            ($reply->after)();
        }

        return $reply;
    }

    // Finding 1: the answer address.

    public function testAnAskFromAnotherSirenGets500AndNamesBothAddressesAndPostsNothing(): void
    {
        $ran = false;
        $handler = $this->handler()->on('distributionResolver', 'split-by-score', function () use (&$ran): array {
            $ran = true;

            return ['outcome' => 'allocated'];
        });

        $reply = self::runRequest($handler, self::ask(['baseUrl' => 'https://preview.example.com/siren/v1']));

        $this->assertSame(500, $reply->status, $reply->body);
        $this->assertNull($reply->after);
        $this->assertFalse($ran, 'The function does not run for a request this client cannot answer.');
        $this->assertSame([], $this->history, 'Nothing is posted anywhere.');
        $this->assertCount(1, $this->errors);
        $message = $this->errors[0][0]->getMessage();
        $this->assertInstanceOf(SirenException::class, $this->errors[0][0]);
        $this->assertStringContainsString('https://preview.example.com/siren/v1/apps/requests/', $message);
        $this->assertStringContainsString('https://api.sirenaffiliates.com/siren/v1', $message);
        $this->assertStringContainsString('Set baseUrl to https://preview.example.com/siren/v1', $message);
        $this->assertStringContainsString('baseUrl', json_decode($reply->body, true)['error']);
    }

    public function testTheSameOriginPassesAndTheAnswerGoesToTheClientsBase(): void
    {
        $handler = $this->handler([self::accepted()], ['baseUrl' => 'https://preview.example.com/siren/v1'])
            ->on('distributionResolver', 'split-by-score', self::split());

        $request = self::ask(['baseUrl' => 'https://preview.example.com/siren/v1']);
        $reply = self::runRequest($handler, $request);

        $this->assertSame(200, $reply->status, $reply->body);
        $this->assertSame([], $this->errors);
        $this->assertSame('https://preview.example.com/siren/v1/apps/requests/' . $request->id() . '/answer', (string) $this->lastRequest()->getUri());
    }

    public function testAnAnswerUrlOnTheAppHostPassesForAClientOnTheApiHostAndTheAnswerGoesToTheApiHost(): void
    {
        $handler = $this->handler([self::accepted()])->on('distributionResolver', 'split-by-score', self::split());

        $request = self::ask(['baseUrl' => 'https://app.sirenaffiliates.com/siren/v1']);
        $this->assertStringStartsWith('https://app.sirenaffiliates.com/siren/v1/apps/requests/', json_decode($request->rawBody, true)['answerUrl']);

        $reply = self::runRequest($handler, $request);

        $this->assertSame(200, $reply->status, $reply->body);
        $this->assertSame([], $this->errors);
        $this->assertSame('https://api.sirenaffiliates.com/siren/v1/apps/requests/' . $request->id() . '/answer', (string) $this->lastRequest()->getUri());
    }

    public function testAnAnswerUrlOnTheApiHostPassesForAClientOnTheAppHost(): void
    {
        $handler = $this->handler([self::accepted()], ['baseUrl' => 'https://app.sirenaffiliates.com/siren/v1'])
            ->on('distributionResolver', 'split-by-score', self::split());

        $request = self::ask();
        $reply = self::runRequest($handler, $request);

        $this->assertSame(200, $reply->status, $reply->body);
        $this->assertSame('https://app.sirenaffiliates.com/siren/v1/apps/requests/' . $request->id() . '/answer', (string) $this->lastRequest()->getUri());
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function otherOrigins(): array
    {
        return [
            'another host' => ['https://evil.example.com/siren/v1', 'https://api.sirenaffiliates.com/siren/v1'],
            'http on a Cloud host' => ['http://app.sirenaffiliates.com/siren/v1', 'https://api.sirenaffiliates.com/siren/v1'],
            'another port' => ['https://preview.example.com:8443/siren/v1', 'https://preview.example.com/siren/v1'],
            'a Cloud look-alike' => ['https://app.sirenaffiliates.com.evil.example/siren/v1', 'https://api.sirenaffiliates.com/siren/v1'],
        ];
    }

    #[DataProvider('otherOrigins')]
    public function testEveryOtherOriginDifferenceFails(string $sentFrom, string $clientBase): void
    {
        $handler = $this->handler([], ['baseUrl' => $clientBase])->on('distributionResolver', 'split-by-score', self::split());

        $reply = self::runRequest($handler, self::ask(['baseUrl' => $sentFrom]));

        $this->assertSame(500, $reply->status, $reply->body);
        $this->assertSame([], $this->history);
        $this->assertCount(1, $this->errors);
    }

    public function testATellHasNoAnswerUrlAndGetsNoCheck(): void
    {
        $ran = false;
        $handler = $this->handler()->on('postConversionAction', 'tell-the-crm', function () use (&$ran): void {
            $ran = true;
        });

        $reply = self::runRequest($handler, AppRequests::make('postConversionAction', 'tell-the-crm', ['secret' => self::SECRET, 'baseUrl' => 'https://preview.example.com/siren/v1']));

        $this->assertSame(200, $reply->status, $reply->body);
        $this->assertTrue($ran);
    }

    public function testThePsr15MountRefusesAnAskFromAnotherSirenToo(): void
    {
        $handler = $this->handler()->on('distributionResolver', 'split-by-score', self::split());
        $request = self::ask(['baseUrl' => 'https://preview.example.com/siren/v1']);
        $factory = new HttpFactory();

        $response = $handler->psr15($factory, $factory)->handle(new ServerRequest('POST', '/siren', $request->headers, $request->rawBody));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertCount(1, $this->errors);
        $this->assertStringContainsString('Set baseUrl to https://preview.example.com/siren/v1', $this->errors[0][0]->getMessage());
    }

    // Finding 2: try a handler without Siren.

    public function testMakeBuildsARequestVerifyRequestAccepts(): void
    {
        $request = AppRequests::make('distributionResolver', 'split-by-score', ['secret' => self::SECRET]);

        $this->assertSame('POST', $request->method);
        $this->assertSame('app.request', $request->headers['X-Siren-Event']);

        $checked = $this->mockSiren([])->apps->verifyRequest($request->rawBody, $request->headers, self::SECRET);

        $this->assertSame('ask', $checked->mode);
        $this->assertSame(['kind' => 'distributionResolver', 'key' => 'split-by-score'], array_intersect_key($checked->item, ['kind' => 1, 'key' => 1]));
        $this->assertMatchesRegularExpression('/^apr_[0-9a-f]{32}$/', $checked->id);
        $this->assertSame(AppRequests::sampleData('distributionResolver'), $checked->data);
        $this->assertSame('https://api.sirenaffiliates.com/siren/v1/apps/requests/' . $checked->id . '/answer', $checked->answerUrl);
        $this->assertNotNull($checked->answerBy);
    }

    public function testMakeTakesItsOptions(): void
    {
        $request = AppRequests::make('postAllocationAction', 'note-the-share', [
            'secret' => self::SECRET,
            'data' => ['allocation' => ['value' => 1]],
            'id' => 'apr_' . str_repeat('ab', 16),
            'app' => 'my-app',
            'timestamp' => 1794913200,
        ]);

        $body = json_decode($request->rawBody, true);
        $this->assertSame('tell', $body['mode']);
        $this->assertArrayNotHasKey('answerUrl', $body);
        $this->assertArrayNotHasKey('answerBy', $body);
        $this->assertSame(['allocation' => ['value' => 1]], $body['data']);
        $this->assertSame('apr_' . str_repeat('ab', 16), $body['id']);
        $this->assertSame('my-app', $body['app']);
        $this->assertSame('1794913200', $request->headers['X-Siren-Timestamp']);
        $this->assertSame(
            't=1794913200,v1=' . hash_hmac('sha256', '1794913200.' . $request->rawBody, self::SECRET),
            $request->headers['X-Siren-Signature-V2'],
        );
    }

    public function testMakeReadsTheSecretFromTheEnvironmentAndRefusesWithoutOne(): void
    {
        putenv('SIREN_SIGNING_SECRET=' . self::SECRET);
        $request = AppRequests::make('distributionResolver', 'split-by-score');
        $this->mockSiren([])->apps->verifyRequest($request->rawBody, $request->headers, self::SECRET);

        putenv('SIREN_SIGNING_SECRET');

        try {
            AppRequests::make('distributionResolver', 'split-by-score');
            $this->fail('A request with no secret was made.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('secret', $e->getMessage());
            $this->assertStringContainsString('SIREN_SIGNING_SECRET', $e->getMessage());
        }
    }

    public function testMakeRefusesAKindItHasNoSampleForUnlessGivenData(): void
    {
        try {
            AppRequests::make('noSuchKind', 'x', ['secret' => self::SECRET]);
            $this->fail('A request for an unknown kind was made with no data.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('noSuchKind', $e->getMessage());
        }

        $this->assertSame(['a' => 1], json_decode(AppRequests::make('noSuchKind', 'x', ['secret' => self::SECRET, 'data' => ['a' => 1]])->rawBody, true)['data']);
    }

    public function testTheShippedSampleDataEqualsSirensFixtureForEveryKind(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/apps-feature-contracts/requests.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertCount(13, $fixture['kinds']);

        foreach ($fixture['kinds'] as $kind => $contract) {
            $this->assertSame($contract['request']['data'], AppRequests::sampleData($kind), $kind);
            $this->assertSame($contract['request']['mode'], json_decode(AppRequests::make($kind, 'k', ['secret' => self::SECRET])->rawBody, true)['mode'], $kind);
        }
    }

    public function testSimulateRunsAnAskAndGivesTheAnswerWithoutPostingIt(): void
    {
        $handler = $this->handler()->on('distributionResolver', 'split-by-score', fn ($request) => [
            'outcome' => 'allocated',
            'units' => $request->data['pool']['units'],
            'shares' => $this->mockSiren([])->apps->splitPool($request->data['pool'], array_column($request->data['metrics'], 'score', 'metricId')),
        ]);

        $result = $handler->simulate(self::ask());

        $this->assertSame(200, $result->status);
        $this->assertSame(['outcome' => 'allocated', 'units' => 'USD', 'shares' => [41 => 6000, 43 => 4000]], $result->answer);
        $this->assertNull($result->error);
        $this->assertSame([], $this->history, 'simulate posts nothing.');
    }

    public function testSimulateGivesATellsReplyAndAFunctionsFailure(): void
    {
        $handler = $this->handler()
            ->on('postConversionAction', 'tell-the-crm', static function (): void {
            })
            ->on('distributionResolver', 'split-by-score', static function (): array {
                throw new \RuntimeException('The ledger is down.');
            });

        $tell = $handler->simulate(AppRequests::make('postConversionAction', 'tell-the-crm', ['secret' => self::SECRET]));
        $this->assertSame([200, null, null], [$tell->status, $tell->answer, $tell->error]);

        $ask = $handler->simulate(self::ask());
        $this->assertSame(200, $ask->status);
        $this->assertNull($ask->answer);
        $this->assertInstanceOf(\RuntimeException::class, $ask->error);
        $this->assertSame([], $this->errors, 'simulate hands the failure back instead of to onError.');
    }

    public function testSimulateChecksTheAnswerAsThePostWould(): void
    {
        $handler = $this->handler()->on('distributionResolver', 'split-by-score', static fn (): array => ['shares' => []]);

        $result = $handler->simulate(self::ask());

        $this->assertNull($result->answer);
        $this->assertInstanceOf(\InvalidArgumentException::class, $result->error);
        $this->assertStringContainsString('outcome', $result->error->getMessage());
    }

    public function testSimulateGivesTheRefusalOfABadSignatureOrAnotherSiren(): void
    {
        $handler = $this->handler()->on('distributionResolver', 'split-by-score', self::split());

        $this->assertSame(401, $handler->simulate(AppRequests::make('distributionResolver', 'split-by-score', ['secret' => 'another-secret']))->status);

        $other = $handler->simulate(self::ask(['baseUrl' => 'https://preview.example.com/siren/v1']));
        $this->assertSame(500, $other->status);
        $this->assertStringContainsString('baseUrl', (string) $other->error?->getMessage());
    }

    // Finding 3: a 400 that names the field.

    /** @return array<string, array{0: \Closure(array<string, mixed>): array<string, mixed>, 1: string}> */
    public static function badEnvelopes(): array
    {
        return [
            'version' => [static fn (array $b): array => ['version' => 2] + $b, 'its `version` must be 1'],
            'id' => [static fn (array $b): array => ['id' => 'apr_x1'] + $b, 'its `id` must be `apr_` followed by 32 hex characters'],
            'type' => [static fn (array $b): array => ['type' => 'app.ask'] + $b, 'its `type` must be `app.request`'],
            'mode' => [static fn (array $b): array => ['mode' => 'maybe'] + $b, 'its `mode` must be `tell` or `ask`'],
            'app' => [static fn (array $b): array => ['app' => ''] + $b, "its `app` must be the app's key"],
            'item' => [static fn (array $b): array => ['item' => 'x'] + $b, 'its `item` must be an object'],
            'item.kind' => [static fn (array $b): array => ['item' => ['key' => 'k', 'id' => 'app:1']] + $b, 'its `item.kind` must be text'],
            'item.key' => [static fn (array $b): array => ['item' => ['kind' => 'k', 'id' => 'app:1']] + $b, 'its `item.key` must be text'],
            'item.id' => [static fn (array $b): array => ['item' => ['kind' => 'k', 'key' => 'k']] + $b, 'its `item.id` must be text'],
            'createdAt' => [static fn (array $b): array => ['createdAt' => 5] + $b, 'its `createdAt` must be a date as text'],
            'data' => [static fn (array $b): array => ['data' => [1, 2]] + $b, 'its `data` must be an object'],
            'answerBy' => [static fn (array $b): array => ['answerBy' => null] + $b, 'an ask\'s `answerBy` must be a date as text'],
            'answerUrl' => [static fn (array $b): array => ['answerUrl' => null] + $b, 'an ask\'s `answerUrl` must be the address'],
        ];
    }

    /** @param \Closure(array<string, mixed>): array<string, mixed> $break */
    #[DataProvider('badEnvelopes')]
    public function testABadEnvelopeGets400NamingTheFirstFieldThatFails(\Closure $break, string $words): void
    {
        $good = json_decode(self::ask()->rawBody, true);
        $request = AppRequests::make('distributionResolver', 'split-by-score', ['secret' => self::SECRET, 'body' => $break($good)]);
        $handler = $this->handler()->on('distributionResolver', 'split-by-score', self::split());

        $reply = $handler->answer($request->method, $request->rawBody, $request->headers);

        $this->assertSame(400, $reply->status, $reply->body);
        $this->assertStringContainsString('not an app request of version 1', json_decode($reply->body, true)['error']);
        $this->assertStringContainsString($words, json_decode($reply->body, true)['error']);

        try {
            $this->mockSiren([])->apps->verifyRequest($request->rawBody, $request->headers, self::SECRET);
            $this->fail('verifyRequest took a bad envelope.');
        } catch (\Siren\Sdk\Exception\SignatureVerificationException $e) {
            $this->assertStringContainsString($words, $e->getMessage());
        }
    }

    // Lower finding: the environment defaults.

    public function testTheClientReadsSirenApiKeyWhenNoKeyIsPassed(): void
    {
        putenv('SIREN_API_KEY=sk_test_from_the_environment');
        $siren = $this->mockSiren([self::accepted()], ['apiKey' => null]);
        $siren->apps->requests->answer('apr_' . str_repeat('cd', 16), ['outcome' => 'paid', 'reference' => 'TX-1']);

        $this->assertSame('Bearer sk_test_from_the_environment', $this->lastRequest()->getHeaderLine('Authorization'));

        $this->mockSiren([self::accepted()], ['apiKey' => 'sk_test_passed']);
        $this->assertNotNull($this->mockSiren([], ['apiKey' => 'sk_test_passed']));
    }

    public function testAPassedKeyWinsOverTheEnvironment(): void
    {
        putenv('SIREN_API_KEY=sk_test_from_the_environment');
        $siren = $this->mockSiren([self::accepted()], ['apiKey' => 'sk_test_passed']);
        $siren->apps->requests->answer('apr_' . str_repeat('cd', 16), ['outcome' => 'paid', 'reference' => 'TX-1']);

        $this->assertSame('Bearer sk_test_passed', $this->lastRequest()->getHeaderLine('Authorization'));
    }

    public function testNoKeyAnywhereStillRefusesNamingTheOptionAndTheVariable(): void
    {
        try {
            new Siren();
            $this->fail('A client with no key was made.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('apiKey', $e->getMessage());
            $this->assertStringContainsString('SIREN_API_KEY', $e->getMessage());
        }
    }

    public function testTheHandlerReadsSirenSigningSecretWhenNoneIsPassed(): void
    {
        putenv('SIREN_SIGNING_SECRET=' . self::SECRET);
        $handler = $this->mockSiren([])->apps->requestHandler()->on('distributionResolver', 'split-by-score', self::split());

        $this->assertSame(200, $handler->simulate(self::ask())->status);

        $passed = $this->mockSiren([])->apps->requestHandler('another-secret')->on('distributionResolver', 'split-by-score', self::split());
        $this->assertSame(401, $passed->simulate(self::ask())->status, 'A passed secret wins over the environment.');

        putenv('SIREN_SIGNING_SECRET');

        try {
            $this->mockSiren([])->apps->requestHandler();
            $this->fail('A handler with no secret was made.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('signing secret', $e->getMessage());
            $this->assertStringContainsString('SIREN_SIGNING_SECRET', $e->getMessage());
        }
    }
}
