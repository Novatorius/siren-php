<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * A page of records from a reconciliation reader, with paging metadata
 * where the API provides it.
 *
 * @implements \IteratorAggregate<int, array<string, mixed>>
 */
final class ListResult implements \IteratorAggregate, \Countable
{
    /**
     * @param list<array<string, mixed>> $data
     */
    public function __construct(
        private readonly array $data,
        private readonly ?int $page = null,
        private readonly ?int $perPage = null,
        private readonly ?int $total = null,
    ) {
    }

    /** @return list<array<string, mixed>> The records on this page. */
    public function getData(): array
    {
        return $this->data;
    }

    public function getPage(): ?int
    {
        return $this->page;
    }

    public function getPerPage(): ?int
    {
        return $this->perPage;
    }

    /** Total records across all pages, when the API reports it. */
    public function getTotal(): ?int
    {
        return $this->total;
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->data);
    }

    public function count(): int
    {
        return count($this->data);
    }
}
