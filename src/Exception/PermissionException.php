<?php

declare(strict_types=1);

namespace Siren\Sdk\Exception;

/**
 * Raised for HTTP 403 responses: the credential is valid but may not do this.
 *
 * Two subclasses let a caller react to the two refusals a connected site
 * meets: {@see MissingScopeException} when the token lacks the scope the
 * route needs (reconnect to fix it), and {@see PolicyException} when Siren's
 * policy refuses the request, such as a connected site writing a field or a
 * program it may not touch (retrying will not help). Any other 403, such as
 * a plan entitlement, is this class.
 */
class PermissionException extends SirenException
{
}
