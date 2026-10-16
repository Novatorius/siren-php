<?php

declare(strict_types=1);

namespace Siren\Sdk\Auth;

/**
 * An {@see AccessTokenSource} built from two callables, for hosts that keep
 * their OAuth grant in their own storage.
 *
 * ```php
 * $tokens = new CallbackAccessTokenSource(
 *     fn (): string => $store->accessToken(),
 *     fn (string $rejected): ?string => $oauth->refresh($rejected),
 * );
 *
 * $siren = new Siren(['accessToken' => $tokens]);
 * ```
 */
final class CallbackAccessTokenSource implements AccessTokenSource
{
    /** @var callable(): string */
    private $get;

    /** @var (callable(string): ?string)|null */
    private $refresh;

    /**
     * @param callable(): string            $get     Returns the current access token.
     * @param (callable(string): ?string)|null $refresh Gets a fresh token after a 401, or null when it cannot.
     */
    public function __construct(callable $get, ?callable $refresh = null)
    {
        $this->get = $get;
        $this->refresh = $refresh;
    }

    public function getAccessToken(): string
    {
        return (string) ($this->get)();
    }

    public function refreshAccessToken(string $rejectedToken): ?string
    {
        if ($this->refresh === null) {
            return null;
        }

        $token = ($this->refresh)($rejectedToken);

        return is_string($token) && $token !== '' ? $token : null;
    }
}
