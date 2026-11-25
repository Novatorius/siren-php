<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * A request Siren signed and sent an app, as {@see Resources\Apps::verifyRequest()}
 * answers it once the signature, the time and the envelope check out.
 *
 * Siren may deliver one request more than once, and sends an ask again each
 * time its answer window closes with no answer, so an app writes once by
 * {@see $id}, the one id that stays the same. A `tell` waits for no answer.
 * An `ask` is answered with {@see Resources\AppRequests::answer()} by
 * {@see $answerBy}.
 *
 * `TData` is the shape of {@see $data}, which {@see AppRequestHandler::on()}
 * sets from the kind, such as `AppRequest<DistributionResolverQuestion>`.
 *
 * @template TData of array<string, mixed> = array<string, mixed>
 */
final class AppRequest
{
    /**
     * @param string $id The request's id, `apr_` and 32 hex characters. It is the same on every send of this request, so write once by `id`.
     * @param string $mode `tell` or `ask`.
     * @param string $app The app's key.
     * @param array{kind: string, key: string, id: string} $feature The feature asked: its kind, its key and its `app:{n}` id. `feature.id` names the feature, not the request, so many requests share it.
     * @param string $createdAt When Siren recorded the request, ISO 8601.
     * @param string|null $answerBy When an ask stops waiting for this send, an ISO 8601 string as Siren sends it, such as `2026-11-17T13:20:03+00:00`. Null for a tell.
     * @param string|null $answerUrl Where an ask's answer goes. Null for a tell.
     * @param TData $data The question, shaped by the feature's kind.
     * @param int $timestamp The Unix time Siren signed the send at.
     * @param string|null $deliveryId The `X-Siren-Delivery` header, when present. It names one delivery and changes on every send, so never dedupe on it.
     * @param array<string, mixed> $body The whole signed body, decoded.
     */
    public function __construct(
        public readonly int $version,
        public readonly string $id,
        public readonly string $type,
        public readonly string $mode,
        public readonly string $app,
        public readonly array $feature,
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
