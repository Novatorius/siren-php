<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\HttpTransport;
use Siren\Sdk\ListResult;

/**
 * Conversions (`/conversions`): an engagement that turned into a reward.
 *
 * `$siren->conversions->list()` keeps working as the reconciliation reader
 * it was in 0.1.0.
 */
class Conversions extends ListResource
{
    public const BULK_APPROVE = 'approve';
    public const BULK_REJECT = 'reject';
    public const BULK_MARK_PENDING = 'markPending';
    public const BULK_DELETE = 'delete';
    public const BULK_PERMANENT_DELETE = 'permanent_delete';

    public function __construct(HttpTransport $transport)
    {
        parent::__construct($transport, '/conversions');
    }

    /**
     * Lists conversions. Filters: `status` (see
     * {@see \Siren\Sdk\ConversionStatus}), `engagementId`, `type`,
     * `obligationId`, `transactionId`, `collaboratorId`, and `programId`.
     * Sort with `orderBy` (`id`, `engagementId`, `type`, `status`,
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
     * Creates a conversion. Required: `engagementId`, `type`, `status`.
     * Optional: `transactionId`, `obligationId`.
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
     * Updates a conversion with `PUT /conversions/{id}`.
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

    /** @return array<int|string, mixed> The conversion types Siren knows. */
    public function types(): array
    {
        return $this->read($this->path . '/types');
    }
}
