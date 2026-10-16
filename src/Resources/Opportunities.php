<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\HttpTransport;
use Siren\Sdk\ListResult;

/**
 * Opportunities (`/opportunities`): a tracked visitor's chance to convert.
 *
 * Siren opens opportunities itself when it ingests a `site-visited` event,
 * so there is no create call. The event's {@see \Siren\Sdk\EventResult}
 * carries the new opportunity id.
 */
class Opportunities extends ListResource
{
    public function __construct(HttpTransport $transport)
    {
        parent::__construct($transport, '/opportunities');
    }

    /**
     * Lists opportunities. Filters: `status` (see
     * {@see \Siren\Sdk\OpportunityStatus}) and `collaboratorId`. Sort with
     * `orderBy` (`id`, `status`, `lastTriggered`, `dateCreated`,
     * `dateModified`) and `order`.
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
     * Updates an opportunity with `PUT /opportunities/{id}`. Accepts
     * `status` (`active` or `inactive`) and `lastTriggered`.
     *
     * @param array<string, mixed> $params
     *
     * @return array<int|string, mixed>
     */
    public function update(int $id, array $params): array
    {
        return $this->write('PUT', $this->recordPath($id), $params);
    }
}
