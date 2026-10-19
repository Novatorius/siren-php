<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\Exception\CodeConflictException;
use Siren\Sdk\Exception\ConflictException;
use Siren\Sdk\HttpTransport;
use Siren\Sdk\ListResult;
use Siren\Sdk\PropertyQualifier;

/**
 * A collaborator's aliases: the tracking codes and coupon codes that
 * attribute a visit or a sale to them.
 *
 * Codes live on `/collaborator-aliases`, which a connected site's token may
 * use. {@see findByCode()}, {@see forCollaborator()} and {@see query()} read
 * it, {@see create()} gives out a code and {@see free()} takes one back.
 *
 * A code's type is `tracking`, `coupon` or `credit_coupon_{CURRENCY}`. Send
 * the plain type. Siren Cloud files a connected site's codes under the site's
 * Property (`coupon@property:5`), and every read matches the plain type in
 * every namespace, so the SDK hands back the plain type with the Property in
 * `propertyId` (null for a code in no site's namespace). A code has one
 * holder per type across all namespaces.
 *
 * {@see list()}, {@see trackingCode()} and {@see setTrackingCode()} read and
 * write the collaborator record's own `aliases`, `referralCode` and
 * `trackingId` fields instead.
 */
final class CollaboratorAliases
{
    public const PATH = '/collaborator-aliases';

    public const TYPE_TRACKING = 'tracking';
    public const TYPE_COUPON = 'coupon';

    public function __construct(
        private readonly HttpTransport $transport,
    ) {
    }

    /**
     * Lists a collaborator's aliases, each `{code, type, issuedDate}`.
     *
     * A connected site's token cannot use this: Siren Cloud refuses the
     * computed field it reads with a 403. A site uses {@see query()}.
     *
     * @return list<array<string, mixed>>
     */
    public function list(int $collaboratorId): array
    {
        $record = $this->transport->request(
            'GET',
            '/collaborators/' . self::id($collaboratorId),
            null,
            ['fields' => 'id,aliases'],
        )->json();

        $aliases = [];

        foreach (is_array($record['aliases'] ?? null) ? $record['aliases'] : [] as $alias) {
            if (is_array($alias)) {
                /** @var array<string, mixed> $alias */
                $aliases[] = $alias;
            }
        }

        return $aliases;
    }

    /**
     * Gets the collaborator's current tracking code, or null when they have none.
     */
    public function trackingCode(int $collaboratorId): ?string
    {
        $record = $this->transport->request(
            'GET',
            '/collaborators/' . self::id($collaboratorId),
            null,
            ['fields' => 'id,referralCode'],
        )->json();

        $code = $record['referralCode'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * Gives the collaborator a new tracking code. Siren refuses a code that
     * another collaborator holds with a 400, raised as
     * {@see \Siren\Sdk\Exception\BadRequestException} with field errors.
     *
     * Siren Cloud refuses `trackingId` from a connected site with a 403. A
     * site uses {@see create()} and {@see free()} instead.
     *
     * @return array<int|string, mixed> The updated collaborator.
     */
    public function setTrackingCode(int $collaboratorId, string $code): array
    {
        if ($code === '') {
            throw new \InvalidArgumentException('A tracking code must not be empty.');
        }

        return $this->transport->request(
            'PUT',
            '/collaborators/' . self::id($collaboratorId),
            ['trackingId' => $code],
            [],
            retryable: false,
        )->json();
    }

    /**
     * Who holds a code: the newest alias with this code and plain type, or
     * null when nobody does. The type matches in every namespace, so
     * `coupon` also finds a site's `coupon@property:5`.
     *
     * @return array<string, mixed>|null `{code, type, collaboratorId, dateIssued, propertyId}`
     */
    public function findByCode(string $code, string $type): ?array
    {
        return $this->first(['code' => self::code($code), 'type' => $type]);
    }

    /**
     * A collaborator's newest code of one plain type, such as their tracking
     * code, or null when they have none.
     *
     * @return array<string, mixed>|null `{code, type, collaboratorId, dateIssued, propertyId}`
     */
    public function forCollaborator(int $collaboratorId, string $type): ?array
    {
        return $this->first(['collaboratorId' => self::id($collaboratorId), 'type' => $type]);
    }

    /**
     * Lists codes with `GET /collaborator-aliases`.
     *
     * Filters, each an exact match: `code`, `type` (plain, matched in every
     * namespace) and `collaboratorId`. A list value matches any of its
     * values, but a connected site may send only one type, because Siren
     * refuses the comma it would be sent with. Sort with `orderBy`
     * (`dateIssued`, `code`, `type`, `collaboratorId`) and `order`. Newest
     * first by default. Pages with `page`/`perPage` or `number`/`offset`.
     *
     * @param array<string, mixed> $params
     */
    public function query(array $params = []): ListResult
    {
        if (array_key_exists('type', $params)) {
            $params['type'] = self::types($params['type']);
        }

        $query = ListResource::listQuery($params);
        $result = ListResource::toListResult($this->transport->request('GET', self::PATH, null, $query), $query);

        return $result->map(static fn (array $alias): array => PropertyQualifier::plainRecord($alias, 'type'));
    }

    /**
     * Gives a collaborator a code with `POST /collaborator-aliases`.
     *
     * Send the plain type. Siren Cloud files a connected site's code under the
     * site's Property. Never auto-retried.
     *
     * @return array<string, mixed> The new alias, `{code, type, collaboratorId, dateIssued, propertyId}`.
     *
     * @throws CodeConflictException When someone already holds the code under this type, in any namespace.
     */
    public function create(string $code, string $type, int $collaboratorId): array
    {
        $body = ['code' => self::code($code), 'type' => PropertyQualifier::assertName($type, 'type'), 'collaboratorId' => self::id($collaboratorId)];

        try {
            $alias = $this->transport->request('POST', self::PATH, $body, [], retryable: false)->json();
        } catch (ConflictException $e) {
            throw CodeConflictException::fromConflict($e, $body['code'], $type);
        }

        /** @var array<string, mixed> $alias */
        return PropertyQualifier::plainRecord($alias, 'type');
    }

    /**
     * Frees one code of one plain type with `DELETE /collaborator-aliases`,
     * as a coupon panel does when a coupon is renamed, reassigned or
     * deleted. A connected site frees only codes in its own namespace.
     * Freeing a code nobody holds is not an error. Never auto-retried.
     *
     * @return array<int|string, mixed> `{"deleted": true}`
     */
    public function free(string $code, string $type): array
    {
        return $this->transport->request(
            'DELETE',
            self::PATH,
            null,
            ['code' => self::code($code), 'type' => PropertyQualifier::assertName($type, 'type')],
            retryable: false,
        )->json();
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>|null
     */
    private function first(array $filters): ?array
    {
        $records = $this->query($filters + ['number' => 1])->getData();

        return $records[0] ?? null;
    }

    /**
     * @return string|list<string>
     */
    private static function types(mixed $type): string|array
    {
        if (is_string($type)) {
            return PropertyQualifier::assertName($type, 'type');
        }

        if (is_array($type) && $type !== [] && array_is_list($type)) {
            return array_map(static fn (mixed $one): string => PropertyQualifier::assertName(is_string($one) ? $one : '', 'type'), $type);
        }

        throw new \InvalidArgumentException('Send type as a plain type name or a list of them.');
    }

    private static function code(string $code): string
    {
        $code = trim($code);

        if ($code === '') {
            throw new \InvalidArgumentException('A code must not be empty.');
        }

        return $code;
    }

    private static function id(int $id): int
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Record ids are positive whole numbers.');
        }

        return $id;
    }
}
