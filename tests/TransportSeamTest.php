<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Siren\Sdk\Exception\ConnectionException;
use Siren\Sdk\Exception\NotFoundException;
use Siren\Sdk\Exception\RateLimitException;
use Siren\Sdk\Http\GuzzleHttpClient;
use Siren\Sdk\Http\HttpClientException;
use Siren\Sdk\Http\HttpRequest;
use Siren\Sdk\Http\HttpResponse;
use Siren\Sdk\Siren;

/**
 * The host-supplied HTTP seam: a connector (WordPress, Drupal, ...) passes
 * its own HttpClient and the SDK sends every request through it.
 */
final class TransportSeamTest extends TestCase
{
    public function testAHostClientReceivesAFullyBuiltWriteRequest(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, [], ['X-Siren-OID' => '31'])], ['timeout' => 12]);

        $result = $siren->events->sale([
            'source' => 'woocommerce',
            'externalId' => '1001',
            'total' => 19.5,
            'trackingId' => 31,
        ]);

        $request = $this->sent();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.sirenaffiliates.com/siren/v1/event/sale', $request->getUrl());
        $this->assertSame('Bearer ' . self::API_KEY, $request->getHeader('Authorization'));
        $this->assertSame('application/json', $request->getHeader('Content-Type'));
        $this->assertSame('application/json', $request->getHeader('Accept'));
        $this->assertSame('siren-php-sdk/0.3.0', $request->getHeader('User-Agent'));
        $this->assertSame(12.0, $request->getTimeout());
        $this->assertSame(
            ['source' => 'woocommerce', 'externalId' => '1001', 'total' => 19.5, 'trackingId' => 31],
            $this->sentJson(),
        );
        $this->assertSame(31, $result->getOpportunityId());
    }

    public function testAReadCarriesItsQueryInTheUrlAndNoBody(): void
    {
        $siren = $this->hostSiren([self::hostPage([['id' => 1]], 1)]);

        $siren->collaborators->list(['status' => 'active', 'fields' => ['id', 'email']]);

        $request = $this->sent();
        $this->assertSame('GET', $request->getMethod());
        $this->assertNull($request->getBody());
        $this->assertNull($request->getHeader('Content-Type'));
        $this->assertSame('/siren/v1/collaborators', $this->sentPath());
        $this->assertSame(['status' => 'active', 'fields' => 'id,email'], $this->sentQuery());
    }

    public function testResponseHeadersAreReadCaseInsensitively(): void
    {
        $siren = $this->hostSiren([new HttpResponse(200, ['x-siren-oid' => ['55']], '')]);

        $result = $siren->events->siteVisited(['collaboratorId' => 4]);

        $this->assertSame(55, $result->getOpportunityId());
    }

    public function testErrorStatusesFromAHostClientMapToTypedExceptions(): void
    {
        $siren = $this->hostSiren([self::hostJson(404, ['error' => 'No record found with that ID.'])]);

        try {
            $siren->programs->retrieve(9);
            $this->fail('Expected a NotFoundException.');
        } catch (NotFoundException $e) {
            $this->assertSame('No record found with that ID.', $e->getMessage());
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function testAHostNetworkFailureIsRetriedThenBecomesAConnectionException(): void
    {
        $failure = new HttpClientException('cURL error 6: Could not resolve host');
        $siren = $this->hostSiren([$failure, $failure, $failure]);

        try {
            $siren->engagements->list();
            $this->fail('Expected a ConnectionException.');
        } catch (ConnectionException $e) {
            $this->assertSame(0, $e->getStatusCode());
            $this->assertStringContainsString('Could not resolve host', $e->getMessage());
            $this->assertSame($failure, $e->getPrevious(), 'The host error stays inspectable.');
        }

        $this->assertCount(3, $this->http->requests);
        $this->assertSame([0.5, 1.0], $this->sleeps);
    }

    public function testAHostNetworkFailureKeepsTheHostsOwnCauseWhenItWrapsOne(): void
    {
        $cause = new \RuntimeException('WP_Error: http_request_failed');
        $siren = $this->hostSiren([new HttpClientException('request failed', 0, $cause)], ['maxRetries' => 0]);

        try {
            $siren->opportunities->list();
            $this->fail('Expected a ConnectionException.');
        } catch (ConnectionException $e) {
            $this->assertSame($cause, $e->getPrevious());
        }
    }

    public function testWritesThroughAHostClientAreNotRetriedOnServerErrors(): void
    {
        $siren = $this->hostSiren([self::hostJson(503, ['error' => 'down']), self::hostJson(201, ['id' => 1])]);

        $this->expectException(\Siren\Sdk\Exception\ApiException::class);

        try {
            $siren->programs->create(['name' => 'Partners', 'incentiveType' => 'x', 'incentiveResolverType' => 'y', 'units' => 'USD']);
        } finally {
            $this->assertCount(1, $this->http->requests, 'A record write must not be repeated after a 5xx.');
        }
    }

    public function testReadsThroughAHostClientRetryOn429(): void
    {
        $siren = $this->hostSiren([
            self::hostJson(429, ['error' => 'slow down'], ['Retry-After' => '2']),
            self::hostPage([], 0),
        ]);

        $siren->programs->list();

        $this->assertCount(2, $this->http->requests);
        $this->assertSame([2.0], $this->sleeps);
    }

    public function testExhausted429RetriesSurfaceTheRateLimit(): void
    {
        $siren = $this->hostSiren([self::hostJson(429, ['error' => 'slow down'])], ['maxRetries' => 0]);

        $this->expectException(RateLimitException::class);

        $siren->programs->list();
    }

    public function testRejectsAnHttpClientOptionOfTheWrongType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('httpClient');

        new Siren(['apiKey' => self::API_KEY, 'httpClient' => new \stdClass()]);
    }

    public function testTheGuzzleAdapterSendsTheRequestAsBuilt(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(201, ['X-Test' => ['a', 'b']], '{"ok":true}')]));
        $stack->push(Middleware::history($history));

        $response = (new GuzzleHttpClient(new Client(['handler' => $stack])))->send(new HttpRequest(
            'PUT',
            'https://api.example.test/siren/v1/programs/3?fields=id',
            ['Authorization' => 'Bearer t', 'Content-Type' => 'application/json'],
            '{"name":"x"}',
            7.0,
        ));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('{"ok":true}', $response->getBody());
        $this->assertSame(['a', 'b'], $response->getHeaders()['X-Test']);

        $sent = $history[0]['request'];
        $this->assertSame('PUT', $sent->getMethod());
        $this->assertSame('https://api.example.test/siren/v1/programs/3?fields=id', (string) $sent->getUri());
        $this->assertSame('Bearer t', $sent->getHeaderLine('Authorization'));
        $this->assertSame('{"name":"x"}', (string) $sent->getBody());
        $this->assertSame(7.0, $history[0]['options']['timeout']);
        $this->assertFalse($history[0]['options']['http_errors']);
    }

    public function testTheGuzzleAdapterTurnsNetworkErrorsIntoHttpClientExceptions(): void
    {
        $connect = new ConnectException('refused', new Request('GET', 'https://api.example.test'));
        $client = new GuzzleHttpClient(new Client(['handler' => HandlerStack::create(new MockHandler([$connect]))]));

        try {
            $client->send(new HttpRequest('GET', 'https://api.example.test'));
            $this->fail('Expected an HttpClientException.');
        } catch (HttpClientException $e) {
            $this->assertSame($connect, $e->getPrevious());
        }
    }

    public function testTheGuzzleAdapterReturnsErrorStatusesInsteadOfThrowing(): void
    {
        $client = new GuzzleHttpClient(new Client(['handler' => HandlerStack::create(new MockHandler([new Response(500, [], 'boom')]))]));

        $response = $client->send(new HttpRequest('GET', 'https://api.example.test'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('boom', $response->getBody());
    }

    public function testGuzzleIsReportedAvailableWhenInstalled(): void
    {
        // Guzzle is a dev dependency here, so the default client is Guzzle.
        $this->assertTrue(GuzzleHttpClient::isAvailable());
    }
}
