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

    /** Whether a 401 message is Siren's revoked-site refusal. */
    public static function matches(string $message): bool
    {
        return stripos($message, 'no longer connected') !== false;
    }
}
