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

    /**
     * Whether a 403 is Siren's missing-scope refusal: the
     * {@see ErrorCode::MISSING_SCOPE} code, or, from a Cloud deployment that
     * sends no code, the message.
     */
    public static function matches(string $message, ?string $code = null): bool
    {
        if (ErrorCode::isKnown($code)) {
            return $code === ErrorCode::MISSING_SCOPE;
        }

        return preg_match(self::MESSAGE_PATTERN, $message) === 1;
    }

    /**
     * The scope the route needed, such as `connect:records:write`, from
     * `error.context.scope` or else the message.
     */
    public function getRequiredScope(): ?string
    {
        $scope = $this->getErrorData()['scope'] ?? null;

        if (is_string($scope) && $scope !== '') {
            return $scope;
        }

        if (preg_match(self::MESSAGE_PATTERN, $this->getMessage(), $matches) !== 1) {
            return null;
        }

        return rtrim($matches[1], ':');
    }
}
