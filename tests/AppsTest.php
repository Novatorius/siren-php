<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use Siren\Sdk\AppAnswerReceipt;
use Siren\Sdk\AppRequest;
use Siren\Sdk\Exception\ApiException;
use Siren\Sdk\Exception\AuthenticationException;
use Siren\Sdk\Exception\ConflictException;
use Siren\Sdk\Exception\ErrorCode;
use Siren\Sdk\Exception\SignatureVerificationException;
use Siren\Sdk\Exception\ValidationException;
use Siren\Sdk\Resources\Apps;
use Siren\Sdk\Resources\AppRequests;

/**
 * An app's side of Siren's exchange: checking a request Siren signed, and
 * answering an ask. The wire fixtures are Siren's own
 * (tests/Fixtures/apps-exchange in Siren, which its AppsExchangeFlowTest
 * holds to what Siren signs), copied unchanged.
 */
final class AppsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/Fixtures/apps-exchange/' . $name), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array{0: string, 1: array<string, string>, 2: string, 3: int} */
    private static function signed(): array
    {
        $fixture = self::fixture('signed-request.json');

        return [$fixture['body'], $fixture['headers'], $fixture['secret'], $fixture['timestamp']];
    }

    private function apps(): Apps
    {
        return $this->mockSiren([])->apps;
    }

    /** @param array<string, string> $headers */
    private static function resign(string $body, array $headers, string $secret, int $t): array
    {
        $headers['X-Siren-Timestamp'] = (string) $t;
        $headers['X-Siren-Signature-V2'] = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $secret);

        return $headers;
    }

    public function testTheFixtureVerifiesAndParses(): void
    {
        [$body, $headers, $secret, $t] = self::signed();

        $request = $this->apps()->verifyRequest($body, $headers, $secret, null, $t);

        $this->assertInstanceOf(AppRequest::class, $request);
        $this->assertSame('apr_b79943ff8ca353ce5cf5312bb3fa66c7', $request->id);
        $this->assertSame('ask', $request->mode);
        $this->assertTrue($request->isAsk());
        $this->assertSame('acme-payouts', $request->app);
        $this->assertSame(['kind' => 'payoutMethod', 'key' => 'acme-ledger', 'id' => 'app:12'], $request->item);
        $this->assertSame(['payoutId' => 88, 'amount' => 2500, 'currency' => 'USD'], $request->data);
        $this->assertSame('2026-11-18T09:14:03+00:00', $request->answerBy);
        $this->assertSame('https://api.siren.test/siren/v1/apps/requests/apr_b79943ff8ca353ce5cf5312bb3fa66c7/answer', $request->answerUrl);
        $this->assertSame(1794913200, $request->timestamp);
        $this->assertSame('apr_b79943ff8ca353ce5cf5312bb3fa66c7.1', $request->deliveryId);
        $this->assertSame(json_decode($body, true), $request->body);
    }

    public function testHeadersMatchWithoutRegardToCaseAndMayBeLists(): void
    {
        [$body, $headers, $secret, $t] = self::signed();
        $lower = [];
        foreach ($headers as $name => $value) {
            $lower[strtolower($name)] = [$value];
        }

        $this->assertSame('apr_b79943ff8ca353ce5cf5312bb3fa66c7', $this->apps()->verifyRequest($body, $lower, $secret, null, $t)->id);
    }

    public function testATamperedBodyOrAnotherSecretIsRefused(): void
    {
        [$body, $headers, $secret, $t] = self::signed();

        foreach ([[str_replace('2500', '25000', $body), $secret], [$body, str_repeat('c', 64)]] as [$sent, $key]) {
            try {
                $this->apps()->verifyRequest($sent, $headers, $key, null, $t);
                $this->fail('A request that does not match its signature was accepted.');
            } catch (SignatureVerificationException $e) {
                $this->assertStringContainsString('signature does not match', $e->getMessage());
            }
        }
    }

    public function testAnySignatureOfSeveralMayMatch(): void
    {
        [$body, $headers, $secret, $t] = self::signed();
        $headers['X-Siren-Signature-V2'] = 't=' . $t . ',v1=' . str_repeat('0', 64) . ',v1=nothex,' . substr($headers['X-Siren-Signature-V2'], strlen('t=' . $t . ','));

        $this->assertSame(1794913200, $this->apps()->verifyRequest($body, $headers, $secret, null, $t)->timestamp);
    }

    /** @return array<string, array{callable(array<string, string>): array<string, string>, string}> */
    public static function badHeaders(): array
    {
        return [
            'another event' => [static fn(array $h) => ['X-Siren-Event' => 'conversion.approved'] + $h, 'This is a conversion.approved webhook, not an app request.'],
            'no V2 signature' => [static function (array $h): array {
                unset($h['X-Siren-Signature-V2']);

                return $h;
            }, 'The request has no X-Siren-Signature-V2 header.'],
            'an unreadable V2 header' => [static fn(array $h) => ['X-Siren-Signature-V2' => 'sha256=abc'] + $h, 'The X-Siren-Signature-V2 header cannot be read.'],
            'two times' => [static fn(array $h) => ['X-Siren-Signature-V2' => 't=1,t=2,v1=' . str_repeat('a', 64)] + $h, 'The X-Siren-Signature-V2 header cannot be read.'],
            'a timestamp header that differs' => [static fn(array $h) => ['X-Siren-Timestamp' => '1794913201'] + $h, 'The X-Siren-Timestamp header differs'],
        ];
    }

    /**
     * @dataProvider badHeaders
     * @param callable(array<string, string>): array<string, string> $change
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badHeaders')]
    public function testABadHeaderIsRefusedWithTheReason(callable $change, string $message): void
    {
        [$body, $headers, $secret, $t] = self::signed();

        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessage($message);

        $this->apps()->verifyRequest($body, $change($headers), $secret, null, $t);
    }

    public function testASignedTimeOutsideTheToleranceIsRefusedAsAReplay(): void
    {
        [$body, $headers, $secret, $t] = self::signed();

        $this->assertSame($t, $this->apps()->verifyRequest($body, $headers, $secret, null, $t + 300)->timestamp);
        $this->assertSame($t, $this->apps()->verifyRequest($body, $headers, $secret, 3600, $t - 3600)->timestamp);

        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessage('more than 300 seconds');
        $this->apps()->verifyRequest($body, $headers, $secret, null, $t + 301);
    }

    public function testASignedTimeAheadOfTheClockByMoreThanTheToleranceIsRefused(): void
    {
        [$body, $headers, $secret, $t] = self::signed();

        $this->assertSame($t, $this->apps()->verifyRequest($body, $headers, $secret, null, $t - 300)->timestamp);

        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessage('more than 300 seconds');
        $this->apps()->verifyRequest($body, $headers, $secret, null, $t - 301);
    }

    public function testTheArgumentsAreCheckedFirst(): void
    {
        [$body, $headers, , $t] = self::signed();

        foreach ([[$body, '', null], [$body, 'secret', 0], [$body, 'secret', 3601]] as [$sent, $secret, $tolerance]) {
            try {
                $this->apps()->verifyRequest($sent, $headers, $secret, $tolerance, $t);
                $this->fail('A bad argument was accepted.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('apps->verifyRequest()', $e->getMessage());
            }
        }
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function badEnvelopes(): array
    {
        $good = json_decode(self::fixture('signed-request.json')['body'], true);

        return [
            'version 2' => [['version' => 2] + $good],
            'an id that is not a request key' => [['id' => 'apr_123'] + $good],
            'another type' => [['type' => 'conversion.approved'] + $good],
            'no app' => [['app' => ''] + $good],
            'an item with no kind' => [['item' => ['key' => 'k', 'id' => 'app:1']] + $good],
            'a list for data' => [['data' => [1, 2]] + $good],
            'another mode' => [['mode' => 'maybe'] + $good],
            'an ask with no answer address' => [(static function (array $body): array {
                unset($body['answerUrl']);

                return $body;
            })($good)],
        ];
    }

    /**
     * @dataProvider badEnvelopes
     * @param array<string, mixed> $envelope
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badEnvelopes')]
    public function testASignedBodyThatIsNotARequestIsRefused(array $envelope): void
    {
        [, $headers, $secret, $t] = self::signed();
        $body = json_encode($envelope, JSON_THROW_ON_ERROR);

        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessage('not an app request of version 1');

        $this->apps()->verifyRequest($body, self::resign($body, $headers, $secret, $t), $secret, null, $t);
    }

    public function testATellNeedsNoAnswerAddress(): void
    {
        [$body, $headers, $secret, $t] = self::signed();
        $tell = json_decode($body, true);
        $tell['mode'] = 'tell';
        unset($tell['answerBy'], $tell['answerUrl']);
        $sent = json_encode($tell, JSON_THROW_ON_ERROR);

        $request = $this->apps()->verifyRequest($sent, self::resign($sent, $headers, $secret, $t), $secret, null, $t);

        $this->assertFalse($request->isAsk());
        $this->assertNull($request->answerBy);
    }

    public function testAnAnswerIsPostedOnceAsTheBodyAlone(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, ['status' => 'accepted'])]);

        $receipt = $siren->apps->requests->answer('apr_b79943ff8ca353ce5cf5312bb3fa66c7', ['outcome' => 'paid', 'reference' => 'acme-ledger-5521']);

        $this->assertInstanceOf(AppAnswerReceipt::class, $receipt);
        $this->assertSame('accepted', $receipt->status);
        $this->assertFalse($receipt->duplicate);
        $request = $this->lastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.sirenaffiliates.com/siren/v1/apps/requests/apr_b79943ff8ca353ce5cf5312bb3fa66c7/answer', (string) $request->getUri());
        $this->assertSame(['outcome' => 'paid', 'reference' => 'acme-ledger-5521'], $this->lastRequestJson());
    }

    public function testTheFixturesExchangesGetTheRepliesTheyExpect(): void
    {
        $fixture = self::fixture('answer.json');
        $id = 'apr_b79943ff8ca353ce5cf5312bb3fa66c7';

        foreach ($fixture['exchanges'] as $exchange) {
            $reply = isset($exchange['code'])
                ? self::errorResponse($exchange['status'], 'Another answer was taken first.', $exchange['code'])
                : self::jsonResponse($exchange['status'], $exchange['reply']);
            $siren = $this->mockSiren([$reply]);

            try {
                $receipt = $siren->apps->requests->answer($id, $exchange['answer']);
                $this->assertSame($exchange['reply']['duplicate'] ?? false, $receipt->duplicate, $exchange['name']);
            } catch (ConflictException $e) {
                $this->assertSame($exchange['code'], $e->getErrorCode(), $exchange['name']);
            }

            $this->assertSame('/siren/v1/apps/requests/' . $id . '/answer', $this->lastRequest()->getUri()->getPath());
        }
    }

    public function testAnAnswerIsNeverRetried(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(503, []), self::jsonResponse(200, ['status' => 'accepted'])]);

        try {
            $siren->apps->requests->answer('apr_b79943ff8ca353ce5cf5312bb3fa66c7', ['outcome' => 'paid']);
            $this->fail('A 503 was not raised.');
        } catch (ApiException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }

        $this->assertCount(1, $this->history);
    }

    /** @return array<string, array{string, array<int|string, mixed>, string}> */
    public static function unsendable(): array
    {
        return [
            'an id that is not a request key' => ['88', ['outcome' => 'paid'], 'needs a request id'],
            'a list' => ['apr_b79943ff8ca353ce5cf5312bb3fa66c7', ['paid'], 'takes the answer as an object'],
            'nothing' => ['apr_b79943ff8ca353ce5cf5312bb3fa66c7', [], 'takes the answer as an object'],
            'no outcome' => ['apr_b79943ff8ca353ce5cf5312bb3fa66c7', ['reference' => 'TX-1'], 'needs a text outcome'],
            'an outcome that is not text' => ['apr_b79943ff8ca353ce5cf5312bb3fa66c7', ['outcome' => 1], 'needs a text outcome'],
            'over sixteen kilobytes' => ['apr_b79943ff8ca353ce5cf5312bb3fa66c7', ['outcome' => 'paid', 'note' => str_repeat('a', AppRequests::MAX_ANSWER_BYTES)], 'at most 16384 bytes'],
        ];
    }

    /**
     * @dataProvider unsendable
     * @param array<int|string, mixed> $answer
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsendable')]
    public function testAnAnswerThatCannotBeSentIsRefusedBeforeSending(string $id, array $answer, string $message): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, ['status' => 'accepted'])]);

        try {
            $siren->apps->requests->answer($id, $answer);
            $this->fail('An answer that cannot be sent was sent.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }

        $this->assertCount(0, $this->history);
    }

    public function testAnUncodedUnauthorizedAnswerGetsTheAppCode(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(401, ['message' => 'Invalid API key. Make a new one under API keys.'])]);

        try {
            $siren->apps->requests->answer('apr_b79943ff8ca353ce5cf5312bb3fa66c7', ['outcome' => 'paid']);
            $this->fail('A 401 was not raised.');
        } catch (AuthenticationException $e) {
            $this->assertSame(ErrorCode::APP_ANSWER_UNAUTHORIZED, $e->getErrorCode());
            $this->assertSame(401, $e->getStatusCode());
            $this->assertStringContainsString('siren apps rotate-key', $e->getMessage());
            $this->assertInstanceOf(AuthenticationException::class, $e->getPrevious());
        }
    }

    public function testAnAnswerThatDoesNotFitTheKindStaysAValidationError(): void
    {
        $siren = $this->mockSiren([self::errorResponse(422, 'outcome must be paid or failed', 'app_answer_invalid')]);

        $this->expectException(ValidationException::class);
        $siren->apps->requests->answer('apr_b79943ff8ca353ce5cf5312bb3fa66c7', ['outcome' => 'maybe']);
    }

    public function testAReplyThatIsNotAnAcceptedAnswerIsRaised(): void
    {
        foreach ([['status' => 'queued'], ['status' => 'accepted', 'duplicate' => 'yes']] as $reply) {
            $siren = $this->mockSiren([self::jsonResponse(200, $reply)]);

            try {
                $siren->apps->requests->answer('apr_b79943ff8ca353ce5cf5312bb3fa66c7', ['outcome' => 'paid']);
                $this->fail('A reply of the wrong shape was read as accepted.');
            } catch (ApiException $e) {
                $this->assertSame($reply, $e->getErrorData());
            }
        }
    }
}
