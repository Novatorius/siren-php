<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests\Resources;

use Siren\Sdk\Exception\ApiException;
use Siren\Sdk\Exception\BadRequestException;
use Siren\Sdk\Exception\ConflictException;
use Siren\Sdk\Exception\NotFoundException;
use Siren\Sdk\Exception\RateLimitException;
use Siren\Sdk\Resources\WebhookSubscriptions;
use Siren\Sdk\Tests\TestCase;

/**
 * `webhooks->subscriptions->retrieve()`, `update()`, `test()` and
 * `deliveries()`, the subscription calls the Node SDK gained for Siren apps.
 *
 * Siren's answers below follow its source at 20c47873d, under
 * lib/Webhooks. A subscription is WebhookSubscriptionAdapter.php `toArray`,
 * which leaves out the signing secret. None was captured from a running
 * Siren.
 */
final class WebhookSubscriptionCallsTest extends TestCase
{
    /** Service/Rest/GetWebhookSubscription.php, status 200. */
    private const SUBSCRIPTION = [
        'id' => 12,
        'targetUrl' => 'https://hooks.example.com/siren',
        'urlValid' => true,
        'urlProblem' => null,
        'events' => ['payout.paid', 'payout.failed'],
        'status' => 'active',
        'description' => 'Payouts to the ledger',
        'propertyId' => null,
        'oauthClientId' => null,
        'dateCreated' => '2026-11-01 08:00:00',
        'dateModified' => '2026-11-01 08:00:00',
    ];

    /** Service/Rest/SendWebhookTest.php, status 200, when the endpoint answered 2xx. */
    private const TEST_DELIVERED = ['delivered' => true, 'httpStatus' => 200, 'error' => null, 'eventType' => 'webhook.test'];

    /** Service/Rest/SendWebhookTest.php, status 200, when the endpoint answered 500. */
    private const TEST_FAILED = ['delivered' => false, 'httpStatus' => 500, 'error' => null, 'eventType' => 'webhook.test'];

    /** Service/Rest/ListWebhookDeliveries.php, status 200: a bare list, newest first, at most 50. */
    private const DELIVERIES = [
        [
            'id' => 902,
            'webhookSubscriptionId' => 12,
            'eventType' => 'webhook.test',
            'eventId' => 'evt_8a3f1b2c4d5e6f7890abcdef12345678',
            'payload' => ['test' => true, 'webhookId' => 12],
            'status' => 'delivered',
            'attemptCount' => 1,
            'httpStatusCode' => 200,
            'responseBody' => 'ok',
            'errorMessage' => null,
            'nextRetryAt' => null,
            'deliveredAt' => '2026-11-16 21:05:00',
            'dateCreated' => '2026-11-16 21:05:00',
            'dateModified' => '2026-11-16 21:05:00',
        ],
        [
            'id' => 901,
            'webhookSubscriptionId' => 12,
            'eventType' => 'payout.paid',
            'eventId' => 'evt_11112222333344445555666677778888',
            'payload' => ['payoutId' => 31, 'value' => 12050, 'currency' => 'USD'],
            'status' => 'failed',
            'attemptCount' => 2,
            'httpStatusCode' => 502,
            'responseBody' => 'Bad gateway',
            'errorMessage' => 'HTTP 502',
            'nextRetryAt' => '2026-11-16 21:07:00',
            'deliveredAt' => null,
            'dateCreated' => '2026-11-16 21:04:00',
            'dateModified' => '2026-11-16 21:05:00',
        ],
    ];

    private static function notFound(): \Siren\Sdk\Http\HttpResponse
    {
        return self::hostJson(404, ['error' => ['message' => 'Webhook subscription not found.', 'context' => []]]);
    }

    public function testRetrieveReadsOneSubscription(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, self::SUBSCRIPTION)]);

        $subscription = $siren->webhooks->subscriptions->retrieve(12);

        $this->assertSame(['GET', '/siren/v1/webhooks/12'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame([], $this->sentQuery());
        $this->assertNull($this->sent()->getBody());
        $this->assertSame(self::SUBSCRIPTION, $subscription);
        $this->assertArrayNotHasKey('signingSecret', $subscription);
    }

    public function testRetrieveRaisesNotFoundForAnUnknownId(): void
    {
        $siren = $this->hostSiren([self::notFound()]);

        $this->expectException(NotFoundException::class);

        $siren->webhooks->subscriptions->retrieve(999);
    }

    public function testRetrieveRetriesAsARead(): void
    {
        $siren = $this->hostSiren([self::hostJson(503, ['error' => 'Down.']), self::hostJson(200, self::SUBSCRIPTION)]);

        $this->assertSame(self::SUBSCRIPTION, $siren->webhooks->subscriptions->retrieve(12));
        $this->assertCount(2, $this->http->requests);
    }

    public function testUpdateNamesTheFieldsTheRouteReads(): void
    {
        $this->assertSame(['targetUrl', 'events', 'status', 'description'], WebhookSubscriptions::UPDATE_FIELDS);
    }

    public function testUpdatePutsOnlyTheGivenFieldsAndAnswersTheSubscription(): void
    {
        $paused = ['status' => 'paused'] + self::SUBSCRIPTION;
        $siren = $this->hostSiren([self::hostJson(200, $paused)]);

        $subscription = $siren->webhooks->subscriptions->update(12, ['status' => 'paused']);

        $this->assertSame(['PUT', '/siren/v1/webhooks/12'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame(['status' => 'paused'], $this->sentJson());
        $this->assertSame($paused, $subscription);
    }

    public function testUpdateSendsEveryFieldItReads(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, self::SUBSCRIPTION)]);

        $siren->webhooks->subscriptions->update(12, [
            'targetUrl' => 'https://hooks.example.com/v2',
            'events' => ['payout.paid'],
            'status' => 'active',
            'description' => 'Paid payouts only',
        ]);

        $this->assertSame([
            'targetUrl' => 'https://hooks.example.com/v2',
            'events' => ['payout.paid'],
            'status' => 'active',
            'description' => 'Paid payouts only',
        ], $this->sentJson());
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function unreadFields(): array
    {
        return [
            'signingSecret' => ['signingSecret', 'whsec_x'],
            'propertyId' => ['propertyId', 5],
            'id' => ['id', 12],
            'a null one' => ['url', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unreadFields')]
    public function testUpdateRefusesAFieldTheRouteDoesNotReadBeforeSending(string $field, mixed $value): void
    {
        $siren = $this->hostSiren([]);

        try {
            $siren->webhooks->subscriptions->update(12, ['status' => 'active', $field => $value]);
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(
                sprintf('webhooks->subscriptions->update() cannot send "%s". Siren\'s update route reads only targetUrl, events, status and description.', $field),
                $e->getMessage(),
            );
        }

        $this->assertSame([], $this->http->requests);
    }

    /**
     * UpdateWebhookSubscription.php skips a null field, so each of these
     * leaves Siren nothing to set.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function updatesWithNothingToChange(): array
    {
        return [
            'no field' => [[]],
            'one null field' => [['description' => null]],
            'every field null' => [['targetUrl' => null, 'events' => null, 'status' => null, 'description' => null]],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('updatesWithNothingToChange')]
    public function testUpdateRefusesAnUpdateWithNothingToChangeBeforeSending(array $params): void
    {
        $siren = $this->hostSiren([]);

        try {
            $siren->webhooks->subscriptions->update(12, $params);
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(
                'webhooks->subscriptions->update() needs at least one of targetUrl, events, status or description that is not null. Siren skips a null field and cannot apply an update with nothing to change.',
                $e->getMessage(),
            );
        }

        $this->assertSame([], $this->http->requests);
    }

    public function testUpdateSendsANullFieldBesideOneThatChanges(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, self::SUBSCRIPTION)]);

        $siren->webhooks->subscriptions->update(12, ['status' => 'active', 'description' => null]);

        $this->assertSame(['status' => 'active', 'description' => null], $this->sentJson());
    }

    public function testUpdateRaisesBadRequestForATargetSirenRefuses(): void
    {
        $siren = $this->hostSiren([self::hostJson(400, ['error' => ['message' => 'The target URL must be a public https endpoint.', 'context' => []]])]);

        $this->expectException(BadRequestException::class);

        $siren->webhooks->subscriptions->update(12, ['targetUrl' => 'http://localhost/hook']);
    }

    public function testUpdateRaisesNotFoundForAnUnknownId(): void
    {
        $siren = $this->hostSiren([self::notFound()]);

        $this->expectException(NotFoundException::class);

        $siren->webhooks->subscriptions->update(999, ['status' => 'active']);
    }

    public function testUpdateIsSentOnce(): void
    {
        $siren = $this->hostSiren([self::hostJson(503, ['error' => 'Down.']), self::hostJson(200, self::SUBSCRIPTION)]);

        $this->expectException(ApiException::class);

        try {
            $siren->webhooks->subscriptions->update(12, ['status' => 'active']);
        } finally {
            $this->assertCount(1, $this->http->requests);
            $this->assertSame([], $this->sleeps);
        }
    }

    public function testTestPostsWithNoBodyAndAnswersWhatTheEndpointDid(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, self::TEST_DELIVERED)]);

        $result = $siren->webhooks->subscriptions->test(12);

        $this->assertSame(['POST', '/siren/v1/webhooks/12/test'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertNull($this->sent()->getBody());
        $this->assertSame(self::TEST_DELIVERED, $result);
    }

    public function testTestAnswersAFailedDeliveryAsSirenDoesWithA200(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, self::TEST_FAILED)]);

        $result = $siren->webhooks->subscriptions->test(12);

        $this->assertFalse($result['delivered']);
        $this->assertSame(500, $result['httpStatus']);
    }

    public function testTestRaisesConflictForAPausedSubscription(): void
    {
        $siren = $this->hostSiren([self::hostJson(409, ['error' => ['message' => 'This webhook is paused. Resume it, then send a test.', 'context' => []]])]);

        $this->expectException(ConflictException::class);

        $siren->webhooks->subscriptions->test(12);
    }

    public function testTestRaisesRateLimitPastTheHourlyLimitWithoutRetrying(): void
    {
        $siren = $this->hostSiren([
            self::hostJson(429, ['error' => 'Too many test deliveries.'], ['Retry-After' => '3600']),
            self::hostJson(200, self::TEST_DELIVERED),
        ]);

        $this->expectException(RateLimitException::class);

        try {
            $siren->webhooks->subscriptions->test(12);
        } finally {
            $this->assertCount(1, $this->http->requests);
            $this->assertSame([], $this->sleeps);
        }
    }

    public function testTestIsSentOnceSinceEachTestMakesSirenSendARequest(): void
    {
        $siren = $this->hostSiren([new \Siren\Sdk\Http\HttpClientException('Connection reset.'), self::hostJson(200, self::TEST_DELIVERED)]);

        $this->expectException(\Siren\Sdk\Exception\ConnectionException::class);

        try {
            $siren->webhooks->subscriptions->test(12);
        } finally {
            $this->assertCount(1, $this->http->requests);
        }
    }

    public function testDeliveriesReadsTheBareListNewestFirst(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, self::DELIVERIES)]);

        $deliveries = $siren->webhooks->subscriptions->deliveries(12);

        $this->assertSame(['GET', '/siren/v1/webhooks/12/deliveries'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame([], $this->sentQuery());
        $this->assertSame(self::DELIVERIES, $deliveries);
    }

    public function testDeliveriesAnswersAnEmptyListForASubscriptionWithNone(): void
    {
        $siren = $this->hostSiren([new \Siren\Sdk\Http\HttpResponse(200, ['Content-Type' => 'application/json'], '[]')]);

        $this->assertSame([], $siren->webhooks->subscriptions->deliveries(12));
    }

    public function testDeliveriesDropsARowThatIsNotAnObjectAsEventsDoes(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, [self::DELIVERIES[0], 'oops', 7, self::DELIVERIES[1]])]);

        $this->assertSame(self::DELIVERIES, $siren->webhooks->subscriptions->deliveries(12));
    }

    public function testDeliveriesRaisesNotFoundForAnUnknownId(): void
    {
        $siren = $this->hostSiren([self::notFound()]);

        $this->expectException(NotFoundException::class);

        $siren->webhooks->subscriptions->deliveries(999);
    }

    public function testDeliveriesRetriesAsARead(): void
    {
        $siren = $this->hostSiren([new \Siren\Sdk\Http\HttpClientException('Connection reset.'), self::hostJson(200, self::DELIVERIES)]);

        $this->assertSame(self::DELIVERIES, $siren->webhooks->subscriptions->deliveries(12));
        $this->assertCount(2, $this->http->requests);
    }

    /**
     * @return array<string, array{0: string, 1: list<mixed>}>
     */
    public static function callsTakingAnId(): array
    {
        return [
            'retrieve, zero' => ['retrieve', [0]],
            'retrieve, negative' => ['retrieve', [-1]],
            'update' => ['update', [0, ['status' => 'active']]],
            'test' => ['test', [0]],
            'deliveries' => ['deliveries', [-12]],
        ];
    }

    /**
     * @param list<mixed> $arguments
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('callsTakingAnId')]
    public function testEachCallRefusesAnIdThatIsNotPositiveBeforeSending(string $method, array $arguments): void
    {
        $siren = $this->hostSiren([]);

        try {
            $siren->webhooks->subscriptions->{$method}(...$arguments);
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Record ids are positive whole numbers.', $e->getMessage());
        }

        $this->assertSame([], $this->http->requests);
    }
}
