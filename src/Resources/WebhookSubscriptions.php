<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\HttpTransport;

/**
 * Webhook subscription management (`/webhooks`).
 *
 * These routes take a signed-in dashboard session's access token. Siren
 * Cloud does not accept an organization API key here, because API keys only
 * send events.
 */
final class WebhookSubscriptions
{
    public function __construct(
        private readonly HttpTransport $transport,
    ) {
    }

    /**
     * Registers a URL to receive event callbacks.
     *
     * Required: `targetUrl`, `events` (a list of {@see \Siren\Sdk\WebhookEventType}
     * strings; `['*']` subscribes to all). Optional: `description`.
     *
     * The returned array includes `signingSecret` — it is returned ONCE and
     * cannot be retrieved later; store it to verify future deliveries.
     * This call is never auto-retried.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed> The created subscription, including `signingSecret`.
     */
    public function create(array $params): array
    {
        foreach (['targetUrl', 'events'] as $field) {
            if (!isset($params[$field]) || $params[$field] === '' || $params[$field] === []) {
                throw new \InvalidArgumentException(
                    sprintf('The "%s" field is required for webhooks->subscriptions->create().', $field),
                );
            }
        }

        $response = $this->transport->request('POST', '/webhooks', $params, [], retryable: false);

        return $response->json();
    }

    /**
     * The event types a subscription can name, from `GET /webhooks/events`.
     *
     * Each entry is `{event, category, label}`, such as
     * `{"event": "sale.created", "category": "Sales", "label": "A sale was recorded"}`.
     * This is the live set Siren delivers, so it can hold an event that
     * {@see \Siren\Sdk\WebhookEventType} does not have yet.
     *
     * @return list<array<string, mixed>>
     */
    public function events(): array
    {
        $response = $this->transport->request('GET', '/webhooks/events');

        return array_values(array_filter($response->json(), 'is_array'));
    }

    /**
     * @return list<array<string, mixed>> All webhook subscriptions.
     */
    public function list(): array
    {
        $response = $this->transport->request('GET', '/webhooks');

        return array_values($response->json());
    }

    /**
     * Deletes a webhook subscription. Deliveries stop immediately.
     */
    public function delete(int $id): void
    {
        $this->transport->request('DELETE', '/webhooks/' . $id);
    }
}
