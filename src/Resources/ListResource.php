<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\HttpTransport;
use Siren\Sdk\ListResult;

/**
 * A thin paginated reader over a reconciliation endpoint
 * (`/conversions`, `/transactions`, `/obligations`, `/payouts`).
 */
final class ListResource
{
    public function __construct(
        private readonly HttpTransport $transport,
        private readonly string $path,
    ) {
    }

    /**
     * Lists a page of records.
     *
     * `$params` supports `page` and `perPage`; any additional keys are passed
     * through as query filters.
     *
     * @param array<string, mixed> $params
     */
    public function list(array $params = []): ListResult
    {
        $response = $this->transport->request('GET', $this->path, null, $params);

        // The body is a bare JSON array of records — there is no envelope.
        // The total (when known) is exposed via the x-siren-estimated-count
        // response header.
        $data = array_values($response->json());

        $page = is_numeric($params['page'] ?? null) ? (int) $params['page'] : null;
        $perPage = is_numeric($params['perPage'] ?? null) ? (int) $params['perPage'] : null;

        $estimatedCount = $response->getHeaderLine('x-siren-estimated-count');
        $total = is_numeric($estimatedCount) ? (int) $estimatedCount : null;

        return new ListResult(
            data: $data,
            page: $page,
            perPage: $perPage,
            total: $total,
        );
    }
}
