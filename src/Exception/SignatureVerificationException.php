<?php

declare(strict_types=1);

namespace Siren\Sdk\Exception;

/** Raised when a webhook payload's signature is missing or does not match the raw body. */
class SignatureVerificationException extends SirenException
{
}
