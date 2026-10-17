<?php

declare(strict_types=1);

namespace Siren\Sdk\Exception;

/**
 * Raised for a 403 whose token lacks the OAuth scope the route needs, such as
 * a connected site calling a record route with a token granted before the
 * record scopes existed.
 *
 * Retrying cannot help. The site has to reconnect and ask for the scopes in
 * {@see \Siren\Sdk\ConnectorScope::CONNECTED_SITE}.
 */
class MissingScopeException extends PermissionException
{
    /** How Siren words the refusal: "This token is not authorized for connect:records:read." */
    public const MESSAGE_PATTERN = '/\bnot authorized for ([A-Za-z0-9_:*-]+)/';

    /** Whether a 403 message is Siren's missing-scope refusal. */
    public static function matches(string $message): bool
    {
        return preg_match(self::MESSAGE_PATTERN, $message) === 1;
    }

    /** The scope the route needed, such as `connect:records:write`, when the message names it. */
    public function getRequiredScope(): ?string
    {
        if (preg_match(self::MESSAGE_PATTERN, $this->getMessage(), $matches) !== 1) {
            return null;
        }

        return rtrim($matches[1], ':');
    }
}
