<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use Siren\Sdk\Auth\CallbackAccessTokenSource;
use Siren\Sdk\Exception\AuthenticationException;
use Siren\Sdk\Exception\MissingScopeException;
use Siren\Sdk\Exception\PermissionException;
use Siren\Sdk\Exception\PolicyException;
use Siren\Sdk\Exception\RateLimitException;
use Siren\Sdk\Exception\SiteDisconnectedException;

/**
 * How the SDK names Siren Cloud's refusals of a connected site, so a host
 * can tell "reconnect" from "this request is not allowed" from "slow down".
 */
final class ConnectorErrorsTest extends TestCase
{
    public function testAMissingRecordScopeIsAMissingScopeException(): void
    {
        $siren = $this->hostSiren([self::hostJson(403, ['error' => ['message' => 'This token is not authorized for connect:records:read.', 'context' => []]])]);

        try {
            $siren->collaborators->list();
            $this->fail('Expected a MissingScopeException.');
        } catch (MissingScopeException $e) {
            $this->assertInstanceOf(PermissionException::class, $e);
            $this->assertSame('connect:records:read', $e->getRequiredScope());
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertCount(1, $this->http->requests, 'A 403 is final, so it is not retried.');
    }

    public function testAMissingWriteScopeNamesTheWriteScope(): void
    {
        $siren = $this->hostSiren([self::hostJson(403, ['error' => ['message' => 'This token is not authorized for connect:records:write.', 'context' => []]])]);

        try {
            $siren->mappings->create(4, '12', 'wordpress_user');
            $this->fail('Expected a MissingScopeException.');
        } catch (MissingScopeException $e) {
            $this->assertSame('connect:records:write', $e->getRequiredScope());
        }
    }

    public function testARoutePolicyDenialIsAPolicyException(): void
    {
        $siren = $this->hostSiren([self::hostJson(403, ['error' => ['message' => "You don't have permission to do that.", 'context' => []]])]);

        try {
            $siren->conversions->create(['collaboratorId' => 4]);
            $this->fail('Expected a PolicyException.');
        } catch (PolicyException $e) {
            $this->assertNull($e->getParam());
        }
    }

    public function testAConnectorRuleIsAPolicyExceptionEvenAsAPlainString(): void
    {
        $siren = $this->hostSiren([self::hostJson(403, ['error' => 'A connected site creates collaborators as pending. The organization approves them.'])]);

        $this->expectException(PolicyException::class);

        $siren->collaborators->create(['fullName' => 'Ada', 'nickname' => '', 'email' => 'a@example.com', 'status' => 'active']);
    }

    public function testAnyOther403StaysAPlainPermissionException(): void
    {
        $siren = $this->hostSiren([self::hostJson(403, ['error' => ['message' => 'Upgrade required.', 'code' => 'entitlement_required']])]);

        try {
            $siren->collaborators->list();
            $this->fail('Expected a PermissionException.');
        } catch (PermissionException $e) {
            $this->assertSame(PermissionException::class, $e::class);
        }
    }

    public function testARevokedSiteIsASiteDisconnectedExceptionAfterOneRefresh(): void
    {
        $refreshed = [];
        $siren = $this->hostSiren(
            [
                self::hostJson(401, ['error' => ['message' => SiteDisconnectedException::MESSAGE, 'context' => []]]),
                self::hostJson(401, ['error' => ['message' => SiteDisconnectedException::MESSAGE, 'context' => []]]),
            ],
            ['accessToken' => new CallbackAccessTokenSource(
                static fn (): string => 'old.jwt.token',
                static function (string $rejected) use (&$refreshed): string {
                    $refreshed[] = $rejected;

                    return 'new.jwt.token';
                },
            )],
        );

        try {
            $siren->collaborators->aliases->findByCode('SAVE10', 'coupon');
            $this->fail('Expected a SiteDisconnectedException.');
        } catch (SiteDisconnectedException $e) {
            $this->assertInstanceOf(AuthenticationException::class, $e);
            $this->assertSame(401, $e->getStatusCode());
        }

        $this->assertSame(['old.jwt.token'], $refreshed);
        $this->assertSame('Bearer new.jwt.token', $this->sent()->getHeader('Authorization'));
        $this->assertCount(2, $this->http->requests);
    }

    public function testAnExpiredTokenStaysAPlainAuthenticationException(): void
    {
        $siren = $this->hostSiren([self::hostJson(401, ['error' => ['message' => 'Authentication required.', 'context' => []]])]);

        try {
            $siren->collaborators->list();
            $this->fail('Expected an AuthenticationException.');
        } catch (AuthenticationException $e) {
            $this->assertSame(AuthenticationException::class, $e::class);
        }
    }

    public function testASitesReadRateLimitIsRetriedAfterTheNamedWait(): void
    {
        $siren = $this->hostSiren([
            self::hostJson(429, ['error' => ['message' => 'Too many requests.', 'context' => ['retryAfter' => 3]]], ['Retry-After' => '3']),
            self::hostJson(200, ['items' => []]),
        ]);

        $siren->credits->checkoutBalances(4);

        $this->assertSame([3.0], $this->sleeps);
        $this->assertCount(2, $this->http->requests);
    }

    public function testWithRetriesOffAReadRateLimitFailsAtOnce(): void
    {
        $siren = $this->hostSiren(
            [self::hostJson(429, ['error' => ['message' => 'Too many requests.', 'context' => ['retryAfter' => 30]]], ['Retry-After' => '30'])],
            ['maxRetries' => 0],
        );

        try {
            $siren->credits->checkoutBalances(4);
            $this->fail('Expected a RateLimitException.');
        } catch (RateLimitException $e) {
            $this->assertSame(30, $e->getRetryAfter());
            $this->assertSame(['retryAfter' => 30], $e->getErrorData());
        }

        $this->assertSame([], $this->sleeps, 'A checkout that turns retries off never waits.');
    }
}
