<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\HttpTransport;
use Siren\Sdk\ListResult;
use Siren\Sdk\PropertyQualifier;

/**
 * Mappings (`/mappings`): links between a Siren record and a record outside
 * Siren, such as a WordPress user and their collaborator, a product and the
 * collaborator it credits, or a WooCommerce order and its transaction.
 *
 * Every call names an external type, as Siren Cloud requires of a connected
 * site. Send the plain type, such as `wordpress_user` or `wc_product`. Cloud
 * files a connected site's links under the site's Property
 * (`wordpress_user@property:5`) and reads only that namespace, so two sites
 * in one organization never see or replace each other's links. The SDK hands
 * back the plain type with the Property in `propertyId`.
 *
 * Cloud's own `user` type links a collaborator to a Cloud account, and Cloud
 * refuses it from a connected site with a 403, raised as
 * {@see \Siren\Sdk\Exception\PolicyException}. A WordPress user is
 * {@see EXTERNAL_TYPE_WORDPRESS_USER}. The SDK does not translate between
 * the two, because an API key may still read and write `user` links. A
 * WordPress host translates at its own boundary.
 *
 * Only collaborator links can be written. Siren makes the order,
 * transaction and opportunity links as it processes events.
 */
class Mappings extends ListResource
{
    /** The Siren side of every link this resource writes. */
    public const LOCAL_TYPE_COLLABORATOR = 'collaborator';

    /** A WordPress user, as a connected site names it. */
    public const EXTERNAL_TYPE_WORDPRESS_USER = 'wordpress_user';

    public function __construct(HttpTransport $transport)
    {
        parent::__construct($transport, '/mappings');
    }

    /**
     * Lists links with `GET /mappings`.
     *
     * `externalType` is required and must be one plain type. Other filters,
     * each an exact match: `localId`, `localType` and `externalId`. Sort with
     * `orderBy` (`localId`, `externalId`, `localType`, `externalType`) and
     * `order`. Pages with `page`/`perPage` or `number`/`offset`.
     *
     * @param array<string, mixed> $params
     */
    public function list(array $params = []): ListResult
    {
        $externalType = $params['externalType'] ?? null;
        $params['externalType'] = self::type(is_string($externalType) ? $externalType : '', 'externalType');

        if (array_key_exists('localType', $params) && $params['localType'] !== null) {
            $params['localType'] = self::type(is_string($params['localType']) ? $params['localType'] : '', 'localType');
        }

        return parent::list($params)->map(static fn (array $mapping): array => PropertyQualifier::plainRecord($mapping, 'externalType'));
    }

    /**
     * The links of one outside record, such as the collaborator a WordPress
     * user belongs to or the transaction a WooCommerce order made.
     *
     * @param array<string, mixed> $params Paging and sorting, as {@see list()} takes.
     */
    public function forExternal(string $externalId, string $externalType, ?string $localType = null, array $params = []): ListResult
    {
        return $this->list(['externalId' => self::externalId($externalId), 'externalType' => $externalType, 'localType' => $localType] + $params);
    }

    /**
     * The links of one Siren record of one external type, such as the
     * WordPress user a collaborator belongs to.
     *
     * @param array<string, mixed> $params Paging and sorting, as {@see list()} takes.
     */
    public function forLocal(int $localId, string $localType, string $externalType, array $params = []): ListResult
    {
        return $this->list(['localId' => self::localId($localId), 'localType' => $localType, 'externalType' => $externalType] + $params);
    }

    /**
     * Links a collaborator to an outside record with `POST /mappings`.
     * Never auto-retried.
     *
     * @return array<string, mixed> The link, `{localId, localType, externalId, externalType, propertyId}`.
     *
     * @throws \Siren\Sdk\Exception\ConflictException When the link already exists.
     */
    public function create(int $collaboratorId, string $externalId, string $externalType): array
    {
        $mapping = $this->write('POST', $this->path, [
            'localId' => self::localId($collaboratorId),
            'localType' => self::LOCAL_TYPE_COLLABORATOR,
            'externalId' => self::externalId($externalId),
            'externalType' => self::type($externalType, 'externalType'),
        ]);

        /** @var array<string, mixed> $mapping */
        return PropertyQualifier::plainRecord($mapping, 'externalType');
    }

    /**
     * Removes a collaborator's links of one external type with
     * `DELETE /mappings`, such as the WordPress user they belonged to.
     * Removing nothing is not an error. Never auto-retried.
     *
     * @return array<int|string, mixed> `{"deleted": true}`
     */
    public function deleteForCollaborator(int $collaboratorId, string $externalType): array
    {
        return $this->remove($this->path, [
            'localType' => self::LOCAL_TYPE_COLLABORATOR,
            'localId' => self::localId($collaboratorId),
            'externalType' => self::type($externalType, 'externalType'),
        ]);
    }

    /**
     * Removes every collaborator link of one outside record with
     * `DELETE /mappings`, such as a deleted WordPress user or a product whose
     * credited collaborators are being replaced. Removing nothing is not an
     * error. Never auto-retried.
     *
     * @return array<int|string, mixed> `{"deleted": true}`
     */
    public function deleteForExternal(string $externalId, string $externalType): array
    {
        return $this->remove($this->path, [
            'localType' => self::LOCAL_TYPE_COLLABORATOR,
            'externalId' => self::externalId($externalId),
            'externalType' => self::type($externalType, 'externalType'),
        ]);
    }

    private static function type(string $type, string $field): string
    {
        return PropertyQualifier::assertName($type, $field);
    }

    private static function externalId(string $externalId): string
    {
        $externalId = trim($externalId);

        if ($externalId === '') {
            throw new \InvalidArgumentException('An externalId must not be empty.');
        }

        return $externalId;
    }

    private static function localId(int $id): int
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Record ids are positive whole numbers.');
        }

        return $id;
    }
}
