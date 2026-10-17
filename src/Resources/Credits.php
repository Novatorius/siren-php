<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\Exception\ConflictException;
use Siren\Sdk\Exception\ErrorCode;
use Siren\Sdk\Exception\HoldConflictException;
use Siren\Sdk\Exception\HoldLimitException;
use Siren\Sdk\Exception\RateLimitException;
use Siren\Sdk\HttpTransport;
use Siren\Sdk\ListResult;
use Siren\Sdk\PropertyQualifier;

/**
 * Store credit: collaborator balances in internal custom currencies, the
 * ledger behind them, and the holds checkouts place on them.
 *
 * Amounts are whole minor units. A connected site's token may read
 * {@see entries()}, {@see checkoutBalances()} and {@see holds()}, place holds
 * with {@see placeHold()} and release them with {@see releaseHold()}. Only
 * Siren writes the ledger, through the engine and the dashboard's
 * {@see adjust()}.
 *
 * A hold ends when its `ttl` runs out (Siren Cloud sweeps expired holds
 * every five minutes and an expired hold reserves nothing), when the same
 * checkout places again and replaces it, when the site releases it early
 * with {@see releaseHold()}, or when Siren settles it from the order's
 * redemption event.
 */
final class Credits
{
    public const HOLD_MIN_TTL = 60;
    public const HOLD_MAX_TTL = 3600;
    public const HOLD_DEFAULT_TTL = 1800;

    /** The longest platform id Siren accepts on a hold. */
    public const HOLD_PLATFORM_MAX_LENGTH = 60;

    /** The longest checkout token Siren accepts on a hold. */
    public const HOLD_TOKEN_MAX_LENGTH = 120;

    public const HOLD_STATUS_ACTIVE = 'active';
    public const HOLD_STATUS_SETTLED = 'settled';
    public const HOLD_STATUS_RELEASED = 'released';
    public const HOLD_STATUS_EXPIRED = 'expired';

    public function __construct(
        private readonly HttpTransport $transport,
    ) {
    }

    /**
     * Adds to or takes from a collaborator's balance in one internal
     * currency.
     *
     * `$amount` is in minor units and must not be zero. A negative amount
     * takes credit away. This call is never auto-retried, because repeating
     * it would apply the adjustment twice. A connected site's token may not
     * call it.
     *
     * @return array<int|string, mixed>
     */
    public function adjust(int $collaboratorId, string $currency, int $amount, ?string $description = null): array
    {
        if ($amount === 0) {
            throw new \InvalidArgumentException('A credit adjustment amount must not be zero.');
        }

        $body = ['collaboratorId' => self::id($collaboratorId), 'currency' => self::currency($currency), 'amount' => $amount];

        if ($description !== null) {
            $body['description'] = $description;
        }

        return $this->transport->request('POST', '/credits/adjust', $body, [], retryable: false)->json();
    }

    /**
     * A collaborator's credit balances, read from their `creditBalances`
     * field. There is one entry per internal currency, with `currency`,
     * `balance` (gross), `onHold`, `available` (gross minus holds), `name`,
     * `symbol`, `symbolPosition`, and `exchangeRate`. A checkout uses
     * {@see checkoutBalances()} instead, which can leave out its own hold.
     *
     * @return array<int|string, mixed>
     */
    public function balances(int $collaboratorId): array
    {
        $record = $this->transport->request(
            'GET',
            '/collaborators/' . self::id($collaboratorId),
            null,
            ['fields' => 'id,creditBalances'],
        )->json();

        return is_array($record['creditBalances'] ?? null) ? $record['creditBalances'] : [];
    }

    /**
     * A collaborator's store credit with `GET /credit-balances`, the read a
     * checkout makes, one row per internal currency: `{collaboratorId, currency, balance, reserved,
     * available}`. `balance` is the ledger sum, `reserved` the live holds,
     * and `available` the balance less reserved, never below zero.
     *
     * Pass `$currency` for one currency. Pass the checkout's own hold
     * reference ({@see holdReference()}) as `$excludeReference` so a
     * checkout does not count its own hold against itself.
     *
     * @return list<array<string, mixed>>
     */
    public function checkoutBalances(int $collaboratorId, ?string $currency = null, ?string $excludeReference = null): array
    {
        $query = ['collaboratorId' => self::id($collaboratorId)];

        if ($currency !== null) {
            $query['currency'] = self::currency($currency);
        }

        if ($excludeReference !== null) {
            $query['excludeReference'] = self::reference($excludeReference, 'excludeReference');
        }

        $body = $this->transport->request('GET', '/credit-balances', null, $query)->json();
        $rows = [];

        foreach (is_array($body['items'] ?? null) ? $body['items'] : [] as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * One currency's row from {@see checkoutBalances()}, or null when Siren
     * has no such internal currency.
     *
     * @return array<string, mixed>|null
     */
    public function checkoutBalance(int $collaboratorId, string $currency, ?string $excludeReference = null): ?array
    {
        foreach ($this->checkoutBalances($collaboratorId, $currency, $excludeReference) as $row) {
            if (($row['currency'] ?? null) === $currency) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Lists ledger entries with `GET /credit-entries`. Each entry holds `id`,
     * `collaboratorId`, `currency`, `amount`, `sourceType`, `sourceId`,
     * `description`, `idempotencyKey` and `createdDate`.
     *
     * A query must name `collaboratorId` or `idempotencyKey`, so it never
     * lists a whole organization's ledger. Other filters, each an exact
     * match: `currency`, `sourceType`, `sourceId` and `description`. Sort
     * with `orderBy` (`id`, `dateCreated`, `amount`) and `order`. Pages with
     * `page`/`perPage` or `number`/`offset`.
     *
     * @param array<string, mixed> $params
     */
    public function entries(array $params): ListResult
    {
        self::requireOneOf($params, ['collaboratorId', 'idempotencyKey']);

        return $this->listAt('/credit-entries', $params);
    }

    /**
     * Lists credit holds with `GET /credit-holds`. Each hold holds `id`,
     * `collaboratorId`, `currency`, `amount`, `status`, `reference`,
     * `idempotencyKey`, `expiresAt` and `createdDate`.
     *
     * A query must name `collaboratorId`, `reference` or `idempotencyKey`.
     * Other filters, each an exact match: `currency` and `status` (the
     * `HOLD_STATUS_*` constants). Send `reference` as
     * {@see holdReference()} builds it. A connected site reads only the holds
     * its own checkouts placed, and their references come back with the
     * site's Property (`hold:woocommerce@property:5:{token}`). Sort with
     * `orderBy` (`id`, `dateCreated`, `expiresAt`) and `order`.
     *
     * @param array<string, mixed> $params
     */
    public function holds(array $params): ListResult
    {
        self::requireOneOf($params, ['collaboratorId', 'reference', 'idempotencyKey']);

        if (is_string($params['reference'] ?? null)) {
            $params['reference'] = self::reference($params['reference'], 'reference');
        }

        return $this->listAt('/credit-holds', $params);
    }

    /**
     * Reserves a collaborator's store credit for a checkout with
     * `POST /credit-holds`, so two carts cannot spend the same credit.
     *
     * `$token` names the checkout session and `$platformId` the commerce
     * platform, such as `woocommerce`. Both must be letters, digits,
     * underscores and hyphens, which a UUID token already is. Send the plain
     * platform. Siren Cloud adds the site's Property. Placing again for the
     * same token and currency replaces that session's hold. `$ttl` is 60 to
     * 3600 seconds, 1800 when null. Never auto-retried.
     *
     * The returned hold's `amount` may be lower than asked, because Siren
     * caps it at the credit available outside this session's own hold.
     *
     * @return array<string, mixed> The hold.
     *
     * @throws HoldConflictException When no credit is available, or another site's checkout used the token. See {@see HoldConflictException::getReason()}.
     * @throws HoldLimitException    When the collaborator already holds credit for ten other live checkouts on this site.
     */
    public function placeHold(int $collaboratorId, string $currency, int $amount, string $token, string $platformId, ?int $ttl = null): array
    {
        if ($amount < 1) {
            throw new \InvalidArgumentException('A hold amount is a whole number of minor units, 1 or more.');
        }

        if ($ttl !== null && ($ttl < self::HOLD_MIN_TTL || $ttl > self::HOLD_MAX_TTL)) {
            throw new \InvalidArgumentException(sprintf('A hold ttl is %d to %d seconds.', self::HOLD_MIN_TTL, self::HOLD_MAX_TTL));
        }

        $body = [
            'collaboratorId' => self::id($collaboratorId),
            'currency' => self::currency($currency),
            'amount' => $amount,
            'token' => self::name($token, 'token', self::HOLD_TOKEN_MAX_LENGTH),
            'platformId' => self::name($platformId, 'platformId', self::HOLD_PLATFORM_MAX_LENGTH),
        ];

        if ($ttl !== null) {
            $body['ttl'] = $ttl;
        }

        try {
            $hold = $this->transport->request('POST', '/credit-holds', $body, [], retryable: false)->json();
        } catch (ConflictException $e) {
            throw HoldConflictException::fromConflict($e);
        } catch (RateLimitException $e) {
            if (self::isHoldLimit($e)) {
                throw HoldLimitException::fromRateLimit($e);
            }

            throw $e;
        }

        /** @var array<string, mixed> $hold */
        return $hold;
    }

    /**
     * Releases a store credit hold early with `DELETE /credit-holds/{id}`,
     * so the credit it reserved is available again. Call it when a checkout
     * is abandoned or no longer uses the credit.
     *
     * Releasing is safe to repeat. A hold that already ended, released,
     * expired or settled, comes back unchanged with no error. Siren answers
     * 404 for a hold this site's checkouts did not place.
     *
     * @return array<string, mixed> The hold as Siren now stores it.
     *
     * @throws \Siren\Sdk\Exception\NotFoundException When no hold of this site has that id.
     */
    public function releaseHold(int $holdId): array
    {
        $hold = $this->transport->request('DELETE', '/credit-holds/' . self::id($holdId))->json();

        /** @var array<string, mixed> $hold */
        return $hold;
    }

    /**
     * Whether a 429 is the hold cap rather than a request rate limit. Siren
     * names the cap with {@see ErrorCode::HOLD_LIMIT}. A deployment that
     * sends no code is read the old way: the rate limit names a wait and the
     * cap does not.
     */
    private static function isHoldLimit(RateLimitException $e): bool
    {
        if (ErrorCode::isKnown($e->getErrorCode())) {
            return $e->getErrorCode() === ErrorCode::HOLD_LIMIT;
        }

        return $e->getRetryAfter() === null && !isset($e->getErrorData()['retryAfter']);
    }

    /**
     * The reference a checkout session's holds carry, `hold:{platform}:{token}`,
     * as a site sends it. Use it as {@see holds()}'s `reference` filter and as
     * {@see checkoutBalances()}'s `$excludeReference`.
     */
    public static function holdReference(string $platformId, string $token): string
    {
        return 'hold:' . self::name($platformId, 'platformId', self::HOLD_PLATFORM_MAX_LENGTH)
            . ':' . self::name($token, 'token', self::HOLD_TOKEN_MAX_LENGTH);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function listAt(string $path, array $params): ListResult
    {
        $query = ListResource::listQuery($params);

        return ListResource::toListResult($this->transport->request('GET', $path, null, $query), $query);
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string>         $names
     */
    private static function requireOneOf(array $params, array $names): void
    {
        foreach ($names as $name) {
            $value = $params[$name] ?? null;

            if (is_int($value) || (is_string($value) && trim($value) !== '')) {
                return;
            }
        }

        throw new \InvalidArgumentException('Send at least one of: ' . implode(', ', $names) . '.');
    }

    /**
     * A plain hold reference, `hold:{platform}:{token}`, as Siren accepts it
     * from a site.
     */
    private static function reference(string $reference, string $field): string
    {
        $parts = explode(':', $reference);

        if (count($parts) !== 3 || $parts[0] !== 'hold'
            || !PropertyQualifier::isValidName($parts[1]) || !PropertyQualifier::isValidName($parts[2])) {
            throw new \InvalidArgumentException(sprintf('Send %s as hold:{platform}:{token}. Build it with Credits::holdReference().', $field));
        }

        return $reference;
    }

    private static function name(string $value, string $field, int $maxLength): string
    {
        PropertyQualifier::assertName($value, $field);

        if (strlen($value) > $maxLength) {
            throw new \InvalidArgumentException(sprintf('A %s is at most %d characters.', $field, $maxLength));
        }

        return $value;
    }

    private static function currency(string $currency): string
    {
        if ($currency === '') {
            throw new \InvalidArgumentException('A currency code is required.');
        }

        return $currency;
    }

    private static function id(int $id): int
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Record ids are positive whole numbers.');
        }

        return $id;
    }
}
