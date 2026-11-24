<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\AppRequest;
use Siren\Sdk\AppRequestHandler;
use Siren\Sdk\Exception\SignatureVerificationException;
use Siren\Sdk\HttpTransport;
use Siren\Sdk\WebhookEvent;

/**
 * An app's side of Siren's exchange: {@see requestHandler()}, which checks,
 * routes and answers each request Siren sends the app, and the low-level
 * calls under it, {@see verifyRequest()} and `requests->answer()`, for an
 * app that queues the work or answers after a person decides.
 *
 * Mirrors `siren.apps` in the Node SDK, and follows the same steps in the
 * same order.
 */
final class Apps
{
    /** How far the signed time may be from this machine's clock by default, in seconds. */
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /** The longest tolerance {@see verifyRequest()} takes, an hour. */
    public const MAX_TOLERANCE_SECONDS = 3600;

    /** The event type of a request Siren sends an app. */
    public const REQUEST_EVENT = 'app.request';

    /** Answer an ask: `answer`. */
    public readonly AppRequests $requests;

    public function __construct(HttpTransport $transport)
    {
        $this->requests = new AppRequests($transport);
    }

    /**
     * Checks a request Siren signed and sent the app, and answers it parsed.
     * It reads nothing over the network.
     *
     * In this order:
     *
     * 1. The arguments: a non-empty secret, and a tolerance from 1 to
     *    {@see MAX_TOLERANCE_SECONDS}.
     * 2. `X-Siren-Event`, when present, must be `app.request`. Any other
     *    webhook is checked with `webhooks->constructEvent()`.
     * 3. `X-Siren-Signature-V2`, `t=<seconds>,v1=<hex>`, must be present
     *    and readable.
     * 4. `X-Siren-Timestamp`, when present, must equal the signed `t`.
     * 5. One `v1` must be the HMAC-SHA256 of `"{t}."` and the raw body under
     *    the secret, compared in constant time.
     * 6. `t` must be within the tolerance of `$now`, so an old request
     *    replayed later is refused.
     * 7. The body must be an app request of version 1.
     *
     * `$rawBody` MUST be the exact bytes received, such as
     * `file_get_contents('php://input')`. Headers are matched without regard
     * to case, and each may be a string or a list of strings.
     *
     * @param array<string, string|list<string>> $headers
     * @param string $secret The app's signing secret, which Siren showed when it made the app.
     * @param int|null $toleranceSeconds How far the signed time may be from `$now`. Defaults to {@see DEFAULT_TOLERANCE_SECONDS}.
     * @param int|float|null $now The time to check against, in Unix seconds. Defaults to this machine's clock.
     *
     * @throws \InvalidArgumentException When an argument can't be used.
     * @throws SignatureVerificationException When the request fails a check, with the reason.
     */
    public function verifyRequest(
        string $rawBody,
        array $headers,
        string $secret,
        ?int $toleranceSeconds = null,
        int|float|null $now = null,
    ): AppRequest {
        if ($secret === '') {
            throw new \InvalidArgumentException("apps->verifyRequest() needs the app's signing secret, which Siren showed when it made the app.");
        }

        $tolerance = $toleranceSeconds ?? self::DEFAULT_TOLERANCE_SECONDS;

        if ($tolerance < 1 || $tolerance > self::MAX_TOLERANCE_SECONDS) {
            throw new \InvalidArgumentException(sprintf(
                'apps->verifyRequest() takes toleranceSeconds as a whole number from 1 to %d.',
                self::MAX_TOLERANCE_SECONDS,
            ));
        }

        $now ??= microtime(true);

        if (!is_finite((float) $now)) {
            throw new \InvalidArgumentException('apps->verifyRequest() takes now as a finite number of Unix seconds.');
        }

        $event = self::header($headers, 'X-Siren-Event');

        if ($event !== null && $event !== self::REQUEST_EVENT) {
            throw new SignatureVerificationException(sprintf(
                'This is a %s webhook, not an app request. Check it with webhooks->constructEvent().',
                $event,
            ));
        }

        $t = self::signedTime($rawBody, $headers, $secret, $tolerance, (float) $now);

        try {
            $body = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SignatureVerificationException('The signed body is not JSON.', 0, null, null, $e);
        }

        return self::requestFrom($body, $t, $headers);
    }

    /**
     * Answers Siren's requests to the app in a few lines: it checks each
     * request as {@see verifyRequest()} does, runs the function registered
     * for the feature, replies, and posts an ask's answer after the reply.
     *
     * ```php
     * $siren->apps->requestHandler()
     *     ->on('postConversionAction', 'tell-the-crm', function ($request) { ... })
     *     ->serve();
     * ```
     *
     * {@see AppRequestHandler} gives each reply. It posts an answer with
     * this client's key, so make the client with the app's own key.
     *
     * @param string|null $signingSecret The app's signing secret, which Siren showed when it made the app.
     *     Defaults to the `SIREN_SIGNING_SECRET` environment variable.
     * @param array{toleranceSeconds?: int, runLater?: callable(callable(): void): void, onError?: callable(\Throwable, AppRequest|WebhookEvent|null): void} $options
     *     `toleranceSeconds`: as {@see verifyRequest()}, 1 to 3600, default 300.
     *     `runLater`: takes an ask's work after the reply, such as
     *     `fn ($work) => app()->terminating($work)` in Laravel.
     *     `onError`: hears every failure the reply can't carry. Defaults to `error_log()`.
     *
     * @throws \InvalidArgumentException For an empty secret or an option it can't use.
     */
    public function requestHandler(#[\SensitiveParameter] ?string $signingSecret = null, array $options = []): AppRequestHandler
    {
        if ($signingSecret === null) {
            $fromEnvironment = getenv('SIREN_SIGNING_SECRET');
            $signingSecret = is_string($fromEnvironment) ? $fromEnvironment : '';
        }

        return new AppRequestHandler($this->requests, $signingSecret, $options);
    }

    /**
     * Splits a pool by weight, for a distribution or incentive resolver's
     * answer, such as the shares of `array_column($data['metrics'], 'score', 'metricId')`.
     *
     * Each amount is a whole number of the currency's step,
     * `10 ** (scale - decimalPlaces)` in the stored unit, so 100 for yen at
     * scale 2, and is rounded down, so the amounts never add up to more than
     * `$pool['value']`. What rounding leaves stays unpaid, which Siren
     * allows. When every weight is 0, every id gets an even share.
     *
     * @param array<string, mixed> $pool The question's `pool`: a whole `value` of 0 or more, and its `currency` with `scale` and `decimalPlaces`.
     * @param array<int|string, int|float> $weights Weight by id, each a finite number of 0 or more.
     * @return array<int|string, int> Amount by id, in the order of `$weights`.
     *
     * @throws \InvalidArgumentException For a pool it can't read, or a weight that is negative, not finite or not a number.
     */
    public static function splitPool(array $pool, array $weights): array
    {
        $value = $pool['value'] ?? null;

        if (!is_int($value) || $value < 0) {
            throw new \InvalidArgumentException('apps->splitPool() needs the pool value as a whole number of 0 or more, as Siren sends it.');
        }

        $currency = $pool['currency'] ?? null;
        $scale = is_array($currency) ? ($currency['scale'] ?? null) : null;
        $places = is_array($currency) ? ($currency['decimalPlaces'] ?? $scale) : null;

        if (!is_int($scale) || !is_int($places) || $places < 0 || $places > $scale) {
            throw new \InvalidArgumentException("apps->splitPool() needs the pool's currency, with whole scale and decimalPlaces and no more places than scale, as Siren sends it.");
        }

        $total = 0;
        $whole = true;

        foreach ($weights as $id => $weight) {
            if ((!is_int($weight) && !is_float($weight)) || !is_finite((float) $weight) || $weight < 0) {
                throw new \InvalidArgumentException(sprintf(
                    'apps->splitPool() takes each weight as a finite number of 0 or more, and the weight for %s is not.',
                    $id,
                ));
            }

            $total += $weight;
            $whole = $whole && is_int($weight);
        }

        if ($weights === []) {
            return [];
        }

        if ($total <= 0) {
            $weights = array_map(static fn(): int => 1, $weights);
            $total = count($weights);
            $whole = true;
        }

        $step = 10 ** ($scale - $places);
        $steps = intdiv($value, $step);
        $shares = [];

        foreach ($weights as $id => $weight) {
            $shares[$id] = self::stepsFor($steps, $weight, $total, $whole && is_int($total)) * $step;
        }

        return $shares;
    }

    /**
     * The whole steps one weight earns of `$steps`, rounded down. Whole
     * weights are worked out exactly while the product fits an integer.
     */
    private static function stepsFor(int $steps, int|float $weight, int|float $total, bool $whole): int
    {
        if ($whole && is_int($weight) && is_int($total) && ($weight === 0 || $steps <= intdiv(PHP_INT_MAX, $weight))) {
            return intdiv($steps * $weight, $total);
        }

        $exact = $steps * ($weight / $total);
        $down = (int) floor($exact);

        // A product such as 90 * (0.7 / 0.9999999999999999) lands a hair under
        // 63. Lifting a share that sits within 1e-9 of the next whole step
        // never passes the pool: the parts add up to $steps, so the shares
        // rounded down lose at least as much as the lifted ones gain.
        return $exact - $down > 1 - 1e-9 ? $down + 1 : $down;
    }

    /**
     * Steps 3 to 6 of {@see verifyRequest()}: the signature header, the
     * timestamp header, the signature and the time. Answers the signed time.
     *
     * @internal Shared with {@see AppRequestHandler}.
     *
     * @param array<string, string|list<string>> $headers
     * @throws SignatureVerificationException When the request fails a check, with the reason.
     */
    public static function signedTime(string $rawBody, array $headers, string $secret, int $tolerance, float $now): int
    {
        $v2 = self::header($headers, 'X-Siren-Signature-V2');

        if ($v2 === null) {
            throw new SignatureVerificationException('The request has no X-Siren-Signature-V2 header.');
        }

        [$t, $signatures] = self::readSignatureHeader($v2);

        $stamp = self::header($headers, 'X-Siren-Timestamp');

        if ($stamp !== null && $stamp !== (string) $t) {
            throw new SignatureVerificationException('The X-Siren-Timestamp header differs from the time X-Siren-Signature-V2 signed.');
        }

        $expected = hash_hmac('sha256', $t . '.' . $rawBody, $secret);
        $matched = false;

        foreach ($signatures as $signature) {
            // Every candidate is compared, so the time taken says nothing about which matched.
            $matched = hash_equals($expected, $signature) || $matched;
        }

        if (!$matched) {
            throw new SignatureVerificationException(
                'The request signature does not match. Pass the raw body exactly as received and the signing secret of this app.',
            );
        }

        if (abs($now - $t) > $tolerance) {
            throw new SignatureVerificationException(sprintf(
                "The request's signed timestamp is more than %d seconds from this machine's clock, so it may be a replay. Check the clock if every request fails.",
                $tolerance,
            ));
        }

        return $t;
    }

    /**
     * Step 7 of {@see verifyRequest()}: the decoded body as an app request
     * of version 1.
     *
     * @internal Shared with {@see AppRequestHandler}.
     *
     * @param array<string, string|list<string>> $headers
     * @throws SignatureVerificationException When the body is not an app request of version 1.
     */
    public static function requestFrom(mixed $body, int $t, array $headers): AppRequest
    {
        $problem = self::envelopeProblem($body);

        if ($problem !== null) {
            throw new SignatureVerificationException(sprintf('The signed body is not an app request of version 1: %s.', $problem));
        }

        /** @var array<string, mixed> $body */
        return new AppRequest(
            version: 1,
            id: $body['id'],
            type: $body['type'],
            mode: $body['mode'],
            app: $body['app'],
            item: $body['item'],
            createdAt: $body['createdAt'],
            answerBy: is_string($body['answerBy'] ?? null) ? $body['answerBy'] : null,
            answerUrl: is_string($body['answerUrl'] ?? null) ? $body['answerUrl'] : null,
            data: $body['data'],
            timestamp: $t,
            deliveryId: self::header($headers, 'X-Siren-Delivery'),
            body: $body,
        );
    }

    /**
     * Reads `t=<seconds>,v1=<hex>[,v1=<hex>...]`: the signed time and every
     * `v1` in lowercase. A `v1` that is not 64 hex characters can match
     * nothing and is dropped.
     *
     * @return array{0: int, 1: list<string>}
     * @throws SignatureVerificationException For a header with no single whole-number `t` or no `v1`.
     */
    private static function readSignatureHeader(string $header): array
    {
        $times = [];
        $signatures = [];
        $v1Count = 0;

        foreach (explode(',', $header) as $part) {
            $at = strpos($part, '=');
            $name = trim($at === false ? $part : substr($part, 0, $at));
            $value = trim($at === false ? '' : substr($part, $at + 1));

            if ($name === 't') {
                $times[] = $value;
            }

            if ($name === 'v1') {
                $v1Count++;

                if (preg_match('/^[0-9a-f]{64}$/i', $value) === 1) {
                    $signatures[] = strtolower($value);
                }
            }
        }

        if (count($times) !== 1 || preg_match('/^\d{1,15}$/', $times[0]) !== 1 || $v1Count === 0) {
            throw new SignatureVerificationException(
                'The X-Siren-Signature-V2 header cannot be read. Siren sends t=<seconds>,v1=<hex signature>.',
            );
        }

        return [(int) $times[0], $signatures];
    }

    /**
     * What first keeps the body from being an app request of version 1, as
     * Siren's `AppRequestEnvelope` builds it, in words that name the field
     * and its rule, or null when it is one.
     */
    private static function envelopeProblem(mixed $body): ?string
    {
        if (!is_array($body) || ($body !== [] && array_is_list($body))) {
            return 'the body must be a JSON object';
        }

        $item = $body['item'] ?? null;
        $mode = $body['mode'] ?? null;
        $isObject = static fn (mixed $value): bool => is_array($value) && ($value === [] || !array_is_list($value));

        return match (true) {
            ($body['version'] ?? null) !== 1 => 'its `version` must be 1',
            !is_string($body['id'] ?? null) || preg_match(AppRequests::REQUEST_ID_PATTERN, $body['id']) !== 1
                => 'its `id` must be `apr_` followed by 32 hex characters, such as apr_9c60190a4501399d0ae55f041c237b39',
            ($body['type'] ?? null) !== self::REQUEST_EVENT => 'its `type` must be `app.request`',
            $mode !== 'tell' && $mode !== 'ask' => 'its `mode` must be `tell` or `ask`',
            !is_string($body['app'] ?? null) || $body['app'] === '' => "its `app` must be the app's key, as text",
            !$isObject($item) => 'its `item` must be an object with the feature\'s kind, key and id',
            !is_string($item['kind'] ?? null) => 'its `item.kind` must be text, such as distributionResolver',
            !is_string($item['key'] ?? null) => "its `item.key` must be text, the feature's key",
            !is_string($item['id'] ?? null) => 'its `item.id` must be text, such as app:12',
            !is_string($body['createdAt'] ?? null) => 'its `createdAt` must be a date as text, in ISO 8601',
            !$isObject($body['data'] ?? null) => 'its `data` must be an object, the question',
            $mode === 'ask' && !is_string($body['answerBy'] ?? null) => "an ask's `answerBy` must be a date as text, in ISO 8601",
            $mode === 'ask' && !is_string($body['answerUrl'] ?? null) => "an ask's `answerUrl` must be the address Siren takes the answer at, as text",
            default => null,
        };
    }

    /**
     * A header's first value, matched without regard to case.
     *
     * @internal Shared with {@see AppRequestHandler}.
     *
     * @param array<string, string|list<string>> $headers
     */
    public static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $header => $value) {
            if (strcasecmp((string) $header, $name) !== 0) {
                continue;
            }

            if (is_array($value)) {
                $value = $value[0] ?? null;
            }

            return is_string($value) ? $value : null;
        }

        return null;
    }
}
