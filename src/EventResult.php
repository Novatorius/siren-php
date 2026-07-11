<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * The result of ingesting an event.
 *
 * The opportunity id is read from the `X-Siren-OID` response header — the
 * response body is empty on success.
 */
final class EventResult
{
    public function __construct(
        private readonly ?int $opportunityId,
    ) {
    }

    /** The opportunity (tracking) id associated with the event, when Siren returned one. */
    public function getOpportunityId(): ?int
    {
        return $this->opportunityId;
    }
}
