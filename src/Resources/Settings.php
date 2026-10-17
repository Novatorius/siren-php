<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\HttpTransport;

/**
 * The organization settings Siren shares with a connected site
 * (`GET /configs`).
 *
 * Siren shares only the settings it names, each by type, subtype and key.
 * Today that is the referral link's query parameter, `collaborator` /
 * `urlReference` / `key`, which a site reads on every page view to spot a
 * referral and to build referral links. Any other type answers 400, and a
 * setting Siren does not share is never in the result.
 */
final class Settings
{
    public const PATH = '/configs';

    public const TYPE_COLLABORATOR = 'collaborator';

    public function __construct(
        private readonly HttpTransport $transport,
    ) {
    }

    /**
     * The shared collaborator settings, each row
     * `{type, subtype, configKey, value}`.
     *
     * @return list<array<string, mixed>>
     */
    public function collaborator(): array
    {
        return $this->rows(['type' => self::TYPE_COLLABORATOR, 'number' => ListResource::MAX_PAGE_SIZE]);
    }

    /**
     * One shared setting's value, or null when Siren holds none, such as
     * `value('collaborator', 'urlReference', 'key')` for the referral link's
     * query parameter.
     */
    public function value(string $type, string $subtype, string $configKey): mixed
    {
        foreach ($this->rows(['type' => $type, 'subtype' => $subtype, 'configKey' => $configKey, 'number' => 1]) as $row) {
            return $row['value'] ?? null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $params): array
    {
        foreach (['type', 'subtype', 'configKey'] as $field) {
            if (array_key_exists($field, $params) && $params[$field] === '') {
                throw new \InvalidArgumentException(sprintf('A setting %s must not be empty.', $field));
            }
        }

        $query = ListResource::listQuery($params);

        return ListResource::toListResult($this->transport->request('GET', self::PATH, null, $query), $query)->getData();
    }
}
