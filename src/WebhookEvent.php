<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * A verified webhook event, produced by `$siren->webhooks->constructEvent()`.
 */
final class WebhookEvent
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private readonly string $type,
        private readonly array $data,
        private readonly ?string $deliveryId = null,
    ) {
    }

    /** The event type, e.g. `conversion.approved`. See {@see WebhookEventType}. */
    public function getType(): string
    {
        return $this->type;
    }

    /** @return array<string, mixed> The event payload. */
    public function getData(): array
    {
        return $this->data;
    }

    /** The unique delivery id, when included in the payload. */
    public function getDeliveryId(): ?string
    {
        return $this->deliveryId;
    }
}
