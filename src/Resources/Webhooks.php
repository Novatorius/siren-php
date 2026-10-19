<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\Exception\SignatureVerificationException;
use Siren\Sdk\HttpTransport;
use Siren\Sdk\WebhookEvent;

/**
 * Inbound webhook verification and subscription management.
 */
final class Webhooks
{
    private const SIGNATURE_PREFIX = 'sha256=';

    /** The header that carries the delivery's signature, `sha256=<hex>`. */
    public const SIGNATURE_HEADER = 'X-Siren-Signature';

    /** The header that names the event type, such as `conversion.approved`. */
    public const EVENT_HEADER = 'X-Siren-Event';

    /** The header that carries the delivery id, the same on every retry of one delivery. */
    public const DELIVERY_HEADER = 'X-Siren-Delivery';

    /** Manage webhook subscriptions: `create`, `list`, `delete`. */
    public readonly WebhookSubscriptions $subscriptions;

    public function __construct(HttpTransport $transport)
    {
        $this->subscriptions = new WebhookSubscriptions($transport);
    }

    /**
     * Verifies a webhook delivery and parses it into a {@see WebhookEvent}.
     *
     * `$rawBody` MUST be the exact bytes received on the wire — e.g.
     * `file_get_contents('php://input')` — not a re-serialized array, or the
     * HMAC will not match. `$signatureHeader` is the value of the
     * `X-Siren-Signature` header (`sha256=<hex>`).
     *
     * A subscription made with `subscriptions->create()` receives the event
     * payload itself as the body, and Siren names the event in the
     * `X-Siren-Event` header and the delivery in `X-Siren-Delivery`. Pass
     * those two headers as `$eventType` and `$deliveryId`, or use
     * {@see constructEventFromHeaders()}, which reads all three. Without
     * them such an event has an empty type. A connected site's subscription
     * receives an envelope that names its own type, data and id, and is
     * read from the body.
     *
     * @throws SignatureVerificationException when the signature is missing,
     *         malformed, or does not match the body.
     */
    public function constructEvent(
        string $rawBody,
        ?string $signatureHeader,
        string $secret,
        ?string $eventType = null,
        ?string $deliveryId = null,
    ): WebhookEvent {
        if ($signatureHeader === null || trim($signatureHeader) === '') {
            throw new SignatureVerificationException(
                'No signature header was provided. Pass the value of the X-Siren-Signature request header.',
            );
        }

        if (!$this->verifySignature($rawBody, $signatureHeader, $secret)) {
            throw new SignatureVerificationException(
                'Webhook signature verification failed. Make sure you passed the raw request body '
                . 'exactly as received and the correct signing secret for this subscription.',
            );
        }

        $decoded = json_decode($rawBody, true);

        if (!is_array($decoded)) {
            throw new SignatureVerificationException('The webhook payload is not valid JSON.');
        }

        $eventType = self::nonEmpty($eventType);
        $deliveryId = self::nonEmpty($deliveryId);

        // An envelope names its own type and wraps the payload in `data`. A
        // connected site's subscription gets one, with the delivery id in
        // `id`. Every other subscription gets the flat payload, with the type
        // and delivery id in headers.
        if (is_string($decoded['type'] ?? null) && $decoded['type'] !== '' && is_array($decoded['data'] ?? null)) {
            $envelopeId = $decoded['id'] ?? $decoded['deliveryId'] ?? null;

            return new WebhookEvent(
                type: $decoded['type'],
                data: $decoded['data'],
                deliveryId: is_string($envelopeId) && $envelopeId !== '' ? $envelopeId : $deliveryId,
                body: $decoded,
            );
        }

        return new WebhookEvent(
            type: $eventType ?? '',
            data: $decoded,
            deliveryId: $deliveryId,
            body: $decoded,
        );
    }

    /**
     * Verifies a webhook delivery from its raw body and request headers.
     *
     * Reads `X-Siren-Signature`, `X-Siren-Event` and `X-Siren-Delivery` from
     * `$headers`, matched without regard to case. Each value may be a string
     * or a list of strings, as PSR-7 and most frameworks give them.
     *
     * ```php
     * $event = $siren->webhooks->constructEventFromHeaders(
     *     file_get_contents('php://input'),
     *     getallheaders(),
     *     $signingSecret,
     * );
     * ```
     *
     * @param array<string, string|list<string>> $headers
     *
     * @throws SignatureVerificationException when the signature is missing,
     *         malformed, or does not match the body.
     */
    public function constructEventFromHeaders(string $rawBody, array $headers, string $secret): WebhookEvent
    {
        return $this->constructEvent(
            $rawBody,
            self::header($headers, self::SIGNATURE_HEADER),
            $secret,
            self::header($headers, self::EVENT_HEADER),
            self::header($headers, self::DELIVERY_HEADER),
        );
    }

    /**
     * Constant-time signature check for callers that parse the body themselves.
     *
     * `$rawBody` MUST be the exact bytes received on the wire.
     */
    public function verifySignature(string $rawBody, ?string $signatureHeader, string $secret): bool
    {
        if ($signatureHeader === null) {
            return false;
        }

        $signature = trim($signatureHeader);

        if (!str_starts_with($signature, self::SIGNATURE_PREFIX)) {
            return false;
        }

        $provided = strtolower(substr($signature, strlen(self::SIGNATURE_PREFIX)));
        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $provided);
    }

    private static function nonEmpty(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
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
