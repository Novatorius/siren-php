<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\HttpTransport;
use Siren\Sdk\ListResult;

/**
 * The members of a collaborator group
 * (`/collaborator-groups/{id}/members`).
 */
final class CollaboratorGroupMembers
{
    public function __construct(
        private readonly HttpTransport $transport,
    ) {
    }

    /**
     * Lists a group's members. Filter: `collaboratorId`. Sort with `orderBy`
     * (`id`, `groupId`, `collaboratorId`, `dateCreated`, `dateModified`)
     * and `order`.
     *
     * @param array<string, mixed> $params
     */
    public function list(int $groupId, array $params = []): ListResult
    {
        $query = ListResource::listQuery($params);
        $response = $this->transport->request('GET', $this->path($groupId), null, $query);

        return ListResource::toListResult($response, $query);
    }

    /**
     * Adds members, each `{collaboratorId, metadata?}`, keeping the rest.
     *
     * @param list<array<string, mixed>> $members
     *
     * @return array<int|string, mixed>
     */
    public function add(int $groupId, array $members): array
    {
        return $this->transport->request('POST', $this->path($groupId), ['members' => $members], [], retryable: false)->json();
    }

    /**
     * Replaces the group's members with exactly this list.
     *
     * @param list<array<string, mixed>> $members
     *
     * @return array<int|string, mixed>
     */
    public function set(int $groupId, array $members): array
    {
        return $this->transport->request('PUT', $this->path($groupId), ['members' => $members], [], retryable: false)->json();
    }

    /**
     * Removes one collaborator from the group.
     *
     * @return array<int|string, mixed>
     */
    public function remove(int $groupId, int $collaboratorId): array
    {
        if ($collaboratorId < 1) {
            throw new \InvalidArgumentException('Record ids are positive whole numbers.');
        }

        return $this->transport->request('DELETE', $this->path($groupId) . '/' . $collaboratorId, null, [], retryable: false)->json();
    }

    private function path(int $groupId): string
    {
        if ($groupId < 1) {
            throw new \InvalidArgumentException('Record ids are positive whole numbers.');
        }

        return '/collaborator-groups/' . $groupId . '/members';
    }
}
