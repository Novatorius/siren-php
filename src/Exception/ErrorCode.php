<?php

declare(strict_types=1);

namespace Siren\Sdk\Exception;

/**
 * The machine-readable `error.code` Siren Cloud sends when it refuses a
 * connected site's or an API key's request, as in
 * `{"error": {"message": "...", "code": "hold_no_credit", "context": {}}}`.
 *
 * The SDK picks the exception from the code first. The message is for
 * people and may change. A reply with no code, or with a code this list
 * does not know, comes from an older or newer Cloud deployment and is read
 * by its wording instead. {@see SirenException::getErrorCode()} carries the
 * code on every exception.
 */
final class ErrorCode
{
    /** 401: the organization revoked or deleted the site's connection. */
    public const SITE_DISCONNECTED = 'site_disconnected';

    /**
     * 403: the token or API key lacks the scope the route needs.
     * `error.context.scope` names it, and for an API key
     * `error.context.anyOf` lists every scope that opens the route.
     */
    public const MISSING_SCOPE = 'missing_scope';

    /**
     * 401: the route does not accept an API key, such as every `/api-keys`
     * route. It takes a signed-in dashboard session. A
     * {@see ApiKeyNotAcceptedException}.
     */
    public const API_KEY_NOT_ACCEPTED = 'api_key_not_accepted';

    /**
     * 403: an API key bound to one store asked for records that are not kept
     * by store, such as payouts or webhook subscriptions. An
     * organization-wide key reads them. A {@see StoreKeyNotAllowedException}.
     */
    public const STORE_KEY_NOT_ALLOWED = 'store_key_not_allowed';

    /** 403: a site may not send this field here. `error.context.param` names it. */
    public const FIELD_NOT_ALLOWED = 'field_not_allowed';

    /** 403: a site creates collaborators as pending, and sent another status. */
    public const PENDING_STATUS_REQUIRED = 'pending_status_required';

    /** 403: a site may not use the `user` mapping type, which names a Cloud account. */
    public const USER_TYPE_NOT_ALLOWED = 'user_type_not_allowed';

    /** 403: the program does not run on this site's Property. */
    public const PROGRAM_NOT_ON_SITE = 'program_not_on_site';

    /**
     * 403: the collaborator does not belong to the site's Property. A site
     * reads and changes only collaborators enrolled in a program that runs
     * on it, or that it signed up or migrated.
     */
    public const COLLABORATOR_NOT_ON_SITE = 'collaborator_not_on_site';

    /**
     * 403: a site reads these records only through a filter that ties them
     * to its own store, such as one collaborator, one obligation or one
     * order. `error.context.anyOf` names the filters that do.
     */
    public const SITE_FILTER_REQUIRED = 'site_filter_required';

    /**
     * 403: a site reads only the reports its collaborator portal shows.
     * `error.context.param` names the part of the query that asked for
     * another.
     */
    public const REPORT_NOT_ALLOWED = 'report_not_allowed';

    /**
     * 403: the site's migration into Cloud is closed. A plain
     * {@see PermissionException}, because an organization admin reopens it
     * by approving the site's connection again.
     */
    public const MIGRATION_CLOSED = 'migration_closed';

    /** 403: the route's own authorization refused the caller, and no plan or role change would lift it. Any route may send it. */
    public const NOT_PERMITTED = 'not_permitted';

    /**
     * 403: the organization's plan does not include the feature. A plain
     * {@see PermissionException}, because a plan upgrade lifts it.
     * `error.context.capability` names the feature.
     */
    public const PLAN_REQUIRED = 'plan_required';

    /**
     * 403: only an organization admin may do this. A plain
     * {@see PermissionException}, because an admin's credential may.
     */
    public const ADMIN_REQUIRED = 'admin_required';

    /** 409: someone already holds the code under that type, in any namespace. */
    public const CODE_HELD = 'code_held';

    /** 409: the mapping already exists. */
    public const MAPPING_EXISTS = 'mapping_exists';

    /** 409: the collaborator has no credit available in that currency. */
    public const HOLD_NO_CREDIT = 'hold_no_credit';

    /** 409: another site's checkout already used the token for this collaborator and currency. */
    public const HOLD_TOKEN_IN_USE = 'hold_token_in_use';

    /**
     * 422: the email address is one Siren Cloud keeps for its own records,
     * such as a store's collaborator that waits for a person to confirm it,
     * so no sign-up may use it. A {@see ValidationException}.
     */
    public const RESERVED_ADDRESS = 'reserved_address';

    /**
     * 503: Siren Cloud could not read the organization's plan because its
     * entitlement service did not answer. An {@see ApiException}. Reads
     * retry it on their own.
     */
    public const PLAN_UNAVAILABLE = 'plan_unavailable';

    /**
     * 429: retired. Siren once answered with it when a collaborator held
     * credit for ten other live checkouts on one site, and no longer caps
     * holds, so it never sends this code now. The SDK keeps it so code
     * written against {@see HoldLimitException} still compiles.
     */
    public const HOLD_LIMIT = 'hold_limit';

    /**
     * The 403 codes that mean Siren's policy refused the request, so
     * repeating it fails again. Each is a {@see PolicyException}.
     *
     * @var list<string>
     */
    public const POLICY = [
        self::FIELD_NOT_ALLOWED,
        self::PENDING_STATUS_REQUIRED,
        self::USER_TYPE_NOT_ALLOWED,
        self::PROGRAM_NOT_ON_SITE,
        self::COLLABORATOR_NOT_ON_SITE,
        self::SITE_FILTER_REQUIRED,
        self::REPORT_NOT_ALLOWED,
        self::NOT_PERMITTED,
    ];

    /** @var list<string> */
    private const ALL = [
        self::SITE_DISCONNECTED,
        self::MISSING_SCOPE,
        self::API_KEY_NOT_ACCEPTED,
        self::STORE_KEY_NOT_ALLOWED,
        self::FIELD_NOT_ALLOWED,
        self::PENDING_STATUS_REQUIRED,
        self::USER_TYPE_NOT_ALLOWED,
        self::PROGRAM_NOT_ON_SITE,
        self::COLLABORATOR_NOT_ON_SITE,
        self::SITE_FILTER_REQUIRED,
        self::REPORT_NOT_ALLOWED,
        self::MIGRATION_CLOSED,
        self::NOT_PERMITTED,
        self::PLAN_REQUIRED,
        self::ADMIN_REQUIRED,
        self::CODE_HELD,
        self::MAPPING_EXISTS,
        self::HOLD_NO_CREDIT,
        self::HOLD_TOKEN_IN_USE,
        self::RESERVED_ADDRESS,
        self::PLAN_UNAVAILABLE,
        self::HOLD_LIMIT,
    ];

    /**
     * Whether this SDK knows the code. A known code decides the exception on
     * its own. An unknown or missing one leaves it to the message.
     */
    public static function isKnown(?string $code): bool
    {
        return $code !== null && in_array($code, self::ALL, true);
    }
}
