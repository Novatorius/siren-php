<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\HttpTransport;
use Siren\Sdk\ListResult;

/**
 * Payouts (`/payouts`): what Siren owes one collaborator in one currency,
 * inside a fulfillment. This resource only reads. Every payout write moves
 * money, so Siren keeps it for a signed-in admin.
 *
 * Takes an API key that holds {@see \Siren\Sdk\ApiKeyScope::LEDGER_PAYOUTS_READ},
 * or a dashboard session's token. Payouts are not kept by store, so a key
 * bound to one store gets a
 * {@see \Siren\Sdk\Exception\StoreKeyNotAllowedException}. Reads retry on a
 * network error, 429 or 5xx inside the client's `maxRetries`.
 */
class Payouts extends ListResource
{
    public function __construct(HttpTransport $transport)
    {
        parent::__construct($transport, '/payouts');
    }

    /**
     * The reconciliation reader for `GET /payouts`. Filters: `id`,
     * `fulfillmentId`, `collaboratorId`, `status` (see
     * {@see \Siren\Sdk\PayoutStatus}) and `currency`. Every key is sent as
     * given. A connected site must send `id`, `collaboratorId` or
     * `fulfillmentId`, or Siren Cloud answers 403 with `site_filter_required`.
     *
     * @param array<string, mixed> $params
     */
    public function list(array $params = []): ListResult
    {
        return parent::list($params);
    }

    /**
     * Reads one payout with `GET /payouts/{id}`. Siren answers its thirteen
     * default fields unless `fields` names others, and
     * `include => 'extended'` adds the collaborator's name and email.
     *
     * Throws {@see \Siren\Sdk\Exception\NotFoundException} for an unknown id,
     * and `InvalidArgumentException` before sending for an id below 1.
     *
     * @param array<string, mixed> $params `fields` or `include`.
     *
     * @return array<int|string, mixed>
     */
    public function retrieve(int $id, array $params = []): array
    {
        return $this->read($this->recordPath($id), $params);
    }
}
