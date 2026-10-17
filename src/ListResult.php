<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * A page of records from a list endpoint, with paging metadata where the
 * API provides it.
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
        private readonly ?int $totalPages = null,
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

    /** Number of pages at this page size, when the API reports it. */
    public function getTotalPages(): ?int
    {
        return $this->totalPages;
    }

    /** Whether a later page exists, judged from the reported total. Null when the total is unknown. */
    public function hasMore(): ?bool
    {
        if ($this->total === null || $this->page === null || $this->perPage === null || $this->perPage < 1) {
            return null;
        }

        return $this->page * $this->perPage < $this->total;
    }

    /**
     * A copy of this page with each record passed through `$transform`, and
     * the same paging metadata.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $transform
     */
    public function map(callable $transform): self
    {
        return new self(
            array_values(array_map($transform, $this->data)),
            $this->page,
            $this->perPage,
            $this->total,
            $this->totalPages,
        );
    }

    /** @return \ArrayIterator<int, array<string, mixed>> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->data);
    }

    public function count(): int
    {
        return count($this->data);
    }
}
