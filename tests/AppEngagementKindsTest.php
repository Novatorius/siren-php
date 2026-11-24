<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use Siren\Sdk\FeatureKind;

/**
 * Slice C16-4: the engagement calculation and engagement filter kinds, and
 * an answer of each shape, which the SDK's own checks let through
 * unchanged. The shapes are Siren's AppEngagementCalculationAnswerHandler
 * and AppEngagementFilterAnswerHandler on pkt/apps-c16-4.
 */
final class AppEngagementKindsTest extends TestCase
{
    private const REQUEST_ID = 'apr_b79943ff8ca353ce5cf5312bb3fa66c7';

    public function testTheKindsMatchTheValuesSirenStoresAndAreAsks(): void
    {
        $this->assertSame('engagementCalculation', FeatureKind::ENGAGEMENT_CALCULATION);
        $this->assertSame('engagementFilter', FeatureKind::ENGAGEMENT_FILTER);
        $this->assertSame('engagementCalculation', FeatureKind::ENGAGEMENT_CALCULATION);
        $this->assertSame('engagementFilter', FeatureKind::ENGAGEMENT_FILTER);
        $this->assertContains(FeatureKind::ENGAGEMENT_CALCULATION, FeatureKind::ASKS);
        $this->assertContains(FeatureKind::ENGAGEMENT_FILTER, FeatureKind::ASKS);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function engagementAnswers(): array
    {
        return [
            'a calculation credits two collaborators' => [['outcome' => 'engagements', 'results' => [['collaboratorId' => 42, 'score' => 10], ['collaboratorId' => 57, 'score' => 2]]]],
            'a calculation credits nobody' => [['outcome' => 'engagements', 'results' => []]],
            'a filter allows' => [['outcome' => 'allow']],
            'a filter suppresses' => [['outcome' => 'suppress', 'reason' => 'The visitor works for the partner.']],
        ];
    }

    /**
     * @dataProvider engagementAnswers
     *
     * @param array<string, mixed> $answer
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('engagementAnswers')]
    public function testAnAnswerOfEachShapeIsSentAsItIs(array $answer): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, ['status' => 'accepted', 'duplicate' => false])]);

        $receipt = $siren->apps->requests->answer(self::REQUEST_ID, $answer);

        $this->assertSame('accepted', $receipt->status);
        $this->assertSame('/siren/v1/apps/requests/' . self::REQUEST_ID . '/answer', $this->lastRequest()->getUri()->getPath());
        $this->assertSame($answer, $this->lastRequestJson());
    }
}
