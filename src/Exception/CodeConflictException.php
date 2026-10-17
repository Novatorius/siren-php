<?php

declare(strict_types=1);

namespace Siren\Sdk\Exception;

/**
 * Raised when Siren refuses to give out a tracking or coupon code because
 * someone already holds it.
 *
 * A code has one holder per type across every namespace, so the holder may
 * be another connected site or the dashboard. Free the code first, or pick
 * another one.
 */
class CodeConflictException extends ConflictException
{
    /**
     * @param array<string, mixed>|null $errorData
     */
    public function __construct(
        string $message,
        private readonly string $conflictingCode,
        private readonly string $type,
        int $statusCode = 409,
        ?string $errorCode = null,
        ?array $errorData = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $errorCode, $errorData, $previous);
    }

    public static function fromConflict(ConflictException $conflict, string $code, string $type): self
    {
        return new self(
            $conflict->getMessage(),
            $code,
            $type,
            $conflict->getStatusCode(),
            $conflict->getErrorCode(),
            $conflict->getErrorData(),
            $conflict,
        );
    }

    /** The code that is already held. */
    public function getConflictingCode(): string
    {
        return $this->conflictingCode;
    }

    /** The plain type the code was asked for under, such as `coupon`. */
    public function getType(): string
    {
        return $this->type;
    }
}
