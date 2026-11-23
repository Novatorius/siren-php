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
 * @phpstan-type AppIncentiveTypeEngagement array{engagementId: int, collaboratorId: int, score: int|float, createdDate: string}
 * @phpstan-type AppIncentiveTypeQuestion array{
 *     reason: 'sale'|'refund',
 *     program: array{id: int, name: string, units: string},
 *     conversionType: 'sale'|'lead'|'renewal',
 *     opportunity: array{id: int},
 *     transaction: array{id: int, status: string, units: string, currency: AppCurrency|null, total: int, lines: list<array{id: int, type: string, name: string, quantity: int|float, value: int}>}|null,
 *     binding: array{type: string, id: string}|null,
 *     engagements: list<AppIncentiveTypeEngagement>,
 *     conversions?: list<array{conversionId: int, engagementId: int}>
 * }
 * @phpstan-type AppIncentiveTypeAwardAnswer array{outcome: 'award', units: string, pool: int, engagementIds: list<int>}
 * @phpstan-type AppIncentiveTypeNoneAnswer array{outcome: 'none'}
 * @phpstan-type AppIncentiveTypeAnswer AppIncentiveTypeAwardAnswer|AppIncentiveTypeNoneAnswer
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
 * @phpstan-type AppEngagementCause array{id: string, event: string, occurredAt: string}
 * @phpstan-type AppEngagementCalculationQuestion array{
 *     program: array{id: int, name: string},
 *     engagementType: string,
 *     programEngagementTypeId: int,
 *     args: array<string, string>,
 *     opportunity: array{id: int, customerId: int|null},
 *     triggeringCollaborator: array{id: int, name: string},
 *     cause: AppEngagementCause,
 * }
 * @phpstan-type AppEngagementCalculationAnswer array{outcome: 'engagements', results: list<array{collaboratorId: int, score: int}>}
 * @phpstan-type AppEngagementFilterQuestion array{
 *     program: array{id: int, name: string},
 *     collaborator: array{id: int, name: string},
 *     opportunity: array{id: int, customerId: int|null},
 *     engagementType: string,
 *     cause: AppEngagementCause,
 * }
 * @phpstan-type AppEngagementFilterAnswer array{outcome: 'allow'}|array{outcome: 'suppress', reason: string}
 * @phpstan-type AppGroupOrderQuestion array{
 *     group: array{id: int, name: string},
 *     conversionType: string,
 *     opportunity: array{id: int},
 *     transaction: array{id: int, units: string, total: int}|null,
 *     programs: list<array{id: int, name: string, engagements: list<array{engagementId: int, collaboratorId: int, score: int, createdDate: string}>}>,
 * }
 * @phpstan-type AppGroupOrderAnswer array{outcome: 'sorted', programIds: list<int>}
 * @phpstan-type AppTransactionLine array{
 *     id: int,
 *     type: 'lineItem'|'discount'|'fee'|'shipping'|'tax',
 *     name: string,
 *     quantity: int,
 *     value: int,
 *     productId: string|null,
 * }
 * @phpstan-type AppTransactionFilterQuestion array{
 *     for: array{type: 'program'|'distributor', id: int, name: string},
 *     transaction: array{id: int, units: string, currency: AppCurrency},
 *     lines: list<AppTransactionLine>,
 *     distribution: array{id: int}|null,
 *     refundDistribution: array{id: int}|null,
 * }
 * @phpstan-type AppTransactionFilterAnswer array{outcome: 'filtered', include: list<int>}
 * @phpstan-type AppCollaboratorGroupStructureQuestion array{
 *     group: array{id: int, name: string},
 *     members: list<array{collaboratorId: int, name: string, status: string, metadata: array<string, mixed>}>,
 *     for: array{type: 'program'|'distributor', id: int},
 *     triggeringCollaborator: array{id: int, name: string},
 *     cause: array{id: string, ...},
 *     ...
 * }
 * @phpstan-type AppCollaboratorGroupStructureDistributorQuestion array{
 *     group: array{id: int, name: string},
 *     members: list<array{collaboratorId: int, name: string, status: string, metadata: array<string, mixed>}>,
 *     for: array{type: 'distributor', id: int},
 *     triggeringCollaborator: array{id: int, name: string},
 *     cause: array{id: string, type: string|null, causeId: int|string|null, ...},
 *     distributor: array{id: int, name: string},
 *     distribution: array{id: int, periodEnd: string},
 *     metricType: string,
 *     distributorMetricTypeId: int,
 *     opportunityId: int|null,
 *     points: int|null,
 *     sale: array<string, mixed>|null,
 *     ...
 * }
 * @phpstan-type AppCollaboratorGroupStructureProgramQuestion array{
 *     group: array{id: int, name: string},
 *     members: list<array{collaboratorId: int, name: string, status: string, metadata: array<string, mixed>}>,
 *     for: array{type: 'program', id: int},
 *     triggeringCollaborator: array{id: int, name: string},
 *     cause: AppEngagementCause,
 *     program: array{id: int, name: string},
 *     engagementType: string,
 *     programEngagementTypeId: int,
 *     args: array<string, string>,
 *     opportunity: array{id: int, customerId: int|null},
 *     ...
 * }
 * @phpstan-type AppCollaboratorGroupStructureAnswer array{outcome: 'structure', shape: 'chain', order: list<int>}|array{outcome: 'structure', shape: 'tree', parents: array<int|string, int|null>}
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
     * for the answer, at most 24 hours from when the sale's reward began to wait.
     */
    public const INCENTIVE_RESOLVER = AppItemKind::INCENTIVE_RESOLVER;

    /**
     * An ask which engagements one sale, lead or renewal converts, picked on
     * a program as its incentive type. The question is an
     * `AppIncentiveTypeQuestion`: the program, the transaction (null for a
     * lead) and the engagements the program holds on the opportunity. The
     * answer is an `AppIncentiveTypeAnswer`: the program's units, a pool of
     * 0 or more and the engagements of the question that convert, each once,
     * or `['outcome' => 'none']`. The program's resolver divides the pool.
     */
    public const INCENTIVE_TYPE = AppItemKind::INCENTIVE_TYPE;

    /**
     * An ask to pay a payout, such as `['payoutId' => 88, 'amount' => 2500, 'currency' => 'USD']`.
     * The answer carries a text `outcome`, such as `['outcome' => 'paid', 'reference' => 'TX-1']`.
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

    /**
     * An ask who earns an engagement, and its score, picked on a program's
     * engagement type. The question is an `AppEngagementCalculationQuestion`
     * and the answer an `AppEngagementCalculationAnswer`.
     */
    public const ENGAGEMENT_CALCULATION = AppItemKind::ENGAGEMENT_CALCULATION;

    /**
     * An ask whether an engagement may be made, picked on a program. The
     * question is an `AppEngagementFilterQuestion`, which names the
     * collaborator the engagement would credit, and the answer an
     * `AppEngagementFilterAnswer`.
     */
    public const ENGAGEMENT_FILTER = AppItemKind::ENGAGEMENT_FILTER;

    /**
     * An ask for the order to try a program group's programs in for one sale
     * or lead, picked on a program group as its structure. The question is an
     * `AppGroupOrderQuestion`: the group's programs that take the
     * conversion, in the group's order, each with its active engagements on
     * the opportunity. The answer is an `AppGroupOrderAnswer`: every program
     * id of the question exactly once, in the order Siren tries them.
     */
    public const PROGRAM_GROUP_SORTER = AppItemKind::PROGRAM_GROUP_SORTER;

    /**
     * An ask for which of a sale's own lines count, once per sale for each
     * program or distributor that picks the filter. Siren's catalog calls it
     * a transaction compiler. The subject id is
     * `{opportunityId}:{programId}:t{transactionId}` for a program's lines and
     * `{distributorId}:t{transactionId}` for a distributor's. The question is
     * an `AppTransactionFilterQuestion`: what the filter is `for`, the
     * `transaction` with its currency, the sale's `lines`, each an
     * `AppTransactionLine` whose `value` is the stored unit value, and the
     * `distribution` and `refundDistribution` periods, null for a program.
     * The answer is an `AppTransactionFilterAnswer`, the ids of the lines to
     * include, each at most once. A refund reuses the stored answer, so Siren
     * never sends refund lines.
     */
    public const TRANSACTION_FILTER = AppItemKind::TRANSACTION_FILTER;

    /**
     * An ask for a collaborator group's shape among the members in the
     * question, once per cascade trigger on the group, for a program (an
     * engagement cascade) or a distributor (a metric cascade). The question
     * is an `AppCollaboratorGroupStructureQuestion`. It also carries the
     * fields of the trigger it interrupts: a distributor's ask carries a
     * metric calculation's fields, typed as
     * `AppCollaboratorGroupStructureDistributorQuestion`, and a program's an
     * engagement calculation's fields, typed as
     * `AppCollaboratorGroupStructureProgramQuestion`. The answer is an
     * `AppCollaboratorGroupStructureAnswer`: a chain with every member's
     * collaborator id once in `order`, top first, or a tree with every
     * member's id once as a key of `parents`, each mapped to another member's
     * id or null for a top member, with no cycle.
     */
    public const COLLABORATOR_GROUP_STRUCTURE = AppItemKind::COLLABORATOR_GROUP_STRUCTURE;

    /** The kinds Siren tells, which wait for no answer. */
    public const TELLS = [self::POST_CONVERSION_ACTION, self::POST_ALLOCATION_ACTION];

    /** The kinds Siren asks, which wait for an answer. */
    public const ASKS = [self::DISTRIBUTION_RESOLVER, self::INCENTIVE_RESOLVER, self::PAYOUT_METHOD, self::METRIC_CALCULATION, self::METRIC_FILTER, self::INCENTIVE_TYPE, self::ENGAGEMENT_CALCULATION, self::ENGAGEMENT_FILTER, self::PROGRAM_GROUP_SORTER, self::TRANSACTION_FILTER, self::COLLABORATOR_GROUP_STRUCTURE];
}
