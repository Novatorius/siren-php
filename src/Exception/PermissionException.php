<?php

declare(strict_types=1);

namespace Siren\Sdk\Exception;

/** Raised for HTTP 403 responses — the API key lacks the required scope. */
class PermissionException extends SirenException
{
}
