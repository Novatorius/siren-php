<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * The kinds of app feature Siren sends requests for, the `item.kind` of an
 * {@see AppRequest}, and the shape of each kind's question, the request's
 * `data`, and answer. Each value is {@see AppItemKind}'s constant of the same
 * name. Only `customEvent` is left out, since Siren sends it nothing. Siren's own builders make the questions
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
 * @phpstan-type AppPayoutQuestion array{
 *     payout: array{id: int, fulfillmentId: int, attempt: int},
 *     amount: array{value: int, units: string, currency: AppCurrency},
 *     collaborator: array{id: int, name: string, email: string},
 * }
 * @phpstan-type AppPayoutAnswer array{outcome: 'paid', reference: string}|array{outcome: 'failed', reason: string}
 * @phpstan-type AppMetricCalculationQuestion array{
 *     distributor: array{id: int, name: string},
 *     distribution: array{id: int, periodEnd: string},
 *     metricType: string,
 *     distributorMetricTypeId: int,
 *     triggeringCollaborator: array{id: int, name: string},
 *     opportunityId: int|null,
 *     points: int|null,
 *     cause: array{id: string, type: string|null, causeId: int|string|null},
 *     sale: array<string, mixed>|null,
 * }
 * @phpstan-type AppMetricCalculationAnswer array{outcome: 'scores', results: list<array{collaboratorId: int, score: int}>}
 * @phpstan-type AppMetricFilterQuestion array{
 *     distributor: array{id: int, name: string},
 *     distribution: array{id: int},
 *     collaborator: array{id: int, name: string},
 *     metricType: string,
 *     opportunityId: int|null,
 *     points: int|null,
 *     cause: array{id: string, type: string|null, causeId: int|string|null},
 *     sale: array<string, mixed>|null,
 * }
 * @phpstan-type AppMetricFilterAnswer array{outcome: 'allow'}|array{outcome: 'suppress', reason: string}
 */
final class AppFeatureKind
{
    /**
     * A tell after Siren records a conversion. The question is an
     * `AppConversionTell`. Nothing is answered.
     */
    public const POST_CONVERSION_ACTION = AppItemKind::POST_CONVERSION_ACTION;

    /**
     * A tell after a distribution gives a collaborator a share. The question
     * is an `AppAllocationTell`. Nothing is answered.
     */
    public const POST_ALLOCATION_ACTION = AppItemKind::POST_ALLOCATION_ACTION;

    /**
     * An ask for how to divide a distribution's pool. The question is an
     * `AppDistributionQuestion`, and the answer an `AppDistributionAnswer`:
     * one share per metric, each a whole number of the currency's step, adding
     * up to no more than `pool.value`. {@see Resources\Apps::splitPool()} gives
     * such shares.
     */
    public const DISTRIBUTION_RESOLVER = AppItemKind::DISTRIBUTION_RESOLVER;

    /**
     * An ask to split a sale's reward among the collaborators it converted,
     * picked on a program. The question is an `AppRewardQuestion`, and the
     * answer an `AppRewardAnswer`: the pool's units and one key per
     * conversion, each a whole number of 0 or more and no more than
     * `pool.value`, or null for a conversion the app did not choose. The
     * rewards may add up to more than the pool.
     * {@see Resources\Apps::splitPool()} gives such rewards. The rewards wait
     * for the answer, 24 hours from the first ask at most.
     */
    public const INCENTIVE_RESOLVER = AppItemKind::INCENTIVE_RESOLVER;

    /**
     * An ask to pay one payout, picked on an autofulfiller's currency. The
     * question is an `AppPayoutQuestion`: the `payout` (`attempt` starts at 1
     * and rises when Siren asks again about the same payout), the `amount` as
     * a stored whole number in the currency's smallest unit, and the
     * `collaborator`. The answer is an `AppPayoutAnswer`: `paid` with a
     * `reference` of 1 to 191 characters, the payment's id in the app's own
     * system, or `failed` with a `reason` of 1 to 500 characters. Pay each
     * payout id at most once. When Siren asks again about a payout the app
     * already paid, answer `paid` with the first reference. Siren drops an ask
     * after 24 hours with no answer and marks the payout failed.
     */
    public const PAYOUT_METHOD = AppItemKind::PAYOUT_METHOD;

    /**
     * An ask to score a metric for a distributor, picked on a distributor's
     * metric type. The question is an `AppMetricCalculationQuestion`, and the
     * answer an `AppMetricCalculationAnswer`: at most 50 results, each
     * collaborator once, each score a whole number of 0 or more.
     */
    public const METRIC_CALCULATION = AppItemKind::METRIC_CALCULATION;

    /**
     * An ask whether one collaborator's metric counts. The question is an
     * `AppMetricFilterQuestion`, and the answer an `AppMetricFilterAnswer`:
     * allow, or suppress with a plain text reason of 1 to 200 characters.
     */
    public const METRIC_FILTER = AppItemKind::METRIC_FILTER;

    /** The kinds Siren tells, which wait for no answer. */
    public const TELLS = [self::POST_CONVERSION_ACTION, self::POST_ALLOCATION_ACTION];

    /** The kinds Siren asks, which wait for an answer. */
    public const ASKS = [self::DISTRIBUTION_RESOLVER, self::INCENTIVE_RESOLVER, self::PAYOUT_METHOD, self::METRIC_CALCULATION, self::METRIC_FILTER];
}
