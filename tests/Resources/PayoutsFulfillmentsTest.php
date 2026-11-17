<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests\Resources;

use Siren\Sdk\Exception\ApiException;
use Siren\Sdk\Exception\ErrorCode;
use Siren\Sdk\Exception\NotFoundException;
use Siren\Sdk\Exception\StoreKeyNotAllowedException;
use Siren\Sdk\Http\HttpResponse;
use Siren\Sdk\ListResult;
use Siren\Sdk\Resources\Fulfillments;
use Siren\Sdk\Resources\ListResource;
use Siren\Sdk\Resources\Payouts;
use Siren\Sdk\Tests\TestCase;

/**
 * `payouts->retrieve()`, `fulfillments->list()` and `fulfillments->retrieve()`,
 * the reads the Node SDK gained for Siren apps.
 *
 * Siren's answers below follow its source at 20c47873d, each naming the file
 * it comes from, under lib/. None was captured from a running Siren.
 */
final class PayoutsFulfillmentsTest extends TestCase
{
    /**
     * `GET /payouts/{id}` with no `fields`: the thirteen DEFAULT_FIELDS of
     * Fulfillments/Rest/Service/GetPayoutById.php.
     */
    private const PAYOUT = [
        'id' => 31,
        'status' => 'unpaid',
        'value' => 12050,
        'currency' => 'USD',
        'collaboratorId' => 42,
        'fulfillmentId' => 7,
        'datePaid' => null,
        'dateCreated' => '2026-11-02T14:00:00+00:00',
        'dateModified' => '2026-11-03T09:30:00+00:00',
        'failureReason' => null,
        'dateFailed' => null,
        'waitingReason' => 'stripe_account_missing',
        'dateWaiting' => '2026-11-03T09:30:00+00:00',
    ];

    /**
     * A fulfillment's default fields, `id`, `status`, `dateCreated` and
     * `dateModified` (Fulfillments/Rest/Service/GetFulfillments.php and
     * GetFulfillmentById.php).
     */
    private const FULFILLMENT = [
        'id' => 7,
        'status' => 'pending',
        'dateCreated' => '2026-11-02T14:00:00+00:00',
        'dateModified' => '2026-11-02T14:00:00+00:00',
    ];

    /**
     * `include=extended` on GetFulfillmentById.php adds seven fields. Each
     * payout is PayoutAdapter.php `toArray` plus `collaboratorName`.
     */
    private const EXTENDED_FULFILLMENT = self::FULFILLMENT + [
        'payoutCount' => 1,
        'paidCount' => 0,
        'unpaidCount' => 1,
        'failedCount' => 0,
        'totalValue' => 12050,
        'currency' => 'USD',
        'payouts' => [[
            'id' => 31,
            'dateCreated' => '2026-11-02 14:00:00',
            'dateModified' => '2026-11-03 09:30:00',
            'value' => 12050,
            'status' => 'unpaid',
            'currency' => 'USD',
            'collaboratorId' => 42,
            'fulfillmentId' => 7,
            'datePaid' => null,
            'failureReason' => null,
            'dateFailed' => null,
            'waitingReason' => null,
            'dateWaiting' => null,
            'collaboratorName' => 'Ada Lovelace',
        ]],
    ];

    public function testTheClientCarriesBothResources(): void
    {
        $siren = $this->hostSiren([]);

        $this->assertInstanceOf(Payouts::class, $siren->payouts);
        $this->assertInstanceOf(ListResource::class, $siren->payouts);
        $this->assertInstanceOf(Fulfillments::class, $siren->fulfillments);
        $this->assertInstanceOf(ListResource::class, $siren->fulfillments);
    }

    public function testBothResourcesOnlyRead(): void
    {
        foreach ([Payouts::class, Fulfillments::class] as $class) {
            foreach (['create', 'update', 'delete', 'bulk'] as $write) {
                $this->assertFalse(method_exists($class, $write), sprintf('%s has no %s()', $class, $write));
            }
        }
    }

    public function testPayoutRetrieveReadsOnePayoutWithTheKey(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, self::PAYOUT)]);

        $payout = $siren->payouts->retrieve(31);

        $this->assertSame(['GET', '/siren/v1/payouts/31'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame([], $this->sentQuery());
        $this->assertSame('Bearer ' . self::API_KEY, $this->sent()->getHeader('Authorization'));
        $this->assertSame(self::PAYOUT, $payout);
    }

    public function testPayoutRetrieveSendsFieldsCommaSeparatedAndIncludeAsGiven(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, ['id' => 31, 'status' => 'paid'])]);

        $siren->payouts->retrieve(31, ['fields' => ['id', 'status'], 'include' => 'extended']);

        $this->assertSame(['fields' => 'id,status', 'include' => 'extended'], $this->sentQuery());
    }

    public function testPayoutRetrieveRaisesNotFoundForAnUnknownId(): void
    {
        $siren = $this->hostSiren([self::hostJson(404, ['error' => ['message' => 'Payout not found.', 'context' => []]])]);

        $this->expectException(NotFoundException::class);

        $siren->payouts->retrieve(999);
    }

    public function testPayoutRetrieveRaisesStoreKeyNotAllowedForAStoreKey(): void
    {
        $siren = $this->hostSiren([self::hostJson(403, ['error' => [
            'message' => 'This API key is bound to one store, and these records are not kept by store. Use an organization-wide key.',
            'code' => ErrorCode::STORE_KEY_NOT_ALLOWED,
            'context' => ['propertyId' => 5],
        ]])]);

        $this->expectException(StoreKeyNotAllowedException::class);

        $siren->payouts->retrieve(31);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function badIds(): array
    {
        return ['zero' => [0], 'negative' => [-4]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badIds')]
    public function testPayoutRetrieveRefusesABadIdBeforeSending(int $id): void
    {
        $siren = $this->hostSiren([]);

        try {
            $siren->payouts->retrieve($id);
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Record ids are positive whole numbers.', $e->getMessage());
        }

        $this->assertSame([], $this->http->requests);
    }

    public function testPayoutRetrieveRetriesAsARead(): void
    {
        $siren = $this->hostSiren([self::hostJson(503, ['error' => 'Down.']), self::hostJson(200, self::PAYOUT)]);

        $this->assertSame(self::PAYOUT, $siren->payouts->retrieve(31));
        $this->assertCount(2, $this->http->requests);
        $this->assertCount(1, $this->sleeps);
    }

    public function testPayoutListKeepsPassingEveryKeyThrough(): void
    {
        $siren = $this->hostSiren([self::hostPage([self::PAYOUT], 1)]);

        $page = $siren->payouts->list(['fulfillmentId' => 7, 'anything' => 'x']);

        $this->assertSame('/siren/v1/payouts', $this->sentPath());
        $this->assertSame(['fulfillmentId' => '7', 'anything' => 'x'], $this->sentQuery());
        $this->assertSame([self::PAYOUT], $page->getData());
    }

    public function testFulfillmentListSendsEveryFilterAndReadsTheEnvelope(): void
    {
        $siren = $this->hostSiren([self::hostPage([self::FULFILLMENT], 1)]);

        $page = $siren->fulfillments->list([
            'status' => ['pending', 'failed'],
            'payoutStatus' => 'failed',
            'currency' => 'USD',
            'from' => '2026-11-01',
            'to' => '2026-11-30',
            'orderBy' => 'dateCreated',
            'order' => 'ASC',
            'fields' => ['id', 'status'],
        ]);

        $this->assertSame(['GET', '/siren/v1/fulfillments'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame([
            'status' => 'pending,failed',
            'payoutStatus' => 'failed',
            'currency' => 'USD',
            'from' => '2026-11-01',
            'to' => '2026-11-30',
            'orderBy' => 'dateCreated',
            'order' => 'ASC',
            'fields' => 'id,status',
        ], $this->sentQuery());
        $this->assertInstanceOf(ListResult::class, $page);
        $this->assertSame([self::FULFILLMENT], $page->getData());
        $this->assertSame(1, $page->getTotal());
    }

    public function testFulfillmentListTurnsPageAndPerPageIntoNumberAndOffset(): void
    {
        $siren = $this->hostSiren([self::hostPage([], 0, 3, 25)]);

        $siren->fulfillments->list(['page' => 3, 'perPage' => 25]);

        $this->assertSame(['number' => '25', 'offset' => '50'], $this->sentQuery());
    }

    /**
     * @return array<string, array{0: string, 1: mixed, 2: string}>
     */
    public static function refusedListValues(): array
    {
        $empty = 'fulfillments->list() cannot send a %s of "", which Siren reads as no filter and answers every fulfillment.';
        $one = 'fulfillments->list() takes one %s as a string, since Siren matches it as one value.';
        $day = 'fulfillments->list() needs %s as a day, YYYY-MM-DD, and got %s.';

        return [
            'empty payoutStatus' => ['payoutStatus', '', sprintf($empty, 'payoutStatus')],
            'empty currency' => ['currency', '', sprintf($empty, 'currency')],
            'payoutStatus list' => ['payoutStatus', ['paid', 'failed'], sprintf($one, 'payoutStatus')],
            'currency list' => ['currency', ['USD'], sprintf($one, 'currency')],
            'currency number' => ['currency', 840, sprintf($one, 'currency')],
            'from not a day' => ['from', '2026-02-30', sprintf($day, 'from', '"2026-02-30"')],
            'to with a time' => ['to', '2026-11-30 10:00:00', sprintf($day, 'to', '"2026-11-30 10:00:00"')],
            'from empty' => ['from', '', sprintf($day, 'from', '""')],
            'to a timestamp' => ['to', 1764460800, sprintf($day, 'to', 'int')],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedListValues')]
    public function testFulfillmentListRefusesAValueSirenWouldMisreadBeforeSending(string $key, mixed $value, string $message): void
    {
        $siren = $this->hostSiren([]);

        try {
            $siren->fulfillments->list([$key => $value]);
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame($message, $e->getMessage());
        }

        $this->assertSame([], $this->http->requests);
    }

    public function testFulfillmentListLeavesANullFilterOut(): void
    {
        $siren = $this->hostSiren([self::hostPage([], 0)]);

        $siren->fulfillments->list(['payoutStatus' => null, 'from' => null, 'status' => 'pending']);

        $this->assertSame(['status' => 'pending'], $this->sentQuery());
    }

    public function testFulfillmentListAcceptsALeapDay(): void
    {
        $siren = $this->hostSiren([self::hostPage([], 0)]);

        $siren->fulfillments->list(['from' => '2028-02-29']);

        $this->assertSame(['from' => '2028-02-29'], $this->sentQuery());
    }

    public function testFulfillmentListRetriesAsARead(): void
    {
        $siren = $this->hostSiren([self::hostJson(429, ['error' => 'Slow down.']), self::hostPage([self::FULFILLMENT], 1)]);

        $this->assertSame([self::FULFILLMENT], $siren->fulfillments->list()->getData());
        $this->assertCount(2, $this->http->requests);
    }

    public function testFulfillmentRetrieveReadsOneFulfillment(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, self::FULFILLMENT)]);

        $this->assertSame(self::FULFILLMENT, $siren->fulfillments->retrieve(7));
        $this->assertSame(['GET', '/siren/v1/fulfillments/7'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame([], $this->sentQuery());
    }

    public function testFulfillmentRetrieveAnswersEachPayoutWhenExtended(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, self::EXTENDED_FULFILLMENT)]);

        $fulfillment = $siren->fulfillments->retrieve(7, ['include' => 'extended']);

        $this->assertSame(['include' => 'extended'], $this->sentQuery());
        $this->assertSame(self::EXTENDED_FULFILLMENT, $fulfillment);
    }

    public function testFulfillmentRetrieveRaisesNotFoundForAnUnknownId(): void
    {
        $siren = $this->hostSiren([self::hostJson(404, ['error' => ['message' => 'Fulfillment not found.', 'context' => []]])]);

        $this->expectException(NotFoundException::class);

        $siren->fulfillments->retrieve(999);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badIds')]
    public function testFulfillmentRetrieveRefusesABadIdBeforeSending(int $id): void
    {
        $siren = $this->hostSiren([]);

        $this->expectException(\InvalidArgumentException::class);

        try {
            $siren->fulfillments->retrieve($id);
        } finally {
            $this->assertSame([], $this->http->requests);
        }
    }

    public function testFulfillmentRetrieveRetriesAsARead(): void
    {
        $siren = $this->hostSiren([self::hostJson(502, ['error' => 'Bad gateway.']), self::hostJson(200, self::FULFILLMENT)]);

        $this->assertSame(self::FULFILLMENT, $siren->fulfillments->retrieve(7));
        $this->assertCount(2, $this->http->requests);
    }

    public function testAReadGivesUpAfterMaxRetries(): void
    {
        $siren = $this->hostSiren(
            [self::hostJson(503, ['error' => 'Down.']), self::hostJson(503, ['error' => 'Down.'])],
            ['maxRetries' => 1],
        );

        $this->expectException(ApiException::class);

        try {
            $siren->fulfillments->retrieve(7);
        } finally {
            $this->assertCount(2, $this->http->requests);
        }
    }

    public function testANonJsonAnswerIsAnEmptyArrayAsEveryPhpReadAnswersIt(): void
    {
        $siren = $this->hostSiren([new HttpResponse(200, ['Content-Type' => 'text/html'], '<html>proxy</html>')]);

        $this->assertSame([], $siren->payouts->retrieve(31));
    }
}
