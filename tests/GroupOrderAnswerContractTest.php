<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

/**
 * Slice C16-5: the program group structure kind, and the answer the request
 * handler's sample builds, which passes Siren's answer check for the kind.
 *
 * The question is Siren's own fixture (tests/Fixtures/apps-feature-contracts/
 * requests.json on Siren's pkt/apps-c16-5), the same file this SDK copies.
 * {@see refusalOf()} holds the rules of Siren's AppGroupOrderQuestion::validate(),
 * in its order, and Siren's AppFeatureContractsTest runs the fixture's answer
 * through validate() itself.
 */
final class GroupOrderAnswerContractTest extends TestCase
{
    private const REQUEST_ID = 'apr_b79943ff8ca353ce5cf5312bb3fa66c7';

    public function testTheSampleAnswerIsTheFixturesAndPassesSirensCheck(): void
    {
        $kind = self::fixture()['kinds']['programGroupSorter'];
        $data = $kind['request']['data'];

        $answer = self::answerOf($data);

        $this->assertNull(self::refusalOf($data, $answer));
        $this->assertSame($kind['answer'], $answer);
    }

    public function testATieKeepsTheGroupsOrderAndAProgramWithNoEngagementComesLast(): void
    {
        $data = self::fixture()['kinds']['programGroupSorter']['request']['data'];
        $data['programs'] = [
            ['id' => 4, 'name' => 'Quiet', 'engagements' => []],
            ['id' => 12, 'name' => 'Resellers', 'engagements' => [['engagementId' => 880, 'collaboratorId' => 42, 'score' => 40, 'createdDate' => '2026-11-02T09:15:00+00:00']]],
            ['id' => 9, 'name' => 'Affiliates', 'engagements' => [['engagementId' => 881, 'collaboratorId' => 57, 'score' => 40, 'createdDate' => '2026-11-09T16:40:00+00:00']]],
        ];

        $answer = self::answerOf($data);

        $this->assertSame([12, 9, 4], $answer['programIds']);
        $this->assertNull(self::refusalOf($data, $answer));
    }

    public function testTheRulesSirenChecksRefuseAMissingARepeatedAndAnUnaskedProgram(): void
    {
        $data = self::fixture()['kinds']['programGroupSorter']['request']['data'];

        $this->assertSame('left out 12', self::refusalOf($data, ['outcome' => 'sorted', 'programIds' => [9]]));
        $this->assertSame('twice 9', self::refusalOf($data, ['outcome' => 'sorted', 'programIds' => [9, 12, 9]]));
        $this->assertSame('not asked 40', self::refusalOf($data, ['outcome' => 'sorted', 'programIds' => [9, 12, 40]]));
        $this->assertSame('outcome', self::refusalOf($data, ['outcome' => 'allocated', 'programIds' => [9, 12]]));
    }

    public function testTheAnswerIsSentAsItIs(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, ['status' => 'accepted', 'duplicate' => false])]);
        $answer = ['outcome' => 'sorted', 'programIds' => [9, 12]];

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
            // Try the program whose best engagement scores highest first.
            $best = fn ($program) => max([0, ...array_column($program['engagements'], 'score')]);
            $programs = $request->data['programs'];
            usort($programs, fn ($a, $b) => $best($b) <=> $best($a));
            return ['outcome' => 'sorted', 'programIds' => array_column($programs, 'id')];
        };

        return json_decode(json_encode($sample((object) ['data' => $data]), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Siren's AppGroupOrderQuestion::validate(): why it refuses the answer with 422, or null.
     *
     * @param array<string, mixed> $question
     * @param array<string, mixed> $answer
     */
    private static function refusalOf(array $question, array $answer): ?string
    {
        if (($answer['outcome'] ?? null) !== 'sorted') {
            return 'outcome';
        }

        $order = $answer['programIds'] ?? null;

        if (!is_array($order) || !array_is_list($order)) {
            return 'programIds';
        }

        $asked = array_column($question['programs'], 'id');
        $seen = [];

        foreach ($order as $id) {
            if (!is_int($id)) {
                return 'not whole';
            }
            if (!in_array($id, $asked, true)) {
                return 'not asked ' . $id;
            }
            if (isset($seen[$id])) {
                return 'twice ' . $id;
            }
            $seen[$id] = true;
        }

        foreach ($asked as $id) {
            if (!isset($seen[$id])) {
                return 'left out ' . $id;
            }
        }

        return null;
    }
}
