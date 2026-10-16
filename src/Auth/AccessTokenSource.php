<?php

declare(strict_types=1);

namespace Siren\Sdk\Auth;

/**
 * Supplies the bearer token the SDK sends with every request.
 *
 * A fixed API key needs nothing more than the `apiKey` option. A host that
 * holds an OAuth grant, such as a connected WordPress site, implements this
 * interface (or uses {@see CallbackAccessTokenSource}) and passes it as the
 * `accessToken` option:
 *
 * ```php
 * $siren = new Siren(['accessToken' => new MyStoredGrant()]);
 * ```
 *
 * The SDK asks for the token before each request, so a token the host
 * rotated elsewhere is picked up straight away. When Siren answers 401, the
 * SDK calls {@see refreshAccessToken()} once and repeats the request with
 * the new token. A second 401 surfaces as
 * {@see \Siren\Sdk\Exception\AuthenticationException}, which means the grant
 * itself is gone and the site needs to reconnect.
 */
interface AccessTokenSource
{
    /** The token to send as `Authorization: Bearer <token>`. */
    public function getAccessToken(): string;

    /**
     * Gets a fresh token after Siren rejected `$rejectedToken` with a 401.
     *
     * Store the new token (and any rotated refresh token) before returning,
     * because later requests read it through {@see getAccessToken()}. If
     * another process already rotated the token, return the stored one
     * instead of refreshing again. Return null when no refresh is possible,
     * and the SDK raises the original 401.
     */
    public function refreshAccessToken(string $rejectedToken): ?string;
}
