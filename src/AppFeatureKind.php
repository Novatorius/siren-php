<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * The kinds of app feature Siren sends requests for, the `item.kind` of an
 * {@see AppRequest}, and the shape of each kind's question, the request's
 * `data`, and answer. Siren's own builders make the questions
 * (lib/Apps/Service in Siren). Every amount is the stored integer, and a
 * currency's shown amount is `value / 10 ** scale`.
 *
 * PHPStan and Psalm read the shapes with `@phpstan-import-type`:
 *
 * ```php
 * /** @phpstan-import-type AppDistributionAnswer from AppFeatureKind *\/
 * ```
 *
 * @phpstan-type AppCurrency array{id: string, symbol: string, position: string, name: string, scale: int, decimalPlaces: int, type: string}
 * @phpstan-type AppConversionTell array{
 *     conversion: array{id: int, type: string, status: string, engagementId: int, opportunityId: int, collaboratorId: int, transactionId: int|null, obligationId: int|null},
 *     reward: array{value: int, units: string, currency: AppCurrency},
 *     program: array{id: int, name: string},
 * }
 * @phpstan-type AppAllocationTell array{
 *     allocation: array{distributionId: int, obligationId: int, collaboratorId: int, status: string, value: int, units: string, currency: AppCurrency},
 *     distributor: array{id: int, name: string},
 * }
 * @phpstan-type AppDistributionQuestion array{
 *     distribution: array{id: int, distributorId: int, periodStart: string, periodEnd: string},
 *     distributor: array{id: int, name: string},
 *     pool: array{value: int, total: int, paid: int, units: string, currency: AppCurrency},
 *     metrics: list<array{metricId: int, collaboratorId: int, score: int, triggerCount: int}>,
 * }
 * @phpstan-type AppDistributionAnswer array{outcome: 'allocated', units: string, shares: array<int|string, int>}
 * @phpstan-type AppRewardQuestion array{
 *     reason: 'sale'|'refund',
 *     program: array{id: int, name: string, status: string},
 *     conversionType: string,
 *     transaction: array{id: int, units: string, currency: AppCurrency}|null,
 *     pool: array{value: int, units: string, currency: AppCurrency},
 *     conversions: list<array{conversionId: int, engagementId: int, collaboratorId: int, collaboratorStatus: string, score: int, engagementCreatedDate: string}>,
 * }
 * @phpstan-type AppRewardAnswer array{outcome: 'rewards', units: string, rewards: array<int|string, int|null>}
 */
final class AppFeatureKind
{
    /**
     * A tell after Siren records a conversion. The question is an
     * `AppConversionTell`. Nothing is answered.
     */
    public const POST_CONVERSION_ACTION = 'postConversionAction';

    /**
     * A tell after a distribution gives a collaborator a share. The question
     * is an `AppAllocationTell`. Nothing is answered.
     */
    public const POST_ALLOCATION_ACTION = 'postAllocationAction';

    /**
     * An ask for how to divide a distribution's pool. The question is an
     * `AppDistributionQuestion`, and the answer an `AppDistributionAnswer`:
     * one share per metric, each a whole number of the currency's step, adding
     * up to no more than `pool.value`. {@see Resources\Apps::splitPool()} gives
     * such shares.
     */
    public const DISTRIBUTION_RESOLVER = 'distributionResolver';

    /**
     * An ask for the reward of each conversion one award made. The question
     * is an `AppRewardQuestion`, and the answer an `AppRewardAnswer`: one key
     * per conversion, each a whole number of 0 or more, or null for a
     * conversion the app did not choose.
     */
    public const INCENTIVE_RESOLVER = 'incentiveResolver';

    /**
     * An ask to pay a payout, such as `['payoutId' => 88, 'amount' => 2500, 'currency' => 'USD']`.
     * The answer carries a text `outcome`, such as `['outcome' => 'paid', 'reference' => 'TX-1']`.
     */
    public const PAYOUT_METHOD = 'payoutMethod';

    /** The kinds Siren tells, which wait for no answer. */
    public const TELLS = [self::POST_CONVERSION_ACTION, self::POST_ALLOCATION_ACTION];

    /** The kinds Siren asks, which wait for an answer. */
    public const ASKS = [self::DISTRIBUTION_RESOLVER, self::INCENTIVE_RESOLVER, self::PAYOUT_METHOD];
}
