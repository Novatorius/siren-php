<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\HttpTransport;
use Siren\Sdk\ListResult;

/**
 * Collaborator program enrollments: which collaborators take part in which
 * programs.
 *
 * Enrollments have their own route, `/collaborator-programs`, which a
 * connected site's token may use: {@see list()}, {@see add()} and
 * {@see remove()}. A connected site may only enroll a collaborator in, or
 * remove one from, a program that runs on its own Property. Siren answers
 * any other program with a 403, raised as
 * {@see \Siren\Sdk\Exception\PolicyException}.
 *
 * {@see programsFor()}, {@see collaboratorsIn()}, {@see enroll()},
 * {@see unenroll()} and {@see replace()} go through the collaborator record
 * and its bulk actions instead. Siren refuses those writes from a connected
 * site, so a connector uses {@see add()} and {@see remove()}.
 */
final class ProgramEnrollments
{
    public const PATH = '/collaborator-programs';

    public function __construct(
        private readonly HttpTransport $transport,
    ) {
    }

    /**
     * The programs a collaborator is enrolled in, each `{id, name}`.
     *
     * A connected site's token cannot use this: Siren Cloud refuses the
     * computed field it reads with a 403. A site uses {@see list()}.
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
     * Lists enrollments with `GET /collaborator-programs`, one row
     * `{collaboratorId, programId}` per enrollment.
     *
     * Filters, each an exact match: `collaboratorId` and `programId`. A list
     * value matches any of its values, so
     * `['collaboratorId' => 4, 'programId' => [2, 3]]` answers "which of these
     * programs is this collaborator in". Sort with `orderBy`
     * (`collaboratorId`, `programId`) and `order`. Pages with
     * `page`/`perPage` or `number`/`offset`.
     *
     * @param array<string, mixed> $params
     */
    public function list(array $params = []): ListResult
    {
        $query = ListResource::listQuery($params);

        return ListResource::toListResult($this->transport->request('GET', self::PATH, null, $query), $query);
    }

    /**
     * Enrolls one collaborator in one program with
     * `POST /collaborator-programs`. Enrolling someone already enrolled
     * changes nothing and is not an error. Never auto-retried.
     *
     * @return array<int|string, mixed> `{collaboratorId, programId}`
     *
     * @throws \Siren\Sdk\Exception\PolicyException When a connected site names a program that does not run on it.
     */
    public function add(int $collaboratorId, int $programId): array
    {
        return $this->transport->request(
            'POST',
            self::PATH,
            ['collaboratorId' => self::id($collaboratorId), 'programId' => self::id($programId)],
            [],
            retryable: false,
        )->json();
    }

    /**
     * Ends one enrollment with `DELETE /collaborator-programs`. Removing an
     * enrollment that does not exist is not an error. Never auto-retried.
     *
     * @return array<int|string, mixed> `{"deleted": true}`
     *
     * @throws \Siren\Sdk\Exception\PolicyException When a connected site names a program that does not run on it.
     */
    public function remove(int $collaboratorId, int $programId): array
    {
        return $this->transport->request(
            'DELETE',
            self::PATH,
            null,
            ['collaboratorId' => self::id($collaboratorId), 'programId' => self::id($programId)],
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
