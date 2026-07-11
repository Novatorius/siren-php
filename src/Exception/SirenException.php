<?php

declare(strict_types=1);

namespace Siren\Sdk\Exception;

/**
 * Base class for every exception raised by the Siren SDK.
 *
 * Carries the HTTP status code (0 when the failure never reached the API,
 * e.g. connection or signature errors), the API's machine-readable error
 * code when present, and any structured error data from the response body.
 */
class SirenException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 0,
        private readonly ?string $errorCode = null,
        private readonly ?array $errorData = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** The HTTP status code of the failed response, or 0 if no response was received. */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** The API's machine-readable `error.code`, when the response included one. */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /** Structured `error.data` from the response body, when present. */
    public function getErrorData(): ?array
    {
        return $this->errorData;
    }
}
