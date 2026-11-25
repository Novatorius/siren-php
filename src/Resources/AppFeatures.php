<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\Exception\ApiException;
use Siren\Sdk\Exception\SirenException;
use Siren\Sdk\FeatureKind;
use Siren\Sdk\HttpTransport;

/**
 * One app's features, through their own routes, `/apps/{app}/features`, so
 * code can add one without declaring the whole app. A feature's kind and key
 * lock once Siren makes it. Every route takes a signed-in admin's session
 * token, and a member limited to some stores gets 403 `org_access_required`,
 * reads included, since a feature's page names the records that pick it.
 *
 * Mirrors `siren.apps.features` in the Node SDK.
 *
 * @phpstan-type AppFeature array{id: int, kind: string, key: string, name: string, ...}
 */
final class AppFeatures
{
    /** The fields {@see create()} sends, those `POST /apps/{app}/features` reads (AddAppItem.php in Siren). */
    public const CREATE_FIELDS = ['kind', 'key', 'name', 'description', 'currencies', 'answerWindowMinutes', 'scoresMoney', 'conversionTypes', 'fields', 'domains'];

    /** The fields {@see update()} sends. Siren refuses a changed kind or key with 422. */
    public const UPDATE_FIELDS = ['name', 'description', 'currencies', 'answerWindowMinutes', 'scoresMoney', 'conversionTypes', 'fields', 'domains'];

    /** The words Siren keeps for its own app routes (`AppService::RESERVED_KEYS`), which no app holds as its key. */
    public const RESERVED_APP_KEYS = ['requests', 'events', 'scopes', 'kinds'];

    /** The payout kind's old stored value, which Siren refuses since it renamed the kind. */
    private const OLD_PAYOUT_KIND = 'fulfillmentMethod';

    public function __construct(private readonly HttpTransport $transport)
    {
    }

    /**
     * Adds one feature to the app with `POST /apps/{app}/features`, sent once,
     * and answers it with `uses` and `usedBy`. Siren answers 201, or 200 when
     * an archived feature of the same kind and key came back with the new
     * fields. With no `key`, Siren makes one from the name.
     *
     * Refused before sending, with `\InvalidArgumentException`: a bad app key,
     * a field other than {@see CREATE_FIELDS}, no `kind` or `name`, and the
     * old kind `fulfillmentMethod`. Siren answers 422 with the field for the
     * rest, 409 `app_features_need_key_and_address` when the app lacks a key
     * or an address, and 404 for an app the organization lacks.
     *
     * @param array<string, mixed> $params
     *
     * @return AppFeature
     *
     * @throws \InvalidArgumentException When the call can't be sent.
     * @throws SirenException When Siren refuses it.
     */
    public function create(string $appKey, array $params): array
    {
        $path = self::appPath($appKey, 'apps->features->create()') . '/features';
        $sent = self::pick($params, self::CREATE_FIELDS, static fn (string $field): string => sprintf('apps->features->create() does not take %s.', $field));

        foreach (['kind', 'name'] as $field) {
            if (($sent[$field] ?? null) === null) {
                throw new \InvalidArgumentException(sprintf('apps->features->create() needs a %s.', $field));
            }
        }

        if ($sent['kind'] === self::OLD_PAYOUT_KIND) {
            throw new \InvalidArgumentException(sprintf(
                'apps->features->create() needs kind %s. Siren renamed fulfillmentMethod to payoutMethod, the documentation\'s word.',
                FeatureKind::PAYOUT_METHOD,
            ));
        }

        return self::feature($this->transport->request('POST', $path, $sent, [], false)->json(), 'POST ' . $path);
    }

    /**
     * One feature by its id, with `uses`, how many records pick it, and
     * `usedBy`, up to 100 of them, from `GET /apps/{app}/features/{id}`.
     * Retried as a read. Siren answers 404 for a feature another app holds.
     *
     * @return AppFeature
     *
     * @throws \InvalidArgumentException For a bad app key or id.
     * @throws SirenException When Siren refuses it.
     */
    public function retrieve(string $appKey, int $id): array
    {
        $path = self::featurePath($appKey, $id, 'apps->features->retrieve()');

        return self::feature($this->transport->request('GET', $path)->json(), 'GET ' . $path);
    }

    /**
     * Changes a feature's name, description or settings with
     * `PATCH /apps/{app}/features/{id}`, sent once, and answers it. A field
     * left out keeps its value.
     *
     * @param array<string, mixed> $params
     *
     * @return AppFeature
     *
     * @throws \InvalidArgumentException For a bad app key or id, or a field other than {@see UPDATE_FIELDS}, `kind` and `key` included.
     * @throws SirenException When Siren refuses it.
     */
    public function update(string $appKey, int $id, array $params): array
    {
        $path = self::featurePath($appKey, $id, 'apps->features->update()');
        $sent = self::pick($params, self::UPDATE_FIELDS, static fn (string $field): string => in_array($field, ['kind', 'key'], true)
            ? sprintf("apps->features->update() cannot change %s. A feature's kind and key never change once Siren makes it.", $field)
            : sprintf('apps->features->update() does not take %s.', $field));

        return self::feature($this->transport->request('PATCH', $path, $sent, [], false)->json(), 'PATCH ' . $path);
    }

    /**
     * Removes a feature with `DELETE /apps/{app}/features/{id}`, sent once.
     * Siren archives it while a record picks it and answers
     * `['archived' => true, 'feature' => [...]]`, and deletes it otherwise
     * and answers `['archived' => false, 'feature' => null]`.
     *
     * @return array{archived: bool, feature: AppFeature|null}
     *
     * @throws \InvalidArgumentException For a bad app key or id.
     * @throws ApiException When Siren answers 2xx with another body.
     * @throws SirenException When Siren refuses it.
     */
    public function delete(string $appKey, int $id): array
    {
        $path = self::featurePath($appKey, $id, 'apps->features->delete()');
        $body = $this->transport->request('DELETE', $path, null, [], false)->json();

        if (!is_bool($body['archived'] ?? null) || !array_key_exists('feature', $body)) {
            throw new ApiException(sprintf('Siren answered DELETE %s with a body that is not { archived, feature }.', $path), 200, null, $body);
        }

        return [
            'archived' => $body['archived'],
            'feature' => $body['feature'] === null ? null : self::feature($body['feature'], 'DELETE ' . $path),
        ];
    }

    /**
     * `/apps/{key}`, or `\InvalidArgumentException` naming the method when
     * the key is not lowercase letters and digits joined by single dashes, at
     * most 64 characters, or is one of {@see RESERVED_APP_KEYS}.
     */
    public static function appPath(string $key, string $method): string
    {
        if (strlen($key) > 64 || preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $key) !== 1 || in_array($key, self::RESERVED_APP_KEYS, true)) {
            throw new \InvalidArgumentException(sprintf(
                '%s needs an app key of lowercase letters and digits joined by single dashes, at most 64 characters and never %s, and got %s.',
                $method,
                implode(', ', self::RESERVED_APP_KEYS),
                json_encode($key),
            ));
        }

        return '/apps/' . $key;
    }

    private static function featurePath(string $appKey, int $id, string $method): string
    {
        if ($id < 1) {
            throw new \InvalidArgumentException(sprintf("%s needs the feature's id as a positive whole number, and got %d.", $method, $id));
        }

        return self::appPath($appKey, $method) . '/features/' . $id;
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string>         $fields
     * @param \Closure(string): string $refusal
     *
     * @return array<string, mixed>
     */
    private static function pick(array $params, array $fields, \Closure $refusal): array
    {
        foreach (array_keys($params) as $field) {
            if (!in_array($field, $fields, true)) {
                throw new \InvalidArgumentException($refusal((string) $field));
            }
        }

        return $params;
    }

    /**
     * @return AppFeature
     *
     * @throws ApiException When the body is not a feature.
     */
    private static function feature(mixed $body, string $route): array
    {
        if (!is_array($body) || !is_int($body['id'] ?? null) || !is_string($body['kind'] ?? null) || !is_string($body['key'] ?? null) || !is_string($body['name'] ?? null)) {
            throw new ApiException(sprintf('Siren answered %s with a body that is not a feature.', $route), 200, null, is_array($body) ? $body : null);
        }

        /** @var AppFeature $body */
        return $body;
    }
}
