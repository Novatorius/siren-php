<?php

declare(strict_types=1);

namespace Siren\Sdk\Exception;

use Psr\Http\Message\ResponseInterface;

/**
 * Maps HTTP error responses to the SDK's typed exceptions.
 *
 * @internal
 */
final class ExceptionFactory
{
    public static function fromResponse(ResponseInterface $response): SirenException
    {
        $status = $response->getStatusCode();

        $body = json_decode((string) $response->getBody(), true);

        $message = sprintf('The Siren API returned an HTTP %d error.', $status);
        $code = null;
        $data = null;

        // Errors are polymorphic. Most endpoints emit `{"error": "<message>"}`
        // with a plain-string message; structured errors (login/entitlement)
        // emit `{"error": {"message", "code", "data"?}}`. Anything else falls
        // back to the HTTP status message.
        if (is_array($body) && array_key_exists('error', $body)) {
            $error = $body['error'];

            if (is_string($error) && $error !== '') {
                $message = $error;
            } elseif (is_array($error)) {
                if (is_string($error['message'] ?? null) && $error['message'] !== '') {
                    $message = $error['message'];
                }
                $code = is_string($error['code'] ?? null) ? $error['code'] : null;
                $data = is_array($error['data'] ?? null) ? $error['data'] : null;
            }
        }

        if ($status === 429) {
            $retryAfterHeader = $response->getHeaderLine('Retry-After');
            $retryAfter = is_numeric($retryAfterHeader) ? (int) $retryAfterHeader : null;

            return new RateLimitException($message, $status, $code, $data, $retryAfter);
        }

        $class = match ($status) {
            400 => BadRequestException::class,
            401 => AuthenticationException::class,
            403 => PermissionException::class,
            404 => NotFoundException::class,
            409 => ConflictException::class,
            422 => ValidationException::class,
            default => ApiException::class,
        };

        return new $class($message, $status, $code, $data);
    }
}
