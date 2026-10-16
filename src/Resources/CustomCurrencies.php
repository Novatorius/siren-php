<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\HttpTransport;
use Siren\Sdk\ListResult;

/**
 * Custom currencies (`/currencies/custom`): points, store credit, and other
 * units an organization defines beside real money. Records are keyed by
 * their `code`, not a numeric id.
 */
class CustomCurrencies extends ListResource
{
    public function __construct(HttpTransport $transport)
    {
        parent::__construct($transport, '/currencies/custom');
    }

    /**
     * Lists custom currencies. Filter: `type` (see
     * {@see \Siren\Sdk\CustomCurrencyType}). Sort with `orderBy` (`code`,
     * `name`, `symbol`, `symbolPosition`, `type`, `exchangeRate`,
     * `dateCreated`, `dateModified`) and `order`.
     *
     * @param array<string, mixed> $params
     */
    public function list(array $params = []): ListResult
    {
        return parent::list($params);
    }

    /**
     * Creates a custom currency. Required: `code` (upper case, unique, not a
     * real currency code), `name`, `symbol`, `type`. Optional:
     * `symbolPosition` (`before` or `after`) and `exchangeRate`.
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
     * Updates a custom currency with `PUT /currencies/custom/{code}`.
     *
     * @param array<string, mixed> $params
     *
     * @return array<int|string, mixed>
     */
    public function update(string $code, array $params): array
    {
        return $this->write('PUT', $this->codePath($code), $params);
    }

    /**
     * Deletes a custom currency. Siren refuses while ledger entries use it,
     * unless `$wipeLedger` is true, which deletes those entries as well.
     *
     * @return array<int|string, mixed>
     */
    public function delete(string $code, bool $wipeLedger = false): array
    {
        return $this->remove($this->codePath($code), $wipeLedger ? ['wipeLedger' => true] : []);
    }

    private function codePath(string $code): string
    {
        if ($code === '') {
            throw new \InvalidArgumentException('A currency code is required.');
        }

        return $this->path . '/' . rawurlencode($code);
    }
}
