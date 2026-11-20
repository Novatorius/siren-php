<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use Siren\Sdk\AppFeatureKind;
use Siren\Sdk\AppItemKind;
use Siren\Sdk\AppRequest;
use Siren\Sdk\Exception\ConflictException;

/**
 * The payout method ask. The envelope and data are the contract fixed for the
 * kind: one payout, its amount in the currency's smallest unit, and the
 * collaborator. The answer is paid with a reference or failed with a reason.
 *
 * @phpstan-import-type AppPayoutQuestion from AppFeatureKind
 * @phpstan-import-type AppPayoutAnswer from AppFeatureKind
 */
final class AppPayoutMethodTest extends TestCase
{
    private const REQUEST_ID = 'apr_9c60190a4501399d0ae55f041c237b39';

    private static function secret(): string
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/apps-exchange/signed-request.json'), true, 512, JSON_THROW_ON_ERROR);

        return $fixture['secret'];
    }

    private static function body(): string
    {
        return json_encode([
            'version' => 1,
            'id' => self::REQUEST_ID,
            'type' => 'app.request',
            'mode' => 'ask',
            'app' => 'acme-crm',
            'item' => ['kind' => 'payoutMethod', 'key' => 'acme-ledger', 'id' => 'app:12'],
            'createdAt' => '2026-11-20T10:00:00+00:00',
            'answerBy' => '2026-11-21T10:00:00+00:00',
            'answerUrl' => 'https://cloud.example.com/siren/v1/apps/requests/' . self::REQUEST_ID . '/answer',
            'data' => [
                'payout' => ['id' => 88, 'fulfillmentId' => 31, 'attempt' => 1],
                'amount' => [
                    'value' => 2500,
                    'units' => 'USD',
                    'currency' => ['id' => 'USD', 'symbol' => '$', 'position' => 'before', 'name' => 'US Dollar', 'scale' => 2, 'decimalPlaces' => 2, 'type' => 'monetary'],
                ],
                'collaborator' => ['id' => 42, 'name' => 'Ana Ruiz', 'email' => 'ana@example.com'],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, string> */
    private static function signed(string $body): array
    {
        $t = time();

        return [
            'Content-Type' => 'application/json',
            'X-Siren-Event' => 'app.request',
            'X-Siren-Delivery' => self::REQUEST_ID . '.1',
            'X-Siren-Timestamp' => (string) $t,
            'X-Siren-Signature-V2' => 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, self::secret()),
        ];
    }

    public function testTheKindIsAnAskWithTheValueSirenStores(): void
    {
        $this->assertSame('payoutMethod', AppItemKind::PAYOUT_METHOD);
        $this->assertSame('payoutMethod', AppFeatureKind::PAYOUT_METHOD);
        $this->assertContains(AppFeatureKind::PAYOUT_METHOD, AppFeatureKind::ASKS);
    }

    public function testAQuestionReadsAsTheTypedShape(): void
    {
        $siren = $this->mockSiren([]);
        $body = self::body();
        $request = $siren->apps->verifyRequest($body, self::signed($body), self::secret());

        $this->assertInstanceOf(AppRequest::class, $request);
        /** @var AppPayoutQuestion $question */
        $question = $request->data;
        $this->assertSame(88, $question['payout']['id']);
        $this->assertSame(1, $question['payout']['attempt']);
        $this->assertSame(2500, $question['amount']['value']);
        $this->assertSame('ana@example.com', $question['collaborator']['email']);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function payoutAnswers(): array
    {
        return [
            'paid' => [['outcome' => 'paid', 'reference' => 'acme-ledger-5521']],
            'failed' => [['outcome' => 'failed', 'reason' => 'The account is closed.']],
        ];
    }

    /**
     * @dataProvider payoutAnswers
     *
     * @param array<string, mixed> $answer
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('payoutAnswers')]
    public function testAnAnswerOfEachShapeIsSentAsItIs(array $answer): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, ['status' => 'accepted', 'duplicate' => false])]);

        $receipt = $siren->apps->requests->answer(self::REQUEST_ID, $answer);

        $this->assertSame('accepted', $receipt->status);
        $this->assertSame('/siren/v1/apps/requests/' . self::REQUEST_ID . '/answer', $this->lastRequest()->getUri()->getPath());
        $this->assertSame($answer, $this->lastRequestJson());
    }

    public function testTheHandlerPostsThePaidAnswerAPayoutFunctionReturns(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, ['status' => 'accepted', 'duplicate' => false])]);
        $seen = [];
        $handler = $siren->apps->requestHandler(self::secret())
            ->on('payoutMethod', 'acme-ledger', function (AppRequest $request) use (&$seen): array {
                /** @var AppPayoutQuestion $question */
                $question = $request->data;
                $seen = [$question['payout']['id'], $question['amount']['value']];
                /** @var AppPayoutAnswer $answer */
                $answer = ['outcome' => 'paid', 'reference' => 'acme-ledger-' . $question['payout']['id']];

                return $answer;
            });
        $body = self::body();

        $reply = $handler->answer('POST', $body, self::signed($body));

        $this->assertSame(200, $reply->status, $reply->body);
        $this->assertInstanceOf(\Closure::class, $reply->after);
        ($reply->after)();
        $this->assertSame([88, 2500], $seen);
        $this->assertCount(1, $this->history);
        $this->assertSame(['outcome' => 'paid', 'reference' => 'acme-ledger-88'], $this->lastRequestJson());
    }

    public function testAnAnswerAfterSirenDroppedTheAskIsAConflictSentOnce(): void
    {
        $siren = $this->mockSiren([
            self::errorResponse(409, 'Siren stopped waiting for this answer.', 'app_request_dropped'),
            self::jsonResponse(200, ['status' => 'accepted']),
        ]);

        try {
            $siren->apps->requests->answer(self::REQUEST_ID, ['outcome' => 'paid', 'reference' => 'acme-ledger-5521']);
            $this->fail('A dropped ask takes no answer.');
        } catch (ConflictException $e) {
            $this->assertSame('app_request_dropped', $e->getErrorCode());
        }

        $this->assertCount(1, $this->history);
    }
}
