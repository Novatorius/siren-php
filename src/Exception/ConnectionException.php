<?php

declare(strict_types=1);

namespace Siren\Sdk\Exception;

/** Raised when the request never produced an HTTP response — DNS failure, timeout, refused connection. */
class ConnectionException extends SirenException
{
}
