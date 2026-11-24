<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * Siren's own check of an ask's answer, ported from Siren's feat/apps branch
 * so {@see AppRequestHandler::simulate()} refuses what Siren would refuse,
 * with Siren's words. Siren's answer route, AppRequestService::answer() in
 * lib/Apps/Service/Services/AppRequestService.php, calls the kind's
 * handler's `validate()` and sends what it returns as 422
 * `app_answer_invalid`. Each rule below names the Siren file and method it
 * comes from. The first rule an answer breaks gives the message, as in Siren.
 *
 * The route's own checks, a JSON object of at most 16384 bytes with a text
 * `outcome`, run first in {@see Resources\AppRequests::check()}, with the
 * SDK's words. Siren's checks that need its database are left out: that a
 * collaborator is in the organization and not in the trash, and that a
 * collaborator merged away counts as the one it merged into. A kind this SDK
 * does not know, taken through `extraKinds`, gets no check here.
 *
 * @internal
 */
final class AppAnswerCheck
{
    /** AppMetricCalculationAnswerHandler::MAX_RESULTS and AppEngagementCalculationAnswerHandler::MAX_RESULTS. */
    private const MAX_RESULTS = 50;

    /** AppMetricFilterAnswerHandler::MAX_REASON and AppEngagementFilterAnswerHandler::MAX_REASON. */
    private const MAX_FILTER_REASON = 200;

    /** AppPayoutQuestion::MAX_REFERENCE. */
    private const MAX_PAYOUT_REFERENCE = 191;

    /** AppPayoutQuestion::MAX_REASON. */
    private const MAX_PAYOUT_REASON = 500;

    private function __construct()
    {
    }

    /**
     * Why Siren would refuse the answer, in Siren's words, or null when it
     * would take it.
     *
     * @param array<string, mixed>     $question The request's `data`.
     * @param array<int|string, mixed> $answer   The answer as the function returned it.
     */
    public static function problem(string $kind, array $question, array $answer): ?string
    {
        // Siren reads the answer from JSON, so a list becomes keys 0, 1 and
        // on, and an id key such as '41' becomes the integer 41, as here.
        $answer = json_decode(json_encode($answer, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($answer)) {
            return null;
        }

        return match ($kind) {
            FeatureKind::DISTRIBUTION_RESOLVER => self::distribution($question, $answer),
            FeatureKind::INCENTIVE_RESOLVER => self::reward($question, $answer),
            FeatureKind::INCENTIVE_TYPE => self::incentiveType($question, $answer),
            FeatureKind::PAYOUT_METHOD => self::payout($answer),
            FeatureKind::METRIC_CALCULATION => self::results('scores', $answer),
            FeatureKind::ENGAGEMENT_CALCULATION => self::results('engagements', $answer),
            FeatureKind::METRIC_FILTER, FeatureKind::ENGAGEMENT_FILTER => self::filter($answer),
            FeatureKind::PROGRAM_GROUP_SORTER => self::groupOrder($question, $answer),
            FeatureKind::TRANSACTION_FILTER => self::transactionFilter($question, $answer),
            FeatureKind::COLLABORATOR_GROUP_STRUCTURE => self::groupStructure($question, $answer),
            default => null,
        };
    }

    /**
     * lib/Apps/Service/Resolvers/AppDistributionQuestion.php, unfitness(),
     * its rules 1 to 9.
     *
     * @param array<string, mixed>     $question
     * @param array<int|string, mixed> $answer
     */
    private static function distribution(array $question, array $answer): ?string
    {
        $offer = (array) ($question['pool'] ?? []);
        $units = (string) ($offer['units'] ?? '');
        $currency = (array) ($offer['currency'] ?? []);
        $asked = array_map(static fn ($row): int => (int) ((array) $row)['metricId'], (array) ($question['metrics'] ?? []));

        // Rule 1.
        if (($answer['outcome'] ?? null) !== 'allocated') {
            return 'outcome must be allocated';
        }

        // Rule 2.
        if (($answer['units'] ?? null) !== $units) {
            return sprintf('units must be %s, the units of the pool', $units);
        }

        // Rule 3.
        $shares = $answer['shares'] ?? null;

        if (!is_array($shares)) {
            return 'shares must be an object of metric id to amount';
        }

        // Rule 4. A key that is not a plain integer names no metric.
        foreach (array_keys($shares) as $metricId) {
            if (!in_array((int) $metricId, $asked, true) || (string) (int) $metricId !== (string) $metricId) {
                return sprintf('shares names metric %s, which this distribution does not hold', $metricId);
            }
        }

        // Rule 5.
        foreach ($shares as $metricId => $amount) {
            if (!is_int($amount)) {
                return sprintf('the share for metric %s must be a whole number of the smallest unit', $metricId);
            }
        }

        /** @var array<int|string, int> $shares */

        // Rule 6.
        foreach ($shares as $metricId => $amount) {
            if ($amount < 0) {
                return sprintf('the share for metric %s must not be below 0', $metricId);
            }
        }

        // Rule 7. 1 for USD and for points, 100 for JPY.
        $scale = (int) ($currency['scale'] ?? 0);
        $places = (int) ($currency['decimalPlaces'] ?? $scale);
        $step = (int) (10 ** max(0, $scale - $places));

        foreach ($shares as $metricId => $amount) {
            if ($amount % $step !== 0) {
                return sprintf('the share for metric %s must be a multiple of %d, as %s amounts have no smaller part', $metricId, $step, $units);
            }
        }

        // Rule 8.
        foreach ($asked as $metricId) {
            if (!array_key_exists($metricId, $shares)) {
                return sprintf('shares has no amount for metric %d, and every metric needs one, 0 included', $metricId);
            }
        }

        // Rule 9. A sum past PHP_INT_MAX is a float, and its exact value is lost.
        $sum = array_sum($shares);
        $pool = (int) ($offer['value'] ?? 0);

        if ($sum > $pool) {
            return is_int($sum)
                ? sprintf('the shares add up to %d, more than the pool of %d', $sum, $pool)
                : sprintf('the shares add up to more than the pool of %d', $pool);
        }

        return null;
    }

    /**
     * lib/Apps/Service/Rewards/AppRewardQuestion.php, validate(), with its
     * conversionIds().
     *
     * @param array<string, mixed>     $question
     * @param array<int|string, mixed> $answer
     */
    private static function reward(array $question, array $answer): ?string
    {
        if (($answer['outcome'] ?? null) !== 'rewards') {
            return 'The answer\'s outcome must be "rewards".';
        }

        $pool = (array) ($question['pool'] ?? []);
        $units = (string) ($pool['units'] ?? '');

        if (($answer['units'] ?? null) !== $units) {
            return sprintf('The answer\'s units must be "%s", the units of the pool Siren offered.', $units);
        }

        $rewards = $answer['rewards'] ?? null;

        if (!is_array($rewards) || ($rewards !== [] && array_is_list($rewards))) {
            return 'The answer\'s rewards must be an object with one key per conversion id in the question.';
        }

        $asked = self::idsOf($question, 'conversions', 'conversionId');
        $value = (int) ($pool['value'] ?? 0);

        foreach ($rewards as $key => $reward) {
            if (!in_array((int) $key, $asked, true) || (string) (int) $key !== (string) $key) {
                return sprintf('The answer names conversion %s, which the question does not hold.', (string) $key);
            }

            if ($reward !== null && (!is_int($reward) || $reward < 0)) {
                return sprintf('The reward for conversion %s must be null or a whole number at or above 0, in the stored integer form.', (string) $key);
            }

            if ($reward !== null && $reward > $value) {
                return sprintf('The reward for conversion %s is %d, more than the pool of %d Siren offered.', (string) $key, $reward, $value);
            }
        }

        foreach ($asked as $id) {
            if (!array_key_exists($id, $rewards)) {
                return sprintf('The answer has no reward for conversion %d. Give every conversion of the question a key, null for one the app did not choose.', $id);
            }
        }

        return null;
    }

    /**
     * lib/Apps/Service/IncentiveTypes/AppIncentiveTypeQuestion.php,
     * validate(), with its askedEngagementIds().
     *
     * @param array<string, mixed>     $question
     * @param array<int|string, mixed> $answer
     */
    private static function incentiveType(array $question, array $answer): ?string
    {
        $outcome = $answer['outcome'] ?? null;

        if ($outcome === 'none') {
            $extra = array_diff(array_keys($answer), ['outcome']);

            return $extra === [] ? null : sprintf('An answer of "none" carries nothing else, and this one has %s.', implode(', ', $extra));
        }

        if ($outcome !== 'award') {
            return 'The answer\'s outcome must be "award" or "none".';
        }

        $units = (string) (((array) ($question['program'] ?? []))['units'] ?? '');

        if (($answer['units'] ?? null) !== $units) {
            return sprintf('The answer\'s units must be "%s", the program\'s units.', $units);
        }

        $pool = $answer['pool'] ?? null;

        if (!is_int($pool) || $pool < 0) {
            return 'The answer\'s pool must be a whole number at or above 0, in the stored integer form.';
        }

        $ids = $answer['engagementIds'] ?? null;

        if (!is_array($ids) || !array_is_list($ids) || $ids === []) {
            return 'The answer\'s engagementIds must be a list of at least one engagement id from the question.';
        }

        $asked = self::idsOf($question, 'engagements', 'engagementId');
        $seen = [];

        foreach ($ids as $id) {
            if (!is_int($id) || !in_array($id, $asked, true)) {
                return sprintf('The answer names engagement %s, which the question does not hold.', is_scalar($id) ? (string) $id : (string) json_encode($id));
            }

            if (isset($seen[$id])) {
                return sprintf('The answer names engagement %d more than once.', $id);
            }

            $seen[$id] = true;
        }

        $extra = array_diff(array_keys($answer), ['outcome', 'units', 'pool', 'engagementIds']);

        return $extra === [] ? null : sprintf('The answer has fields Siren does not read: %s.', implode(', ', $extra));
    }

    /**
     * lib/Apps/Service/Payouts/AppPayoutQuestion.php, validate().
     *
     * @param array<int|string, mixed> $answer
     */
    private static function payout(array $answer): ?string
    {
        $outcome = $answer['outcome'] ?? null;

        if ($outcome === 'paid') {
            $reference = $answer['reference'] ?? null;

            if (!is_string($reference) || trim($reference) === '') {
                return 'A paid answer names the payment\'s reference in your system, as "reference".';
            }

            if (mb_strlen(trim($reference)) > self::MAX_PAYOUT_REFERENCE) {
                return sprintf('A reference is at most %d characters.', self::MAX_PAYOUT_REFERENCE);
            }

            return null;
        }

        if ($outcome === 'failed') {
            $reason = $answer['reason'] ?? null;

            if (!is_string($reason) || trim($reason) === '') {
                return 'A failed answer says why in plain words, as "reason".';
            }

            if (mb_strlen(trim($reason)) > self::MAX_PAYOUT_REASON) {
                return sprintf('A reason is at most %d characters.', self::MAX_PAYOUT_REASON);
            }

            return null;
        }

        return 'The outcome is "paid" or "failed".';
    }

    /**
     * lib/Apps/Service/Handlers/AppMetricCalculationAnswerHandler.php and
     * lib/Apps/Service/Engagements/AppEngagementCalculationAnswerHandler.php,
     * validate(), which differ only in the outcome. Siren also refuses a
     * collaborator who is not in the organization or is in the trash, which
     * needs its database.
     *
     * @param array<int|string, mixed> $answer
     */
    private static function results(string $outcome, array $answer): ?string
    {
        if (($answer['outcome'] ?? null) !== $outcome) {
            return sprintf('outcome must be %s.', $outcome);
        }

        $results = $answer['results'] ?? null;

        if (!is_array($results) || !array_is_list($results)) {
            return 'results must be a list of {collaboratorId, score}.';
        }

        if (count($results) > self::MAX_RESULTS) {
            return sprintf('results may hold at most %d entries, and this holds %d.', self::MAX_RESULTS, count($results));
        }

        $seen = [];

        foreach ($results as $i => $result) {
            $collaboratorId = is_array($result) ? ($result['collaboratorId'] ?? null) : null;
            $score = is_array($result) ? ($result['score'] ?? null) : null;

            if (!is_int($collaboratorId) || $collaboratorId < 1) {
                return sprintf('results[%d].collaboratorId must be a collaborator id.', $i);
            }

            if (!is_int($score) || $score < 0) {
                return sprintf('results[%d].score must be a whole number of 0 or more.', $i);
            }

            if (isset($seen[$collaboratorId])) {
                return sprintf('results names collaborator %d more than once.', $collaboratorId);
            }

            $seen[$collaboratorId] = true;
        }

        return null;
    }

    /**
     * lib/Apps/Service/Handlers/AppMetricFilterAnswerHandler.php and
     * lib/Apps/Service/Engagements/AppEngagementFilterAnswerHandler.php,
     * validate(), which are the same.
     *
     * @param array<int|string, mixed> $answer
     */
    private static function filter(array $answer): ?string
    {
        $outcome = $answer['outcome'] ?? null;

        if ($outcome === 'allow') {
            return null;
        }

        if ($outcome !== 'suppress') {
            return 'outcome must be allow or suppress.';
        }

        $reason = $answer['reason'] ?? null;

        if (!is_string($reason) || trim($reason) === '') {
            return 'A suppress answer gives a reason, in plain text.';
        }

        if (mb_strlen($reason) > self::MAX_FILTER_REASON) {
            return sprintf('reason may be at most %d characters, and this is %d.', self::MAX_FILTER_REASON, mb_strlen($reason));
        }

        if (preg_match('/[\x00-\x1F\x7F]|<[^>]*>/u', $reason) === 1) {
            return 'reason must be plain text, with no markup or control characters.';
        }

        return null;
    }

    /**
     * lib/Apps/Service/GroupOrders/AppGroupOrderQuestion.php, validate(),
     * with its programIds().
     *
     * @param array<string, mixed>     $question
     * @param array<int|string, mixed> $answer
     */
    private static function groupOrder(array $question, array $answer): ?string
    {
        if (($answer['outcome'] ?? null) !== 'sorted') {
            return 'outcome must be sorted.';
        }

        $order = $answer['programIds'] ?? null;

        if (!is_array($order) || !array_is_list($order)) {
            return 'programIds must be a list of the question\'s program ids.';
        }

        $asked = self::idsOf($question, 'programs', 'id');
        $seen = [];

        foreach ($order as $id) {
            if (!is_int($id)) {
                return 'Each program id is a whole number.';
            }

            if (!in_array($id, $asked, true)) {
                return sprintf('Program %d is not one of the programs Siren asked about.', $id);
            }

            if (isset($seen[$id])) {
                return sprintf('Program %d is listed more than once.', $id);
            }

            $seen[$id] = true;
        }

        foreach ($asked as $id) {
            if (!isset($seen[$id])) {
                return sprintf('The answer leaves out program %d. List every program Siren asked about.', $id);
            }
        }

        return null;
    }

    /**
     * lib/Apps/Service/TransactionFilters/AppTransactionFilterQuestion.php,
     * validate(), with its lineIds(), which a program's lines and a
     * distributor's both use.
     *
     * @param array<string, mixed>     $question
     * @param array<int|string, mixed> $answer
     */
    private static function transactionFilter(array $question, array $answer): ?string
    {
        if (($answer['outcome'] ?? null) !== 'filtered') {
            return 'The answer\'s outcome must be "filtered".';
        }

        $include = $answer['include'] ?? null;

        if (!is_array($include) || !array_is_list($include)) {
            return 'The answer\'s include must be a list of line ids from the question.';
        }

        $asked = self::idsOf($question, 'lines', 'id');
        $seen = [];

        foreach ($include as $id) {
            if (!is_int($id)) {
                return 'Each id in the answer\'s include must be a whole number, a line id from the question.';
            }

            if (!in_array($id, $asked, true)) {
                return sprintf('The answer includes line %d, which is not a line of the sale Siren asked about.', $id);
            }

            if (isset($seen[$id])) {
                return sprintf('The answer includes line %d more than once.', $id);
            }

            $seen[$id] = true;
        }

        return null;
    }

    /**
     * lib/Apps/Service/Handlers/AppGroupStructureAnswerHandler.php,
     * problem(), with the members from AppGroupStructureQuestion::memberIds()
     * in lib/Apps/Service/Items/AppGroupStructureQuestion.php. Siren also
     * counts a member merged away as the one it merged into, which needs its
     * database.
     *
     * @param array<string, mixed>     $question
     * @param array<int|string, mixed> $answer
     */
    private static function groupStructure(array $question, array $answer): ?string
    {
        if (($answer['outcome'] ?? null) !== 'structure') {
            return 'outcome must be structure.';
        }

        $members = [];

        foreach (is_array($question['members'] ?? null) ? $question['members'] : [] as $member) {
            if (is_array($member) && is_int($member['collaboratorId'] ?? null)) {
                $members[] = $member['collaboratorId'];
            }
        }

        $members = array_values(array_unique($members));

        return match ($answer['shape'] ?? null) {
            'chain' => self::chainProblem($answer['order'] ?? null, $members),
            'tree' => self::treeProblem($answer['parents'] ?? null, $members),
            default => 'shape must be chain or tree.',
        };
    }

    /**
     * lib/Apps/Service/Handlers/AppGroupStructureAnswerHandler.php, chainProblem().
     *
     * @param list<int> $members
     */
    private static function chainProblem(mixed $order, array $members): ?string
    {
        if (!is_array($order) || !array_is_list($order)) {
            return 'order must be a list of the members\' collaborator ids, top first.';
        }

        $isMember = array_fill_keys($members, true);
        $seen = [];

        foreach ($order as $i => $collaboratorId) {
            if (!is_int($collaboratorId)) {
                return sprintf('order[%d] must be a collaborator id.', $i);
            }

            if (!isset($isMember[$collaboratorId])) {
                return sprintf('order[%d] names collaborator %d, who is not a member in the question.', $i, $collaboratorId);
            }

            if (isset($seen[$collaboratorId])) {
                return sprintf('order names collaborator %d more than once.', $collaboratorId);
            }

            $seen[$collaboratorId] = true;
        }

        $missing = array_values(array_diff($members, array_keys($seen)));

        return $missing === [] ? null : sprintf('order leaves out member %d. List every member of the question once.', $missing[0]);
    }

    /**
     * lib/Apps/Service/Handlers/AppGroupStructureAnswerHandler.php, treeProblem().
     *
     * @param list<int> $members
     */
    private static function treeProblem(mixed $parents, array $members): ?string
    {
        if (!is_array($parents)) {
            return 'parents must be an object of each member\'s collaborator id to its parent\'s, or null at the top.';
        }

        $isMember = array_fill_keys($members, true);
        $parentOf = [];

        foreach ($parents as $key => $parentId) {
            $collaboratorId = filter_var($key, FILTER_VALIDATE_INT);

            if ($collaboratorId === false || !isset($isMember[$collaboratorId])) {
                return sprintf('parents names %s, which is not a member in the question.', (string) $key);
            }

            if ($parentId !== null && !is_int($parentId)) {
                return sprintf('parents["%d"] must be a member\'s collaborator id or null.', $collaboratorId);
            }

            if ($parentId !== null && !isset($isMember[$parentId])) {
                return sprintf('parents["%d"] names collaborator %d, who is not a member in the question.', $collaboratorId, $parentId);
            }

            if ($parentId === $collaboratorId) {
                return sprintf('parents["%d"] names the member as their own parent.', $collaboratorId);
            }

            $parentOf[$collaboratorId] = $parentId;
        }

        $missing = array_values(array_diff($members, array_keys($parentOf)));

        if ($missing !== []) {
            return sprintf('parents leaves out member %d. Name every member of the question once, with null at the top.', $missing[0]);
        }

        // Each walk up ends at the top or at a member already known to end
        // there. A walk that meets itself is a cycle.
        $endsAtTop = [];

        foreach (array_keys($parentOf) as $start) {
            $path = [];
            $at = $start;

            while ($at !== null && !isset($endsAtTop[$at])) {
                if (isset($path[$at])) {
                    return sprintf('parents form a cycle through member %d. Every member must reach the top.', $at);
                }

                $path[$at] = true;
                $at = $parentOf[$at];
            }

            $endsAtTop += $path;
        }

        return null;
    }

    /**
     * The ids of the question's rows, as Siren's conversionIds(),
     * askedEngagementIds(), programIds() and lineIds() read them: each row
     * that is an array with the field set, cast to an integer.
     *
     * @param array<string, mixed> $question
     *
     * @return list<int>
     */
    private static function idsOf(array $question, string $rows, string $field): array
    {
        $ids = [];

        foreach ((array) ($question[$rows] ?? []) as $row) {
            if (is_array($row) && isset($row[$field])) {
                $ids[] = (int) $row[$field];
            }
        }

        return $ids;
    }
}
