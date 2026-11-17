<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\HttpTransport;
use Siren\Sdk\ListResult;

/**
 * Fulfillments (`/fulfillments`): the batches Siren groups payouts into.
 * This resource only reads. Making, changing and deleting a fulfillment
 * moves money, so Siren keeps those for a signed-in admin.
 *
 * Takes an API key that holds
 * {@see \Siren\Sdk\ApiKeyRecordScope::FULFILLMENTS_READ}, or a dashboard
 * session's token. Fulfillments are not kept by store, so a key bound to one
 * store gets a {@see \Siren\Sdk\Exception\StoreKeyNotAllowedException}.
 * Reads retry on a network error, 429 or 5xx inside the client's
 * `maxRetries`.
 */
class Fulfillments extends ListResource
{
    public function __construct(HttpTransport $transport)
    {
        parent::__construct($transport, '/fulfillments');
    }

    /**
     * Lists fulfillments with `GET /fulfillments`, newest first by default.
     * Siren answers `id`, `status`, `dateCreated` and `dateModified` unless
     * `fields` names others. Filters: `status` (see
     * {@see \Siren\Sdk\FulfillmentStatus}, and a list means "any of"),
     * `payoutStatus` (only fulfillments holding a payout in that status, and
     * `failed` finds the ones that need attention), `currency`, and `from`
     * and `to` (days, `YYYY-MM-DD`). Sort with `orderBy` (`id`, `status`,
     * `dateCreated`) and `order`. Every other key is sent as given, as on
     * every list.
     *
     * Siren reads some values as no filter at all, and would answer every
     * fulfillment. So this throws `InvalidArgumentException` before sending
     * for a `payoutStatus` or `currency` of `""`, for either one when it is
     * not a string (Siren matches each as one value, so a joined list would
     * answer none), and for a `from` or `to` that is not exactly
     * `YYYY-MM-DD` naming a real day. A `null` filter is left out.
     *
     * @param array<string, mixed> $params
     */
    public function list(array $params = []): ListResult
    {
        foreach (['payoutStatus', 'currency'] as $key) {
            $value = $params[$key] ?? null;

            if ($value === null) {
                continue;
            }

            if ($value === '') {
                throw new \InvalidArgumentException(sprintf(
                    'fulfillments->list() cannot send a %s of "", which Siren reads as no filter and answers every fulfillment.',
                    $key,
                ));
            }

            if (!is_string($value)) {
                throw new \InvalidArgumentException(sprintf(
                    'fulfillments->list() takes one %s as a string, since Siren matches it as one value.',
                    $key,
                ));
            }
        }

        foreach (['from', 'to'] as $key) {
            $value = $params[$key] ?? null;

            if ($value !== null && !self::isDay($value)) {
                throw new \InvalidArgumentException(sprintf(
                    'fulfillments->list() needs %s as a day, YYYY-MM-DD, and got %s.',
                    $key,
                    is_string($value) ? json_encode($value, JSON_UNESCAPED_SLASHES) : get_debug_type($value),
                ));
            }
        }

        return parent::list($params);
    }

    /**
     * Reads one fulfillment with `GET /fulfillments/{id}`.
     * `include => 'extended'` adds the payout counts, the total, the
     * currency and each payout.
     *
     * Throws {@see \Siren\Sdk\Exception\NotFoundException} for an unknown id,
     * and `InvalidArgumentException` before sending for an id below 1.
     *
     * @param array<string, mixed> $params `fields` or `include`.
     *
     * @return array<int|string, mixed>
     */
    public function retrieve(int $id, array $params = []): array
    {
        return $this->read($this->recordPath($id), $params);
    }

    /** Whether a value is exactly `YYYY-MM-DD` and names a real calendar day. */
    private static function isDay(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));

        return $day !== false && $day->format('Y-m-d') === $value;
    }
}
