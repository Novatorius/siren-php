<?php

declare(strict_types=1);

namespace Siren\Sdk\Exception;

/**
 * Raised when Siren refuses to place a store credit hold with a 409.
 *
 * {@see getReason()} tells the two causes apart. With
 * {@see REASON_NO_CREDIT} the collaborator has nothing available in that
 * currency, so the checkout should apply no credit. With
 * {@see REASON_TOKEN_IN_USE} another site's checkout already holds credit
 * for this collaborator and currency under the same session token, so the
 * checkout should mint a new token. Siren says which only in its message,
 * so the reason is null when the wording is unfamiliar.
 */
class HoldConflictException extends ConflictException
{
    public const REASON_NO_CREDIT = 'no_credit';
    public const REASON_TOKEN_IN_USE = 'token_in_use';

    public static function fromConflict(ConflictException $conflict): self
    {
        return new self(
            $conflict->getMessage(),
            $conflict->getStatusCode(),
            $conflict->getErrorCode(),
            $conflict->getErrorData(),
            $conflict,
        );
    }

    /** One of the `REASON_*` constants, or null when Siren's message names neither. */
    public function getReason(): ?string
    {
        $message = strtolower($this->getMessage());

        if (str_contains($message, 'under that token')) {
            return self::REASON_TOKEN_IN_USE;
        }

        if (str_contains($message, 'no credit available')) {
            return self::REASON_NO_CREDIT;
        }

        return null;
    }
}
