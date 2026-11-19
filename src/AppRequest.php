<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * A request Siren signed and sent an app, as {@see Resources\Apps::verifyRequest()}
 * answers it once the signature, the time and the envelope check out.
 *
 * Siren may deliver one request more than once, so an app drops a repeat by
 * {@see $id}. A `tell` waits for no answer. An `ask` is answered with
 * {@see Resources\AppRequests::answer()} by {@see $answerBy}.
 */
final class AppRequest
{
    /**
     * @param string $id The request key, `apr_` and 32 hex characters, the same on every send.
     * @param string $mode `tell` or `ask`.
     * @param string $app The app's key.
     * @param array{kind: string, key: string, id: string} $item The feature asked: its kind, its key and its `app:{n}` id.
     * @param string $createdAt When Siren recorded the request, ISO 8601.
     * @param string|null $answerBy When an ask stops waiting for this send, ISO 8601. Null for a tell.
     * @param string|null $answerUrl Where an ask's answer goes. Null for a tell.
     * @param array<string, mixed> $data The question, shaped by the feature's kind.
     * @param int $timestamp The Unix time Siren signed the send at.
     * @param string|null $deliveryId The `X-Siren-Delivery` header, one per send, when present.
     * @param array<string, mixed> $body The whole signed body, decoded.
     */
    public function __construct(
        public readonly int $version,
        public readonly string $id,
        public readonly string $type,
        public readonly string $mode,
        public readonly string $app,
        public readonly array $item,
        public readonly string $createdAt,
        public readonly ?string $answerBy,
        public readonly ?string $answerUrl,
        public readonly array $data,
        public readonly int $timestamp,
        public readonly ?string $deliveryId,
        public readonly array $body,
    ) {
    }

    /** Whether Siren waits for an answer to this request. */
    public function isAsk(): bool
    {
        return $this->mode === 'ask';
    }
}
