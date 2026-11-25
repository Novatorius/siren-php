<?php

declare(strict_types=1);

namespace Siren\Sdk\Testing;

use Siren\Sdk\Exception\AppHandlerException;
use Siren\Sdk\Resources\Apps;
use Siren\Sdk\Siren;

/**
 * Builds the requests Siren sends an app, signed, so a handler can be tried
 * and tested without Siren.
 *
 * ```php
 * use Siren\Sdk\Testing\AppRequests;
 *
 * $result = $answers->simulate(AppRequests::make('distributionResolver', 'split-by-score'));
 * // $result->answer is what the function returned, checked and not posted.
 * ```
 *
 * The SDK's own README samples are tested with these, so they match what
 * the handler takes.
 */
final class AppRequests
{
    /** The app key a made request names when none is given. */
    public const DEFAULT_APP = 'acme-crm';

    /** How long after it is made an ask's `answerBy` falls, an hour, as Siren's engine kinds wait. */
    public const ANSWER_WINDOW_SECONDS = 3600;

    /** @var array<string, array{mode: string, data: array<string, mixed>}>|null */
    private static ?array $samples = null;

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $eventSamples = null;

    /**
     * A request for one feature, signed now with the app's signing secret.
     *
     * Options:
     * - `secret`: the signing secret. Defaults to the `SIREN_SIGNING_SECRET` environment variable.
     * - `data`: the question. Defaults to {@see sampleData()} for the kind.
     * - `id`: the request id. Defaults to a new `apr_` and 32 hex characters.
     * - `app`: the app's key. Defaults to {@see DEFAULT_APP}.
     * - `baseUrl`: the Siren the request comes from, which `answerUrl` is built on.
     *   Defaults to Siren Cloud's, as a client's does.
     * - `timestamp`: the Unix time to sign at. Defaults to now.
     * - `test`: true for a test request, as `siren trigger ask` sends one, with `"test": true` after `mode`.
     *   Siren checks a test ask's answer and applies nothing.
     * - `body`: a whole envelope to sign as it is, in place of the one built, to try a malformed request.
     *
     * The mode follows the kind: a tell for the two actions, an ask for every other kind.
     *
     * @param array{secret?: string, data?: array<string, mixed>, id?: string, app?: string, baseUrl?: string, timestamp?: int, test?: bool, body?: array<string, mixed>} $options
     *
     * @throws \InvalidArgumentException With no secret, or no data for a kind with no sample.
     */
    public static function make(string $kind, string $key, array $options = []): SignedRequest
    {
        $secret = self::secret($options, 'make');
        $timestamp = $options['timestamp'] ?? time();
        $body = $options['body'] ?? self::envelope($kind, $key, $options, $timestamp);

        return self::sign($body, $secret, $timestamp);
    }

    /**
     * A stored request, signed now as Siren sent it, so a request that went
     * wrong becomes a test case: its id, kind, mode, question, `answerBy`
     * and `test` are the record's own.
     *
     * `$record` is the file `siren apps requests <id> --json > request.json`
     * saves, as an array or its JSON text. It holds Siren's request with
     * `appKey` and `featureKey` beside it, so no key needs passing. A row
     * `GET /apps/requests/{id}` gives names its feature only as `app:12`, so
     * pass the key with it, which `siren apps show <app>` lists. `$key` is
     * used only when the record holds no key, such as a saved request whose
     * app is gone, where `featureKey` is null.
     *
     * ```php
     * $request = AppRequests::fromRecord(file_get_contents(__DIR__ . '/request.json'));
     * $result = $answers->simulate($request);
     * ```
     *
     * Takes `make()`'s `secret`, `app`, `baseUrl` and `timestamp` options.
     *
     * @param array<string, mixed>|string $record
     * @param array{secret?: string, app?: string, baseUrl?: string, timestamp?: int} $options
     *
     * @throws \InvalidArgumentException For a record that is not a request, or no secret.
     * @throws AppHandlerException For a record that names no feature key when `$key` is not given, as Node's and Python's `AppHandlerError`.
     */
    public static function fromRecord(array|string $record, ?string $key = null, array $options = []): SignedRequest
    {
        if (is_string($record)) {
            try {
                $record = json_decode($record, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \InvalidArgumentException('AppRequests::fromRecord() takes the record as an array or its JSON text, and this text is not JSON.', 0, $e);
            }

            if (!is_array($record)) {
                throw new \InvalidArgumentException('AppRequests::fromRecord() takes a request record, a JSON object.');
            }
        }

        foreach (['id' => 'is_string', 'kind' => 'is_string', 'mode' => 'is_string', 'data' => 'is_array'] as $field => $is) {
            if (!$is($record[$field] ?? null)) {
                throw new \InvalidArgumentException(sprintf(
                    'AppRequests::fromRecord() takes a request as `siren apps requests <id>` or GET /apps/requests/{id} gives it, and this one has no `%s`.',
                    $field,
                ));
            }
        }

        $feature = $record['feature'] ?? null;
        $featureId = is_array($feature) && is_string($feature['id'] ?? null) ? $feature['id'] : (is_string($feature) ? $feature : 'app:1');
        $held = is_array($feature) && is_string($feature['key'] ?? null) ? $feature['key'] : ($record['featureKey'] ?? null);
        $key = is_string($held) && $held !== '' ? $held : $key;

        if ($key === null || $key === '') {
            throw new AppHandlerException(sprintf(
                "The record names its feature as %s, without the feature's key.%s Pass the key, such as AppRequests::fromRecord(\$record, 'split-by-score'). `siren apps show <app>` lists each feature's key.",
                $featureId,
                array_key_exists('featureKey', $record) && $record['featureKey'] === null ? ' Its featureKey is null, as `siren apps requests <id> --json` saves it when the app is gone.' : '',
            ));
        }

        $secret = self::secret($options, 'fromRecord');
        $timestamp = $options['timestamp'] ?? time();
        /** @var array<string, mixed> $data */
        $data = $record['data'];
        $body = self::envelope((string) $record['kind'], $key, [
            'id' => (string) $record['id'],
            'app' => $options['app'] ?? (is_string($record['app'] ?? null) ? $record['app'] : (is_string($record['appKey'] ?? null) ? $record['appKey'] : self::DEFAULT_APP)),
            'baseUrl' => $options['baseUrl'] ?? null,
            'data' => $data,
            'test' => ($record['test'] ?? false) === true,
        ], $timestamp, [
            'mode' => (string) $record['mode'],
            'featureId' => $featureId,
            'createdAt' => is_string($record['createdAt'] ?? null) ? $record['createdAt'] : null,
            'answerBy' => is_string($record['answerBy'] ?? null) ? $record['answerBy'] : null,
        ]);

        return self::sign($body, $secret, $timestamp);
    }

    /**
     * A webhook event, signed now as Siren signs one to an app's address,
     * for testing a function registered with `onEvent()`. Its headers are
     * Siren's: `X-Siren-Event` with the type, an `evt_` delivery id, the
     * V2 signature and its timestamp, and the older `X-Siren-Signature`.
     *
     * ```php
     * $result = $answers->simulate(AppRequests::event('conversion.approved'));
     * ```
     *
     * Options:
     * - `secret`: the signing secret. Defaults to the `SIREN_SIGNING_SECRET` environment variable.
     * - `deliveryId`: the `X-Siren-Delivery` id. Defaults to a new `evt_` and 32 hex characters.
     * - `timestamp`: the Unix time to sign at. Defaults to now.
     *
     * @param array<string, mixed>|null $data The payload. Defaults to {@see sampleEventData()} for the type.
     * @param array{secret?: string, deliveryId?: string, timestamp?: int} $options
     *
     * @throws \InvalidArgumentException For `app.request`, a type with no sample and no data, or no secret.
     */
    public static function event(string $type, ?array $data = null, array $options = []): SignedRequest
    {
        if ($type === Apps::REQUEST_EVENT) {
            throw new \InvalidArgumentException('An app request is not a webhook event. Build one with AppRequests::make($kind, $key).');
        }

        $secret = self::secret($options, 'event');
        $timestamp = $options['timestamp'] ?? time();
        $rawBody = json_encode($data ?? self::sampleEventData($type), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        // Siren's WebhookSignature::headers(), in its order.
        return new SignedRequest('POST', [
            'Content-Type' => 'application/json',
            'X-Siren-Signature' => 'sha256=' . hash_hmac('sha256', $rawBody, $secret),
            'X-Siren-Event' => $type,
            'X-Siren-Delivery' => $options['deliveryId'] ?? 'evt_' . bin2hex(random_bytes(16)),
            'User-Agent' => 'Siren-Webhooks/1.0',
            'X-Siren-Timestamp' => (string) $timestamp,
            'X-Siren-Signature-V2' => 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret),
        ], $rawBody);
    }

    /**
     * A sample payload for a webhook event, with each field Siren's event
     * catalog (its docs/api/webhooks-events.md) lists for the type, filled
     * with a value of the field's type.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException For a type with no sample.
     */
    public static function sampleEventData(string $type): array
    {
        self::$eventSamples ??= self::readEventSamples();

        if (!isset(self::$eventSamples[$type])) {
            throw new \InvalidArgumentException(sprintf(
                'There is no sample for %s. Pass the payload as data, or use one of %s.',
                $type,
                implode(', ', array_keys(self::$eventSamples)),
            ));
        }

        return self::$eventSamples[$type];
    }

    /**
     * The example question Siren's feature page shows for a kind, copied
     * from Siren's own feature contracts.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException For a kind with no sample.
     */
    public static function sampleData(string $kind): array
    {
        return self::sample($kind)['data'];
    }

    /** @param array<string, mixed> $options */
    private static function secret(array $options, string $method): string
    {
        $secret = $options['secret'] ?? getenv('SIREN_SIGNING_SECRET');

        if (!is_string($secret) || $secret === '') {
            throw new \InvalidArgumentException(sprintf(
                'AppRequests::%s() signs with the app\'s signing secret. Pass it as the secret option, or set SIREN_SIGNING_SECRET.',
                $method,
            ));
        }

        return $secret;
    }

    /** @param array<string, mixed> $body */
    private static function sign(array $body, string $secret, int $timestamp): SignedRequest
    {
        $rawBody = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $id = is_string($body['id'] ?? null) ? $body['id'] : '';

        return new SignedRequest('POST', [
            'Content-Type' => 'application/json',
            'X-Siren-Event' => Apps::REQUEST_EVENT,
            'X-Siren-Delivery' => $id . '.1',
            'X-Siren-Timestamp' => (string) $timestamp,
            'X-Siren-Signature-V2' => 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret),
            'Idempotency-Key' => $id,
        ], $rawBody);
    }

    /**
     * @param array<string, mixed> $options
     * @param array{mode?: string, featureId?: string, createdAt?: string|null, answerBy?: string|null} $stored A stored request's own values.
     *
     * @return array<string, mixed>
     */
    private static function envelope(string $kind, string $key, array $options, int $timestamp, array $stored = []): array
    {
        $data = $options['data'] ?? null;
        $mode = $stored['mode'] ?? (isset(self::samples()[$kind]) ? self::samples()[$kind]['mode'] : 'ask');

        if ($data === null) {
            $data = self::sampleData($kind);
        }

        $id = $options['id'] ?? 'apr_' . bin2hex(random_bytes(16));
        $base = rtrim($options['baseUrl'] ?? Siren::DEFAULT_BASE_URL, '/');
        $at = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC'));

        $body = [
            'version' => 1,
            'id' => $id,
            'type' => Apps::REQUEST_EVENT,
            'mode' => $mode,
        ];

        // Siren puts `test` right after `mode`, and only on a test request.
        if (($options['test'] ?? false) === true) {
            $body['test'] = true;
        }

        $body += [
            'app' => $options['app'] ?? self::DEFAULT_APP,
            'feature' => ['kind' => $kind, 'key' => $key, 'id' => $stored['featureId'] ?? 'app:1'],
            'createdAt' => $stored['createdAt'] ?? $at->format(DATE_ATOM),
        ];

        if ($mode === 'ask') {
            $body['answerBy'] = $stored['answerBy'] ?? $at->modify('+' . self::ANSWER_WINDOW_SECONDS . ' seconds')->format(DATE_ATOM);
            $body['answerUrl'] = $base . '/apps/requests/' . rawurlencode($id) . '/answer';
        }

        $body['data'] = $data;

        return $body;
    }

    /** @return array{mode: string, data: array<string, mixed>} */
    private static function sample(string $kind): array
    {
        $samples = self::samples();

        if (!isset($samples[$kind])) {
            throw new \InvalidArgumentException(sprintf(
                'There is no sample question for %s. Pass the question as the data option, or use one of %s.',
                $kind,
                implode(', ', array_keys($samples)),
            ));
        }

        return $samples[$kind];
    }

    /**
     * Read from resources/webhook-event-samples.json, which bin/event-samples.php
     * writes from Siren's docs/api/webhooks-events.md.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function readEventSamples(): array
    {
        $file = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/resources/webhook-event-samples.json'), true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, array<string, mixed>> $events */
        $events = is_array($file) && is_array($file['events'] ?? null) ? $file['events'] : [];

        return $events;
    }

    /** @return array<string, array{mode: string, data: array<string, mixed>}> */
    private static function samples(): array
    {
        /** @var array<string, array{mode: string, data: array<string, mixed>}> $samples */
        $samples = self::$samples ??= require __DIR__ . '/sample-data.php';

        return $samples;
    }
}
