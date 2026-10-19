<?php

declare(strict_types=1);

namespace Siren\Sdk\Exception;

use Siren\Sdk\Http\HttpResponse;

/**
 * Maps HTTP error responses to the SDK's typed exceptions.
 *
 * @internal
 */
final class ExceptionFactory
{
    public static function fromResponse(HttpResponse $response): SirenException
    {
        $status = $response->getStatusCode();

        $body = json_decode($response->getBody(), true);

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

                // Siren's REST layer sends `{"error": {"message", "context"}}`,
                // and a failed validation puts `type: VALIDATION_FAILED` and
                // the per-field messages in `context.failedValidations`.
                if ($data === null && is_array($error['context'] ?? null) && $error['context'] !== []) {
                    $data = $error['context'];
                }

                if ($code === null && is_array($data) && is_string($data['type'] ?? null)) {
                    $code = $data['type'];
                }
            }
        }

        if ($status === 429) {
            $retryAfterHeader = $response->getHeaderLine('Retry-After');
            $retryAfter = is_numeric($retryAfterHeader) ? (int) $retryAfterHeader : null;

            return new RateLimitException($message, $status, $code, $data, $retryAfter);
        }

        $class = match ($status) {
            400 => BadRequestException::class,
            // Each check reads the stable `error.code` first and falls back to
            // the message when the reply has no code this SDK knows.
            401 => match (true) {
                SiteDisconnectedException::matches($message, $code) => SiteDisconnectedException::class,
                ApiKeyNotAcceptedException::matches($message, $code) => ApiKeyNotAcceptedException::class,
                default => AuthenticationException::class,
            },
            // A missing scope, which a new token or key fixes, a store-bound
            // key on records kept by no store, or a policy refusal, which
            // nothing about the credential fixes. Any other 403 stays a plain
            // PermissionException, such as a plan refusal (`plan_required`)
            // or an admin-only action (`admin_required`).
            403 => match (true) {
                MissingScopeException::matches($message, $code) => MissingScopeException::class,
                StoreKeyNotAllowedException::matches($message, $code) => StoreKeyNotAllowedException::class,
                PolicyException::matches($message, $data, $code) => PolicyException::class,
                default => PermissionException::class,
            },
            404 => NotFoundException::class,
            409 => ConflictException::class,
            422 => ValidationException::class,
            default => ApiException::class,
        };

        return new $class($message, $status, $code, $data);
    }
}
