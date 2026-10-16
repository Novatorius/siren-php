<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\HttpTransport;
use Siren\Sdk\ListResult;

/**
 * Distributors (`/distributors`): pools that share revenue among the
 * collaborators they cover on a schedule.
 */
class Distributors extends ListResource
{
    public const BULK_ACTIVATE = 'activate';
    public const BULK_DEACTIVATE = 'deactivate';
    public const BULK_RESTORE = 'restore';
    public const BULK_DELETE = 'delete';
    public const BULK_PERMANENT_DELETE = 'permanent_delete';

    public function __construct(HttpTransport $transport)
    {
        parent::__construct($transport, '/distributors');
    }

    /**
     * Lists distributors. Filters: `status` (see
     * {@see \Siren\Sdk\DistributorStatus}) and `s` (search name and
     * description). Sort with `orderBy` (`id`, `name`, `status`,
     * `dateCreated`) and `order`.
     *
     * @param array<string, mixed> $params
     */
    public function list(array $params = []): ListResult
    {
        return parent::list($params);
    }

    /**
     * @param array<string, mixed> $params `fields` or `include`.
     *
     * @return array<int|string, mixed>
     */
    public function retrieve(int $id, array $params = []): array
    {
        return $this->read($this->recordPath($id), $params);
    }

    /**
     * Creates a distributor.
     *
     * Required: `name`, `description`, `distributionResolver`,
     * `distributionPoolResolver`, `status`, and `units`. Optional:
     * `revenuePercentage`, `schedule` (a list of date modifiers such as
     * `first day of next month`), `engagementTypes`, `collaboratorIds`,
     * `collaboratorGroupId`, `transactionCompilers`, `lineItemFilters`,
     * `postAllocationActions`, and `metricFilters`.
     *
     * @param array<string, mixed> $params
     *
     * @return array<int|string, mixed>
     */
    public function create(array $params): array
    {
        return $this->write('POST', $this->path, $params);
    }

    /**
     * Updates a distributor with `PUT /distributors/{id}`. Send only the
     * fields to change.
     *
     * @param array<string, mixed> $params
     *
     * @return array<int|string, mixed>
     */
    public function update(int $id, array $params): array
    {
        return $this->write('PUT', $this->recordPath($id), $params);
    }

    /**
     * The first call marks the record `deleted` and returns it. A second
     * call removes it and returns an empty array.
     *
     * @return array<int|string, mixed>
     */
    public function delete(int $id): array
    {
        return $this->remove($this->recordPath($id));
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int|string, mixed>
     */
    public function bulk(string $action, array $ids): array
    {
        return $this->bulkAction($action, $ids);
    }

    /**
     * Credits a manual metric to a collaborator on every active distributor
     * that counts manual metrics.
     *
     * @return array<int|string, mixed>
     */
    public function creditMetric(int $collaboratorId): array
    {
        return $this->write('POST', $this->path . '/credit-metric', ['collaboratorId' => $collaboratorId]);
    }
}
