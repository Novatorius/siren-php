<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use Siren\Sdk\Auth\AccessTokenSource;
use Siren\Sdk\Auth\CallbackAccessTokenSource;
use Siren\Sdk\Auth\StaticAccessToken;
use Siren\Sdk\Exception\AuthenticationException;
use Siren\Sdk\Siren;

/**
 * A host-supplied access token source, refreshed once when Siren answers 401.
 */
final class TokenRefreshTest extends TestCase
{
    /**
     * A source that rotates through a fixed list of tokens and counts refreshes.
     */
    private function rotatingSource(string $first, ?string $next): AccessTokenSource
    {
        return new class ($first, $next) implements AccessTokenSource {
            /** @var list<string> */
            public array $rejected = [];

            public function __construct(private string $current, private readonly ?string $next)
            {
            }

            public function getAccessToken(): string
            {
                return $this->current;
            }

            public function refreshAccessToken(string $rejectedToken): ?string
            {
                $this->rejected[] = $rejectedToken;

                if ($this->next === null) {
                    return null;
                }

                $this->current = $this->next;

                return $this->next;
            }
        };
    }

    public function testSendsTheSourcesTokenAsABearer(): void
    {
        $siren = $this->hostSiren([self::hostPage([], 0)], ['accessToken' => $this->rotatingSource('jwt.one', null)]);

        $siren->collaborators->list();

        $this->assertSame('Bearer jwt.one', $this->sent()->getHeader('Authorization'));
    }

    public function testRefreshesOnceAfterA401AndRepeatsTheRequestWithTheNewToken(): void
    {
        $source = $this->rotatingSource('jwt.expired', 'jwt.fresh');
        $siren = $this->hostSiren(
            [self::hostJson(401, ['error' => 'Invalid or expired token.']), self::hostJson(200, ['id' => 5, 'status' => 'active'])],
            ['accessToken' => $source],
        );

        $record = $siren->collaborators->retrieve(5);

        $this->assertSame(['id' => 5, 'status' => 'active'], $record);
        $this->assertCount(2, $this->http->requests);
        $this->assertSame('Bearer jwt.expired', $this->http->requests[0]->getHeader('Authorization'));
        $this->assertSame('Bearer jwt.fresh', $this->http->requests[1]->getHeader('Authorization'));
        $this->assertSame(['jwt.expired'], $source->rejected, 'The source learns which token was rejected.');
        $this->assertSame([], $this->sleeps, 'A refresh is not a backoff retry.');
    }

    public function testASecond401AfterRefreshSurfacesAsAuthenticationError(): void
    {
        $source = $this->rotatingSource('jwt.expired', 'jwt.also-rejected');
        $siren = $this->hostSiren(
            [self::hostJson(401, ['error' => 'Invalid or expired token.']), self::hostJson(401, ['error' => 'Token has been revoked.'])],
            ['accessToken' => $source],
        );

        try {
            $siren->programs->list();
            $this->fail('Expected an AuthenticationException.');
        } catch (AuthenticationException $e) {
            $this->assertSame('Token has been revoked.', $e->getMessage());
        }

        $this->assertCount(2, $this->http->requests, 'One refresh, no loop.');
        $this->assertCount(1, $source->rejected);
    }

    public function testASourceThatCannotRefreshSurfacesTheOriginal401(): void
    {
        $source = $this->rotatingSource('jwt.expired', null);
        $siren = $this->hostSiren([self::hostJson(401, ['error' => 'Invalid or expired token.'])], ['accessToken' => $source]);

        $this->expectException(AuthenticationException::class);

        try {
            $siren->conversions->list();
        } finally {
            $this->assertCount(1, $this->http->requests);
            $this->assertCount(1, $source->rejected);
        }
    }

    public function testAWriteIsRepeatedOnceAfterARefreshBecauseThe401MeansNothingRan(): void
    {
        $siren = $this->hostSiren(
            [self::hostJson(401, ['error' => 'expired']), self::hostJson(201, ['id' => 77])],
            ['accessToken' => $this->rotatingSource('jwt.old', 'jwt.new')],
        );

        $created = $siren->collaborators->create([
            'fullName' => 'Ada Lovelace',
            'nickname' => 'ada',
            'email' => 'ada@example.test',
            'status' => 'active',
        ]);

        $this->assertSame(['id' => 77], $created);
        $this->assertCount(2, $this->http->requests);
        $this->assertSame($this->http->requests[0]->getBody(), $this->http->requests[1]->getBody());
    }

    public function testEventIngestionAlsoRefreshes(): void
    {
        $siren = $this->hostSiren(
            [self::hostJson(401, ['error' => 'expired']), self::hostJson(200, [], ['X-Siren-OID' => '12'])],
            ['accessToken' => $this->rotatingSource('jwt.old', 'jwt.new')],
        );

        $result = $siren->events->ingest('lead', ['email' => 'lead@example.test']);

        $this->assertSame(12, $result->getOpportunityId());
        $this->assertSame('Bearer jwt.new', $this->sent()->getHeader('Authorization'));
    }

    public function testTheTokenIsReadBeforeEveryRequest(): void
    {
        $current = 'jwt.a';
        $source = new CallbackAccessTokenSource(static function () use (&$current): string {
            return $current;
        });
        $siren = $this->hostSiren([self::hostPage([], 0), self::hostPage([], 0)], ['accessToken' => $source]);

        $siren->programs->list();
        $current = 'jwt.b';
        $siren->programs->list();

        $this->assertSame('Bearer jwt.a', $this->http->requests[0]->getHeader('Authorization'));
        $this->assertSame('Bearer jwt.b', $this->http->requests[1]->getHeader('Authorization'));
    }

    public function testTheCallbackSourceRefreshesThroughItsSecondCallable(): void
    {
        $seen = [];
        $source = new CallbackAccessTokenSource(
            static fn (): string => 'jwt.old',
            static function (string $rejected) use (&$seen): string {
                $seen[] = $rejected;

                return 'jwt.new';
            },
        );

        $this->assertSame('jwt.new', $source->refreshAccessToken('jwt.old'));
        $this->assertSame(['jwt.old'], $seen);
    }

    public function testTheCallbackSourceWithoutARefreshCallableCannotRefresh(): void
    {
        $source = new CallbackAccessTokenSource(static fn (): string => 'jwt.old');

        $this->assertNull($source->refreshAccessToken('jwt.old'));
    }

    public function testTheCallbackSourceTreatsAnEmptyRefreshResultAsNoRefresh(): void
    {
        $source = new CallbackAccessTokenSource(static fn (): string => 'jwt.old', static fn (): string => '');

        $this->assertNull($source->refreshAccessToken('jwt.old'));
    }

    public function testAPlainStringAccessTokenIsSentAsIs(): void
    {
        $siren = $this->hostSiren([self::hostPage([], 0)], ['accessToken' => 'jwt.static']);

        $siren->engagements->list();

        $this->assertSame('Bearer jwt.static', $this->sent()->getHeader('Authorization'));
    }

    public function testAStaticTokenNeverRefreshes(): void
    {
        $this->assertNull((new StaticAccessToken('sk_live_x'))->refreshAccessToken('sk_live_x'));
    }

    public function testAStaticTokenMustNotBeEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new StaticAccessToken('');
    }

    public function testApiKeyAndAccessTokenTogetherAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not both');

        new Siren(['apiKey' => self::API_KEY, 'accessToken' => 'jwt.x']);
    }

    public function testAnAccessTokenOfTheWrongTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('accessToken');

        new Siren(['accessToken' => 42]);
    }
}
