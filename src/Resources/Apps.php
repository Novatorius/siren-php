<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\AppRequest;
use Siren\Sdk\Exception\SignatureVerificationException;
use Siren\Sdk\HttpTransport;

/**
 * An app's side of Siren's exchange: checking a request Siren signed and
 * sent the app, and answering an ask through {@see $requests}.
 *
 * Mirrors `siren.apps.verifyRequest()` and `siren.apps.requests.answer()`
 * in the Node SDK, and follows the same steps in the same order.
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

        if (abs((float) $now - $t) > $tolerance) {
            throw new SignatureVerificationException(sprintf(
                "The request's signed timestamp is more than %d seconds from this machine's clock, so it may be a replay. Check the clock if every request fails.",
                $tolerance,
            ));
        }

        try {
            $body = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SignatureVerificationException('The signed body is not JSON.', 0, null, null, $e);
        }

        if (!self::isEnvelope($body)) {
            throw new SignatureVerificationException('The signed body is not an app request of version 1.');
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

    /** Whether the body is an app request of version 1, as Siren's `AppRequestEnvelope` builds it. */
    private static function isEnvelope(mixed $body): bool
    {
        if (!is_array($body) || array_is_list($body)) {
            return false;
        }

        $item = $body['item'] ?? null;
        $mode = $body['mode'] ?? null;

        return ($body['version'] ?? null) === 1
            && is_string($body['id'] ?? null)
            && preg_match(AppRequests::REQUEST_ID_PATTERN, $body['id']) === 1
            && ($body['type'] ?? null) === self::REQUEST_EVENT
            && is_string($body['app'] ?? null)
            && $body['app'] !== ''
            && is_array($item)
            && ($item === [] || !array_is_list($item))
            && is_string($item['kind'] ?? null)
            && is_string($item['key'] ?? null)
            && is_string($item['id'] ?? null)
            && is_string($body['createdAt'] ?? null)
            && is_array($body['data'] ?? null)
            && ($body['data'] === [] || !array_is_list($body['data']))
            && ($mode === 'tell' || $mode === 'ask')
            && ($mode === 'tell' || (is_string($body['answerBy'] ?? null) && is_string($body['answerUrl'] ?? null)));
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    private static function header(array $headers, string $name): ?string
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
