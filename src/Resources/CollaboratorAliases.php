<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\HttpTransport;

/**
 * A collaborator's aliases: the tracking codes that attribute a visit or a
 * sale to them.
 *
 * Siren has no standalone alias endpoint. Aliases are read through the
 * collaborator's `aliases` field and the tracking code is written through
 * the collaborator's `trackingId` field, which is what this class does.
 */
final class CollaboratorAliases
{
    public function __construct(
        private readonly HttpTransport $transport,
    ) {
    }

    /**
     * Lists a collaborator's aliases, each `{code, type, issuedDate}`.
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
     * another collaborator holds with a 422.
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

    private static function id(int $id): int
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Record ids are positive whole numbers.');
        }

        return $id;
    }
}
