<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\HttpTransport;
use Siren\Sdk\ListResult;

/**
 * Transactions (`/transactions`) and their line-item details.
 *
 * `$siren->transactions->list()` keeps working as the reconciliation reader
 * it was in 0.1.0.
 */
class Transactions extends ListResource
{
    public const BULK_CANCEL = 'cancel';
    public const BULK_REFUND = 'refund';
    public const BULK_DELETE = 'delete';

    public function __construct(HttpTransport $transport)
    {
        parent::__construct($transport, '/transactions');
    }

    /**
     * Lists transactions. Filter: `status` (see
     * {@see \Siren\Sdk\TransactionStatus}). Sort with `orderBy` (`id`,
     * `status`, `dateCreated`) and `order`. Ask for `details`,
     * `detailCount`, `totalValue`, `totalQuantity`, or `currency` through
     * `fields`, or send `include=extended` for the summary fields.
     *
     * A connected site may ask only for `id`, `status`, `dateCreated` and
     * `details`, and may not send `include`. Siren Cloud refuses the rest
     * with a 403.
     *
     * @param array<string, mixed> $params
     */
    public function list(array $params = []): ListResult
    {
        return parent::list($params);
    }

    /**
     * @param array<string, mixed> $params `fields` (such as `['id', 'status', 'details']`) or `include`.
     *
     * @return array<int|string, mixed>
     */
    public function retrieve(int $id, array $params = []): array
    {
        return $this->read($this->recordPath($id), $params);
    }

    /**
     * A transaction's line-item details. Each detail carries `name`,
     * `description`, `type`, `value` (minor units, per unit), `quantity`,
     * `units`, and `attributes`.
     *
     * @return list<array<string, mixed>>
     */
    public function details(int $id): array
    {
        $record = $this->read($this->recordPath($id), ['fields' => ['id', 'details']]);
        $details = [];

        foreach (is_array($record['details'] ?? null) ? $record['details'] : [] as $detail) {
            if (is_array($detail)) {
                /** @var array<string, mixed> $detail */
                $details[] = $detail;
            }
        }

        return $details;
    }

    /**
     * Creates a transaction with its details.
     *
     * Required: `details`, a list of `{name, value, quantity?, type?,
     * description?, units?, attributes?}` with `value` in minor units.
     * Optional: `bindingId` and `bindingDataType`, an external reference
     * Siren stores as a mapping so the transaction can be found again.
     *
     * Commerce sales should go through `events->sale()` instead, which runs
     * attribution. This call records a transaction directly.
     *
     * @param array<string, mixed> $params
     *
     * @return array<int|string, mixed>
     */
    public function create(array $params): array
    {
        if (!isset($params['details']) || !is_array($params['details']) || $params['details'] === []) {
            throw new \InvalidArgumentException('The "details" field is required for transactions->create().');
        }

        return $this->write('POST', $this->path, $params);
    }

    /**
     * Runs a bulk action on several transactions. Use the `BULK_*` constants.
     *
     * @param list<int> $ids
     *
     * @return array<int|string, mixed>
     */
    public function bulk(string $action, array $ids): array
    {
        return $this->bulkAction($action, $ids);
    }

    /**
     * Credits existing transactions to a collaborator, creating conversions
     * for them. `$type` sets the conversion type.
     *
     * @param list<int> $transactionIds
     *
     * @return array<int|string, mixed>
     */
    public function creditCollaborator(array $transactionIds, int $collaboratorId, ?string $type = null): array
    {
        if ($transactionIds === []) {
            throw new \InvalidArgumentException('Name at least one transaction id.');
        }

        $body = ['transactionIds' => array_values($transactionIds), 'collaboratorId' => $collaboratorId];

        if ($type !== null) {
            $body['type'] = $type;
        }

        return $this->write('POST', $this->path . '/credit-collaborator', $body);
    }

    /** @return array<int|string, mixed> The transaction sources Siren knows. */
    public function sources(): array
    {
        return $this->read($this->path . '/sources');
    }
}
