<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\HttpTransport;

/**
 * Store credit: collaborator balances in internal custom currencies.
 *
 * Siren exposes credit as an adjustment call and a `creditBalances` field
 * on the collaborator. There is no endpoint that lists ledger entries.
 */
final class Credits
{
    public function __construct(
        private readonly HttpTransport $transport,
    ) {
    }

    /**
     * Adds to or takes from a collaborator's balance in one internal
     * currency.
     *
     * `$amount` is in minor units and must not be zero. A negative amount
     * takes credit away. This call is never auto-retried, because repeating
     * it would apply the adjustment twice.
     *
     * @return array<int|string, mixed>
     */
    public function adjust(int $collaboratorId, string $currency, int $amount, ?string $description = null): array
    {
        if ($amount === 0) {
            throw new \InvalidArgumentException('A credit adjustment amount must not be zero.');
        }

        if ($currency === '') {
            throw new \InvalidArgumentException('A currency code is required.');
        }

        $body = ['collaboratorId' => $collaboratorId, 'currency' => $currency, 'amount' => $amount];

        if ($description !== null) {
            $body['description'] = $description;
        }

        return $this->transport->request('POST', '/credits/adjust', $body, [], retryable: false)->json();
    }

    /**
     * A collaborator's credit balances, read from their `creditBalances`
     * field. There is one entry per internal currency, with `currency`,
     * `balance` (gross), `onHold`, `available` (gross minus holds), `name`,
     * `symbol`, `symbolPosition`, and `exchangeRate`.
     *
     * @return array<int|string, mixed>
     */
    public function balances(int $collaboratorId): array
    {
        if ($collaboratorId < 1) {
            throw new \InvalidArgumentException('Record ids are positive whole numbers.');
        }

        $record = $this->transport->request(
            'GET',
            '/collaborators/' . $collaboratorId,
            null,
            ['fields' => 'id,creditBalances'],
        )->json();

        return is_array($record['creditBalances'] ?? null) ? $record['creditBalances'] : [];
    }
}
