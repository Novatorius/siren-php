<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests\Resources;

use Siren\Sdk\Exception\ConflictException;
use Siren\Sdk\Exception\HoldConflictException;
use Siren\Sdk\Exception\HoldLimitException;
use Siren\Sdk\Exception\RateLimitException;
use Siren\Sdk\Resources\Credits;
use Siren\Sdk\Tests\TestCase;

/**
 * The store credit routes a connected site's checkout uses: balances,
 * ledger entries, and holds (Siren PR 384).
 */
final class CreditRoutesTest extends TestCase
{
    public function testCheckoutBalancesReadTheBalanceRoute(): void
    {
        $rows = [['collaboratorId' => 4, 'currency' => 'STORE_CREDIT', 'balance' => 2000, 'reserved' => 500, 'available' => 1500]];
        $siren = $this->hostSiren([self::hostJson(200, ['items' => $rows])]);

        $this->assertSame($rows, $siren->credits->checkoutBalances(4));
        $this->assertSame(['GET', '/siren/v1/credit-balances'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame(['collaboratorId' => '4'], $this->sentQuery());
        $this->assertNull($this->sent()->getBody());
    }

    public function testCheckoutBalanceNamesTheCurrencyAndLeavesOutTheSessionsOwnHold(): void
    {
        $row = ['collaboratorId' => 4, 'currency' => 'STORE_CREDIT', 'balance' => 2000, 'reserved' => 0, 'available' => 2000];
        $siren = $this->hostSiren([self::hostJson(200, ['items' => [$row]])]);

        $reference = Credits::holdReference('woocommerce', '0b6f3c2e-8a1d-4c55-9a0e-0d6c1b7f4e21');
        $this->assertSame($row, $siren->credits->checkoutBalance(4, 'STORE_CREDIT', $reference));
        $this->assertSame(
            ['collaboratorId' => '4', 'currency' => 'STORE_CREDIT', 'excludeReference' => 'hold:woocommerce:0b6f3c2e-8a1d-4c55-9a0e-0d6c1b7f4e21'],
            $this->sentQuery(),
        );
    }

    public function testCheckoutBalanceIsNullForAnUnknownCurrency(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, ['items' => []])]);

        $this->assertNull($siren->credits->checkoutBalance(4, 'NOPE'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedReferences(): array
    {
        return [
            'qualified platform' => ['hold:woocommerce@property:5:tok'],
            'missing token' => ['hold:woocommerce'],
            'wrong prefix' => ['place:woocommerce:tok'],
            'comma' => ['hold:woocommerce:a,b'],
            'extra colon' => ['hold:woocommerce:a:b'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedReferences')]
    public function testHoldReferencesAreCheckedBeforeSending(string $reference): void
    {
        $siren = $this->hostSiren([]);

        foreach ([fn () => $siren->credits->checkoutBalances(4, null, $reference), fn () => $siren->credits->holds(['reference' => $reference])] as $call) {
            try {
                $call();
                $this->fail('Expected an InvalidArgumentException.');
            } catch (\InvalidArgumentException) {
            }
        }

        $this->assertSame([], $this->http->requests);
    }

    public function testEntriesListTheLedgerWithFiltersAndPaging(): void
    {
        $siren = $this->hostSiren([self::hostPage([['id' => 7, 'collaboratorId' => 4, 'currency' => 'STORE_CREDIT', 'amount' => 500]], 1)]);

        $page = $siren->credits->entries(['collaboratorId' => 4, 'currency' => 'STORE_CREDIT', 'sourceType' => 'order', 'orderBy' => 'dateCreated', 'order' => 'DESC', 'perPage' => 100]);

        $this->assertSame(['GET', '/siren/v1/credit-entries'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame(
            ['collaboratorId' => '4', 'currency' => 'STORE_CREDIT', 'sourceType' => 'order', 'orderBy' => 'dateCreated', 'order' => 'DESC', 'number' => '100'],
            $this->sentQuery(),
        );
        $this->assertSame(7, $page->getData()[0]['id']);
    }

    public function testEntriesFindOneByIdempotencyKey(): void
    {
        $siren = $this->hostSiren([self::hostPage([])]);

        $siren->credits->entries(['idempotencyKey' => 'redeem:wc:1043']);

        $this->assertSame(['idempotencyKey' => 'redeem:wc:1043'], $this->sentQuery());
    }

    public function testEntriesAndHoldsRefuseAQueryThatNamesNoOwner(): void
    {
        $siren = $this->hostSiren([]);

        foreach ([fn () => $siren->credits->entries(['currency' => 'STORE_CREDIT']), fn () => $siren->credits->holds(['status' => 'active']), fn () => $siren->credits->entries(['collaboratorId' => ' '])] as $call) {
            try {
                $call();
                $this->fail('A ledger query that names nobody would list the whole organization.');
            } catch (\InvalidArgumentException) {
            }
        }

        $this->assertSame([], $this->http->requests);
    }

    public function testHoldsListOneSessionsHolds(): void
    {
        $siren = $this->hostSiren([self::hostPage([['id' => 3, 'reference' => 'hold:woocommerce@property:5:tok', 'status' => 'active']], 1)]);

        $page = $siren->credits->holds(['reference' => Credits::holdReference('woocommerce', 'tok'), 'status' => Credits::HOLD_STATUS_ACTIVE, 'orderBy' => 'expiresAt']);

        $this->assertSame(['GET', '/siren/v1/credit-holds'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame(['reference' => 'hold:woocommerce:tok', 'status' => 'active', 'orderBy' => 'expiresAt'], $this->sentQuery());
        $this->assertSame('hold:woocommerce@property:5:tok', $page->getData()[0]['reference'], 'Hold references come back as Siren stores them.');
    }

    public function testHoldsListACollaboratorsHolds(): void
    {
        $siren = $this->hostSiren([self::hostPage([])]);

        $siren->credits->holds(['collaboratorId' => 4, 'currency' => 'STORE_CREDIT']);

        $this->assertSame(['collaboratorId' => '4', 'currency' => 'STORE_CREDIT'], $this->sentQuery());
    }

    public function testPlaceHoldPostsThePlainPlatformAndToken(): void
    {
        $hold = ['id' => 3, 'collaboratorId' => 4, 'currency' => 'STORE_CREDIT', 'amount' => 800, 'status' => 'active', 'reference' => 'hold:woocommerce@property:5:tok-1'];
        $siren = $this->hostSiren([self::hostJson(201, $hold)]);

        $this->assertSame($hold, $siren->credits->placeHold(4, 'STORE_CREDIT', 1000, 'tok-1', 'woocommerce', 900));
        $this->assertSame(['POST', '/siren/v1/credit-holds'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame(
            ['collaboratorId' => 4, 'currency' => 'STORE_CREDIT', 'amount' => 1000, 'token' => 'tok-1', 'platformId' => 'woocommerce', 'ttl' => 900],
            $this->sentJson(),
        );
    }

    public function testPlaceHoldLeavesOutAnUnsetTtl(): void
    {
        $siren = $this->hostSiren([self::hostJson(201, ['id' => 3])]);

        $siren->credits->placeHold(4, 'STORE_CREDIT', 1000, 'tok', 'woocommerce');

        $this->assertArrayNotHasKey('ttl', $this->sentJson());
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: string, 3: int|null}>
     */
    public static function badHolds(): array
    {
        return [
            'zero amount' => [0, 'tok', 'woocommerce', null],
            'token with a colon' => [100, 'a:b', 'woocommerce', null],
            'token with a newline' => [100, "tok\n", 'woocommerce', null],
            'token too long' => [100, str_repeat('a', 121), 'woocommerce', null],
            'qualified platform' => [100, 'tok', 'woocommerce@property:5', null],
            'platform with a comma' => [100, 'tok', 'woo,commerce', null],
            'platform too long' => [100, 'tok', str_repeat('w', 61), null],
            'ttl too short' => [100, 'tok', 'woocommerce', 59],
            'ttl too long' => [100, 'tok', 'woocommerce', 3601],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badHolds')]
    public function testPlaceHoldChecksItsInputBeforeSending(int $amount, string $token, string $platform, ?int $ttl): void
    {
        $siren = $this->hostSiren([]);

        try {
            $siren->credits->placeHold(4, 'STORE_CREDIT', $amount, $token, $platform, $ttl);
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame([], $this->http->requests);
    }

    public function testNoCreditIsAHoldConflict(): void
    {
        $siren = $this->hostSiren([self::hostJson(409, ['error' => 'This collaborator has no credit available in that currency.'])]);

        try {
            $siren->credits->placeHold(4, 'STORE_CREDIT', 1000, 'tok', 'woocommerce');
            $this->fail('Expected a HoldConflictException.');
        } catch (HoldConflictException $e) {
            $this->assertInstanceOf(ConflictException::class, $e);
            $this->assertSame(HoldConflictException::REASON_NO_CREDIT, $e->getReason());
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertCount(1, $this->http->requests);
    }

    public function testAnotherSitesTokenIsAHoldConflict(): void
    {
        $siren = $this->hostSiren([self::hostJson(409, ['error' => 'Another checkout already holds credit under that token.'])]);

        try {
            $siren->credits->placeHold(4, 'STORE_CREDIT', 1000, 'tok', 'woocommerce');
            $this->fail('Expected a HoldConflictException.');
        } catch (HoldConflictException $e) {
            $this->assertSame(HoldConflictException::REASON_TOKEN_IN_USE, $e->getReason());
        }
    }

    public function testAnUnfamiliarConflictHasNoReason(): void
    {
        $siren = $this->hostSiren([self::hostJson(409, ['error' => 'Something new.'])]);

        try {
            $siren->credits->placeHold(4, 'STORE_CREDIT', 1000, 'tok', 'woocommerce');
            $this->fail('Expected a HoldConflictException.');
        } catch (HoldConflictException $e) {
            $this->assertNull($e->getReason());
        }
    }

    public function testTheTenCheckoutCapIsAHoldLimitAndIsNotRetried(): void
    {
        $siren = $this->hostSiren([
            self::hostJson(429, ['error' => 'This collaborator already has too many checkouts holding credit. Try again when one finishes.']),
            self::hostJson(201, ['id' => 3]),
        ]);

        try {
            $siren->credits->placeHold(4, 'STORE_CREDIT', 1000, 'tok', 'woocommerce');
            $this->fail('Expected a HoldLimitException.');
        } catch (HoldLimitException $e) {
            $this->assertInstanceOf(RateLimitException::class, $e);
            $this->assertNull($e->getRetryAfter());
        }

        $this->assertCount(1, $this->http->requests);
        $this->assertSame([], $this->sleeps);
    }

    public function testTheSitesRequestRateLimitStaysARateLimit(): void
    {
        $siren = $this->hostSiren([
            self::hostJson(429, ['error' => ['message' => 'Too many requests.', 'context' => ['retryAfter' => 12]]], ['Retry-After' => '12']),
        ]);

        try {
            $siren->credits->placeHold(4, 'STORE_CREDIT', 1000, 'tok', 'woocommerce');
            $this->fail('Expected a RateLimitException.');
        } catch (RateLimitException $e) {
            $this->assertNotInstanceOf(HoldLimitException::class, $e);
            $this->assertSame(12, $e->getRetryAfter());
        }
    }

    public function testHoldReferenceBuildsThePlainReference(): void
    {
        $this->assertSame('hold:edd:abc_123', Credits::holdReference('edd', 'abc_123'));

        $this->expectException(\InvalidArgumentException::class);

        Credits::holdReference('edd', 'a:b');
    }
}
