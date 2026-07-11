<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use GuzzleHttp\RequestOptions;
use Siren\Sdk\Siren;

final class SirenClientTest extends TestCase
{
    public function testRequiresAnApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('apiKey');

        new Siren([]);
    }

    public function testRejectsAnEmptyApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Siren(['apiKey' => '']);
    }

    public function testSendsBearerAuthorizationHeader(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200)]);

        $siren->events->sale([
            'source' => 'stripe',
            'externalId' => 'order-1',
            'total' => 9.99,
            'trackingId' => 42,
        ]);

        $this->assertSame('Bearer ' . self::API_KEY, $this->lastRequest()->getHeaderLine('Authorization'));
    }

    public function testSendsJsonContentTypeAndUserAgent(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200)]);

        $siren->events->refund(['source' => 'stripe', 'externalId' => 'order-1']);

        $request = $this->lastRequest();
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertSame('siren-php-sdk/0.1.0', $request->getHeaderLine('User-Agent'));
    }

    public function testDefaultsToTheProductionBaseUrl(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, [])]);

        $siren->conversions->list();

        $this->assertSame(
            'https://api.sirenaffiliates.com/siren/v1/conversions',
            (string) $this->lastRequest()->getUri(),
        );
    }

    public function testHonorsACustomBaseUrlAndTrimsTrailingSlash(): void
    {
        $siren = $this->mockSiren(
            [self::jsonResponse(200, [])],
            ['baseUrl' => 'http://localhost:8080/siren/v1/'],
        );

        $siren->payouts->list();

        $this->assertSame(
            'http://localhost:8080/siren/v1/payouts',
            (string) $this->lastRequest()->getUri(),
        );
    }

    public function testPassesTimeoutToTheHttpClient(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, [])], ['timeout' => 5]);

        $siren->conversions->list();

        $options = $this->history[0]['options'];
        $this->assertSame(5.0, $options[RequestOptions::TIMEOUT]);
        $this->assertSame(5.0, $options[RequestOptions::CONNECT_TIMEOUT]);
    }

    public function testDefaultTimeoutIsThirtySeconds(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, [])]);

        $siren->conversions->list();

        $this->assertSame(30.0, $this->history[0]['options'][RequestOptions::TIMEOUT]);
    }

    public function testExposesAllNamespaces(): void
    {
        $siren = $this->mockSiren([]);

        $this->assertInstanceOf(\Siren\Sdk\Resources\Events::class, $siren->events);
        $this->assertInstanceOf(\Siren\Sdk\Resources\Webhooks::class, $siren->webhooks);
        $this->assertInstanceOf(\Siren\Sdk\Resources\WebhookSubscriptions::class, $siren->webhooks->subscriptions);
        $this->assertInstanceOf(\Siren\Sdk\Resources\ApiKeys::class, $siren->apiKeys);
        $this->assertInstanceOf(\Siren\Sdk\Resources\ListResource::class, $siren->conversions);
        $this->assertInstanceOf(\Siren\Sdk\Resources\ListResource::class, $siren->transactions);
        $this->assertInstanceOf(\Siren\Sdk\Resources\ListResource::class, $siren->obligations);
        $this->assertInstanceOf(\Siren\Sdk\Resources\ListResource::class, $siren->payouts);
    }
}
