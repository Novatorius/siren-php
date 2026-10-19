<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\ApiResponse;
use Siren\Sdk\HttpTransport;
use Siren\Sdk\ListResult;

/**
 * A paginated reader over one Siren list endpoint, and the base for every
 * record resource.
 *
 * `list()` accepts the API's own paging parameters, `number` (page size,
 * 1 to 200, default 10) and `offset` (first row, default 0), or the
 * friendlier `page` and `perPage`, which are turned into `number` and
 * `offset` before the request goes out. A page size above 200 is lowered to
 * 200, as Siren would. Every other key is sent as a query filter. A list
 * value, such as `['active', 'pending']` or a `fields` list, is sent
 * comma-separated, which is how Siren reads both field lists and "any of"
 * filters.
 *
 * Siren wraps list responses as
 * `{"items": [...], "total", "page", "perPage", "totalPages"}`. A bare JSON
 * array is also accepted, with the total read from the
 * `x-siren-estimated-count` header.
 */
class ListResource
{
    /** The largest page Siren serves. A larger `number` is lowered to this. */
    public const MAX_PAGE_SIZE = 200;

    /** The most ids one bulk action may carry. Siren answers 400 past it. */
    public const MAX_BULK_IDS = 500;

    public function __construct(
        protected readonly HttpTransport $transport,
        protected readonly string $path,
    ) {
    }

    /**
     * Lists a page of records.
     *
     * @param array<string, mixed> $params Paging (`page`/`perPage` or `number`/`offset`),
     *                                     sorting (`orderBy`, `order`), `fields`, and filters.
     */
    public function list(array $params = []): ListResult
    {
        return $this->listAt($this->path, $params);
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function listAt(string $path, array $params): ListResult
    {
        $query = self::listQuery($params);
        $response = $this->transport->request('GET', $path, null, $query);

        return self::toListResult($response, $query);
    }

    /**
     * Reads one record (or any JSON object) with a GET.
     *
     * @param array<string, mixed> $params Query parameters, such as `fields`.
     *
     * @return array<int|string, mixed>
     */
    protected function read(string $path, array $params = []): array
    {
        return $this->transport->request('GET', $path, null, self::query($params))->json();
    }

    /**
     * Sends a write and returns the decoded body, or an empty array when
     * Siren answers without one. Writes are never auto-retried.
     *
     * @param array<int|string, mixed> $body
     *
     * @return array<int|string, mixed>
     */
    protected function write(string $method, string $path, array $body = []): array
    {
        return $this->transport->request($method, $path, $body, [], retryable: false)->json();
    }

    /**
     * Sends a DELETE. Siren's record deletes are two-stage (the first call
     * marks the record deleted and returns it, a second call removes it), so
     * a DELETE is never retried automatically.
     *
     * @param array<string, mixed> $query
     *
     * @return array<int|string, mixed> The soft-deleted record, or an empty array when the record was removed.
     */
    protected function remove(string $path, array $query = []): array
    {
        return $this->transport->request('DELETE', $path, null, self::query($query), retryable: false)->json();
    }

    /**
     * Runs one of the endpoint's `/bulk` actions.
     *
     * @param list<int>            $ids
     * @param array<string, mixed> $extra Extra body fields the action needs.
     *
     * @return array<int|string, mixed>
     */
    protected function bulkAction(string $action, array $ids, array $extra = []): array
    {
        if ($action === '') {
            throw new \InvalidArgumentException('A bulk action name is required.');
        }

        if ($ids === []) {
            throw new \InvalidArgumentException('A bulk action needs at least one id.');
        }

        if (count($ids) > self::MAX_BULK_IDS) {
            throw new \InvalidArgumentException(sprintf(
                'A bulk action takes at most %d ids. Send the rest in another call.',
                self::MAX_BULK_IDS,
            ));
        }

        return $this->write('POST', $this->path . '/bulk', ['action' => $action, 'ids' => array_values($ids)] + $extra);
    }

    protected function recordPath(int $id): string
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Record ids are positive whole numbers.');
        }

        return $this->path . '/' . $id;
    }

    /**
     * Builds a list query: turns `page`/`perPage` into Siren's `number` and
     * `offset`, then applies {@see query()}.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public static function listQuery(array $params): array
    {
        $page = $params['page'] ?? null;
        $perPage = $params['perPage'] ?? null;
        unset($params['page'], $params['perPage']);

        if (is_numeric($perPage) && !isset($params['number'])) {
            $params['number'] = (int) $perPage;
        }

        // Siren lowers a page size above 200 to 200. Clamp it here too, or a
        // page-based offset would skip the rows between the two sizes.
        if (is_numeric($params['number'] ?? null) && (int) $params['number'] > self::MAX_PAGE_SIZE) {
            $params['number'] = self::MAX_PAGE_SIZE;
        }

        if (is_numeric($page) && !isset($params['offset'])) {
            $size = is_numeric($params['number'] ?? null) ? (int) $params['number'] : 10;
            $params['number'] = $size;
            $params['offset'] = max(0, ((int) $page - 1) * $size);
        }

        return self::query($params);
    }

    /**
     * Drops null values and sends list values comma-separated.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public static function query(array $params): array
    {
        $query = [];

        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value) && array_is_list($value) && self::allScalar($value)) {
                $value = implode(',', array_map(static fn ($item): string => is_bool($item) ? ($item ? 'true' : 'false') : (string) $item, $value));
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            $query[$key] = $value;
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $query The query that was sent.
     */
    public static function toListResult(ApiResponse $response, array $query): ListResult
    {
        $body = $response->json();

        $number = is_numeric($query['number'] ?? null) ? (int) $query['number'] : null;
        $offset = is_numeric($query['offset'] ?? null) ? (int) $query['offset'] : null;
        $page = $number !== null && $number > 0 && $offset !== null ? intdiv($offset, $number) + 1 : null;

        if (isset($body['items']) && is_array($body['items'])) {
            return new ListResult(
                data: self::records($body['items']),
                page: is_numeric($body['page'] ?? null) ? (int) $body['page'] : $page,
                perPage: is_numeric($body['perPage'] ?? null) ? (int) $body['perPage'] : $number,
                total: is_numeric($body['total'] ?? null) ? (int) $body['total'] : null,
                totalPages: is_numeric($body['totalPages'] ?? null) ? (int) $body['totalPages'] : null,
            );
        }

        $estimatedCount = $response->getHeaderLine('x-siren-estimated-count');

        return new ListResult(
            data: self::records($body),
            page: $page,
            perPage: $number,
            total: is_numeric($estimatedCount) ? (int) $estimatedCount : null,
        );
    }

    /**
     * @param array<int|string, mixed> $items
     *
     * @return list<array<string, mixed>>
     */
    private static function records(array $items): array
    {
        $records = [];

        foreach ($items as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $records[] = $item;
            }
        }

        return $records;
    }

    /**
     * @param list<mixed> $values
     */
    private static function allScalar(array $values): bool
    {
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                return false;
            }
        }

        return true;
    }
}
