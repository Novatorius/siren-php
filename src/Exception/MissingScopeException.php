<?php

declare(strict_types=1);

namespace Siren\Sdk\Exception;

/**
 * Raised for a 403 whose credential lacks the scope the route needs.
 *
 * For a connected site, that is a token granted before the record scopes
 * existed. The site has to reconnect and ask for the scopes in
 * {@see \Siren\Sdk\ConnectorScope::CONNECTED_SITE}.
 *
 * For an API key, that is a key created without the route's ledger or
 * webhook scope. {@see getAnyOf()} lists every scope that opens the route.
 * Create a key that holds one of them, from
 * {@see \Siren\Sdk\ApiKeyScope}.
 *
 * Retrying with the same credential cannot help.
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

    /**
     * Every scope that opens the route, from `error.context.anyOf`, such as
     * `['webhook-subscriptions:read', 'webhook-subscriptions:manage']`. Siren
     * sends it when it refuses an API key. Holding any one of them is
     * enough. Empty when Siren did not list them.
     *
     * @return list<string>
     */
    public function getAnyOf(): array
    {
        $anyOf = $this->getErrorData()['anyOf'] ?? null;

        if (!is_array($anyOf)) {
            return [];
        }

        return array_values(array_filter($anyOf, static fn ($scope): bool => is_string($scope) && $scope !== ''));
    }
}
