<?php

declare(strict_types=1);

namespace Siren\Sdk\Exception;

/**
 * The machine-readable `error.code` Siren Cloud sends when it refuses a
 * connected site's request, as in
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

    /** 403: the token lacks the scope the route needs. `error.context.scope` names it. */
    public const MISSING_SCOPE = 'missing_scope';

    /** 403: a site may not send this field here. `error.context.param` names it. */
    public const FIELD_NOT_ALLOWED = 'field_not_allowed';

    /** 403: a site creates collaborators as pending, and sent another status. */
    public const PENDING_STATUS_REQUIRED = 'pending_status_required';

    /** 403: a site may not use the `user` mapping type, which names a Cloud account. */
    public const USER_TYPE_NOT_ALLOWED = 'user_type_not_allowed';

    /** 403: the program does not run on this site's Property. */
    public const PROGRAM_NOT_ON_SITE = 'program_not_on_site';

    /** 403: the route's own policy refuses the caller, such as a site writing an engine record. */
    public const NOT_PERMITTED = 'not_permitted';

    /** 409: someone already holds the code under that type, in any namespace. */
    public const CODE_HELD = 'code_held';

    /** 409: the mapping already exists. */
    public const MAPPING_EXISTS = 'mapping_exists';

    /** 409: the collaborator has no credit available in that currency. */
    public const HOLD_NO_CREDIT = 'hold_no_credit';

    /** 409: another site's checkout already used the token for this collaborator and currency. */
    public const HOLD_TOKEN_IN_USE = 'hold_token_in_use';

    /** 429: the collaborator already holds credit for ten other live checkouts on this site. */
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
        self::NOT_PERMITTED,
    ];

    /** @var list<string> */
    private const ALL = [
        self::SITE_DISCONNECTED,
        self::MISSING_SCOPE,
        self::FIELD_NOT_ALLOWED,
        self::PENDING_STATUS_REQUIRED,
        self::USER_TYPE_NOT_ALLOWED,
        self::PROGRAM_NOT_ON_SITE,
        self::NOT_PERMITTED,
        self::CODE_HELD,
        self::MAPPING_EXISTS,
        self::HOLD_NO_CREDIT,
        self::HOLD_TOKEN_IN_USE,
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
