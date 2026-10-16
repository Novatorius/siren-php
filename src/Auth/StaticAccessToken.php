<?php

declare(strict_types=1);

namespace Siren\Sdk\Auth;

/**
 * A token that never changes, such as an API key from the Siren dashboard.
 *
 * `new Siren(['apiKey' => ...])` uses this. It cannot refresh, so a 401
 * surfaces straight away as an authentication error.
 */
final class StaticAccessToken implements AccessTokenSource
{
    public function __construct(
        private readonly string $token,
    ) {
        if ($token === '') {
            throw new \InvalidArgumentException('An access token must not be empty.');
        }
    }

    public function getAccessToken(): string
    {
        return $this->token;
    }

    public function refreshAccessToken(string $rejectedToken): ?string
    {
        return null;
    }
}
