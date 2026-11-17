<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\HttpTransport;

/**
 * Webhook subscription management (`/webhooks`).
 *
 * These routes take a signed-in dashboard session's access token, or an API
 * key with a webhook scope. {@see \Siren\Sdk\ApiKeyScope::WEBHOOK_SUBSCRIPTIONS_READ}
 * opens `list()`, `events()`, `retrieve()` and `deliveries()`, and
 * {@see \Siren\Sdk\ApiKeyScope::WEBHOOK_SUBSCRIPTIONS_MANAGE} opens every
 * method here. The key must be organization-wide, because subscriptions are
 * not kept by store. A key bound to one store gets a
 * {@see \Siren\Sdk\Exception\StoreKeyNotAllowedException}.
 *
 * `retrieve()`, `update()`, `test()` and `deliveries()` throw
 * `InvalidArgumentException` before sending for an id below 1, and
 * {@see \Siren\Sdk\Exception\NotFoundException} for a subscription the
 * organization does not have.
 */
final class WebhookSubscriptions
{
    /** The fields {@see update()} sends, the four `PUT /webhooks/{id}` reads. */
    public const UPDATE_FIELDS = ['targetUrl', 'events', 'status', 'description'];

    public function __construct(
        private readonly HttpTransport $transport,
    ) {
    }

    /**
     * Reads one subscription with `GET /webhooks/{id}`. The answer never
     * holds the signing secret, which Siren shows only once, on create.
     * Retried on a network error, 429 or 5xx, as a read.
     *
     * @return array<int|string, mixed>
     */
    public function retrieve(int $id): array
    {
        return $this->transport->request('GET', self::path($id))->json();
    }

    /**
     * Changes a subscription with `PUT /webhooks/{id}` and answers it as it
     * now stands. Send only the fields to change. `status => 'paused'` stops
     * deliveries and `'active'` starts them again.
     *
     * Sends a new array holding only the {@see UPDATE_FIELDS} that `$params`
     * gives, and throws `InvalidArgumentException` before sending when
     * `$params` holds any other key, whatever its value. Siren answers 400,
     * raised as {@see \Siren\Sdk\Exception\BadRequestException}, for a target
     * URL that is not a public https endpoint.
     *
     * Never auto-retried. Sending the same update again after a lost answer
     * sets the same values, so the caller may repeat it.
     *
     * @param array<string, mixed> $params
     *
     * @return array<int|string, mixed>
     */
    public function update(int $id, array $params): array
    {
        $path = self::path($id);
        $body = [];

        foreach ($params as $key => $value) {
            if (!in_array($key, self::UPDATE_FIELDS, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'webhooks->subscriptions->update() cannot send "%s". Siren\'s update route reads only targetUrl, events, status and description.',
                    $key,
                ));
            }

            $body[$key] = $value;
        }

        return $this->transport->request('PUT', $path, $body, [], retryable: false)->json();
    }

    /**
     * Sends the subscription one signed `webhook.test` delivery now, with
     * `POST /webhooks/{id}/test`, and answers what the endpoint did:
     * `delivered`, `httpStatus`, `error` and `eventType`. Siren answers 200
     * whether or not the delivery arrived, so read `delivered`. The test
     * shows in {@see deliveries()}.
     *
     * Never auto-retried, since each call makes Siren send a request. Siren
     * allows 20 tests an hour for one subscription and 60 in all from one
     * address, and answers more with a 429, raised as
     * {@see \Siren\Sdk\Exception\RateLimitException}. It answers 409, raised
     * as {@see \Siren\Sdk\Exception\ConflictException}, for a paused
     * subscription.
     *
     * @return array<int|string, mixed>
     */
    public function test(int $id): array
    {
        return $this->transport->request('POST', self::path($id) . '/test', null, [], retryable: false)->json();
    }

    /**
     * The subscription's latest deliveries, newest first, at most 50, from
     * `GET /webhooks/{id}/deliveries`. Each holds the payload Siren sent, its
     * status (`pending`, `delivered` or `failed`), how many tries it took and
     * the first 200 bytes of the endpoint's answer. A row that is not an
     * object is left out, as {@see events()} does. Retried on a network
     * error, 429 or 5xx, as a read.
     *
     * @return list<array<string, mixed>>
     */
    public function deliveries(int $id): array
    {
        $response = $this->transport->request('GET', self::path($id) . '/deliveries');

        return array_values(array_filter($response->json(), 'is_array'));
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

    private static function path(int $id): string
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Record ids are positive whole numbers.');
        }

        return '/webhooks/' . $id;
    }
}
