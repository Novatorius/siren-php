<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use Siren\Sdk\Exception\ApiException;
use Siren\Sdk\Exception\SignatureVerificationException;
use Siren\Sdk\WebhookEvent;
use Siren\Sdk\WebhookEventType;

final class WebhooksTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    private static function sign(string $body, string $secret = self::SECRET): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    /**
     * A delivery body as Siren's WebhookDeliveryService sends it to a
     * subscription with no Property: the event payload alone. The type and
     * delivery id travel in headers.
     */
    private static function payload(): string
    {
        return json_encode(['conversionId' => 501, 'amount' => 12.5], JSON_THROW_ON_ERROR);
    }

    public function testConstructEventReturnsAVerifiedEvent(): void
    {
        $siren = $this->mockSiren([]);
        $body = self::payload();

        $event = $siren->webhooks->constructEvent($body, self::sign($body), self::SECRET, 'conversion.approved', 'evt_abc123');

        $this->assertInstanceOf(WebhookEvent::class, $event);
        $this->assertSame(WebhookEventType::CONVERSION_APPROVED, $event->getType());
        $this->assertSame(['conversionId' => 501, 'amount' => 12.5], $event->getData());
        $this->assertSame('evt_abc123', $event->getDeliveryId());
    }

    /**
     * Code written for 0.2.0 calls constructEvent() with three arguments. A
     * flat body names no event, so the SDK refuses it instead of returning
     * an event with an empty type that no handler matches.
     */
    public function testConstructEventRefusesAFlatBodyWithoutTheEventHeader(): void
    {
        $siren = $this->mockSiren([]);
        $body = self::payload();

        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessage('X-Siren-Event');

        $siren->webhooks->constructEvent($body, self::sign($body), self::SECRET);
    }

    public function testConstructEventRefusesABlankEventHeader(): void
    {
        $siren = $this->mockSiren([]);
        $body = self::payload();

        $this->expectException(SignatureVerificationException::class);

        $siren->webhooks->constructEvent($body, self::sign($body), self::SECRET, '  ', 'evt_1');
    }

    /**
     * Only an envelope carries `version`. A flat payload with its own `type`
     * and `data` keys is still the payload, and still needs the header.
     */
    public function testAFlatBodyWithTypeAndDataKeysIsNotAnEnvelope(): void
    {
        $siren = $this->mockSiren([]);
        $body = json_encode(['type' => 'points', 'data' => ['note' => 'x']], JSON_THROW_ON_ERROR);

        $event = $siren->webhooks->constructEvent($body, self::sign($body), self::SECRET, 'currency.created', 'evt_9');

        $this->assertSame(WebhookEventType::CURRENCY_CREATED, $event->getType());
        $this->assertSame(['type' => 'points', 'data' => ['note' => 'x']], $event->getData());

        $this->expectException(SignatureVerificationException::class);
        $siren->webhooks->constructEvent($body, self::sign($body), self::SECRET);
    }

    /** An envelope names its own type and id, so it needs no headers. */
    public function testAnEnvelopeNeedsNoHeaders(): void
    {
        $siren = $this->mockSiren([]);
        $body = json_encode([
            'version' => 1,
            'id' => 'evt_test',
            'type' => 'webhook.test',
            'kind' => 'test',
            'data' => ['nonce' => 'abc'],
            'records' => new \stdClass(),
        ], JSON_THROW_ON_ERROR);

        $event = $siren->webhooks->constructEvent($body, self::sign($body), self::SECRET);

        $this->assertSame('webhook.test', $event->getType());
        $this->assertSame(['nonce' => 'abc'], $event->getData());
        $this->assertSame('evt_test', $event->getDeliveryId());
    }

    public function testConstructEventRejectsATamperedBody(): void
    {
        $siren = $this->mockSiren([]);
        $body = self::payload();
        $signature = self::sign($body);
        $tampered = str_replace('12.5', '9912.5', $body);

        $this->expectException(SignatureVerificationException::class);

        $siren->webhooks->constructEvent($tampered, $signature, self::SECRET);
    }

    public function testConstructEventRejectsAMissingSignature(): void
    {
        $siren = $this->mockSiren([]);

        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessage('No signature header');

        $siren->webhooks->constructEvent(self::payload(), null, self::SECRET);
    }

    public function testConstructEventRejectsAnEmptySignature(): void
    {
        $siren = $this->mockSiren([]);

        $this->expectException(SignatureVerificationException::class);

        $siren->webhooks->constructEvent(self::payload(), '', self::SECRET);
    }

    public function testConstructEventRejectsTheWrongSecret(): void
    {
        $siren = $this->mockSiren([]);
        $body = self::payload();

        $this->expectException(SignatureVerificationException::class);

        $siren->webhooks->constructEvent($body, self::sign($body, 'other_secret'), self::SECRET);
    }

    public function testConstructEventRejectsNonJsonBodies(): void
    {
        $siren = $this->mockSiren([]);
        $body = 'not json';

        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessage('not valid JSON');

        $siren->webhooks->constructEvent($body, self::sign($body), self::SECRET);
    }

    public function testVerifySignatureAcceptsAValidSignature(): void
    {
        $siren = $this->mockSiren([]);
        $body = self::payload();

        $this->assertTrue($siren->webhooks->verifySignature($body, self::sign($body), self::SECRET));
    }

    public function testVerifySignatureRejectsTamperingMissingAndMalformedHeaders(): void
    {
        $siren = $this->mockSiren([]);
        $body = self::payload();

        $this->assertFalse($siren->webhooks->verifySignature($body . 'x', self::sign($body), self::SECRET));
        $this->assertFalse($siren->webhooks->verifySignature($body, null, self::SECRET));
        $this->assertFalse($siren->webhooks->verifySignature($body, '', self::SECRET));
        $this->assertFalse(
            $siren->webhooks->verifySignature($body, hash_hmac('sha256', $body, self::SECRET), self::SECRET),
            'A header without the sha256= prefix must be rejected.',
        );
        $this->assertFalse($siren->webhooks->verifySignature($body, 'sha256=deadbeef', self::SECRET));
    }

    public function testVerifySignatureIsCaseInsensitiveOnTheHexDigest(): void
    {
        $siren = $this->mockSiren([]);
        $body = self::payload();
        $upper = 'sha256=' . strtoupper(hash_hmac('sha256', $body, self::SECRET));

        $this->assertTrue($siren->webhooks->verifySignature($body, $upper, self::SECRET));
    }

    public function testSubscriptionCreatePostsAndReturnsTheSigningSecret(): void
    {
        $siren = $this->mockSiren([
            self::jsonResponse(201, [
                'id' => 12,
                'targetUrl' => 'https://example.com/webhooks/siren',
                'events' => ['conversion.approved', 'payout.paid'],
                'status' => 'active',
                'signingSecret' => 'whsec_only_returned_once',
            ]),
        ]);

        $subscription = $siren->webhooks->subscriptions->create([
            'targetUrl' => 'https://example.com/webhooks/siren',
            'events' => [WebhookEventType::CONVERSION_APPROVED, WebhookEventType::PAYOUT_PAID],
        ]);

        $request = $this->lastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.sirenaffiliates.com/siren/v1/webhooks', (string) $request->getUri());
        $this->assertSame(
            [
                'targetUrl' => 'https://example.com/webhooks/siren',
                'events' => ['conversion.approved', 'payout.paid'],
            ],
            $this->lastRequestJson(),
        );
        $this->assertSame('whsec_only_returned_once', $subscription['signingSecret']);
    }

    public function testSubscriptionCreateIsNeverAutoRetried(): void
    {
        $siren = $this->mockSiren([
            self::errorResponse(500, 'boom'),
            self::jsonResponse(201, ['id' => 1]),
        ]);

        try {
            $siren->webhooks->subscriptions->create([
                'targetUrl' => 'https://example.com/hook',
                'events' => [WebhookEventType::ALL],
            ]);
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(500, $e->getStatusCode());
        }

        $this->assertCount(1, $this->history, 'A failed create must not be retried.');
        $this->assertSame([], $this->sleeps, 'No backoff sleep should have happened.');
    }

    public function testSubscriptionCreateRequiresTargetUrlAndEvents(): void
    {
        $siren = $this->mockSiren([]);

        $this->expectException(\InvalidArgumentException::class);

        $siren->webhooks->subscriptions->create(['targetUrl' => 'https://example.com/hook']);
    }

    public function testSubscriptionListReturnsTheBareJsonArray(): void
    {
        $siren = $this->mockSiren([
            self::jsonResponse(200, [['id' => 1], ['id' => 2]]),
        ]);

        $subscriptions = $siren->webhooks->subscriptions->list();

        $request = $this->lastRequest();
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('https://api.sirenaffiliates.com/siren/v1/webhooks', (string) $request->getUri());
        $this->assertSame([['id' => 1], ['id' => 2]], $subscriptions);
    }

    public function testSubscriptionDeleteTargetsTheId(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200)]);

        $siren->webhooks->subscriptions->delete(12);

        $request = $this->lastRequest();
        $this->assertSame('DELETE', $request->getMethod());
        $this->assertSame('https://api.sirenaffiliates.com/siren/v1/webhooks/12', (string) $request->getUri());
    }

    public function testEventTypeCatalogIsComplete(): void
    {
        $constants = (new \ReflectionClass(WebhookEventType::class))->getConstants();

        $this->assertSame('*', $constants['ALL']);
        // The full canonical set is pinned in TaxonomyTest.
        $this->assertCount(35, $constants, 'Expected 34 event types plus ALL.');

        foreach ($constants as $name => $value) {
            if ($name === 'ALL') {
                continue;
            }

            $this->assertMatchesRegularExpression('/^[a-z]+\.[a-z]+$/', $value);
        }
    }

    /**
     * A subscription made through POST /webhooks receives the event payload
     * as the body, with the type in X-Siren-Event and the delivery id in
     * X-Siren-Delivery (Siren's WebhookDeliveryService).
     */
    public function testConstructEventReadsAFlatPayloadWithItsHeaders(): void
    {
        $siren = $this->mockSiren([]);
        $body = json_encode(['conversionId' => 501, 'collaboratorId' => 42, 'status' => 'approved'], JSON_THROW_ON_ERROR);

        $event = $siren->webhooks->constructEvent($body, self::sign($body), self::SECRET, 'conversion.approved', 'evt_8a3f1b2c');

        $this->assertSame(WebhookEventType::CONVERSION_APPROVED, $event->getType());
        $this->assertSame(['conversionId' => 501, 'collaboratorId' => 42, 'status' => 'approved'], $event->getData());
        $this->assertSame('evt_8a3f1b2c', $event->getDeliveryId());
    }

    /** A flat payload may hold its own `type` field, such as currency.created. */
    public function testAFlatPayloadWithATypeFieldKeepsTheHeaderType(): void
    {
        $siren = $this->mockSiren([]);
        $body = json_encode(['code' => 'PTS', 'type' => 'points'], JSON_THROW_ON_ERROR);

        $event = $siren->webhooks->constructEvent($body, self::sign($body), self::SECRET, 'currency.created', 'evt_1');

        $this->assertSame(WebhookEventType::CURRENCY_CREATED, $event->getType());
        $this->assertSame(['code' => 'PTS', 'type' => 'points'], $event->getData());
    }

    public function testConstructEventFromHeadersReadsAllThreeHeaders(): void
    {
        $siren = $this->mockSiren([]);
        $body = json_encode(['payoutId' => 9], JSON_THROW_ON_ERROR);

        $event = $siren->webhooks->constructEventFromHeaders($body, [
            'content-type' => 'application/json',
            'x-siren-signature' => self::sign($body),
            'X-Siren-Event' => ['payout.paid'],
            'X-SIREN-DELIVERY' => 'evt_42',
        ], self::SECRET);

        $this->assertSame(WebhookEventType::PAYOUT_PAID, $event->getType());
        $this->assertSame(['payoutId' => 9], $event->getData());
        $this->assertSame('evt_42', $event->getDeliveryId());
        $this->assertSame(['payoutId' => 9], $event->getBody());
    }

    public function testConstructEventFromHeadersRejectsAMissingSignature(): void
    {
        $siren = $this->mockSiren([]);

        $this->expectException(SignatureVerificationException::class);

        $siren->webhooks->constructEventFromHeaders('{}', ['X-Siren-Event' => 'sale.created'], self::SECRET);
    }

    /** A connected site's Property-bound subscription receives an envelope. */
    public function testConstructEventReadsAPropertyEnvelope(): void
    {
        $siren = $this->mockSiren([]);
        $envelope = [
            'version' => 1,
            'id' => 'evt_envelope',
            'type' => 'sale.created',
            'kind' => 'store',
            'occurredAt' => '2026-10-18T12:00:00+00:00',
            'propertyId' => 5,
            'match' => 'origin',
            'originPropertyId' => 5,
            'data' => ['transactionId' => 77],
            'records' => ['transaction' => ['id' => 77]],
            'recordDetail' => 'full',
            'omittedRecords' => [],
            'recordsTruncated' => false,
        ];
        $body = json_encode($envelope, JSON_THROW_ON_ERROR);

        $event = $siren->webhooks->constructEvent($body, self::sign($body), self::SECRET, 'sale.created', 'evt_envelope');

        $this->assertSame(WebhookEventType::SALE_CREATED, $event->getType());
        $this->assertSame(['transactionId' => 77], $event->getData());
        $this->assertSame('evt_envelope', $event->getDeliveryId());
        $this->assertSame(5, $event->getBody()['propertyId']);
    }

    public function testSubscriptionEventsReadsTheCatalog(): void
    {
        $catalog = [
            ['event' => 'sale.created', 'category' => 'Sales', 'label' => 'A sale was recorded'],
            ['event' => 'program.created', 'category' => 'Programs', 'label' => 'A program was created'],
        ];
        $siren = $this->mockSiren([self::jsonResponse(200, $catalog)]);

        $this->assertSame($catalog, $siren->webhooks->subscriptions->events());
        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('https://api.sirenaffiliates.com/siren/v1/webhooks/events', (string) $this->lastRequest()->getUri());
    }
}
