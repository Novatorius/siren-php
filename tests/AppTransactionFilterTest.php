<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use ReflectionClass;
use Siren\Sdk\FeatureKind;
use Siren\Sdk\AppRequest;

/**
 * Slice C16-3: the transaction filter kind, and its answer of the lines to
 * include, which the request handler and the SDK's own checks send unchanged.
 * The question is the slice's example, in the envelope of Siren's own
 * distribution resolver request
 * (tests/Fixtures/apps-feature-contracts/requests.json), with the incentive
 * resolver request's transaction from the same file, since both carry the
 * same currency, signed now with the secret of tests/Fixtures/apps-exchange.
 * The README's handler sample runs here from the README's own text.
 */
final class AppTransactionFilterTest extends TestCase
{
    private const REQUEST_ID = 'apr_b79943ff8ca353ce5cf5312bb3fa66c7';

    private const LINE_ITEMS = ['outcome' => 'filtered', 'include' => [5501]];

    private const NOTHING = ['outcome' => 'filtered', 'include' => []];

    /** @return array<string, mixed> */
    private static function contracts(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/Fixtures/apps-feature-contracts/requests.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> The slice's example question, from the program side. */
    private static function question(): array
    {
        return [
            'for' => ['type' => 'program', 'id' => 3, 'name' => 'Partner program'],
            'transaction' => self::contracts()['kinds']['incentiveResolver']['request']['data']['transaction'],
            'lines' => [
                ['id' => 5501, 'type' => 'lineItem', 'name' => 'Annual plan', 'quantity' => 1, 'value' => 12000, 'productId' => 'prod_annual'],
                ['id' => 5502, 'type' => 'shipping', 'name' => 'Shipping', 'quantity' => 1, 'value' => 800, 'productId' => null],
            ],
            'distribution' => null,
            'refundDistribution' => null,
        ];
    }

    /** @return array<string, mixed> A distributor's ask, with the period the sale lands in and a later refund's. */
    private static function distributorQuestion(): array
    {
        return array_merge(self::question(), [
            'for' => ['type' => 'distributor', 'id' => 2, 'name' => 'Partner leaderboard'],
            'distribution' => ['id' => 21],
            'refundDistribution' => ['id' => 22],
        ]);
    }

    private static function secret(): string
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/apps-exchange/signed-request.json'), true, 512, JSON_THROW_ON_ERROR);

        return $fixture['secret'];
    }

    /**
     * Siren's request envelope with this kind's feature and a question, as JSON.
     *
     * @param array<string, mixed> $data
     */
    private static function body(string $key, array $data): string
    {
        $request = self::contracts()['kinds']['distributionResolver']['request'];
        $request['feature'] = ['kind' => 'transactionFilter', 'key' => $key, 'id' => 'app:11'];
        $request['data'] = $data;

        return json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, string> Headers as WebhookSignature::headers() sets them. */
    private static function signed(string $body): array
    {
        $t = time();

        return [
            'Content-Type' => 'application/json',
            'X-Siren-Event' => 'app.request',
            'X-Siren-Timestamp' => (string) $t,
            'X-Siren-Signature-V2' => 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, self::secret()),
        ];
    }

    /** The README's transaction filter sample, by its readme-test name. */
    private static function readmeSample(): string
    {
        $found = preg_match('/<!-- readme-test: answer-transaction-filter -->\n```php\n(.*?)\n```/s', (string) file_get_contents(dirname(__DIR__) . '/README.md'), $matches);
        self::assertSame(1, $found, 'The README has no transaction filter sample');

        return $matches[1];
    }

    public function testTheKindIsNamedInBothListsAsAnAsk(): void
    {
        $this->assertSame('transactionFilter', FeatureKind::TRANSACTION_FILTER);
        $this->assertSame('transactionFilter', FeatureKind::TRANSACTION_FILTER);
        $this->assertContains('transactionFilter', (new ReflectionClass(FeatureKind::class))->getConstants());
        $this->assertContains('transactionFilter', FeatureKind::ASKS);
    }

    public function testTheReadmeSampleIncludesOnlyTheLineItems(): void
    {
        $errors = [];
        $siren = $this->mockSiren([self::jsonResponse(200, ['status' => 'accepted'])], ['baseUrl' => self::FIXTURE_SIREN]);
        $handler = $siren->apps->requestHandler(self::secret(), ['onError' => function (\Throwable $error) use (&$errors): void {
            $errors[] = $error;
        }]);
        // The README's chained call, run on this handler as written.
        $handler = eval('return $handler' . "\n" . self::readmeSample() . ';');

        $body = self::body('plan-lines', self::question());
        $reply = $handler->answer('POST', $body, self::signed($body));
        $this->assertSame(200, $reply->status, $reply->body);
        $this->assertNotNull($reply->after);
        ($reply->after)();

        $this->assertSame([], $errors);
        $this->assertSame('/siren/v1/apps/requests/' . json_decode($body, true)['id'] . '/answer', $this->lastRequest()->getUri()->getPath());
        $this->assertSame(self::LINE_ITEMS, $this->lastRequestJson());
    }

    public function testTheHandlerPostsADistributorsAnswerAsTheFunctionReturnsIt(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, ['status' => 'accepted'])], ['baseUrl' => self::FIXTURE_SIREN]);
        $handler = $siren->apps->requestHandler(self::secret());
        $handler->on('transactionFilter', 'nothing-late', static function (AppRequest $request): array {
            $late = $request->data['distribution'] !== null && $request->data['refundDistribution'] !== null;

            return ['outcome' => 'filtered', 'include' => $late ? [] : array_column($request->data['lines'], 'id')];
        });

        $body = self::body('nothing-late', self::distributorQuestion());
        $reply = $handler->answer('POST', $body, self::signed($body));
        $this->assertSame(200, $reply->status, $reply->body);
        $this->assertNotNull($reply->after);
        ($reply->after)();

        $this->assertSame(self::NOTHING, $this->lastRequestJson());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function filterAnswers(): array
    {
        return [
            'the lines to include' => [self::LINE_ITEMS],
            'an empty list' => [self::NOTHING],
        ];
    }

    /**
     * @dataProvider filterAnswers
     *
     * @param array<string, mixed> $answer
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('filterAnswers')]
    public function testAnAnswerIsSentAsItIs(array $answer): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, ['status' => 'accepted', 'duplicate' => false])], ['baseUrl' => self::FIXTURE_SIREN]);

        $receipt = $siren->apps->requests->answer(self::REQUEST_ID, $answer);

        $this->assertSame('accepted', $receipt->status);
        $this->assertSame('/siren/v1/apps/requests/' . self::REQUEST_ID . '/answer', $this->lastRequest()->getUri()->getPath());
        $this->assertSame($answer, $this->lastRequestJson());
    }
}
