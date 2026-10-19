<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * The result of ingesting an event.
 *
 * Siren answers 200 when it processed the event and 202 when it queued it
 * because the organization's engine is paused. A sale and a site visit
 * answer with a body that says what happened, such as
 * `{"status": "created", "transactionId": 77, "conversions": [...]}`. A
 * refund or a custom event answers with no body. {@see EventStatus} lists
 * the statuses and reasons.
 *
 * The opportunity id comes from the `X-Siren-OID` response header, or from
 * the body's `opportunityId` when the header is absent.
 */
final class EventResult
{
    /**
     * @param array<string, mixed> $body The decoded response body, or an empty array.
     */
    public function __construct(
        private readonly ?int $opportunityId,
        private readonly int $httpStatus = 200,
        private readonly array $body = [],
    ) {
    }

    /** The opportunity (tracking) id associated with the event, when Siren returned one. */
    public function getOpportunityId(): ?int
    {
        if ($this->opportunityId !== null) {
            return $this->opportunityId;
        }

        $fromBody = $this->body['opportunityId'] ?? null;

        return is_int($fromBody) || (is_string($fromBody) && ctype_digit($fromBody)) ? (int) $fromBody : null;
    }

    /** The HTTP status Siren answered with: 200 when processed, 202 when queued. */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /** One of the {@see EventStatus} values, or null when the response had no body. */
    public function getStatus(): ?string
    {
        return $this->stringField('status');
    }

    /**
     * Whether Siren queued the event instead of processing it, because the
     * organization's engine is paused. A queued event is processed once the
     * engine resumes, so it must not be sent again.
     */
    public function isQueued(): bool
    {
        return $this->httpStatus === 202
            || ($this->body['queued'] ?? null) === true
            || $this->getStatus() === EventStatus::QUEUED;
    }

    /** For a site visit, whether it was attributed to the collaborator. Null for other events. */
    public function isAttributed(): ?bool
    {
        $attributed = $this->body['attributed'] ?? null;

        return is_bool($attributed) ? $attributed : null;
    }

    /** Why nothing was earned or recorded, one of the `EventStatus::REASON_*` values, when Siren says. */
    public function getReason(): ?string
    {
        return $this->stringField('reason');
    }

    /** Siren's explanation, for people. Branch on {@see getStatus()} instead. */
    public function getMessage(): ?string
    {
        return $this->stringField('message');
    }

    /** For a sale, the transaction Siren recorded or found, when there is one. */
    public function getTransactionId(): ?int
    {
        $id = $this->body['transactionId'] ?? null;

        return is_int($id) ? $id : null;
    }

    /**
     * For a sale, the conversions it produced, each `{id, status}`.
     *
     * @return list<array<string, mixed>>
     */
    public function getConversions(): array
    {
        $conversions = $this->body['conversions'] ?? null;

        if (!is_array($conversions)) {
            return [];
        }

        return array_values(array_filter($conversions, 'is_array'));
    }

    /**
     * The decoded response body, or an empty array when Siren sent none.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->body;
    }

    private function stringField(string $key): ?string
    {
        $value = $this->body[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
