<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * A verified webhook event, produced by `$siren->webhooks->constructEvent()`
 * or `constructEventFromHeaders()`.
 */
final class WebhookEvent
{
    /**
     * @param array<string, mixed>     $data
     * @param array<int|string, mixed> $body The whole decoded request body.
     */
    public function __construct(
        private readonly string $type,
        private readonly array $data,
        private readonly ?string $deliveryId = null,
        private readonly array $body = [],
    ) {
    }

    /**
     * The event type, e.g. `conversion.approved`. See {@see WebhookEventType}.
     *
     * Empty when the delivery's body did not name it and the `X-Siren-Event`
     * header was not passed to `constructEvent()`.
     */
    public function getType(): string
    {
        return $this->type;
    }

    /** @return array<string, mixed> The event payload. */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * The delivery id, from the `X-Siren-Delivery` header or the envelope's
     * `id`. Retries of one delivery share it, so use it to skip duplicates.
     */
    public function getDeliveryId(): ?string
    {
        return $this->deliveryId;
    }

    /**
     * The whole decoded body. For most subscriptions this is the payload
     * itself. A connected site's subscription receives an envelope, which
     * also carries `version`, `kind`, `occurredAt`, `propertyId`,
     * `originPropertyId` and the related `records`.
     *
     * @return array<int|string, mixed>
     */
    public function getBody(): array
    {
        return $this->body;
    }
}
