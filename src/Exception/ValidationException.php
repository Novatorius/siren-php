<?php

declare(strict_types=1);

namespace Siren\Sdk\Exception;

/** Raised for HTTP 422 responses — the request body failed validation. */
class ValidationException extends SirenException
{
    /**
     * Field-level validation errors from the API's `error.data`, when present.
     *
     * @return array<string, mixed>
     */
    public function getFieldErrors(): array
    {
        return $this->getErrorData() ?? [];
    }
}
