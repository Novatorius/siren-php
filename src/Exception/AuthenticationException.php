<?php

declare(strict_types=1);

namespace Siren\Sdk\Exception;

/** Raised for HTTP 401 responses — the API key is missing, invalid, or revoked. */
class AuthenticationException extends SirenException
{
}
