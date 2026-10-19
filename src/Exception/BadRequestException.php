<?php

declare(strict_types=1);

namespace Siren\Sdk\Exception;

/**
 * Raised for HTTP 400 responses — the request was malformed.
 *
 * Siren also answers 400 when a field fails validation. Then
 * {@see getErrorCode()} is `VALIDATION_FAILED` and {@see getFieldErrors()}
 * lists each broken rule.
 */
class BadRequestException extends SirenException
{
    public const VALIDATION_FAILED = 'VALIDATION_FAILED';

    /**
     * The broken rules from `error.context.failedValidations`, one entry per
     * rule in Siren's order, each `{field, message, type, context}`, such as
     * `{"field": "total", "message": "total is required.", "type": "REQUIRED"}`.
     * Empty when the 400 was not a validation failure.
     *
     * @return array<int|string, mixed>
     */
    public function getFieldErrors(): array
    {
        $failures = $this->getErrorData()['failedValidations'] ?? null;

        return is_array($failures) ? $failures : [];
    }
}
