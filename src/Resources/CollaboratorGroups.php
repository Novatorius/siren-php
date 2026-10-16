<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\HttpTransport;
use Siren\Sdk\ListResult;

/**
 * Collaborator groups (`/collaborator-groups`): named sets of collaborators
 * that programs and distributors can target. Members live on
 * {@see $members}.
 */
class CollaboratorGroups extends ListResource
{
    public readonly CollaboratorGroupMembers $members;

    public function __construct(HttpTransport $transport)
    {
        parent::__construct($transport, '/collaborator-groups');

        $this->members = new CollaboratorGroupMembers($transport);
    }

    /**
     * Lists collaborator groups. Filters: `structure` and `s` (search name
     * and description). Sort with `orderBy` (`id`, `name`, `description`,
     * `structure`, `dateCreated`, `dateModified`) and `order`.
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
     * Creates a group. Required: `name`, `structure` (such as `flat`).
     * Optional: `description` and `members`, a list of
     * `{collaboratorId, metadata?}`.
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
     * Updates a group's `name`, `description`, or `structure` with
     * `PUT /collaborator-groups/{id}`.
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
     * Deletes the group for good. Siren answers 204, so this returns an
     * empty array.
     *
     * @return array<int|string, mixed>
     */
    public function delete(int $id): array
    {
        return $this->remove($this->recordPath($id));
    }

    /** @return array<int|string, mixed> The group structures Siren supports. */
    public function structures(): array
    {
        return $this->read($this->path . '/structures');
    }
}
