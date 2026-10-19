<?php

declare(strict_types=1);

namespace Siren\Sdk\Exception;

/**
 * Raised for a 401 that says the route does not accept an API key
 * ({@see ErrorCode::API_KEY_NOT_ACCEPTED}).
 *
 * The key itself may be valid. The route needs a signed-in dashboard
 * session instead, as every `/api-keys` route does. An API key sends events,
 * and with the scopes in {@see \Siren\Sdk\ApiKeyScope} it reads the ledger
 * and manages webhook subscriptions. Retrying with the same key fails again.
 */
class ApiKeyNotAcceptedException extends AuthenticationException
{
    /**
     * Whether a 401 is Siren's refusal of an API key on a route that takes a
     * session: the {@see ErrorCode::API_KEY_NOT_ACCEPTED} code, or, from a
     * Cloud deployment that sends no code, the message.
     */
    public static function matches(string $message, ?string $code = null): bool
    {
        if (ErrorCode::isKnown($code)) {
            return $code === ErrorCode::API_KEY_NOT_ACCEPTED;
        }

        return stripos($message, 'does not accept API keys') !== false;
    }
}
