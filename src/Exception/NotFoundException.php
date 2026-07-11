<?php

declare(strict_types=1);

namespace Siren\Sdk\Exception;

/** Raised for HTTP 404 responses — the resource does not exist (e.g. a refund for an unknown sale). */
class NotFoundException extends SirenException
{
}
