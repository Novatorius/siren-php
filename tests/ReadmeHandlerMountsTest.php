<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use GuzzleHttp\Psr7\ServerRequest;
use Psr\Http\Server\RequestHandlerInterface;
use Siren\Sdk\AppRequestHandler;
use Siren\Sdk\Tests\Support\ReadmeStubs;

require_once __DIR__ . '/Support/readme-stubs.php';

/**
 * The README's framework mounts, its options and its onError sample, each
 * run here from the README's own text. Slim and Laravel are not dependencies
 * of this package, so a recording `$app` stands in for Slim's and
 * tests/Support/readme-stubs.php for Laravel's Route, Request, app(),
 * response() and report(). The handler, the signature check and the answer
 * post are the SDK's own, over the real transport with Guzzle's mock at the
 * HTTP seam. The requests are Siren's own, from
 * tests/Fixtures/apps-feature-contracts/requests.json, signed now with the
 * secret of tests/Fixtures/apps-exchange.
 */
final class ReadmeHandlerMountsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ReadmeStubs::reset();
    }

    /** A sample from the README, by its readme-test name. */
    private static function readme(string $name): string
    {
        $found = preg_match('/<!-- readme-test: ' . preg_quote($name, '/') . ' -->\n```php\n(.*?)\n```/s', (string) file_get_contents(dirname(__DIR__) . '/README.md'), $matches);
        self::assertSame(1, $found, 'The README has no sample named ' . $name);

        return $matches[1];
    }

    private static function secret(): string
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/apps-exchange/signed-request.json'), true, 512, JSON_THROW_ON_ERROR);

        return $fixture['secret'];
    }

    /** @return array{request: array<string, mixed>, answer: array<string, mixed>|null} */
    private static function contract(string $kind): array
    {
        $contracts = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/apps-feature-contracts/requests.json'), true, 512, JSON_THROW_ON_ERROR);

        return $contracts['kinds'][$kind];
    }

    /** @return array{0: string, 1: array<string, string>} The kind's request as JSON, and headers as WebhookSignature::headers() sets them. */
    private static function signed(string $kind): array
    {
        $body = json_encode(self::contract($kind)['request'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $t = time();

        return [$body, [
            'Content-Type' => 'application/json',
            'X-Siren-Event' => 'app.request',
            'X-Siren-Timestamp' => (string) $t,
            'X-Siren-Signature-V2' => 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, self::secret()),
        ]];
    }

    /** The distribution resolver's function, as the README's sample writes it, on a handler. */
    private static function splitByScore(AppRequestHandler $answers, \Siren\Sdk\Siren $siren): AppRequestHandler
    {
        return eval('return $answers' . "\n" . self::readme('answer-distribution') . ';');
    }

    public function testThePsr15MountGivesSlimAHandlerThatAnswersATell(): void
    {
        $siren = $this->mockSiren([]);
        $seen = [];
        $answers = $siren->apps->requestHandler(self::secret())
            ->on('postConversionAction', 'tell-the-crm', function ($request) use (&$seen): void {
                $seen[] = $request->id;
            });
        $app = new class () {
            /** @var array<string, mixed> */
            public array $routes = [];

            public function post(string $path, mixed $handler): void
            {
                $this->routes[$path] = $handler;
            }
        };

        eval(self::readme('mount-psr15'));

        $handler = $app->routes['/siren'] ?? null;
        $this->assertInstanceOf(RequestHandlerInterface::class, $handler);
        [$body, $headers] = self::signed('postConversionAction');
        $response = $handler->handle(new ServerRequest('POST', '/siren', $headers, $body));

        $this->assertSame([200, '{}'], [$response->getStatusCode(), (string) $response->getBody()]);
        $this->assertSame([self::contract('postConversionAction')['request']['id']], $seen);
    }

    public function testTheLaravelRouteRepliesAtOnceAndHandsTheAnswerToTerminating(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, ['status' => 'accepted'])]);
        $answers = self::splitByScore($siren->apps->requestHandler(self::secret()), $siren);

        eval(self::readme('mount-laravel'));

        $this->assertCount(1, ReadmeStubs::$routes);
        [$method, $path, $action] = ReadmeStubs::$routes[0];
        $this->assertSame(['POST', '/siren'], [$method, $path]);

        [$body, $headers] = self::signed('distributionResolver');
        // Laravel's header bag holds each name with a list of values.
        $response = $action(new \Illuminate\Http\Request('POST', $body, array_map(static fn(string $value): array => [$value], $headers)));

        $this->assertSame([200, '{}'], [$response->status, $response->body]);
        $this->assertSame('no-store', $response->headers['Cache-Control'] ?? null);
        $this->assertCount(1, ReadmeStubs::$terminating, 'The answer waits for the response to go.');
        $this->assertSame([], $this->history, 'Nothing was posted before the reply.');

        (ReadmeStubs::$terminating[0])();

        $this->assertSame('/siren/v1/apps/requests/' . self::contract('distributionResolver')['request']['id'] . '/answer', $this->lastRequest()->getUri()->getPath());
        $this->assertSame(self::contract('distributionResolver')['answer'], $this->lastRequestJson());
    }

    public function testTheOptionsSampleHandsAnAsksWorkToRunLater(): void
    {
        putenv('SIREN_SIGNING_SECRET=' . self::secret());

        try {
            $siren = $this->mockSiren([self::jsonResponse(200, ['status' => 'accepted'])]);
            $answers = null;

            eval(self::readme('handler-options'));

            $this->assertInstanceOf(AppRequestHandler::class, $answers);
            $answers = self::splitByScore($answers, $siren);
            [$body, $headers] = self::signed('distributionResolver');
            $reply = $answers->answer('POST', $body, $headers);

            $this->assertSame(200, $reply->status);
            $this->assertNull($reply->after, 'runLater took the work.');
            $this->assertCount(1, ReadmeStubs::$terminating);

            (ReadmeStubs::$terminating[0])();

            $this->assertSame(self::contract('distributionResolver')['answer'], $this->lastRequestJson());
            $this->assertSame([], ReadmeStubs::$reported);
        } finally {
            putenv('SIREN_SIGNING_SECRET');
        }
    }

    public function testTheOptionsSampleReportsAFunctionThatThrows(): void
    {
        putenv('SIREN_SIGNING_SECRET=' . self::secret());

        try {
            $siren = $this->mockSiren([]);
            $answers = null;

            eval(self::readme('handler-options'));

            $answers->on('postConversionAction', 'tell-the-crm', static function (): void {
                throw new \RuntimeException('The CRM is down.');
            });
            [$body, $headers] = self::signed('postConversionAction');
            $reply = $answers->answer('POST', $body, $headers);

            $this->assertSame(500, $reply->status);
            $this->assertCount(1, ReadmeStubs::$reported);
            $this->assertSame('The CRM is down.', ReadmeStubs::$reported[0]->getMessage());
        } finally {
            putenv('SIREN_SIGNING_SECRET');
        }
    }

    public function testTheOnErrorSampleUndoesTheWorkOfADroppedRequest(): void
    {
        putenv('SIREN_SIGNING_SECRET=' . self::secret());

        try {
            $siren = $this->mockSiren([self::errorResponse(409, 'Siren dropped this request because the sale or visit it was about waited more than 24 hours for your app. This answer was not used.', 'app_request_dropped')]);
            $answers = null;

            eval(self::readme('on-error'));

            $answers = self::splitByScore($answers, $siren);
            [$body, $headers] = self::signed('distributionResolver');
            $reply = $answers->answer('POST', $body, $headers);
            $this->assertSame(200, $reply->status);
            $this->assertNotNull($reply->after);

            ($reply->after)();

            $this->assertCount(1, $this->history, 'A refusal is not sent again.');
            $this->assertSame([self::contract('distributionResolver')['request']['id']], ReadmeStubs::$undone);
        } finally {
            putenv('SIREN_SIGNING_SECRET');
        }
    }
}
