<?php

declare(strict_types=1);

namespace Siren\Sdk\Exception;

/**
 * Raised when Siren refuses a store credit hold with a 429 because the
 * collaborator already holds credit for ten other live checkouts on this
 * site.
 *
 * Siren no longer caps holds, so a current deployment never sends this
 * refusal and the SDK never raises this exception for it. The class stays
 * so code that catches it keeps working.
 *
 * Siren names it with the {@see ErrorCode::HOLD_LIMIT} code. A Cloud
 * deployment older than the codes sends none, and then the missing
 * `Retry-After` tells the cap from a rate limit.
 *
 * This is a cap, not a rate limit, so it carries no `Retry-After`. The
 * checkout should go ahead without credit, or try again after one of the
 * other checkouts finishes or its hold lapses.
 */
class HoldLimitException extends RateLimitException
{
    public static function fromRateLimit(RateLimitException $limit): self
    {
        return new self(
            $limit->getMessage(),
            $limit->getStatusCode(),
            $limit->getErrorCode(),
            $limit->getErrorData(),
            $limit->getRetryAfter(),
            $limit,
        );
    }
}
