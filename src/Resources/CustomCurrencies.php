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
     * Lists custom currencies. Filters: `type` (see
     * {@see \Siren\Sdk\CustomCurrencyType}) and `code`. Sort with `orderBy` (`code`,
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
     * Reads one custom currency by its code, or null when there is none.
     * Uses the list's `code` filter, which a connected site's token may
     * read, because Siren has no read route by code.
     *
     * @param array<string, mixed> $params Other list parameters, such as `fields`.
     *
     * @return array<string, mixed>|null
     */
    public function retrieve(string $code, array $params = []): ?array
    {
        if ($code === '') {
            throw new \InvalidArgumentException('A currency code is required.');
        }

        $records = $this->list(['code' => $code, 'number' => 1] + $params)->getData();

        return $records[0] ?? null;
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
     * Updates a custom currency with `PUT /currencies/custom/{code}`. Accepts
     * `name`, `symbol`, `symbolPosition`, and `exchangeRate`. Siren refuses a
     * change of `type` with a 400. Delete and recreate the currency instead.
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
     * Deletes a custom currency. Siren first sets every program that pays in
     * it to `inactive`, then answers `{success, deactivatedPrograms}`. With
     * `$wipeLedger` true, the ledger entries in the currency are deleted too.
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
