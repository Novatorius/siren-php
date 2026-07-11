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

    private static function payload(): string
    {
        return json_encode([
            'type' => 'conversion.approved',
            'data' => ['id' => 501, 'amount' => 12.5],
            'deliveryId' => 'dlv_abc123',
        ], JSON_THROW_ON_ERROR);
    }

    public function testConstructEventReturnsAVerifiedEvent(): void
    {
        $siren = $this->mockSiren([]);
        $body = self::payload();

        $event = $siren->webhooks->constructEvent($body, self::sign($body), self::SECRET);

        $this->assertInstanceOf(WebhookEvent::class, $event);
        $this->assertSame(WebhookEventType::CONVERSION_APPROVED, $event->getType());
        $this->assertSame(['id' => 501, 'amount' => 12.5], $event->getData());
        $this->assertSame('dlv_abc123', $event->getDeliveryId());
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
        $this->assertCount(28, $constants, 'Expected 27 event types plus ALL.');

        foreach ($constants as $name => $value) {
            if ($name === 'ALL') {
                continue;
            }

            $this->assertMatchesRegularExpression('/^[a-z]+\.[a-z]+$/', $value);
        }
    }
}
