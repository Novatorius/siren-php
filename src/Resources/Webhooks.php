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
     * @throws SignatureVerificationException when the signature is missing,
     *         malformed, or does not match the body.
     */
    public function constructEvent(string $rawBody, ?string $signatureHeader, string $secret): WebhookEvent
    {
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

        return new WebhookEvent(
            type: is_string($decoded['type'] ?? null) ? $decoded['type'] : '',
            data: is_array($decoded['data'] ?? null) ? $decoded['data'] : [],
            deliveryId: is_string($decoded['deliveryId'] ?? null) ? $decoded['deliveryId'] : null,
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
}
