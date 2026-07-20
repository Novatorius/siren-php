<?php

declare(strict_types=1);

namespace Siren\Sdk\Exception;

/** Raised for HTTP 429 responses — too many requests. */
class RateLimitException extends SirenException
{
    /**
     * @param array<string, mixed>|null $errorData
     */
    public function __construct(
        string $message,
        int $statusCode = 429,
        ?string $errorCode = null,
        ?array $errorData = null,
        private readonly ?int $retryAfter = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $errorCode, $errorData, $previous);
    }

    /** Seconds to wait before retrying, from the `Retry-After` header when set. */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
