<?php

declare(strict_types=1);

namespace Siren\Sdk\Exception;

/**
 * Raised for a 401 that says the connected site's OAuth client was revoked
 * or deleted ("This site is no longer connected.").
 *
 * The SDK has already asked the token source for a fresh token once. A
 * host should stop calling Siren for this site until it reconnects, rather
 * than retry.
 */
class SiteDisconnectedException extends AuthenticationException
{
    /** The message Siren Cloud sends for a revoked site. */
    public const MESSAGE = 'This site is no longer connected.';

    /**
     * Whether a 401 is Siren's revoked-site refusal: the
     * {@see ErrorCode::SITE_DISCONNECTED} code, or, from a Cloud deployment
     * that sends no code, the message.
     */
    public static function matches(string $message, ?string $code = null): bool
    {
        if (ErrorCode::isKnown($code)) {
            return $code === ErrorCode::SITE_DISCONNECTED;
        }

        return stripos($message, 'no longer connected') !== false;
    }
}
