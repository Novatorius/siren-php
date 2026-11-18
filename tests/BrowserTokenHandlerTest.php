<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Siren\Sdk\BrowserTokenHandler;
use Siren\Sdk\Http\HttpClientException;
use Siren\Sdk\Siren;

/**
 * The browser token address on the app's server, as a PSR-15 handler and as
 * the framework-neutral answer() behind it and the plain-PHP function.
 */
final class BrowserTokenHandlerTest extends TestCase
{
    private const URL = 'https://api.sirenaffiliates.com/siren/v1/event/demo-booked';

    /**
     * @param list<\Siren\Sdk\Http\HttpResponse|\Throwable> $responses
     */
    private function siren(array $responses): Siren
    {
        return $this->hostSiren($responses, ['tokenCache' => false, 'maxRetries' => 0]);
    }

    private static function grant(string $token = 'brw.one'): \Siren\Sdk\Http\HttpResponse
    {
        return self::hostJson(200, ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 600, 'scope' => 'browser:demo-booked']);
    }

    /** @return array<string, mixed> */
    private static function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testAnswers200WithTheTokenForAnEventInAJsonBody(): void
    {
        $handler = $this->siren([self::grant()])->events->browserTokenHandler();

        $response = $handler->handle(new ServerRequest('POST', '/siren/token', ['Content-Type' => 'application/json'], '{"event":"demo-booked"}'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertSame(['token' => 'brw.one', 'expiresIn' => 600, 'url' => self::URL], self::json($response));
        $this->assertStringContainsString('"url":"https://api', (string) $response->getBody(), 'Slashes stay readable.');
    }

    public function testReadsTheEventFromTheQuery(): void
    {
        $handler = $this->siren([self::grant()])->events->browserTokenHandler();

        $response = $handler->handle((new ServerRequest('GET', '/siren/token?event=demo-booked'))->withQueryParams(['event' => 'demo-booked']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('browser:demo-booked', json_decode((string) $this->sent()->getBody(), true)['scope']);
    }

    public function testReadsABodyTheFrameworkAlreadyParsed(): void
    {
        $handler = $this->siren([self::grant()])->events->browserTokenHandler();

        $response = $handler->handle((new ServerRequest('POST', '/siren/token'))->withParsedBody(['event' => 'demo-booked']));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testReusesOneTokenAcrossPageLoads(): void
    {
        $handler = $this->siren([self::grant()])->events->browserTokenHandler();

        $handler->handle((new ServerRequest('GET', '/'))->withQueryParams(['event' => 'demo-booked']));
        $second = $handler->handle((new ServerRequest('GET', '/'))->withQueryParams(['event' => 'demo-booked']));

        $this->assertSame('brw.one', self::json($second)['token']);
        $this->assertCount(1, $this->http->requests);
    }

    public function testAnswers400WhenNoEventIsNamed(): void
    {
        $handler = $this->siren([])->events->browserTokenHandler();

        foreach (['', '{}', '{"event":""}', '{"event":42}', 'not json'] as $body) {
            $response = $handler->handle(new ServerRequest('POST', '/siren/token', [], $body));

            $this->assertSame(400, $response->getStatusCode(), $body);
            $this->assertIsString(self::json($response)['error'] ?? null);
        }

        $this->assertSame([], $this->http->requests, 'Siren is never asked.');
    }

    public function testAnswers400ForAnEventOutsideTheList(): void
    {
        $handler = $this->siren([])->events->browserTokenHandler(['events' => ['trial-started']]);

        $response = $handler->handle((new ServerRequest('GET', '/'))->withQueryParams(['event' => 'demo-booked']));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(['error' => 'This server gives no browser token for that event.'], self::json($response));
        $this->assertSame([], $this->http->requests);
    }

    public function testAnswers400WithSirensWordsWhenSirenRefusesTheEvent(): void
    {
        $handler = $this->siren([
            self::hostJson(400, ['error' => 'invalid_scope', 'error_description' => 'The event "demo-booked" allows no domain, so no browser may send it.']),
        ])->events->browserTokenHandler(['events' => ['demo-booked']]);

        $response = $handler->handle((new ServerRequest('GET', '/'))->withQueryParams(['event' => 'demo-booked']));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(['error' => 'The event "demo-booked" allows no domain, so no browser may send it.'], self::json($response));
    }

    /**
     * @return iterable<string, array{0: \Siren\Sdk\Http\HttpResponse|\Throwable}>
     */
    public static function sirenUnreachable(): iterable
    {
        yield 'no connection' => [new HttpClientException('Connection refused')];
        yield 'key refused' => [self::hostJson(401, ['error' => 'invalid_client', 'error_description' => 'Siren doesn\'t know this key.'])];
        yield 'key of no app' => [self::hostJson(400, ['error' => 'unauthorized_client', 'error_description' => 'Only an app\'s key can get an access token.'])];
        yield 'server error' => [self::hostJson(503, ['error' => 'temporarily_unavailable', 'error_description' => 'Try again shortly.'])];
    }

    /**
     * @dataProvider sirenUnreachable
     */
    public function testAnswers502WhenSirenCannotGiveAToken(\Siren\Sdk\Http\HttpResponse|\Throwable $answer): void
    {
        $handler = $this->siren([$answer])->events->browserTokenHandler();

        $response = $handler->handle((new ServerRequest('GET', '/'))->withQueryParams(['event' => 'demo-booked']));

        $this->assertSame(502, $response->getStatusCode());
        $this->assertSame(['error' => 'Siren could not be reached for a browser token. Try again shortly.'], self::json($response));
        $this->assertStringNotContainsString(self::API_KEY, (string) $response->getBody());
    }

    public function testTakesTheFactoriesItIsGiven(): void
    {
        $factory = new HttpFactory();
        $handler = $this->siren([self::grant()])->events->browserTokenHandler(['responseFactory' => $factory, 'streamFactory' => $factory]);

        $this->assertInstanceOf(BrowserTokenHandler::class, $handler);
        $this->assertSame(200, $handler->handle((new ServerRequest('GET', '/'))->withQueryParams(['event' => 'demo-booked']))->getStatusCode());
    }

    public function testAnswerIsTheSameLogicWithNoFramework(): void
    {
        $siren = $this->siren([self::grant()]);
        $endpoint = new \Siren\Sdk\BrowserTokenEndpoint($siren->events, ['events' => ['demo-booked']]);

        $this->assertSame(['status' => 400, 'body' => ['error' => 'This server gives no browser token for that event.']], $endpoint->answer(null, ['event' => 'other']));
        $this->assertSame(
            ['status' => 200, 'body' => ['token' => 'brw.one', 'expiresIn' => 600, 'url' => self::URL]],
            $endpoint->answer('{"event":"demo-booked"}'),
        );
    }

    public function testRefusesAnEventsOptionThatIsNotAList(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->siren([])->events->browserTokenHandler(['events' => 'demo-booked']);
    }
}
