<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * The kinds of feature an app can add, as Siren sends them in an app
 * request's `feature.kind`, and the shape of each kind's question, the
 * request's `data`, and answer. Compare {@see AppRequest::$feature} against
 * these to pick the code that answers.
 *
 * The kinds and their names come from Siren's kinds snapshot, which this
 * package ships byte for byte as resources/app-kinds.json and
 * {@see catalog()} reads. bin/feature-kinds.php writes the first line of each
 * constant's docs and the {@see SENT}, {@see TELLS}, {@see ASKS} and
 * {@see NAMES} lists from it, in the order of Siren's "New feature" chooser.
 * {@see SENT} lists the thirteen kinds Siren sends requests for, the kinds
 * {@see AppRequestHandler::on()} takes, and {@see NAMES} gives the name
 * Siren Cloud's screens show for each. `customEvent` is the one kind Siren
 * sends nothing, since the app sends it. Siren's own builders make the
 * questions (lib/Apps/Service in Siren). Every amount is the stored integer,
 * and a currency's shown amount is `value / 10 ** scale`.
 *
 * PHPStan and Psalm read the shapes with `@phpstan-import-type`:
 *
 * ```php
 * /** @phpstan-import-type DistributionResolverAnswer from FeatureKind *\/
 * ```
 *
 * @phpstan-type AppCurrency array{id: string, symbol: string, position: string, name: string, scale: int, decimalPlaces: int, type: string}
 * @phpstan-type PostConversionActionTell array{
 *     conversion: array{id: int, type: string, status: string, engagementId: int, opportunityId: int, collaboratorId: int, transactionId: int|null, obligationId: int|null},
 *     reward: array{value: int, units: string, currency: AppCurrency}|null,
 *     program: array{id: int, name: string},
 * }
 * @phpstan-type PostAllocationActionTell array{
 *     allocation: array{distributionId: int, obligationId: int, collaboratorId: int, status: string, value: int, units: string, currency: AppCurrency},
 *     distributor: array{id: int, name: string},
 * }
 * @phpstan-type DistributionResolverQuestion array{
 *     distribution: array{id: int, distributorId: int, periodStart: string, periodEnd: string},
 *     distributor: array{id: int, name: string},
 *     pool: array{value: int, total: int, paid: int, units: string, currency: AppCurrency},
 *     metrics: list<array{metricId: int, collaboratorId: int, score: int, triggerCount: int}>,
 * }
 * @phpstan-type DistributionResolverAnswer array{outcome: 'allocated', units: string, shares: array<int|string, int>}
 * @phpstan-type IncentiveResolverQuestion array{
 *     reason: 'sale'|'refund',
 *     program: array{id: int, name: string, status: string},
 *     conversionType: string,
 *     transaction: array{id: int, units: string, currency: AppCurrency}|null,
 *     pool: array{value: int, units: string, currency: AppCurrency},
 *     conversions: list<array{conversionId: int, engagementId: int, collaboratorId: int, collaboratorStatus: string, score: int, engagementCreatedDate: string}>,
 * }
 * @phpstan-type IncentiveResolverAnswer array{outcome: 'rewards', units: string, rewards: array<int|string, int|null>}
 * @phpstan-type IncentiveTypeEngagement array{engagementId: int, collaboratorId: int, score: int, createdDate: string}
 * @phpstan-type IncentiveTypeQuestion array{
 *     reason: 'sale'|'refund',
 *     program: array{id: int, name: string, units: string},
 *     conversionType: 'sale'|'lead'|'renewal',
 *     opportunity: array{id: int},
 *     transaction: array{id: int, status: string, units: string, currency: AppCurrency|null, total: int, lines: list<array{id: int, type: string, name: string, quantity: int, value: int}>}|null,
 *     binding: array{type: string, id: string}|null,
 *     engagements: list<IncentiveTypeEngagement>,
 *     conversions?: list<array{conversionId: int, engagementId: int}>
 * }
 * @phpstan-type IncentiveTypeAwardAnswer array{outcome: 'award', units: string, pool: int, engagementIds: list<int>}
 * @phpstan-type IncentiveTypeNoneAnswer array{outcome: 'none'}
 * @phpstan-type IncentiveTypeAnswer IncentiveTypeAwardAnswer|IncentiveTypeNoneAnswer
 * @phpstan-type PayoutMethodQuestion array{
 *     payout: array{id: int, fulfillmentId: int, attempt: int},
 *     amount: array{value: int, units: string, currency: AppCurrency},
 *     collaborator: array{id: int, name: string, email: string|null},
 * }
 * @phpstan-type PayoutMethodPaidAnswer array{outcome: 'paid', reference: non-empty-string}
 * @phpstan-type PayoutMethodFailedAnswer array{outcome: 'failed', reason: non-empty-string}
 * @phpstan-type PayoutMethodAnswer PayoutMethodPaidAnswer|PayoutMethodFailedAnswer
 * @phpstan-type AppMetricCause array{id: string, type: string|null, causeId: string|null}
 * @phpstan-type AppMetricSale array{transactionId: int, conversionId: int|null, excludedLineIds: list<int>}
 * @phpstan-type MetricCalculationQuestion array{
 *     distributor: array{id: int, name: string},
 *     distribution: array{id: int, periodEnd: string},
 *     metricType: string,
 *     distributorMetricTypeId: int,
 *     triggeringCollaborator: array{id: int, name: string},
 *     opportunityId: int|null,
 *     points: int|null,
 *     cause: AppMetricCause,
 *     sale: AppMetricSale|null,
 * }
 * @phpstan-type MetricCalculationAnswer array{outcome: 'scores', results: list<array{collaboratorId: int, score: int}>}
 * @phpstan-type MetricFilterQuestion array{
 *     distributor: array{id: int, name: string},
 *     distribution: array{id: int},
 *     collaborator: array{id: int, name: string},
 *     metricType: string,
 *     opportunityId: int|null,
 *     points: int|null,
 *     cause: AppMetricCause,
 *     sale: AppMetricSale|null,
 * }
 * @phpstan-type MetricFilterAnswer array{outcome: 'allow'}|array{outcome: 'suppress', reason: string}
 * @phpstan-type AppEngagementCause array{id: string, event: string, occurredAt: string}
 * @phpstan-type EngagementCalculationQuestion array{
 *     program: array{id: int, name: string},
 *     engagementType: string,
 *     programEngagementTypeId: int,
 *     args: array<string, string>,
 *     opportunity: array{id: int, customerId: int|null},
 *     triggeringCollaborator: array{id: int, name: string},
 *     cause: AppEngagementCause,
 * }
 * @phpstan-type EngagementCalculationAnswer array{outcome: 'engagements', results: list<array{collaboratorId: int, score: int}>}
 * @phpstan-type EngagementFilterQuestion array{
 *     program: array{id: int, name: string},
 *     collaborator: array{id: int, name: string},
 *     opportunity: array{id: int, customerId: int|null},
 *     engagementType: string,
 *     cause: AppEngagementCause,
 * }
 * @phpstan-type EngagementFilterAnswer array{outcome: 'allow'}|array{outcome: 'suppress', reason: string}
 * @phpstan-type ProgramGroupSorterQuestion array{
 *     group: array{id: int, name: string},
 *     conversionType: string,
 *     opportunity: array{id: int},
 *     transaction: array{id: int, units: string, total: int}|null,
 *     programs: list<array{id: int, name: string, engagements: list<array{engagementId: int, collaboratorId: int, score: int, createdDate: string}>}>,
 * }
 * @phpstan-type ProgramGroupSorterAnswer array{outcome: 'sorted', programIds: list<int>}
 * @phpstan-type AppTransactionLine array{
 *     id: int,
 *     type: 'lineItem'|'discount'|'fee'|'shipping'|'tax',
 *     name: string,
 *     quantity: int,
 *     value: int,
 *     productId: string|null,
 * }
 * @phpstan-type TransactionFilterQuestion array{
 *     for: array{type: 'program'|'distributor', id: int, name: string},
 *     transaction: array{id: int, units: string, currency: AppCurrency|null},
 *     lines: list<AppTransactionLine>,
 *     distribution: array{id: int}|null,
 *     refundDistribution: array{id: int}|null,
 * }
 * @phpstan-type TransactionFilterAnswer array{outcome: 'filtered', include: list<int>}
 * @phpstan-type AppGroupMember array{collaboratorId: int, name: string, status: string, metadata: array<string, mixed>}
 * @phpstan-type CollaboratorGroupStructureDistributorQuestion array{
 *     group: array{id: int, name: string},
 *     members: list<AppGroupMember>,
 *     for: array{type: 'distributor', id: int},
 *     triggeringCollaborator: array{id: int, name: string},
 *     cause: AppMetricCause,
 *     distributor: array{id: int, name: string},
 *     distribution: array{id: int, periodEnd: string},
 *     metricType: string,
 *     distributorMetricTypeId: int,
 *     opportunityId: int|null,
 *     points: int|null,
 *     sale: AppMetricSale|null,
 * }
 * @phpstan-type CollaboratorGroupStructureProgramQuestion array{
 *     group: array{id: int, name: string},
 *     members: list<AppGroupMember>,
 *     for: array{type: 'program', id: int},
 *     triggeringCollaborator: array{id: int, name: string},
 *     cause: AppEngagementCause,
 *     program: array{id: int, name: string},
 *     engagementType: string,
 *     programEngagementTypeId: int,
 *     args: array<string, string>,
 *     opportunity: array{id: int, customerId: int|null},
 * }
 * @phpstan-type CollaboratorGroupStructureQuestion CollaboratorGroupStructureDistributorQuestion|CollaboratorGroupStructureProgramQuestion
 * @phpstan-type CollaboratorGroupStructureChainAnswer array{outcome: 'structure', shape: 'chain', order: list<int>}
 * @phpstan-type CollaboratorGroupStructureTreeAnswer array{outcome: 'structure', shape: 'tree', parents: array<int|string, int|null>}
 * @phpstan-type CollaboratorGroupStructureAnswer CollaboratorGroupStructureChainAnswer|CollaboratorGroupStructureTreeAnswer
 */
final class FeatureKind
{
    /** @var list<array{kind: string, term: string, name: string, description: string, mode: string, direction: string}>|null */
    private static ?array $catalog = null;

    /**
     * Post-conversion action.
     *
     * Siren tells the app when a conversion is approved in a program that picks
     * this action. A tell: Siren lets the app know.
     *
     * A tell after each approved conversion, picked on a program. The
     * question is a `PostConversionActionTell`. Nothing is answered.
     */
    public const POST_CONVERSION_ACTION = 'postConversionAction';

    /**
     * Post-allocation action.
     *
     * Siren tells the app when an allocation completes for a distributor that
     * picks this action. A tell: Siren lets the app know.
     *
     * A tell after a completed allocation gives a collaborator a share,
     * picked on a distributor. The question is a `PostAllocationActionTell`.
     * Nothing is answered.
     */
    public const POST_ALLOCATION_ACTION = 'postAllocationAction';

    /**
     * Payout method.
     *
     * Siren asks the app to pay a payout, in the currencies it names, when an
     * autofulfiller picks this method. An ask: Siren waits for the app to
     * answer.
     *
     * An ask to pay one payout, picked on an autofulfiller for a currency
     * the feature names. The question is a `PayoutMethodQuestion`, and
     * the answer a `PayoutMethodAnswer`. The question holds the payout's id, its
     * fulfillment and `attempt`, which rises when Siren asks again about the
     * same payout, the amount as a whole number in the currency's smallest
     * unit, and the collaborator's id, name and email, which is null when
     * they have none. The answer is `['outcome' => 'paid', 'reference' =>
     * '...']`, with the payment's id in the app's own system, at most 191
     * characters, or `['outcome' => 'failed', 'reason' => '...']`, in plain
     * words, at most 500. Pay each payout id at most once, and answer a
     * second ask about a payout already paid with the first reference.
     */
    public const PAYOUT_METHOD = 'payoutMethod';

    /**
     * Distribution resolver.
     *
     * Siren asks the app how to divide a distribution's pool when its
     * distributor picks this resolver. An ask: Siren waits for the app to
     * answer.
     *
     * An ask for how to divide a distribution's pool, picked on a
     * distributor. The question is a `DistributionResolverQuestion`, and the
     * answer a `DistributionResolverAnswer`: one share per metric, each a whole
     * number of the currency's step, adding up to no more than `pool.value`.
     * {@see Resources\Apps::splitPool()} gives such shares.
     */
    public const DISTRIBUTION_RESOLVER = 'distributionResolver';

    /**
     * Attribution model. Siren's code calls it the incentive resolver.
     *
     * Siren asks the app how to split a program's reward among the
     * collaborators a sale or lead converted, when the program picks this
     * resolver. An ask: Siren waits for the app to answer.
     *
     * An ask to split a sale's reward among the collaborators it converted,
     * picked on a program. The question is an `IncentiveResolverQuestion`, and the
     * answer an `IncentiveResolverAnswer`, such as
     * `['outcome' => 'rewards', 'units' => 'USD', 'rewards' => ['1042' => 900, '1043' => null]]`:
     * the pool's units and one key per conversion, each a whole number of 0
     * or more and no more than `pool.value`, or null for a conversion the
     * app did not choose. The rewards may add up to more than the pool.
     * {@see Resources\Apps::splitPool()} gives such rewards. The rewards wait
     * for the answer, at most 24 hours from when the sale's reward began to wait.
     */
    public const INCENTIVE_RESOLVER = 'incentiveResolver';

    /**
     * Incentive structure. Siren's code calls it the incentive type.
     *
     * Siren asks the app which engagements a sale, lead or renewal converts on
     * a program that picks this structure, and the reward pool they share. An
     * ask: Siren waits for the app to answer.
     *
     * An ask which engagements one sale, lead or renewal converts, picked on
     * a program as its incentive type. The question is an
     * `IncentiveTypeQuestion`: the program, the transaction (null for a
     * lead) and the engagements the program holds on the opportunity. The
     * answer is an `IncentiveTypeAnswer`, such as
     * `['outcome' => 'award', 'units' => 'USD', 'pool' => 1500, 'engagementIds' => [880, 881]]`:
     * the program's units, a pool of 0 or more and the engagements of the
     * question that convert, each once, or `['outcome' => 'none']` to award
     * nothing. The program's resolver divides the pool. The program's award
     * waits for the answer, 24 hours from the first ask at most.
     */
    public const INCENTIVE_TYPE = 'incentiveType';

    /**
     * Metric calculation. Siren's code calls it the calculation strategy.
     *
     * Siren asks the app who scores, and how much, each time a metric triggers
     * for a distributor whose metric type picks this calculation. An ask: Siren
     * waits for the app to answer.
     *
     * An ask to score a metric for a distributor, picked on a distributor's
     * metric type. The question is a `MetricCalculationQuestion`, and the
     * answer a `MetricCalculationAnswer`, such as
     * `['outcome' => 'scores', 'results' => [['collaboratorId' => 7, 'score' => 10]]]`.
     * Siren takes at most 50 results, each collaborator once, each in the
     * organization and not in the trash, and each score a whole number of 0
     * or more. An empty list scores nobody.
     */
    public const METRIC_CALCULATION = 'metricCalculation';

    /**
     * Customer filter for distributors. Siren's code calls it the metric filter.
     *
     * Siren asks the app whether a metric may score each time one triggers for
     * a distributor that picks this filter. An ask: Siren waits for the app to
     * answer.
     *
     * An ask whether one collaborator's metric counts, picked on a
     * distributor. The question is a `MetricFilterQuestion`, and the
     * answer a `MetricFilterAnswer`: `['outcome' => 'allow']`, or
     * `['outcome' => 'suppress', 'reason' => '...']` with a plain text reason
     * of 1 to 200 characters.
     */
    public const METRIC_FILTER = 'metricFilter';

    /**
     * Transaction filter. Siren's code calls it the transaction compiler.
     *
     * Siren asks the app which of a sale's lines count, once per sale for each
     * program or distributor that picks this filter. An ask: Siren waits for
     * the app to answer.
     *
     * An ask for which of a sale's own lines count, once per sale for each
     * program or distributor that picks the filter. Siren's catalog calls it
     * a transaction compiler. The subject id is
     * `{opportunityId}:{programId}:t{transactionId}` for a program's lines and
     * `{distributorId}:t{transactionId}` for a distributor's. The question is
     * a `TransactionFilterQuestion`: what the filter is `for`, the
     * `transaction` with its currency, the sale's `lines`, each an
     * `AppTransactionLine` whose `value` is the stored unit value, and the
     * `distribution` and `refundDistribution` periods, null for a program.
     * The answer is a `TransactionFilterAnswer`, such as
     * `['outcome' => 'filtered', 'include' => [5501, 5502]]`, the ids of the
     * question's lines to include, each at most once. An empty list includes
     * nothing. A refund reuses the stored answer, so Siren never sends refund
     * lines.
     */
    public const TRANSACTION_FILTER = 'transactionFilter';

    /**
     * Engagement calculation. Siren's code calls it the calculation strategy.
     *
     * Siren asks the app who earns an engagement, and its score, each time one
     * triggers for a program whose engagement type picks this calculation. An
     * ask: Siren waits for the app to answer.
     *
     * An ask who earns an engagement, and its score, picked on a program's
     * engagement type. The question is an `EngagementCalculationQuestion`
     * and the answer an `EngagementCalculationAnswer`, such as
     * `['outcome' => 'engagements', 'results' => [['collaboratorId' => 7, 'score' => 10]]]`.
     * Siren takes at most 50 results, each collaborator once, each in the
     * organization and not in the trash, and each score a whole number of 0
     * or more. An empty list makes no engagement. Siren makes no engagement
     * until the answer, at most 24 hours from when Siren asked. When a sale
     * waits on the answer, the ask ends with that sale's wait, 24 hours after
     * the sale reached Siren.
     */
    public const ENGAGEMENT_CALCULATION = 'engagementCalculation';

    /**
     * Customer filter for programs. Siren's code calls it the engagement filter.
     *
     * Siren asks the app whether an engagement may be written each time one
     * triggers for a program that picks this filter. An ask: Siren waits for
     * the app to answer.
     *
     * An ask whether an engagement may be made for one collaborator, picked
     * on a program. The question is an `EngagementFilterQuestion`, which
     * names the collaborator the engagement would credit, and the answer an
     * `EngagementFilterAnswer`: `['outcome' => 'allow']`, or
     * `['outcome' => 'suppress', 'reason' => '...']` with a plain text reason
     * of 1 to 200 characters, which Siren notes on the opportunity.
     */
    public const ENGAGEMENT_FILTER = 'engagementFilter';

    /**
     * Program group structure. Siren's code calls it the group sorter.
     *
     * Siren asks the app which program of a program group a sale or lead tries
     * first, when the group picks this structure. An ask: Siren waits for the
     * app to answer.
     *
     * An ask for the order to try a program group's programs in for one sale
     * or lead, picked on a program group as its structure. The question is an
     * `ProgramGroupSorterQuestion`: the group's programs that take the
     * conversion, in the group's order, each with its active engagements on
     * the opportunity. The answer is a `ProgramGroupSorterAnswer`, such as
     * `['outcome' => 'sorted', 'programIds' => [9, 12]]`, every program id of
     * the question exactly once, in the order Siren tries them. The first
     * program that converts wins the group. The group's award waits for the
     * answer, 24 hours from the sale at most.
     */
    public const PROGRAM_GROUP_SORTER = 'programGroupSorter';

    /**
     * Collaborator group structure.
     *
     * Siren asks the app who sits above and below whom in a collaborator group
     * each time a cascade runs over a group that picks this structure. An ask:
     * Siren waits for the app to answer.
     *
     * An ask for a collaborator group's shape among the members in the
     * question, once per cascade trigger on the group, for a program (an
     * engagement cascade) or a distributor (a metric cascade). The question
     * is a `CollaboratorGroupStructureQuestion`. It also carries the
     * fields of the trigger it interrupts: a distributor's ask carries a
     * metric calculation's fields, typed as
     * `CollaboratorGroupStructureDistributorQuestion`, and a program's an
     * engagement calculation's fields, typed as
     * `CollaboratorGroupStructureProgramQuestion`. The answer is an
     * `CollaboratorGroupStructureAnswer`: a chain,
     * `['outcome' => 'structure', 'shape' => 'chain', 'order' => [63, 57, 42]]`,
     * with every member's collaborator id once, top first, or a tree,
     * `['outcome' => 'structure', 'shape' => 'tree', 'parents' => ['42' => 57, '57' => 63, '63' => null]]`,
     * with every member's id once as a key, each mapped to another member's
     * id or null for a top member, and no cycle.
     */
    public const COLLABORATOR_GROUP_STRUCTURE = 'collaboratorGroupStructure';

    /**
     * Custom event.
     *
     * The app sends Siren an event of its own at /event/{key}, with a
     * short-lived token its key gets. Programs and distributors can credit it.
     * The app sends it: Siren sends nothing.
     *
     * An event of the app's own, which the app sends Siren. Siren sends it nothing.
     */
    public const CUSTOM_EVENT = 'customEvent';

    // generated:start, by bin/feature-kinds.php from resources/app-kinds.json

    /** The kinds Siren sends requests for, the snapshot's tells and asks in its order, which {@see AppRequestHandler::on()} takes. */
    public const SENT = [
        self::POST_CONVERSION_ACTION,
        self::POST_ALLOCATION_ACTION,
        self::PAYOUT_METHOD,
        self::DISTRIBUTION_RESOLVER,
        self::METRIC_CALCULATION,
        self::METRIC_FILTER,
        self::INCENTIVE_RESOLVER,
        self::TRANSACTION_FILTER,
        self::INCENTIVE_TYPE,
        self::ENGAGEMENT_CALCULATION,
        self::ENGAGEMENT_FILTER,
        self::PROGRAM_GROUP_SORTER,
        self::COLLABORATOR_GROUP_STRUCTURE,
    ];

    /** The kinds Siren tells, which wait for no answer. */
    public const TELLS = [
        self::POST_CONVERSION_ACTION,
        self::POST_ALLOCATION_ACTION,
    ];

    /** The kinds Siren asks, which wait for an answer. */
    public const ASKS = [
        self::PAYOUT_METHOD,
        self::DISTRIBUTION_RESOLVER,
        self::METRIC_CALCULATION,
        self::METRIC_FILTER,
        self::INCENTIVE_RESOLVER,
        self::TRANSACTION_FILTER,
        self::INCENTIVE_TYPE,
        self::ENGAGEMENT_CALCULATION,
        self::ENGAGEMENT_FILTER,
        self::PROGRAM_GROUP_SORTER,
        self::COLLABORATOR_GROUP_STRUCTURE,
    ];

    /** The name Siren Cloud's screens give each kind. */
    public const NAMES = [
        self::POST_CONVERSION_ACTION => 'Post-conversion action',
        self::POST_ALLOCATION_ACTION => 'Post-allocation action',
        self::PAYOUT_METHOD => 'Payout method',
        self::DISTRIBUTION_RESOLVER => 'Distribution resolver',
        self::CUSTOM_EVENT => 'Custom event',
        self::METRIC_CALCULATION => 'Metric calculation',
        self::METRIC_FILTER => 'Customer filter for distributors',
        self::INCENTIVE_RESOLVER => 'Attribution model',
        self::TRANSACTION_FILTER => 'Transaction filter',
        self::INCENTIVE_TYPE => 'Incentive structure',
        self::ENGAGEMENT_CALCULATION => 'Engagement calculation',
        self::ENGAGEMENT_FILTER => 'Customer filter for programs',
        self::PROGRAM_GROUP_SORTER => 'Program group structure',
        self::COLLABORATOR_GROUP_STRUCTURE => 'Collaborator group structure',
    ];

    // generated:end

    /**
     * Siren's kinds snapshot as this package ships it: one entry per kind,
     * in the order of Siren's "New feature" chooser, each with its `kind`,
     * Siren's own `term`, the `name` its screens show, a `description`, its
     * `mode` (`tell`, `ask` or `receive`) and its `direction` (`out` or
     * `in`). `GET /apps/kinds` serves the same fields from a running Siren.
     *
     * @return list<array{kind: string, term: string, name: string, description: string, mode: string, direction: string}>
     */
    public static function catalog(): array
    {
        if (self::$catalog === null) {
            /** @var list<array{kind: string, term: string, name: string, description: string, mode: string, direction: string}> $kinds */
            $kinds = json_decode((string) file_get_contents(dirname(__DIR__) . '/resources/app-kinds.json'), true, 512, JSON_THROW_ON_ERROR);
            self::$catalog = $kinds;
        }

        return self::$catalog;
    }

    /** Whether Siren waits for an answer to a request of this kind. */
    public static function isAsk(string $kind): bool
    {
        return in_array($kind, self::ASKS, true);
    }

    private function __construct()
    {
    }
}
