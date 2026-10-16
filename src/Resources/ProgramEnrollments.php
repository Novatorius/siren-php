<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\HttpTransport;
use Siren\Sdk\ListResult;

/**
 * Collaborator program enrollments: which collaborators take part in which
 * programs.
 *
 * Siren keeps enrollments on the collaborator. They are read through the
 * collaborator's `programs` field or the `programId` filter on the
 * collaborator list, and written through the collaborator bulk actions or a
 * collaborator update. This class gives those calls enrollment names.
 */
final class ProgramEnrollments
{
    public function __construct(
        private readonly HttpTransport $transport,
    ) {
    }

    /**
     * The programs a collaborator is enrolled in, each `{id, name}`.
     *
     * @return list<array<string, mixed>>
     */
    public function programsFor(int $collaboratorId): array
    {
        $record = $this->transport->request(
            'GET',
            '/collaborators/' . self::id($collaboratorId),
            null,
            ['fields' => 'id,programs'],
        )->json();

        $programs = [];

        foreach (is_array($record['programs'] ?? null) ? $record['programs'] : [] as $program) {
            if (is_array($program)) {
                /** @var array<string, mixed> $program */
                $programs[] = $program;
            }
        }

        return $programs;
    }

    /**
     * A page of the collaborators enrolled in a program. Accepts the same
     * parameters as {@see Collaborators::list()}.
     *
     * @param array<string, mixed> $params
     */
    public function collaboratorsIn(int $programId, array $params = []): ListResult
    {
        $query = ListResource::listQuery(['programId' => self::id($programId)] + $params);
        $response = $this->transport->request('GET', '/collaborators', null, $query);

        return ListResource::toListResult($response, $query);
    }

    /**
     * Enrolls collaborators in a program, keeping their other enrollments.
     *
     * @param list<int> $collaboratorIds
     *
     * @return array<int|string, mixed>
     */
    public function enroll(int $programId, array $collaboratorIds): array
    {
        return $this->bulk(Collaborators::BULK_ADD_TO_PROGRAM, $programId, $collaboratorIds);
    }

    /**
     * Removes collaborators from a program, keeping their other enrollments.
     *
     * @param list<int> $collaboratorIds
     *
     * @return array<int|string, mixed>
     */
    public function unenroll(int $programId, array $collaboratorIds): array
    {
        return $this->bulk(Collaborators::BULK_REMOVE_FROM_PROGRAM, $programId, $collaboratorIds);
    }

    /**
     * Replaces a collaborator's enrollments with exactly these programs. An
     * empty list removes every enrollment.
     *
     * @param list<int> $programIds
     *
     * @return array<int|string, mixed> The updated collaborator.
     */
    public function replace(int $collaboratorId, array $programIds): array
    {
        return $this->transport->request(
            'PUT',
            '/collaborators/' . self::id($collaboratorId),
            ['programs' => array_values($programIds)],
            [],
            retryable: false,
        )->json();
    }

    /**
     * @param list<int> $collaboratorIds
     *
     * @return array<int|string, mixed>
     */
    private function bulk(string $action, int $programId, array $collaboratorIds): array
    {
        if ($collaboratorIds === []) {
            throw new \InvalidArgumentException('Name at least one collaborator id.');
        }

        return $this->transport->request(
            'POST',
            '/collaborators/bulk',
            ['action' => $action, 'ids' => array_values($collaboratorIds), 'programId' => self::id($programId)],
            [],
            retryable: false,
        )->json();
    }

    private static function id(int $id): int
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Record ids are positive whole numbers.');
        }

        return $id;
    }
}
