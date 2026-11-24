<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use ReflectionClass;
use Siren\Sdk\AppItemKind;

/**
 * Slice C16-1: the metric calculation and metric filter kinds, and an
 * answer of each shape, which the SDK's own checks let through unchanged.
 */
final class AppMetricKindsTest extends TestCase
{
    private const REQUEST_ID = 'apr_b79943ff8ca353ce5cf5312bb3fa66c7';

    public function testTheKindsMatchTheValuesSirenStores(): void
    {
        $values = array_values(array_filter((new ReflectionClass(AppItemKind::class))->getConstants(), 'is_string'));
        sort($values);

        $this->assertSame([
            'collaboratorGroupStructure',
            'customEvent',
            'distributionResolver',
            'engagementCalculation',
            'engagementFilter',
            'incentiveResolver',
            'incentiveType',
            'metricCalculation',
            'metricFilter',
            'payoutMethod',
            'postAllocationAction',
            'postConversionAction',
            'programGroupSorter',
            'transactionFilter',
        ], $values);
        $this->assertSame('metricCalculation', AppItemKind::METRIC_CALCULATION);
        $this->assertSame('metricFilter', AppItemKind::METRIC_FILTER);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function metricAnswers(): array
    {
        return [
            'a metric calculation scores' => [['outcome' => 'scores', 'results' => [['collaboratorId' => 7, 'score' => 10]]]],
            'a metric calculation scores nobody' => [['outcome' => 'scores', 'results' => []]],
            'a metric filter allows' => [['outcome' => 'allow']],
            'a metric filter suppresses' => [['outcome' => 'suppress', 'reason' => 'Customer already owned by a partner']],
        ];
    }

    /**
     * @dataProvider metricAnswers
     *
     * @param array<string, mixed> $answer
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('metricAnswers')]
    public function testAnAnswerOfEachShapeIsSentAsItIs(array $answer): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, ['status' => 'accepted', 'duplicate' => false])]);

        $receipt = $siren->apps->requests->answer(self::REQUEST_ID, $answer);

        $this->assertSame('accepted', $receipt->status);
        $this->assertSame('/siren/v1/apps/requests/' . self::REQUEST_ID . '/answer', $this->lastRequest()->getUri()->getPath());
        $this->assertSame($answer, $this->lastRequestJson());
    }
}
