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
     * An ask to split a sale's reward among the collaborators it converted,
     * picked on a program. The app answers
     * `['outcome' => 'rewards', 'units' => 'USD', 'rewards' => ['1042' => 900, '1043' => null]]`,
     * with the pool's units and every conversion id of the question, each a
     * whole number of 0 or more or null. The rewards wait for the answer, 24
     * hours from the first ask at most.
     */
    public const INCENTIVE_RESOLVER = 'incentiveResolver';

    private function __construct()
    {
    }
}
