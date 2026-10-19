<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * The `status` Siren reports in the body of an ingested event
 * ({@see EventResult::getStatus()}).
 *
 * A sale answers with one of `CREATED`, `RECORDED`, `DUPLICATE`,
 * `UNATTRIBUTED` or `IGNORED`. A site visit answers with `ATTRIBUTED`,
 * `RECORDED` or `NOT_ATTRIBUTED`. Any event can answer `QUEUED` while the
 * organization's engine is paused. A refund or a custom event answers with
 * no body, so its status is null.
 */
final class EventStatus
{
    /** A sale: a transaction and at least one conversion were recorded. */
    public const CREATED = 'created';

    /** A sale: the transaction was recorded and no program awarded a conversion. A visit: recorded, and no active program rewards a visit. */
    public const RECORDED = 'recorded';

    /** A sale: the order was ingested before. Nothing new was recorded. */
    public const DUPLICATE = 'duplicate';

    /** A sale: the tracking id matches no opportunity. Nothing was recorded, so the sale can be sent again later. */
    public const UNATTRIBUTED = 'unattributed';

    /** A sale: no active program takes sales, or a listener refused it. Nothing was recorded. */
    public const IGNORED = 'ignored';

    /** A visit: it created or added to an engagement for the collaborator. */
    public const ATTRIBUTED = 'attributed';

    /** A visit: nothing was written. {@see EventResult::getReason()} says why. */
    public const NOT_ATTRIBUTED = 'not_attributed';

    /**
     * The organization's engine is paused, so Siren queued the event and
     * answered 202. It is processed once the engine resumes.
     */
    public const QUEUED = 'queued';

    /** A sale that earned nothing: no collaborator ever had an engagement on the opportunity. */
    public const REASON_NO_ENGAGEMENT = 'no_engagement';

    /** A sale that earned nothing: every engagement on the opportunity already earned its conversion. */
    public const REASON_ENGAGEMENT_USED = 'engagement_used';

    /** A sale that earned nothing: the engagements on the opportunity expired or were cancelled. */
    public const REASON_ENGAGEMENT_ENDED = 'engagement_ended';

    /** A sale that earned nothing: an engagement is active and no program awarded a conversion. */
    public const REASON_NOT_AWARDED = 'not_awarded';

    /** A visit that was not attributed: no collaborator with that id or tracking code exists. */
    public const REASON_COLLABORATOR_NOT_FOUND = 'collaborator_not_found';

    /** A visit that was not attributed: the collaborator is not active. */
    public const REASON_COLLABORATOR_NOT_ACTIVE = 'collaborator_not_active';

    private function __construct()
    {
    }
}
