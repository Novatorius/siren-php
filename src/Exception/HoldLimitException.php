<?php

declare(strict_types=1);

namespace Siren\Sdk\Exception;

/**
 * Raised when Siren refuses a store credit hold with a 429 because the
 * collaborator already holds credit for ten other live checkouts on this
 * site.
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
