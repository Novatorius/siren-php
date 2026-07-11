<?php

declare(strict_types=1);

namespace Siren\Sdk\Exception;

/** Raised for 5xx responses and any HTTP error status without a more specific mapping. */
class ApiException extends SirenException
{
}
