<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Siren\Sdk\Exception\ApiException;
use Siren\Sdk\Exception\AuthenticationException;
use Siren\Sdk\Exception\BadRequestException;
use Siren\Sdk\Exception\ConflictException;
use Siren\Sdk\Exception\ConnectionException;
use Siren\Sdk\Exception\NotFoundException;
use Siren\Sdk\Exception\PermissionException;
use Siren\Sdk\Exception\RateLimitException;
use Siren\Sdk\Exception\SirenException;
use Siren\Sdk\Exception\ValidationException;

final class ErrorHandlingTest extends TestCase
{
    /**
     * @param class-string<SirenException> $expectedClass
     */
    #[DataProvider('statusMapProvider')]
    public function testMapsStatusCodesToTypedExceptions(int $status, string $expectedClass): void
    {
        $siren = $this->mockSiren(
            [self::errorResponse($status, 'nope', 'some_code')],
            ['maxRetries' => 0],
        );

        try {
            $siren->conversions->list();
            $this->fail('Expected a SirenException.');
        } catch (SirenException $e) {
            $this->assertSame($expectedClass, $e::class);
            $this->assertSame($status, $e->getStatusCode());
            $this->assertSame('nope', $e->getMessage());
            $this->assertSame('some_code', $e->getErrorCode());
        }
    }

    public static function statusMapProvider(): array
    {
        return [
            '400 bad request' => [400, BadRequestException::class],
            '401 authentication' => [401, AuthenticationException::class],
            '403 permission' => [403, PermissionException::class],
            '404 not found' => [404, NotFoundException::class],
            '409 conflict' => [409, ConflictException::class],
            '422 validation' => [422, ValidationException::class],
            '429 rate limit' => [429, RateLimitException::class],
            '418 fallback' => [418, ApiException::class],
            '500 server error' => [500, ApiException::class],
            '503 unavailable' => [503, ApiException::class],
        ];
    }

    public function testParsesThePlainStringErrorForm(): void
    {
        // Most endpoints emit `{"error": "<message>"}` — a plain string.
        $siren = $this->mockSiren(
            [self::stringErrorResponse(403, 'Your API key lacks the events scope.')],
            ['maxRetries' => 0],
        );

        try {
            $siren->conversions->list();
            $this->fail('Expected a PermissionException.');
        } catch (PermissionException $e) {
            $this->assertSame('Your API key lacks the events scope.', $e->getMessage());
            $this->assertNull($e->getErrorCode(), 'String errors carry no code.');
            $this->assertNull($e->getErrorData());
        }
    }

    public function testParsesTheStructuredObjectErrorForm(): void
    {
        // Login/entitlement endpoints emit `{"error": {"message","code","data"}}`.
        $siren = $this->mockSiren(
            [self::errorResponse(403, 'Upgrade required.', 'entitlement_required', ['feature' => 'webhooks'])],
            ['maxRetries' => 0],
        );

        try {
            $siren->conversions->list();
            $this->fail('Expected a PermissionException.');
        } catch (PermissionException $e) {
            $this->assertSame('Upgrade required.', $e->getMessage());
            $this->assertSame('entitlement_required', $e->getErrorCode());
            $this->assertSame(['feature' => 'webhooks'], $e->getErrorData());
        }
    }

    public function testStringErrorFormAppliesToRateLimitWithRetryAfterHeader(): void
    {
        $siren = $this->mockSiren(
            [self::stringErrorResponse(429, 'Slow down.', ['Retry-After' => '5'])],
            ['maxRetries' => 0],
        );

        try {
            $siren->conversions->list();
            $this->fail('Expected a RateLimitException.');
        } catch (RateLimitException $e) {
            $this->assertSame('Slow down.', $e->getMessage());
            $this->assertSame(5, $e->getRetryAfter(), 'Retry-After header still drives retryAfter.');
            $this->assertNull($e->getErrorCode());
        }
    }

    public function testValidationFieldErrorsAreNullForTheStringErrorForm(): void
    {
        $siren = $this->mockSiren(
            [self::stringErrorResponse(422, 'Invalid payload.')],
            ['maxRetries' => 0],
        );

        try {
            $siren->events->ingest('sale', ['source' => 'stripe']);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('Invalid payload.', $e->getMessage());
            $this->assertSame([], $e->getFieldErrors(), 'String errors have no field-level data.');
        }
    }

    public function testRefundForAnUnknownSaleRaisesNotFound(): void
    {
        $siren = $this->mockSiren([
            self::errorResponse(404, 'No sale transaction matches (externalId, source).', 'not_found'),
        ]);

        $this->expectException(NotFoundException::class);

        $siren->events->refund(['source' => 'stripe', 'externalId' => 'missing-order']);
    }

    public function testValidationExceptionExposesFieldErrors(): void
    {
        $fieldErrors = ['total' => ['The total field is required.']];
        $siren = $this->mockSiren([
            self::errorResponse(422, 'Validation failed.', 'validation_failed', $fieldErrors),
        ]);

        try {
            $siren->events->ingest('sale', ['source' => 'stripe']);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame($fieldErrors, $e->getFieldErrors());
            $this->assertSame($fieldErrors, $e->getErrorData());
        }
    }

    public function testRateLimitExceptionExposesRetryAfter(): void
    {
        $siren = $this->mockSiren(
            [self::errorResponse(429, 'Too many requests.', 'rate_limited', null, ['Retry-After' => '7'])],
            ['maxRetries' => 0],
        );

        try {
            $siren->conversions->list();
            $this->fail('Expected a RateLimitException.');
        } catch (RateLimitException $e) {
            $this->assertSame(7, $e->getRetryAfter());
            $this->assertSame(429, $e->getStatusCode());
        }
    }

    public function testRateLimitRetryAfterIsNullWithoutTheHeader(): void
    {
        $siren = $this->mockSiren(
            [self::errorResponse(429, 'Too many requests.')],
            ['maxRetries' => 0],
        );

        try {
            $siren->conversions->list();
            $this->fail('Expected a RateLimitException.');
        } catch (RateLimitException $e) {
            $this->assertNull($e->getRetryAfter());
        }
    }

    public function testFallsBackToAGenericMessageWhenTheBodyIsNotTheErrorEnvelope(): void
    {
        $siren = $this->mockSiren(
            [new \GuzzleHttp\Psr7\Response(500, [], 'gateway meltdown (not json)')],
            ['maxRetries' => 0],
        );

        try {
            $siren->conversions->list();
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertStringContainsString('500', $e->getMessage());
            $this->assertNull($e->getErrorCode());
        }
    }

    public function testRetriesIdempotentReadsOn5xxThenSucceeds(): void
    {
        $siren = $this->mockSiren([
            self::errorResponse(500, 'flaky'),
            self::jsonResponse(200, [['id' => 1]]),
        ]);

        $result = $siren->conversions->list();

        $this->assertSame([['id' => 1]], $result->getData());
        $this->assertCount(2, $this->history);
        $this->assertSame([0.5], $this->sleeps, 'First retry backs off 0.5s.');
    }

    public function testRetriesEventIngestionOn429UsingRetryAfter(): void
    {
        $siren = $this->mockSiren([
            self::errorResponse(429, 'slow down', null, null, ['Retry-After' => '3']),
            self::jsonResponse(200, [], ['X-Siren-OID' => '8']),
        ]);

        $result = $siren->events->sale([
            'source' => 'stripe',
            'externalId' => 'order-1',
            'total' => 9.99,
            'trackingId' => 42,
        ]);

        $this->assertSame(8, $result->getOpportunityId());
        $this->assertCount(2, $this->history);
        $this->assertSame([3.0], $this->sleeps, 'Retry-After overrides the smaller backoff.');
    }

    public function testExhaustedRetriesUseExponentialBackoffThenThrow(): void
    {
        $siren = $this->mockSiren([
            self::errorResponse(500, 'down'),
            self::errorResponse(500, 'down'),
            self::errorResponse(500, 'down'),
        ]);

        try {
            $siren->conversions->list();
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(500, $e->getStatusCode());
        }

        $this->assertCount(3, $this->history, 'Two retries after the initial attempt (maxRetries default 2).');
        $this->assertSame([0.5, 1.0], $this->sleeps);
    }

    public function testDoesNotRetryNonRetryableStatuses(): void
    {
        $siren = $this->mockSiren([
            self::errorResponse(422, 'bad payload'),
            self::jsonResponse(200),
        ]);

        $this->expectException(ValidationException::class);

        try {
            $siren->events->ingest('sale', ['source' => 'stripe']);
        } finally {
            $this->assertCount(1, $this->history, '4xx client errors (other than 429) must not be retried.');
        }
    }

    public function testNetworkFailureBecomesConnectionExceptionAfterRetries(): void
    {
        $siren = $this->mockSiren([
            new ConnectException('refused', new Request('GET', 'https://api.sirenaffiliates.com/siren/v1/conversions')),
            new ConnectException('refused', new Request('GET', 'https://api.sirenaffiliates.com/siren/v1/conversions')),
            new ConnectException('refused', new Request('GET', 'https://api.sirenaffiliates.com/siren/v1/conversions')),
        ]);

        try {
            $siren->conversions->list();
            $this->fail('Expected a ConnectionException.');
        } catch (ConnectionException $e) {
            $this->assertSame(0, $e->getStatusCode());
            $this->assertInstanceOf(ConnectException::class, $e->getPrevious());
        }

        $this->assertSame([0.5, 1.0], $this->sleeps);
    }

    public function testNetworkFailureRecoversWhenARetrySucceeds(): void
    {
        $siren = $this->mockSiren([
            new ConnectException('blip', new Request('POST', 'https://api.sirenaffiliates.com/siren/v1/event/sale')),
            self::jsonResponse(200, [], ['X-Siren-OID' => '77']),
        ]);

        $result = $siren->events->sale([
            'source' => 'stripe',
            'externalId' => 'order-2',
            'total' => 5.00,
            'trackingId' => 9,
        ]);

        $this->assertSame(77, $result->getOpportunityId());
        $this->assertCount(2, $this->history);
    }

    public function testEveryHttpExceptionExtendsSirenException(): void
    {
        foreach ([
            BadRequestException::class,
            AuthenticationException::class,
            PermissionException::class,
            NotFoundException::class,
            ConflictException::class,
            ValidationException::class,
            RateLimitException::class,
            ApiException::class,
            ConnectionException::class,
            \Siren\Sdk\Exception\SignatureVerificationException::class,
        ] as $class) {
            $this->assertTrue(
                is_subclass_of($class, SirenException::class),
                $class . ' must extend SirenException.',
            );
        }
    }
}
