<?php

declare(strict_types=1);

namespace Siren\Sdk\Exception;

/**
 * Raised when a webhook payload's signature is missing or does not match the raw body.
 *
 * An app request's checks carry one of the codes below as
 * {@see getErrorCode()}, the same codes Node's and Python's
 * `SignatureVerificationError` carry, so a handler's `onError` can tell a wrong signing
 * secret from a clock that is off or a proxy that drops a header.
 */
class SignatureVerificationException extends SirenException
{
    /** No `X-Siren-Signature-V2` header. */
    public const SIGNATURE_MISSING = 'signature_missing';

    /** An `X-Siren-Signature-V2` header that can't be read. */
    public const SIGNATURE_MALFORMED = 'signature_malformed';

    /** An `X-Siren-Timestamp` header that differs from the signed time. */
    public const TIMESTAMP_MISMATCH = 'timestamp_mismatch';

    /** A signature that doesn't match: a wrong or rotated signing secret, or a body that isn't the raw bytes. */
    public const SIGNATURE_MISMATCH = 'signature_mismatch';

    /** A signed time too far from this machine's clock. */
    public const TIMESTAMP_OUT_OF_TOLERANCE = 'timestamp_out_of_tolerance';
}
