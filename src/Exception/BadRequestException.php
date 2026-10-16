<?php

declare(strict_types=1);

namespace Siren\Sdk\Exception;

/**
 * Raised for HTTP 400 responses — the request was malformed.
 *
 * Siren also answers 400 when a field fails validation. Then
 * {@see getErrorCode()} is `VALIDATION_FAILED` and {@see getFieldErrors()}
 * holds the messages for each field.
 */
class BadRequestException extends SirenException
{
    public const VALIDATION_FAILED = 'VALIDATION_FAILED';

    /**
     * Per-field validation messages from `error.context.failedValidations`,
     * keyed by field name. Empty when the 400 was not a validation failure.
     *
     * @return array<string, mixed>
     */
    public function getFieldErrors(): array
    {
        $failures = $this->getErrorData()['failedValidations'] ?? null;

        return is_array($failures) ? $failures : [];
    }
}
