<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\HttpTransport;
use Siren\Sdk\ListResult;

/**
 * Program groups (`/program-groups`): sets of programs that compete for the
 * same engagement, with a sorter that picks the winner.
 */
class ProgramGroups extends ListResource
{
    public const BULK_DELETE = 'delete';

    public function __construct(HttpTransport $transport)
    {
        parent::__construct($transport, '/program-groups');
    }

    /**
     * Lists program groups. Filters: `sorter` and `s` (search name and
     * description). Sort with `orderBy` (`id`, `name`, `description`,
     * `sorter`) and `order`.
     *
     * @param array<string, mixed> $params
     */
    public function list(array $params = []): ListResult
    {
        return parent::list($params);
    }

    /**
     * @param array<string, mixed> $params `fields` to choose the returned fields.
     *
     * @return array<int|string, mixed>
     */
    public function retrieve(int $id, array $params = []): array
    {
        return $this->read($this->recordPath($id), $params);
    }

    /**
     * Creates a program group. Required: `name`, `description`, `sorter`
     * (see {@see \Siren\Sdk\ProgramGroupSorter}). Optional: `programs`, a
     * list of program ids.
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
     * Updates a program group with `PUT /program-groups/{id}`. `programs`,
     * when sent, replaces the group's programs.
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
     * Deletes the program group for good. Siren answers 204, so this returns an
     * empty array.
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
}
