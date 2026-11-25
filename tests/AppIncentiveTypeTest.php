<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use Siren\Sdk\FeatureKind;

/**
 * Slice C16-6: the incentive type kind, and the answer the request handler's
 * sample builds, which passes Siren's answer check for the kind.
 *
 * The question is Siren's own fixture (tests/Fixtures/apps-feature-contracts/
 * requests.json on Siren's pkt/apps-c16-6), the same file this SDK copies.
 * {@see refusalOf()} holds the rules of Siren's
 * AppIncentiveTypeQuestion::validate(), in its order, and Siren's
 * AppFeatureContractsTest runs the fixture's answer through validate() itself.
 */
final class AppIncentiveTypeTest extends TestCase
{
    private const REQUEST_ID = 'apr_b79943ff8ca353ce5cf5312bb3fa66c7';

    public function testTheKindIsTheValueSirenStoresAndAnAsk(): void
    {
        $this->assertSame('incentiveType', FeatureKind::INCENTIVE_TYPE);
        $this->assertSame('incentiveType', FeatureKind::INCENTIVE_TYPE);
        $this->assertContains(FeatureKind::INCENTIVE_TYPE, FeatureKind::ASKS);
        $this->assertNotContains(FeatureKind::INCENTIVE_TYPE, FeatureKind::TELLS);
    }

    public function testTheSampleAnswerIsTheFixturesAndPassesSirensCheck(): void
    {
        $kind = self::fixture()['kinds']['incentiveType'];
        $data = $kind['request']['data'];

        $answer = self::answerOf($data);

        $this->assertNull(self::refusalOf($data, $answer));
        $this->assertSame($kind['answer'], $answer);
    }

    public function testTheSampleAwardsNothingForALead(): void
    {
        $data = self::fixture()['kinds']['incentiveType']['request']['data'];
        $data['transaction'] = null;
        $data['binding'] = ['type' => 'crm_lead', 'id' => 'L-1'];

        $answer = self::answerOf($data);

        $this->assertSame(['outcome' => 'none'], $answer);
        $this->assertNull(self::refusalOf($data, $answer));
    }

    public function testTheRulesSirenChecksRefuseOtherUnitsANegativePoolAndAnUnaskedOrRepeatedEngagement(): void
    {
        $data = self::fixture()['kinds']['incentiveType']['request']['data'];
        $award = ['outcome' => 'award', 'units' => 'USD', 'pool' => 1500, 'engagementIds' => [880]];

        $this->assertSame('units', self::refusalOf($data, ['units' => 'EUR'] + $award));
        $this->assertSame('pool', self::refusalOf($data, ['pool' => -1] + $award));
        $this->assertSame('engagementIds', self::refusalOf($data, ['engagementIds' => []] + $award));
        $this->assertSame('not asked 999', self::refusalOf($data, ['engagementIds' => [880, 999]] + $award));
        $this->assertSame('twice 880', self::refusalOf($data, ['engagementIds' => [880, 880]] + $award));
        $this->assertSame('extra', self::refusalOf($data, ['outcome' => 'none', 'pool' => 0]));
        $this->assertSame('outcome', self::refusalOf($data, ['outcome' => 'rewards'] + $award));
    }

    public function testTheAnswerIsSentAsItIs(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, ['status' => 'accepted', 'duplicate' => false])]);
        $answer = ['outcome' => 'award', 'units' => 'USD', 'pool' => 1500, 'engagementIds' => [880, 881]];

        $receipt = $siren->apps->requests->answer(self::REQUEST_ID, $answer);

        $this->assertSame('accepted', $receipt->status);
        $this->assertSame('/siren/v1/apps/requests/' . self::REQUEST_ID . '/answer', $this->lastRequest()->getUri()->getPath());
        $this->assertSame($answer, $this->lastRequestJson());
    }

    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/Fixtures/apps-feature-contracts/requests.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The sample's function, as README and AppRequestSamplesTest write it,
     * JSON encoded as the handler posts it.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function answerOf(array $data): array
    {
        $sample = function ($request) {
            // A tenth of the sale, shared by every engagement. A lead earns nothing here.
            $sale = $request->data['transaction'];
            if (!$sale || $sale['units'] !== $request->data['program']['units']) {
                return ['outcome' => 'none'];
            }
            return ['outcome' => 'award', 'units' => $sale['units'], 'pool' => intdiv($sale['total'], 10), 'engagementIds' => array_column($request->data['engagements'], 'engagementId')];
        };

        return json_decode(json_encode($sample((object) ['data' => $data]), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Siren's AppIncentiveTypeQuestion::validate(): why it refuses the answer
     * with 422, or null.
     *
     * @param array<string, mixed> $question
     * @param array<string, mixed> $answer
     */
    private static function refusalOf(array $question, array $answer): ?string
    {
        $outcome = $answer['outcome'] ?? null;

        if ($outcome === 'none') {
            return array_diff(array_keys($answer), ['outcome']) === [] ? null : 'extra';
        }

        if ($outcome !== 'award') {
            return 'outcome';
        }

        if (($answer['units'] ?? null) !== $question['program']['units']) {
            return 'units';
        }

        if (!is_int($answer['pool'] ?? null) || $answer['pool'] < 0) {
            return 'pool';
        }

        $ids = $answer['engagementIds'] ?? null;

        if (!is_array($ids) || !array_is_list($ids) || $ids === []) {
            return 'engagementIds';
        }

        $asked = array_column($question['engagements'], 'engagementId');
        $seen = [];

        foreach ($ids as $id) {
            if (!is_int($id) || !in_array($id, $asked, true)) {
                return 'not asked ' . (is_scalar($id) ? (string) $id : '?');
            }
            if (isset($seen[$id])) {
                return 'twice ' . $id;
            }
            $seen[$id] = true;
        }

        return array_diff(array_keys($answer), ['outcome', 'units', 'pool', 'engagementIds']) === [] ? null : 'extra';
    }
}
