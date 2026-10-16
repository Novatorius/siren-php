<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\HttpTransport;
use Siren\Sdk\ListResult;

/**
 * Programs (`/programs`): the incentive rules that turn engagements into
 * rewards.
 */
class Programs extends ListResource
{
    public const BULK_ACTIVATE = 'activate';
    public const BULK_DEACTIVATE = 'deactivate';
    public const BULK_RESTORE = 'restore';
    public const BULK_DELETE = 'delete';
    public const BULK_PERMANENT_DELETE = 'permanent_delete';

    public function __construct(HttpTransport $transport)
    {
        parent::__construct($transport, '/programs');
    }

    /**
     * Lists programs.
     *
     * Filters: `status`, `incentiveType`, `incentiveResolverType`, `units`
     * (exact match; a list means "any of"), `s` (search name and
     * description), and `programGroupId`. Sort with `orderBy` (`id`, `name`,
     * `description`, `incentiveType`, `incentiveResolverType`, `status`,
     * `units`, `dateCreated`, `dateModified`) and `order`.
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
     * Creates a program.
     *
     * Required: `name`, `description`, `status` (see
     * {@see \Siren\Sdk\ProgramStatus}), `incentiveType`,
     * `incentiveResolverType`, `units` (a currency code), and
     * `engagementTypes`, a map of engagement type to
     * `{calculationType, calculationArgs}`. Optional: `incentiveTypeArgs`.
     * Plus-tier extras: `collaboratorIds` and `collaboratorGroupId`.
     *
     * @param array<string, mixed> $params
     *
     * @return array<int|string, mixed> The created program.
     */
    public function create(array $params): array
    {
        return $this->write('POST', $this->path, $params);
    }

    /**
     * Updates a program with `PUT /programs/{id}`. Send only the fields to
     * change. `engagementTypes`, when sent, replaces the program's rules.
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
     * Deletes a program. The first call marks it `deleted`, a second call
     * removes it.
     *
     * @return array<int|string, mixed>
     */
    public function delete(int $id): array
    {
        return $this->remove($this->recordPath($id));
    }

    /**
     * Runs a bulk action on several programs. Use the `BULK_*` constants.
     *
     * @param list<int> $ids
     *
     * @return array<int|string, mixed>
     */
    public function bulk(string $action, array $ids): array
    {
        return $this->bulkAction($action, $ids);
    }

    /** @return array<int|string, mixed> The program statuses Siren accepts. */
    public function statusTypes(): array
    {
        return $this->read($this->path . '/status-types');
    }

    /** @return array<int|string, mixed> The incentive types a program can use. */
    public function incentiveTypes(): array
    {
        return $this->read($this->path . '/incentive-types');
    }

    /** @return array<int|string, mixed> The currencies a program can pay in. */
    public function currencyTypes(): array
    {
        return $this->read($this->path . '/currency-types');
    }
}
