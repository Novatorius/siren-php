<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * The kinds of feature an app can add, as Siren stores them in an item's
 * `kind` and sends them in an app request's `item.kind`. Compare
 * {@see AppRequest::$item} against these to pick the code that answers.
 */
final class AppItemKind
{
    /** A tell after each approved conversion, picked on a program. */
    public const POST_CONVERSION_ACTION = 'postConversionAction';

    /** A tell after each completed allocation, picked on a distributor. */
    public const POST_ALLOCATION_ACTION = 'postAllocationAction';

    /** An ask to pay each payout, picked on an autofulfiller. */
    public const PAYOUT_METHOD = 'payoutMethod';

    /** An ask to divide each pool, picked on a distributor. */
    public const DISTRIBUTION_RESOLVER = 'distributionResolver';

        /** An event of the app's own, which the app sends Siren. Siren sends it nothing. */
    public const CUSTOM_EVENT = 'customEvent';

    /**
     * An ask to score a metric, picked on a distributor's metric type. The
     * app answers `['outcome' => 'scores', 'results' => [['collaboratorId' => 7, 'score' => 10]]]`.
     * Siren takes at most 50 results, each collaborator once, each in the
     * organization and not in the trash, and each score a whole number of 0
     * or more. An empty list scores nobody.
     */
    public const METRIC_CALCULATION = 'metricCalculation';

    /**
     * An ask whether one collaborator's metric counts, picked on a
     * distributor. The app answers `['outcome' => 'allow']`, or
     * `['outcome' => 'suppress', 'reason' => '...']` with a plain text reason
     * of 1 to 200 characters.
     */
    public const METRIC_FILTER = 'metricFilter';

    /**
     * An ask to split a sale's reward among the collaborators it converted,
     * picked on a program. The app answers
     * `['outcome' => 'rewards', 'units' => 'USD', 'rewards' => ['1042' => 900, '1043' => null]]`,
     * with the pool's units and every conversion id of the question, each a
     * whole number of 0 or more and no more than the pool, or null. The
     * rewards wait for the answer, at most 24 hours from when the sale's reward
     * began to wait.
     */
    public const INCENTIVE_RESOLVER = 'incentiveResolver';

    /**
     * An ask who earns an engagement, and its score, picked on a program's
     * engagement type. The app answers
     * `['outcome' => 'engagements', 'results' => [['collaboratorId' => 7, 'score' => 10]]]`.
     * Siren takes at most 50 results, each collaborator once, each in the
     * organization and not in the trash, and each score a whole number of 0
     * or more. An empty list makes no engagement. Siren makes no engagement
     * until the answer, at most 24 hours from the trigger, or from the sale that
     * waits on the answer when that sale came first.
     */
    public const ENGAGEMENT_CALCULATION = 'engagementCalculation';

    /**
     * An ask whether an engagement may be made for one collaborator, picked
     * on a program. The app answers `['outcome' => 'allow']`, or
     * `['outcome' => 'suppress', 'reason' => '...']` with a plain text reason
     * of 1 to 200 characters, which Siren notes on the opportunity.
     */
    public const ENGAGEMENT_FILTER = 'engagementFilter';

    private function __construct()
    {
    }
}
