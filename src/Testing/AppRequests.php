<?php

declare(strict_types=1);

namespace Siren\Sdk\Testing;

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
     * - `body`: a whole envelope to sign as it is, in place of the one built, to try a malformed request.
     *
     * The mode follows the kind: a tell for the two actions, an ask for every other kind.
     *
     * @param array{secret?: string, data?: array<string, mixed>, id?: string, app?: string, baseUrl?: string, timestamp?: int, body?: array<string, mixed>} $options
     *
     * @throws \InvalidArgumentException With no secret, or no data for a kind with no sample.
     */
    public static function make(string $kind, string $key, array $options = []): SignedRequest
    {
        $secret = $options['secret'] ?? getenv('SIREN_SIGNING_SECRET');

        if (!is_string($secret) || $secret === '') {
            throw new \InvalidArgumentException(
                'AppRequests::make() signs with the app\'s signing secret. Pass it as the secret option, or set SIREN_SIGNING_SECRET.',
            );
        }

        $timestamp = $options['timestamp'] ?? time();
        $body = $options['body'] ?? self::envelope($kind, $key, $options, $timestamp);
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

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function envelope(string $kind, string $key, array $options, int $timestamp): array
    {
        $data = $options['data'] ?? null;
        $mode = isset(self::samples()[$kind]) ? self::samples()[$kind]['mode'] : 'ask';

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
            'app' => $options['app'] ?? self::DEFAULT_APP,
            'feature' => ['kind' => $kind, 'key' => $key, 'id' => 'app:1'],
            'createdAt' => $at->format(DATE_ATOM),
        ];

        if ($mode === 'ask') {
            $body['answerBy'] = $at->modify('+' . self::ANSWER_WINDOW_SECONDS . ' seconds')->format(DATE_ATOM);
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

    /** @return array<string, array{mode: string, data: array<string, mixed>}> */
    private static function samples(): array
    {
        /** @var array<string, array{mode: string, data: array<string, mixed>}> $samples */
        $samples = self::$samples ??= require __DIR__ . '/sample-data.php';

        return $samples;
    }
}
