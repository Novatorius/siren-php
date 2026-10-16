<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\HttpTransport;
use Siren\Sdk\ListResult;

/**
 * Engagements (`/engagements`): a collaborator's claim on an opportunity in
 * one program. Siren creates them while it processes events, so this
 * resource only reads.
 */
class Engagements extends ListResource
{
    public function __construct(HttpTransport $transport)
    {
        parent::__construct($transport, '/engagements');
    }

    /**
     * Lists engagements. Filters: `status`, `collaboratorId`, `programId`,
     * and `opportunityId`. Sort with `orderBy` (`id`, `opportunityId`,
     * `programId`, `collaboratorId`, `score`, `status`, `source`,
     * `dateCreated`, `dateModified`) and `order`.
     *
     * @param array<string, mixed> $params
     */
    public function list(array $params = []): ListResult
    {
        return parent::list($params);
    }

    /**
     * @param array<string, mixed> $params `fields` or `include`.
     *
     * @return array<int|string, mixed>
     */
    public function retrieve(int $id, array $params = []): array
    {
        return $this->read($this->recordPath($id), $params);
    }

    /** @return array<int|string, mixed> The engagement types Siren knows. */
    public function types(): array
    {
        return $this->read($this->path . '/types');
    }

    /** @return array<int|string, mixed> The calculations a program can apply to an engagement type. */
    public function calculations(): array
    {
        return $this->read($this->path . '/calculations');
    }
}
