<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use ReflectionClass;
use Siren\Sdk\AppItemKind;
use Siren\Sdk\Exception\ConflictException;

/**
 * Slice C16-2: the incentive resolver kind, its answer, which the SDK's own
 * checks let through unchanged, and the 409 Siren answers once it stopped
 * waiting. The question and answer are Siren's example in
 * tests/Fixtures/apps-feature-contracts/requests.json on its pkt/apps-c16-2.
 */
final class AppIncentiveResolverTest extends TestCase
{
    private const REQUEST_ID = 'apr_b79943ff8ca353ce5cf5312bb3fa66c7';

    public function testTheKindsMatchTheValuesSirenStores(): void
    {
        $values = array_values((new ReflectionClass(AppItemKind::class))->getConstants());
        sort($values);

        $this->assertSame([
            'customEvent',
            'distributionResolver',
            'incentiveResolver',
            'metricCalculation',
            'metricFilter',
            'payoutMethod',
            'postAllocationAction',
            'postConversionAction',
            'programGroupSorter',
        ], $values);
        $this->assertSame('incentiveResolver', AppItemKind::INCENTIVE_RESOLVER);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function rewardAnswers(): array
    {
        return [
            'split by score' => [['outcome' => 'rewards', 'units' => 'USD', 'rewards' => ['1042' => 900, '1043' => 600]]],
            'one collaborator earns nothing' => [['outcome' => 'rewards', 'units' => 'USD', 'rewards' => ['1042' => 1500, '1043' => null]]],
        ];
    }

    /**
     * @dataProvider rewardAnswers
     *
     * @param array<string, mixed> $answer
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rewardAnswers')]
    public function testAnAnswerOfEachShapeIsSentAsItIs(array $answer): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, ['status' => 'accepted', 'duplicate' => false])]);

        $receipt = $siren->apps->requests->answer(self::REQUEST_ID, $answer);

        $this->assertSame('accepted', $receipt->status);
        $this->assertSame('/siren/v1/apps/requests/' . self::REQUEST_ID . '/answer', $this->lastRequest()->getUri()->getPath());
        $this->assertSame($answer, $this->lastRequestJson());
    }

    public function testAnAnswerAfterSirenStoppedWaitingIsAConflictSentOnce(): void
    {
        // AppRequestService::dropped() on Siren's pkt/apps-c16-2.
        $siren = $this->mockSiren([
            self::errorResponse(409, "Siren stopped waiting for this answer: the app didn't answer within 24 hours, so the work went on without it.", 'app_request_dropped'),
            self::jsonResponse(200, ['status' => 'accepted']),
        ]);

        try {
            $siren->apps->requests->answer(self::REQUEST_ID, ['outcome' => 'rewards', 'units' => 'USD', 'rewards' => ['1042' => 900, '1043' => 600]]);
            $this->fail('A dropped ask takes no answer.');
        } catch (ConflictException $e) {
            $this->assertSame('app_request_dropped', $e->getErrorCode());
        }

        $this->assertCount(1, $this->history);
    }
}
