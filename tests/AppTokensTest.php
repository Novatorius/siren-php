<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use Siren\Sdk\Auth\StaticAccessToken;
use Siren\Sdk\Exception\AuthenticationException;
use Siren\Sdk\Exception\BadRequestException;
use Siren\Sdk\Http\HttpClientException;
use Siren\Sdk\Http\HttpRequest;
use Siren\Sdk\Siren;
use Siren\Sdk\Tests\Support\ArrayCache;
use Siren\Sdk\Tests\Support\RecordingHttpClient;

/**
 * A custom event goes out with a short-lived app token the SDK gets from the
 * app's key on its own (slice C13, apps-event-tokens, section 4).
 */
final class AppTokensTest extends TestCase
{
    private const BASE = 'https://api.sirenaffiliates.com/siren/v1';

    private float $now = 1_800_000_000.0;

    /**
     * @param list<\Siren\Sdk\Http\HttpResponse|\Throwable> $responses
     * @param array<string, mixed>                          $config
     */
    private function appSiren(array $responses, array $config = []): Siren
    {
        return $this->hostSiren($responses, $config + [
            'clock' => fn (): float => $this->now,
            'tokenCache' => false,
        ]);
    }

    private static function grant(string $token, int $expiresIn = 1200): \Siren\Sdk\Http\HttpResponse
    {
        return self::hostJson(200, ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => $expiresIn]);
    }

    private static function oauthError(int $status, string $code, string $description = 'Refused.'): \Siren\Sdk\Http\HttpResponse
    {
        return self::hostJson($status, ['error' => $code, 'error_description' => $description]);
    }

    private static function sent200(): \Siren\Sdk\Http\HttpResponse
    {
        return self::hostJson(200, ['status' => 'recorded'], ['X-Siren-OID' => '77']);
    }

    /** @return list<string> Each request as "METHOD path". */
    private function calls(): array
    {
        return array_map(
            static fn (HttpRequest $r): string => $r->getMethod() . ' ' . (string) parse_url($r->getUrl(), PHP_URL_PATH),
            $this->http->requests,
        );
    }

    /** @return array<string, mixed> */
    private static function jsonOf(HttpRequest $request): array
    {
        $decoded = json_decode((string) $request->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testTheFirstCustomEventTradesTheKeyForATokenAndSendsWithIt(): void
    {
        $siren = $this->appSiren([self::grant('srv.one'), self::sent200()]);

        $result = $siren->events->ingest('demo-booked', ['email' => 'a@example.com']);

        $this->assertSame(['POST /siren/v1/oauth/token', 'POST /siren/v1/event/demo-booked'], $this->calls());
        $grant = $this->http->requests[0];
        $this->assertSame(['grant_type' => 'client_credentials', 'client_secret' => self::API_KEY], self::jsonOf($grant));
        $this->assertNull($grant->getHeader('Authorization'), 'The key goes in the body, not as a bearer.');
        $this->assertSame('Bearer srv.one', $this->http->requests[1]->getHeader('Authorization'));
        $this->assertSame(['email' => 'a@example.com'], self::jsonOf($this->http->requests[1]));
        $this->assertSame(77, $result->getOpportunityId());
    }

    public function testKeepsTheTokenForLaterSends(): void
    {
        $siren = $this->appSiren([self::grant('srv.one'), self::sent200(), self::sent200(), self::sent200()]);

        $siren->events->ingest('demo-booked', []);
        $this->now += 600;
        $siren->events->ingest('demo-booked', []);
        $siren->events->ingest('trial-started', []);

        $this->assertSame(1, $this->countGrants());
        $this->assertSame('Bearer srv.one', $this->http->requests[3]->getHeader('Authorization'));
    }

    public function testReusesTheTokenWithExactlyTwoMinutesLeft(): void
    {
        $siren = $this->appSiren([self::grant('srv.one'), self::sent200(), self::sent200()]);

        $siren->events->ingest('demo-booked', []);
        $this->now += 1200 - 120;
        $siren->events->ingest('demo-booked', []);

        $this->assertSame(1, $this->countGrants());
    }

    public function testAsksForANewTokenWhenFewerThanTwoMinutesAreLeft(): void
    {
        $siren = $this->appSiren([self::grant('srv.one'), self::sent200(), self::grant('srv.two'), self::sent200()]);

        $siren->events->ingest('demo-booked', []);
        $this->now += 1200 - 119;
        $siren->events->ingest('demo-booked', []);

        $this->assertSame(2, $this->countGrants());
        $this->assertSame('Bearer srv.two', $this->http->last()->getHeader('Authorization'));
    }

    public function testA401DropsTheTokenGetsANewOneAndSendsOnceMore(): void
    {
        $siren = $this->appSiren([
            self::grant('srv.revoked'),
            self::hostJson(401, ['error' => 'This access token has expired.']),
            self::grant('srv.fresh'),
            self::sent200(),
        ]);

        $result = $siren->events->ingest('demo-booked', ['n' => 1]);

        $this->assertSame([
            'POST /siren/v1/oauth/token',
            'POST /siren/v1/event/demo-booked',
            'POST /siren/v1/oauth/token',
            'POST /siren/v1/event/demo-booked',
        ], $this->calls());
        $this->assertSame('Bearer srv.fresh', $this->http->last()->getHeader('Authorization'));
        $this->assertSame(['n' => 1], self::jsonOf($this->http->last()));
        $this->assertSame(200, $result->getHttpStatus());
        $this->assertSame([], $this->sleeps, 'A new token is not a backoff retry.');
    }

    public function testASecond401SurfacesAsTheUsualAuthenticationError(): void
    {
        $siren = $this->appSiren([
            self::grant('srv.one'),
            self::hostJson(401, ['error' => 'This access token has expired.']),
            self::grant('srv.two'),
            self::hostJson(401, ['error' => 'This access token has expired.']),
        ]);

        try {
            $siren->events->ingest('demo-booked', []);
            $this->fail('Expected an AuthenticationException.');
        } catch (AuthenticationException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }

        $this->assertCount(4, $this->http->requests, 'One retry, not a loop.');
    }

    public function testATokenDroppedAfterA401IsNotUsedAgain(): void
    {
        $siren = $this->appSiren([
            self::grant('srv.one'),
            self::hostJson(401, ['error' => 'This access token has expired.']),
            self::grant('srv.two'),
            self::sent200(),
            self::sent200(),
        ]);

        $siren->events->ingest('demo-booked', []);
        $siren->events->ingest('demo-booked', []);

        $this->assertSame(2, $this->countGrants());
        $this->assertSame('Bearer srv.two', $this->http->last()->getHeader('Authorization'));
    }

    public function testAKeyOfNoAppSendsWithTheKeyAndStopsAsking(): void
    {
        $siren = $this->appSiren([
            self::oauthError(400, 'unauthorized_client', 'Only an app\'s key can get an access token.'),
            self::sent200(),
            self::sent200(),
        ]);

        $siren->events->ingest('demo-booked', []);
        $siren->events->ingest('demo-booked', []);

        $this->assertSame([
            'POST /siren/v1/oauth/token',
            'POST /siren/v1/event/demo-booked',
            'POST /siren/v1/event/demo-booked',
        ], $this->calls());
        $this->assertSame('Bearer ' . self::API_KEY, $this->http->requests[1]->getHeader('Authorization'));
        $this->assertSame('Bearer ' . self::API_KEY, $this->http->requests[2]->getHeader('Authorization'));
    }

    public function testAKeySirenDoesNotKnowSurfacesFromTheGrantWithoutASend(): void
    {
        $siren = $this->appSiren([self::oauthError(401, 'invalid_client', 'Siren doesn\'t know this key, or it was revoked.')]);

        try {
            $siren->events->ingest('demo-booked', []);
            $this->fail('Expected an AuthenticationException.');
        } catch (AuthenticationException $e) {
            $this->assertSame('invalid_client', $e->getErrorCode());
            $this->assertSame('Siren doesn\'t know this key, or it was revoked.', $e->getMessage());
        }

        $this->assertSame(['POST /siren/v1/oauth/token'], $this->calls());
    }

    public function testAnyOtherRefusedGrantSurfacesAsItIs(): void
    {
        $siren = $this->appSiren([self::oauthError(400, 'invalid_request', 'client_secret is required.')]);

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('client_secret is required.');

        $siren->events->ingest('demo-booked', []);
    }

    public function testEveryOtherCallKeepsSendingTheKey(): void
    {
        $siren = $this->appSiren([
            self::grant('srv.one'),
            self::sent200(),
            self::sent200(),
            self::sent200(),
            self::sent200(),
            self::hostPage([], 0),
        ]);

        $siren->events->ingest('demo-booked', []);
        $siren->events->sale(['source' => 'stripe', 'externalId' => 'cs_1', 'total' => 9.99, 'trackingId' => 3]);
        $siren->events->refund(['source' => 'stripe', 'externalId' => 'cs_1']);
        $siren->events->siteVisited(['collaboratorId' => 3]);
        $siren->collaborators->list();

        $this->assertSame(1, $this->countGrants());

        foreach (array_slice($this->http->requests, 2) as $request) {
            $this->assertSame('Bearer ' . self::API_KEY, $request->getHeader('Authorization'), $request->getUrl());
        }
    }

    public function testAClientBuiltWithAnAccessTokenNeverAsksForAnAppToken(): void
    {
        $siren = $this->appSiren([self::sent200()], ['accessToken' => new StaticAccessToken('site.jwt')]);

        $siren->events->ingest('demo-booked', []);

        $this->assertSame(['POST /siren/v1/event/demo-booked'], $this->calls());
        $this->assertSame('Bearer site.jwt', $this->sent()->getHeader('Authorization'));
    }

    public function testAClientBuiltWithAnAccessTokenCannotMakeABrowserToken(): void
    {
        $siren = $this->appSiren([], ['accessToken' => 'site.jwt']);

        $this->expectException(\LogicException::class);

        $siren->events->browserToken('demo-booked');
    }

    public function testWorkersSharingACacheShareOneToken(): void
    {
        $cache = new ArrayCache();
        $first = $this->appSiren([self::grant('srv.one'), self::sent200()], ['tokenCache' => $cache]);
        $first->events->ingest('demo-booked', []);
        $firstHttp = $this->http;

        // A second PHP request on the same server builds its own client.
        $second = $this->appSiren([self::sent200()], ['tokenCache' => $cache]);
        $this->now += 300;
        $second->events->ingest('demo-booked', []);

        $this->assertCount(2, $firstHttp->requests);
        $this->assertSame(['POST /siren/v1/event/demo-booked'], $this->calls());
        $this->assertSame('Bearer srv.one', $this->sent()->getHeader('Authorization'));
        $this->assertSame([1200], array_values($cache->ttls), 'The cache keeps the token no longer than it lives.');
    }

    public function testA401ClearsTheSharedCopyToo(): void
    {
        $cache = new ArrayCache();
        $siren = $this->appSiren([
            self::grant('srv.one'),
            self::hostJson(401, ['error' => 'This access token has expired.']),
            self::grant('srv.two'),
            self::sent200(),
        ], ['tokenCache' => $cache]);

        $siren->events->ingest('demo-booked', []);

        $this->assertCount(1, $cache->values);
        $this->assertSame('srv.two', array_values($cache->values)[0]['token']);
    }

    public function testATokenCacheThatIsNotPsr16IsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Siren(['apiKey' => self::API_KEY, 'tokenCache' => new \stdClass(), 'httpClient' => new RecordingHttpClient()]);
    }

    public function testBrowserTokenAsksForTheEventsScopeAndAnswersTokenSecondsAndAddress(): void
    {
        $siren = $this->appSiren([self::grant('brw.one', 600)]);

        $token = $siren->events->browserToken('demo-booked');

        $this->assertSame(
            ['grant_type' => 'client_credentials', 'client_secret' => self::API_KEY, 'scope' => 'browser:demo-booked'],
            self::jsonOf($this->sent()),
        );
        $this->assertSame(['token' => 'brw.one', 'expiresIn' => 600, 'url' => self::BASE . '/event/demo-booked'], $token->toArray());
        $this->assertSame('{"token":"brw.one","expiresIn":600,"url":"https:\/\/api.sirenaffiliates.com\/siren\/v1\/event\/demo-booked"}', json_encode($token));
    }

    public function testBrowserTokenIsReusedUntilFewerThanSixtySecondsAreLeft(): void
    {
        $siren = $this->appSiren([self::grant('brw.one', 600), self::grant('brw.two', 600)]);

        $siren->events->browserToken('demo-booked');
        $this->now += 540;
        $held = $siren->events->browserToken('demo-booked');
        $this->now += 1;
        $fresh = $siren->events->browserToken('demo-booked');

        $this->assertSame(['brw.one', 60], [$held->token, $held->expiresIn]);
        $this->assertSame(['brw.two', 600], [$fresh->token, $fresh->expiresIn]);
        $this->assertSame(2, $this->countGrants());
    }

    public function testEachEventGetsItsOwnBrowserTokenApartFromTheServerToken(): void
    {
        $siren = $this->appSiren([self::grant('brw.a', 600), self::grant('brw.b', 600), self::grant('srv.one'), self::sent200()]);

        $a = $siren->events->browserToken('demo-booked');
        $b = $siren->events->browserToken('trial started');
        $siren->events->ingest('demo-booked', []);

        $this->assertSame(['brw.a', 'brw.b'], [$a->token, $b->token]);
        $this->assertSame(self::BASE . '/event/trial%20started', $b->url);
        $this->assertSame('Bearer srv.one', $this->sent()->getHeader('Authorization'));
        $this->assertSame(['grant_type' => 'client_credentials', 'client_secret' => self::API_KEY], self::jsonOf($this->http->requests[2]));
    }

    public function testBrowserTokenSurfacesSirensRefusal(): void
    {
        $siren = $this->appSiren([self::oauthError(400, 'invalid_scope', 'The event "demo-booked" allows no domain.')]);

        try {
            $siren->events->browserToken('demo-booked');
            $this->fail('Expected a BadRequestException.');
        } catch (BadRequestException $e) {
            $this->assertSame('invalid_scope', $e->getErrorCode());
            $this->assertSame('The event "demo-booked" allows no domain.', $e->getMessage());
        }
    }

    public function testAGrantThatFailsToConnectIsRetriedLikeAnyRead(): void
    {
        $siren = $this->appSiren([new HttpClientException('Connection refused'), self::grant('srv.one'), self::sent200()]);

        $siren->events->ingest('demo-booked', []);

        $this->assertSame([0.5], $this->sleeps);
        $this->assertSame('Bearer srv.one', $this->sent()->getHeader('Authorization'));
    }

    private function countGrants(): int
    {
        return count(array_filter($this->calls(), static fn (string $c): bool => $c === 'POST /siren/v1/oauth/token'));
    }
}
