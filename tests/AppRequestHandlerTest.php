<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\DataProvider;
use Siren\Sdk\AppRequest;
use Siren\Sdk\AppRequestHandler;
use Siren\Sdk\AppRequestPsrHandler;
use Siren\Sdk\AppRequestReply;
use Siren\Sdk\Auth\AccessTokenSource;
use Siren\Sdk\Exception\ApiException;
use Siren\Sdk\Exception\ConflictException;
use Siren\Sdk\Exception\ConnectionException;
use Siren\Sdk\Exception\NotFoundException;
use Siren\Sdk\Exception\PermissionException;
use Siren\Sdk\Exception\SignatureVerificationException;
use Siren\Sdk\Exception\SirenException;
use Siren\Sdk\Exception\ValidationException;
use Siren\Sdk\Resources\AppRequests;
use Siren\Sdk\Siren;
use Siren\Sdk\WebhookEvent;

/**
 * The request handler, one test or data set per row of section 3.1 of the
 * design note (design/sdk-answer-handler.md in the run folder) and per step
 * of section 3.2. Requests are Siren's own feature contracts
 * (tests/Fixtures/apps-feature-contracts/requests.json, copied unchanged
 * from Siren), signed now with the secret of tests/Fixtures/apps-exchange.
 */
final class AppRequestHandlerTest extends TestCase
{
    private const WORDS = 'Mount the handler before the JSON parser, or give the route a raw body';

    /** @var list<array{0: \Throwable, 1: object|null}> */
    private array $errors = [];

    /** @return array<string, mixed> */
    private static function contracts(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/Fixtures/apps-feature-contracts/requests.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function secret(): string
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/apps-exchange/signed-request.json'), true, 512, JSON_THROW_ON_ERROR);

        return $fixture['secret'];
    }

    /** The request Siren sends for one kind, as JSON. */
    private static function body(string $kind): string
    {
        return json_encode(self::contracts()['kinds'][$kind]['request'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, string> Headers as WebhookSignature::headers() sets them, signed at `$t`. */
    private static function signed(string $body, ?int $t = null, string $event = 'app.request', ?string $secret = null): array
    {
        $t ??= time();

        return [
            'Content-Type' => 'application/json',
            'X-Siren-Event' => $event,
            'X-Siren-Delivery' => 'apr_9c60190a4501399d0ae55f041c237b39.1',
            'X-Siren-Timestamp' => (string) $t,
            'X-Siren-Signature-V2' => 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $secret ?? self::secret()),
        ];
    }

    /**
     * @param list<\Psr\Http\Message\ResponseInterface|\Throwable> $responses
     * @param array<string, mixed> $options
     * @param array<string, mixed> $config
     */
    private function handler(array $responses = [], array $options = [], array $config = []): AppRequestHandler
    {
        $this->errors = [];
        $siren = $this->mockSiren($responses, $config);

        return $siren->apps->requestHandler(self::secret(), $options + ['onError' => function (\Throwable $error, ?object $about): void {
            $this->errors[] = [$error, $about];
        }]);
    }

    /** Every reply is JSON that may not be cached, and never holds the secret or the request's body. */
    private function assertReply(int $status, AppRequestReply $reply, ?string $sent = null): void
    {
        $this->assertSame($status, $reply->status, $reply->body);
        $this->assertSame('application/json', $reply->headers['Content-Type'] ?? null);
        $this->assertSame('no-store', $reply->headers['Cache-Control'] ?? null);
        $this->assertIsArray(json_decode($reply->body, true), $reply->body);
        $this->assertStringNotContainsString(self::secret(), $reply->body);

        if ($sent !== null && $status !== 200) {
            $this->assertStringNotContainsString($sent, $reply->body);
            $this->assertStringNotContainsString('Partner p', $reply->body, 'The reply repeats nothing of the body.');
        }

        if ($status === 200) {
            $this->assertSame('{}', $reply->body);
        } else {
            $this->assertIsString(json_decode($reply->body, true)['error'] ?? null, 'An error reply is {"error": "..."}.');
        }
    }

    // Section 3.1, row 1: the method is not POST.

    public function testAMethodOtherThanPostGets405AndRunsNothing(): void
    {
        $ran = false;
        $handler = $this->handler()->on('postConversionAction', 'tell-the-crm', function () use (&$ran): void {
            $ran = true;
        });
        $body = self::body('postConversionAction');

        foreach (['GET', 'PUT', 'HEAD'] as $method) {
            $reply = $handler->answer($method, $body, self::signed($body));

            $this->assertReply(405, $reply);
            $this->assertSame('POST', $reply->headers['Allow'] ?? null);
            $this->assertNull($reply->after);
        }

        $this->assertSame(200, $handler->answer('post', $body, self::signed($body))->status, 'The method matches without regard to case.');
        $this->assertTrue($ran);
        $this->assertSame([], $this->errors);
    }

    // Row 2: a body parser already turned the body into an object.

    public function testABodyAParserAlreadyReadGets500WithTheWords(): void
    {
        $ran = false;
        $handler = $this->handler()->on('postConversionAction', function () use (&$ran): void {
            $ran = true;
        });
        $body = self::body('postConversionAction');

        foreach ([json_decode($body, true), null] as $parsed) {
            $this->errors = [];
            $reply = $handler->answer('POST', $parsed, self::signed($body));

            $this->assertReply(500, $reply, $body);
            $this->assertStringContainsString(self::WORDS, json_decode($reply->body, true)['error']);
            $this->assertCount(1, $this->errors);
            $this->assertInstanceOf(SirenException::class, $this->errors[0][0]);
            $this->assertStringContainsString(self::WORDS, $this->errors[0][0]->getMessage());
            $this->assertNull($this->errors[0][1]);
        }

        $this->assertFalse($ran);
    }

    public function testAPsr15RequestWhoseBodyAParserAlreadyReadGets500WithTheWords(): void
    {
        $body = self::body('postConversionAction');
        $handler = $this->handler()->on('postConversionAction', static function (): void {
        })->psr15();
        $request = (new ServerRequest('POST', '/siren', self::signed($body)))
            ->withBody(Utils::streamFor(''))
            ->withParsedBody(json_decode($body, true));

        $response = $handler->handle($request);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString(self::WORDS, json_decode((string) $response->getBody(), true)['error']);
        $this->assertCount(1, $this->errors);
    }

    // Row 3: the signature, the timestamp header or the signed time is wrong.

    /** @return array<string, array{callable(string, array<string, string>): array{0: string, 1: array<string, string>}}> */
    public static function unsigned(): array
    {
        $secret = str_repeat('c', 64);

        return [
            'no V2 signature' => [static function (string $body, array $headers): array {
                unset($headers['X-Siren-Signature-V2']);

                return [$body, $headers];
            }],
            'an unreadable V2 header' => [static fn(string $body, array $headers): array => [$body, ['X-Siren-Signature-V2' => 'sha256=abc'] + $headers]],
            'another secret' => [static fn(string $body, array $headers): array => [$body, self::signed($body, null, 'app.request', $secret)]],
            'a changed body' => [static fn(string $body, array $headers): array => [str_replace('1500', '15000', $body), $headers]],
            'a timestamp header that differs' => [static fn(string $body, array $headers): array => [$body, ['X-Siren-Timestamp' => (string) (time() + 1)] + $headers]],
            'signed too long ago' => [static fn(string $body, array $headers): array => [$body, self::signed($body, time() - 301)]],
            'signed too far ahead' => [static fn(string $body, array $headers): array => [$body, self::signed($body, time() + 301)]],
            'another event, wrongly signed' => [static fn(string $body, array $headers): array => [$body, self::signed($body, null, 'conversion.approved', $secret)]],
        ];
    }

    /** @param callable(string, array<string, string>): array{0: string, 1: array<string, string>} $change */
    #[DataProvider('unsigned')]
    public function testARequestThatFailsVerifyRequestsChecksGets401WithItsWords(callable $change): void
    {
        $ran = false;
        $handler = $this->handler()
            ->on('postConversionAction', function () use (&$ran): void {
                $ran = true;
            })
            ->onEvent('conversion.approved', function () use (&$ran): void {
                $ran = true;
            });
        $original = self::body('postConversionAction');
        [$body, $headers] = $change($original, self::signed($original));

        $reply = $handler->answer('POST', $body, $headers);

        $this->assertReply(401, $reply, $body);
        $this->assertFalse($ran);
        $this->assertSame([], $this->errors);

        // The words are verifyRequest's own, for the same request.
        try {
            $this->mockSiren([])->apps->verifyRequest($body, ['X-Siren-Event' => 'app.request'] + $headers, self::secret());
            $this->fail('verifyRequest() took a request the handler refused.');
        } catch (SignatureVerificationException $e) {
            $this->assertSame($e->getMessage(), json_decode($reply->body, true)['error']);
        }
    }

    public function testToleranceSecondsWidensTheWindowAsVerifyRequestsDoes(): void
    {
        $body = self::body('postConversionAction');
        $handler = $this->handler([], ['toleranceSeconds' => 600])->on('postConversionAction', static function (): void {
        });

        $this->assertReply(200, $handler->answer('POST', $body, self::signed($body, time() - 590)));
        $this->assertReply(401, $handler->answer('POST', $body, self::signed($body, time() - 610)));
    }

    // Row 4: the signed body is not JSON, or not an app request of version 1.

    /** @return array<string, array{string, string}> */
    public static function unreadable(): array
    {
        $request = self::contracts()['kinds']['postConversionAction']['request'];
        $envelope = static fn(array $change): string => json_encode($change + $request, JSON_THROW_ON_ERROR);

        return [
            'not JSON' => ['{"version": 1', 'app.request'],
            'version 2' => [$envelope(['version' => 2]), 'app.request'],
            'an id that is not a request key' => [$envelope(['id' => 'apr_1']), 'app.request'],
            'an item with no kind' => [$envelope(['item' => ['key' => 'tell-the-crm', 'id' => 'app:7']]), 'app.request'],
            'an ask with no answer address' => [$envelope(['mode' => 'ask']), 'app.request'],
            'a list' => ['[1, 2]', 'app.request'],
            'an event that is not JSON' => ['not json', 'conversion.approved'],
            'an event that is a number' => ['42', 'conversion.approved'],
        ];
    }

    #[DataProvider('unreadable')]
    public function testASignedBodyThatIsNotJsonOrNotAnEnvelopeGets400(string $body, string $event): void
    {
        $ran = false;
        $handler = $this->handler()
            ->on('postConversionAction', function () use (&$ran): void {
                $ran = true;
            })
            ->onEvent('conversion.approved', function () use (&$ran): void {
                $ran = true;
            });

        $reply = $handler->answer('POST', $body, self::signed($body, null, $event));

        $this->assertReply(400, $reply, $body);
        $this->assertFalse($ran);
    }

    public function testARequestWithNoEventHeaderIsReadAsAnAppRequest(): void
    {
        $body = self::body('postConversionAction');
        $headers = self::signed($body);
        unset($headers['X-Siren-Event']);
        $seen = [];
        $handler = $this->handler()->on('postConversionAction', 'tell-the-crm', function (AppRequest $request) use (&$seen): void {
            $seen[] = $request->id;
        });

        $this->assertReply(200, $handler->answer('POST', $body, $headers));
        $this->assertSame(['apr_9c60190a4501399d0ae55f041c237b39'], $seen);
    }

    // Row 5: a tell, with a function for its kind and key.

    public function testATellRunsItsFunctionBeforeThe200(): void
    {
        $seen = [];
        $handler = $this->handler()->on('postConversionAction', 'tell-the-crm', function (AppRequest $request) use (&$seen): string {
            $seen[] = $request;

            return 'ignored';
        });
        $body = self::body('postConversionAction');

        $reply = $handler->answer('POST', $body, self::signed($body));

        $this->assertReply(200, $reply);
        $this->assertNull($reply->after, 'A tell has nothing to do after the reply.');
        $this->assertCount(1, $seen);
        $this->assertSame('apr_9c60190a4501399d0ae55f041c237b39', $seen[0]->id);
        $this->assertSame('tell', $seen[0]->mode);
        $this->assertSame(1042, $seen[0]->data['conversion']['id']);
        $this->assertSame([], $this->history, 'A tell posts nothing.');
    }

    public function testATellWhoseFunctionThrowsGets500SoSirenTriesAgain(): void
    {
        $failure = new \RuntimeException('The CRM at crm.internal refused us.');
        $handler = $this->handler()->on('postConversionAction', 'tell-the-crm', static function () use ($failure): void {
            throw $failure;
        });
        $body = self::body('postConversionAction');

        $reply = $handler->answer('POST', $body, self::signed($body));

        $this->assertReply(500, $reply, $body);
        $this->assertStringNotContainsString('crm.internal', $reply->body, "The function's own words stay out of the reply.");
        $this->assertCount(1, $this->errors);
        $this->assertSame($failure, $this->errors[0][0]);
        $this->assertInstanceOf(AppRequest::class, $this->errors[0][1]);
    }

    public function testOnKindTakesEveryKeyAndOnKindAndKeyWinsOverIt(): void
    {
        $calls = [];
        $handler = $this->handler()
            ->on('postAllocationAction', function (AppRequest $request) use (&$calls): void {
                $calls[] = 'every ' . $request->item['key'];
            })
            ->on('postAllocationAction', 'note-the-share', function () use (&$calls): void {
                $calls[] = 'note-the-share';
            });
        $request = self::contracts()['kinds']['postAllocationAction']['request'];
        $other = json_encode(['item' => ['kind' => 'postAllocationAction', 'key' => 'another-key', 'id' => 'app:11']] + $request, JSON_THROW_ON_ERROR);
        $body = self::body('postAllocationAction');

        $this->assertReply(200, $handler->answer('POST', $body, self::signed($body)));
        $this->assertReply(200, $handler->answer('POST', $other, self::signed($other)));
        $this->assertSame(['note-the-share', 'every another-key'], $calls);
    }

    // Row 6: an ask, with a function for its kind and key.

    public function testAnAskGets200AtOnceAndItsFunctionRunsAfterAndItsAnswerIsPosted(): void
    {
        $contract = self::contracts()['kinds']['distributionResolver'];
        $ran = false;
        $handler = $this->handler([self::jsonResponse(200, ['status' => 'accepted'])])
            ->on('distributionResolver', 'split-by-score', function (AppRequest $request) use (&$ran): array {
                $ran = true;

                return ['outcome' => 'allocated', 'units' => 'USD', 'shares' => ['41' => 6000, '43' => 4000]];
            });
        $body = self::body('distributionResolver');

        $reply = $handler->answer('POST', $body, self::signed($body));

        $this->assertReply(200, $reply);
        $this->assertFalse($ran, 'The function waits until the reply has gone.');
        $this->assertSame([], $this->history);
        $this->assertInstanceOf(\Closure::class, $reply->after);

        ($reply->after)();

        $this->assertTrue($ran);
        $this->assertCount(1, $this->history);
        $this->assertSame($contract['answer'], $this->lastRequestJson());
        $this->assertSame([], $this->errors);
    }

    public function testAnAskWhoseFunctionReturnsNothingPostsNothing(): void
    {
        $handler = $this->handler()->on('incentiveResolver', static function (): void {
        });
        $body = self::body('incentiveResolver');

        $reply = $handler->answer('POST', $body, self::signed($body));
        $this->assertReply(200, $reply);
        ($reply->after)();

        $this->assertSame([], $this->history);
        $this->assertSame([], $this->errors);
    }

    public function testRunLaterIsHandedTheWorkAndTheReplyCarriesNone(): void
    {
        $later = [];
        $handler = $this->handler([self::jsonResponse(200, ['status' => 'accepted'])], ['runLater' => function (callable $work) use (&$later): void {
            $later[] = $work;
        }])->on('incentiveResolver', 'split-the-reward', static fn(): array => self::contracts()['kinds']['incentiveResolver']['answer']);
        $body = self::body('incentiveResolver');

        $reply = $handler->answer('POST', $body, self::signed($body));

        $this->assertReply(200, $reply);
        $this->assertNull($reply->after);
        $this->assertCount(1, $later);
        $this->assertSame([], $this->history);

        $later[0]();

        $this->assertSame(self::contracts()['kinds']['incentiveResolver']['answer'], $this->lastRequestJson());
    }

    public function testATellNeverReachesRunLater(): void
    {
        $later = 0;
        $handler = $this->handler([], ['runLater' => function () use (&$later): void {
            $later++;
        }])->on('postConversionAction', static function (): void {
        });
        $body = self::body('postConversionAction');

        $this->assertReply(200, $handler->answer('POST', $body, self::signed($body)));
        $this->assertSame(0, $later);
    }

    // Row 7: an app request for a kind and key with no function.

    public function testAFeatureWithNoFunctionGets404NamingIt(): void
    {
        $handler = $this->handler()
            ->on('distributionResolver', 'another-key', static fn(): array => ['outcome' => 'allocated'])
            ->on('incentiveResolver', static fn(): array => ['outcome' => 'rewards'])
            ->onEvent('app.created', static function (): void {
            });
        $body = self::body('distributionResolver');

        $reply = $handler->answer('POST', $body, self::signed($body));

        $this->assertReply(404, $reply, $body);
        $this->assertSame('{"error":"No function handles distributionResolver split-by-score."}', $reply->body);
        $this->assertNull($reply->after);
        $this->assertSame([], $this->history);
    }

    // Rows 8 and 9: any other X-Siren-Event.

    public function testAnEventWithAFunctionGetsItsEventThen200(): void
    {
        $seen = [];
        $handler = $this->handler()->onEvent('conversion.approved', function (WebhookEvent $event) use (&$seen): void {
            $seen[] = $event;
        });
        $body = '{"conversionId":1042,"status":"approved"}';

        $reply = $handler->answer('POST', $body, self::signed($body, null, 'conversion.approved'));

        $this->assertReply(200, $reply);
        $this->assertNull($reply->after);
        $this->assertCount(1, $seen);
        $this->assertSame('conversion.approved', $seen[0]->getType());
        $this->assertSame(['conversionId' => 1042, 'status' => 'approved'], $seen[0]->getData());
        $this->assertSame('apr_9c60190a4501399d0ae55f041c237b39.1', $seen[0]->getDeliveryId());
    }

    public function testAnEventWhoseFunctionThrowsGets500(): void
    {
        $failure = new \RuntimeException('Disk full.');
        $handler = $this->handler()->onEvent('conversion.approved', static function () use ($failure): void {
            throw $failure;
        });
        $body = '{"conversionId":1042}';

        $reply = $handler->answer('POST', $body, self::signed($body, null, 'conversion.approved'));

        $this->assertReply(500, $reply, $body);
        $this->assertSame($failure, $this->errors[0][0] ?? null);
        $this->assertInstanceOf(WebhookEvent::class, $this->errors[0][1]);
    }

    public function testAnEventWithNoFunctionGets200(): void
    {
        $handler = $this->handler()->on('postConversionAction', static function (): void {
        });
        $body = '{"conversionId":1042}';

        $this->assertReply(200, $handler->answer('POST', $body, self::signed($body, null, 'conversion.approved')));
        $this->assertSame([], $this->errors);
    }

    // Section 3.2, step 1: the answer is checked as requests->answer() checks it.

    /** @return array<string, array{mixed, string}> */
    public static function unsendable(): array
    {
        return [
            'a list' => [['allocated'], 'takes the answer as an object'],
            'no outcome' => [['units' => 'USD'], 'needs a text outcome'],
            'an outcome that is not text' => [['outcome' => 1], 'needs a text outcome'],
            'over sixteen kilobytes' => [['outcome' => 'allocated', 'note' => str_repeat('a', AppRequests::MAX_ANSWER_BYTES)], 'at most 16384 bytes'],
            'a string' => ['allocated', 'returns the answer as an array'],
            'an object' => [new \ArrayObject(['outcome' => 'allocated']), 'returns the answer as an array'],
        ];
    }

    #[DataProvider('unsendable')]
    public function testABadAnswerGoesToOnErrorAndIsNotSent(mixed $answer, string $words): void
    {
        $handler = $this->handler([self::jsonResponse(200, ['status' => 'accepted'])])
            ->on('distributionResolver', static fn(): mixed => $answer);
        $body = self::body('distributionResolver');

        $reply = $handler->answer('POST', $body, self::signed($body));
        $this->assertReply(200, $reply);
        ($reply->after)();

        $this->assertSame([], $this->history);
        $this->assertCount(1, $this->errors);
        $this->assertInstanceOf(\InvalidArgumentException::class, $this->errors[0][0]);
        $this->assertStringContainsString($words, $this->errors[0][0]->getMessage());
        $this->assertInstanceOf(AppRequest::class, $this->errors[0][1]);
    }

    // Step 2: the answer goes to the answer route with the client's own key.

    public function testTheAnswerIsPostedToTheAnswerRouteWithTheClientsKey(): void
    {
        $handler = $this->handler([self::jsonResponse(200, ['status' => 'accepted', 'duplicate' => true])])
            ->on('distributionResolver', 'split-by-score', static fn(): array => self::contracts()['kinds']['distributionResolver']['answer']);
        $body = self::body('distributionResolver');

        ($handler->answer('POST', $body, self::signed($body))->after)();

        $request = $this->lastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.sirenaffiliates.com/siren/v1/apps/requests/apr_9c60190a4501399d0ae55f041c237b39/answer', (string) $request->getUri());
        $this->assertSame('Bearer ' . self::API_KEY, $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame([], $this->errors, 'Siren taking the same answer again is no error.');
    }

    // Step 3: a lost connection, 429 or 5xx is sent again, and so is a 401 once.

    public function testTheAnswerIsSentAgainAfterALostConnectionA429AndA5xx(): void
    {
        $handler = $this->handler([
            new ConnectException('Connection reset by peer', new Request('POST', '/')),
            self::jsonResponse(429, ['error' => 'Slow down.']),
            self::jsonResponse(503, ['error' => 'Down for a moment.']),
            self::jsonResponse(200, ['status' => 'accepted']),
        ], [], ['maxRetries' => 3])->on('distributionResolver', static fn(): array => self::contracts()['kinds']['distributionResolver']['answer']);
        $body = self::body('distributionResolver');

        ($handler->answer('POST', $body, self::signed($body))->after)();

        $this->assertCount(4, $this->history);
        $this->assertSame([0.5, 1.0, 2.0], $this->sleeps, "The transport's backoff.");
        $this->assertSame([], $this->errors);

        foreach ($this->history as $sent) {
            $this->assertSame(self::contracts()['kinds']['distributionResolver']['answer'], json_decode((string) $sent['request']->getBody(), true), 'Each send carries the same answer.');
        }
    }

    public function testTheAnswerStopsAtMaxRetriesAndGoesToOnError(): void
    {
        $handler = $this->handler([
            self::jsonResponse(503, ['error' => 'Down.']),
            self::jsonResponse(503, ['error' => 'Down.']),
            self::jsonResponse(503, ['error' => 'Down.']),
        ])->on('distributionResolver', static fn(): array => self::contracts()['kinds']['distributionResolver']['answer']);
        $body = self::body('distributionResolver');

        ($handler->answer('POST', $body, self::signed($body))->after)();

        $this->assertCount(3, $this->history, 'One send and the default two retries.');
        $this->assertInstanceOf(ApiException::class, $this->errors[0][0] ?? null);
        $this->assertSame(503, $this->errors[0][0]->getStatusCode());
    }

    public function testALostConnectionAtEveryTryGoesToOnError(): void
    {
        $lost = static fn(): ConnectException => new ConnectException('Connection refused', new Request('POST', '/'));
        $handler = $this->handler([$lost(), $lost(), $lost()])
            ->on('distributionResolver', static fn(): array => self::contracts()['kinds']['distributionResolver']['answer']);
        $body = self::body('distributionResolver');

        ($handler->answer('POST', $body, self::signed($body))->after)();

        $this->assertCount(3, $this->history);
        $this->assertInstanceOf(ConnectionException::class, $this->errors[0][0] ?? null);
    }

    public function testTheAnswerIsSentOnceMoreAfterA401WithAFreshToken(): void
    {
        $source = new class () implements AccessTokenSource {
            public string $token = 'jwt.old';

            public function getAccessToken(): string
            {
                return $this->token;
            }

            public function refreshAccessToken(string $rejectedToken): ?string
            {
                return $this->token = 'jwt.new';
            }
        };
        $handler = $this->handler([
            self::jsonResponse(401, ['error' => 'Expired.']),
            self::jsonResponse(200, ['status' => 'accepted']),
        ], [], ['apiKey' => null, 'accessToken' => $source])->on('distributionResolver', static fn(): array => self::contracts()['kinds']['distributionResolver']['answer']);
        $body = self::body('distributionResolver');

        ($handler->answer('POST', $body, self::signed($body))->after)();

        $this->assertCount(2, $this->history);
        $this->assertSame('Bearer jwt.new', $this->lastRequest()->getHeaderLine('Authorization'));
        $this->assertSame([], $this->errors);
    }

    public function testRequestsAnswerItselfIsStillSentOnce(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(503, []), self::jsonResponse(200, ['status' => 'accepted'])]);
        $siren->apps->requestHandler(self::secret());

        try {
            $siren->apps->requests->answer('apr_9c60190a4501399d0ae55f041c237b39', ['outcome' => 'allocated']);
            $this->fail('A 503 was not raised.');
        } catch (ApiException) {
        }

        $this->assertCount(1, $this->history);
    }

    // Step 4: a refusal goes to onError with the SDK's typed error, sent once.

    /** @return array<string, array{int, string|null, class-string<SirenException>}> */
    public static function refusals(): array
    {
        return [
            '422 app_answer_invalid' => [422, 'app_answer_invalid', ValidationException::class],
            '409 app_request_already_answered' => [409, 'app_request_already_answered', ConflictException::class],
            '409 app_request_dropped' => [409, 'app_request_dropped', ConflictException::class],
            '403 app_inactive' => [403, 'app_inactive', PermissionException::class],
            '404 app_request_not_found' => [404, 'app_request_not_found', NotFoundException::class],
        ];
    }

    /** @param class-string<SirenException> $class */
    #[DataProvider('refusals')]
    public function testARefusalGoesToOnErrorAndIsNotSentAgain(int $status, ?string $code, string $class): void
    {
        $handler = $this->handler([
            self::errorResponse($status, 'Refused.', $code),
            self::jsonResponse(200, ['status' => 'accepted']),
        ])->on('distributionResolver', static fn(): array => self::contracts()['kinds']['distributionResolver']['answer']);
        $body = self::body('distributionResolver');

        ($handler->answer('POST', $body, self::signed($body))->after)();

        $this->assertCount(1, $this->history);
        $this->assertCount(1, $this->errors);
        $this->assertInstanceOf($class, $this->errors[0][0]);
        $this->assertSame($code, $this->errors[0][0]->getErrorCode());
        $this->assertInstanceOf(AppRequest::class, $this->errors[0][1]);
    }

    // Step 5: when the function throws, nothing is sent and Siren asks again later.

    public function testAnAskWhoseFunctionThrowsSendsNothing(): void
    {
        $failure = new \RuntimeException('The scores are not in yet.');
        $handler = $this->handler()->on('distributionResolver', static function () use ($failure): never {
            throw $failure;
        });
        $body = self::body('distributionResolver');

        $reply = $handler->answer('POST', $body, self::signed($body));
        $this->assertReply(200, $reply);
        ($reply->after)();

        $this->assertSame([], $this->history);
        $this->assertSame($failure, $this->errors[0][0] ?? null);
    }

    // The options and the registrations.

    /** @return array<string, array{string, array<string, mixed>, string}> */
    public static function badOptions(): array
    {
        return [
            'no secret' => ['', [], "needs the app's signing secret"],
            'a tolerance of 0' => ['secret', ['toleranceSeconds' => 0], 'toleranceSeconds'],
            'a tolerance past an hour' => ['secret', ['toleranceSeconds' => 3601], 'toleranceSeconds'],
            'a tolerance that is text' => ['secret', ['toleranceSeconds' => '300'], 'toleranceSeconds'],
            'a runLater that is no function' => ['secret', ['runLater' => 'not a function'], 'runLater'],
            'an onError that is no function' => ['secret', ['onError' => 42], 'onError'],
            'an option it does not know' => ['secret', ['tolerance' => 300], 'tolerance'],
        ];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('badOptions')]
    public function testTheSecretAndOptionsAreChecked(string $secret, array $options, string $words): void
    {
        try {
            $this->mockSiren([])->apps->requestHandler($secret, $options);
            $this->fail('A bad option was taken.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('apps->requestHandler()', $e->getMessage());
            $this->assertStringContainsString($words, $e->getMessage());
        }
    }

    public function testOnRefusesASecondFunctionForOneFeatureAndAMissingFunction(): void
    {
        $handler = $this->handler()->on('incentiveResolver', 'split-the-reward', static fn() => null);

        foreach ([
            static fn() => $handler->on('incentiveResolver', 'split-the-reward', static fn() => null),
            static fn() => $handler->on('incentiveResolver', 'split-the-reward'),
            static fn() => $handler->on('', 'split-the-reward', static fn() => null),
            static fn() => $handler->on('incentiveResolver', '', static fn() => null),
            static fn() => $handler->onEvent('app.request', static fn() => null),
            static fn() => $handler->onEvent('', static fn() => null),
        ] as $i => $register) {
            try {
                $register();
                $this->fail('Registration ' . $i . ' was taken.');
            } catch (\InvalidArgumentException $e) {
                $this->assertMatchesRegularExpression('/->on(Event)?\(\)/', $e->getMessage());
            }
        }

        $handler->on('incentiveResolver', static fn() => null);
        $this->expectException(\InvalidArgumentException::class);
        $handler->on('incentiveResolver', static fn() => null);
    }

    public function testOnErrorDefaultsToTheErrorLog(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'siren-log');
        $previous = ini_set('error_log', (string) $log);

        try {
            $handler = $this->mockSiren([])->apps->requestHandler(self::secret())->on('postConversionAction', static function (): void {
                throw new \RuntimeException('The CRM is down.');
            });
            $body = self::body('postConversionAction');

            $this->assertSame(500, $handler->answer('POST', $body, self::signed($body))->status);
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $logged = (string) file_get_contents((string) $log);
        unlink((string) $log);
        $this->assertStringContainsString('The CRM is down.', $logged);
        $this->assertStringContainsString('postConversionAction tell-the-crm', $logged);
    }

    public function testAnOnErrorThatThrowsDoesNotChangeTheReply(): void
    {
        $handler = $this->mockSiren([])->apps->requestHandler(self::secret(), ['onError' => static function (): never {
            throw new \LogicException('The logger broke.');
        }])->on('postConversionAction', static function (): void {
            throw new \RuntimeException('The CRM is down.');
        });
        $body = self::body('postConversionAction');
        $previous = ini_set('error_log', '/dev/null');

        try {
            $this->assertSame(500, $handler->answer('POST', $body, self::signed($body))->status);
        } finally {
            ini_set('error_log', (string) $previous);
        }
    }

    // The mounts: serve() for plain PHP and psr15() for Slim and Mezzio.

    public function testServeEchoesTheReply(): void
    {
        $handler = $this->handler()->on('postConversionAction', static function (): void {
        });
        $method = $_SERVER['REQUEST_METHOD'] ?? null;

        try {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            ob_start();
            $handler->serve();
            $got = (string) ob_get_clean();

            // The CLI has no request body, so a POST here has nothing signed.
            $_SERVER['REQUEST_METHOD'] = 'POST';
            ob_start();
            $handler->serve();
            $unsigned = (string) ob_get_clean();
        } finally {
            if ($method === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $method;
            }
        }

        $this->assertStringContainsString('POST', json_decode($got, true)['error']);
        $this->assertSame('The request has no X-Siren-Signature-V2 header.', json_decode($unsigned, true)['error']);
    }

    public function testThePsr15HandlerAnswersATellAndAnAsk(): void
    {
        $later = [];
        $tells = 0;
        $handler = $this->handler([self::jsonResponse(200, ['status' => 'accepted'])], ['runLater' => function (callable $work) use (&$later): void {
            $later[] = $work;
        }])
            ->on('postConversionAction', function () use (&$tells): void {
                $tells++;
            })
            ->on('distributionResolver', static fn(): array => self::contracts()['kinds']['distributionResolver']['answer'])
            ->psr15(new HttpFactory(), new HttpFactory());

        $this->assertInstanceOf(AppRequestPsrHandler::class, $handler);

        $tell = self::body('postConversionAction');
        $response = $handler->handle(new ServerRequest('POST', '/siren', self::signed($tell), $tell));
        $this->assertSame([200, '{}', 'application/json', 'no-store'], [$response->getStatusCode(), (string) $response->getBody(), $response->getHeaderLine('Content-Type'), $response->getHeaderLine('Cache-Control')]);
        $this->assertSame(1, $tells);

        $ask = self::body('distributionResolver');
        $response = $handler->handle(new ServerRequest('POST', '/siren', self::signed($ask), $ask));
        $this->assertSame([200, '{}'], [$response->getStatusCode(), (string) $response->getBody()]);
        $this->assertSame([], $this->history);
        $this->assertCount(1, $later);

        $later[0]();
        $this->assertSame(self::contracts()['kinds']['distributionResolver']['answer'], $this->lastRequestJson());

        $response = $handler->handle(new ServerRequest('GET', '/siren'));
        $this->assertSame(405, $response->getStatusCode());
    }

    public function testThePsr15HandlerReadsABodyAMiddlewareAlreadyRead(): void
    {
        $tells = 0;
        $handler = $this->handler()->on('postConversionAction', function () use (&$tells): void {
            $tells++;
        })->psr15();
        $tell = self::body('postConversionAction');
        $stream = Utils::streamFor($tell);
        $stream->getContents();

        $response = $handler->handle((new ServerRequest('POST', '/siren', self::signed($tell)))->withBody($stream)->withParsedBody(json_decode($tell, true)));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(1, $tells);
    }
}
