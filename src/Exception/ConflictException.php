<?php

declare(strict_types=1);

namespace Siren\Sdk\Exception;

/** Raised for HTTP 409 responses — the request conflicts with existing state. */
class ConflictException extends SirenException
{
}
