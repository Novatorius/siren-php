<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

/**
 * Slice C16-2: the answer the request handler's incentive resolver sample
 * builds with `splitPool()` passes Siren's answer check for the kind.
 *
 * The question is Siren's own fixture (tests/Fixtures/apps-feature-contracts/
 * requests.json on Siren's pkt/apps-c16-2), the same file this SDK copies.
 * {@see refusalOf()} holds the rules of Siren's AppRewardQuestion::validate(),
 * in its order, and Siren's AppFeatureContractsTest runs the fixture's answer
 * through validate() itself. This test checks the sample's answer equals that
 * answer and meets the same rules, so a change on either side fails here.
 */
final class IncentiveAnswerContractTest extends TestCase
{
    public function testTheSplitPoolSampleAnswerPassesSirensCheck(): void
    {
        $contracts = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/apps-feature-contracts/requests.json'), true, 512, JSON_THROW_ON_ERROR);
        $kind = $contracts['kinds']['incentiveResolver'];
        $data = $kind['request']['data'];
        $siren = $this->mockSiren([]);

        // The sample's function, section 5.4, as README and AppRequestSamplesTest write it.
        $sample = fn ($request) => [
            'outcome' => 'rewards',
            'units' => $request->data['pool']['units'],
            'rewards' => $siren->apps->splitPool($request->data['pool'], array_column($request->data['conversions'], 'score', 'conversionId')),
        ];
        // The sample reads only the request's data.
        $request = (object) ['data' => $data];

        // As the handler posts it, JSON encoded.
        $answer = json_decode(json_encode($sample($request), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        $this->assertNull(self::refusalOf($data, $answer));
        $this->assertSame($kind['answer'], $answer);

        $answer['rewards']['1042'] = $data['pool']['value'] + 1;
        $this->assertSame('above the pool 1042', self::refusalOf($data, $answer));
    }

    /**
     * Siren's AppRewardQuestion::validate(): why it refuses the answer with
     * 422, or null.
     *
     * @param array<string, mixed> $question
     * @param array<string, mixed> $answer
     */
    private static function refusalOf(array $question, array $answer): ?string
    {
        if (($answer['outcome'] ?? null) !== 'rewards') {
            return 'outcome';
        }

        if (($answer['units'] ?? null) !== $question['pool']['units']) {
            return 'units';
        }

        $rewards = $answer['rewards'] ?? null;

        if (!is_array($rewards) || ($rewards !== [] && array_is_list($rewards))) {
            return 'rewards';
        }

        $asked = array_column($question['conversions'], 'conversionId');

        foreach ($rewards as $key => $value) {
            if (!in_array((int) $key, $asked, true) || (string) (int) $key !== (string) $key) {
                return 'unknown ' . $key;
            }

            if ($value !== null && (!is_int($value) || $value < 0)) {
                return 'not whole ' . $key;
            }

            if ($value !== null && $value > $question['pool']['value']) {
                return 'above the pool ' . $key;
            }
        }

        foreach ($asked as $id) {
            if (!array_key_exists($id, $rewards) && !array_key_exists((string) $id, $rewards)) {
                return 'missing ' . $id;
            }
        }

        return null;
    }
}
