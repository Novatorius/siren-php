<?php

declare(strict_types=1);

namespace Siren\Sdk\Auth;

use Siren\Sdk\BrowserToken;
use Siren\Sdk\Exception\ApiException;
use Siren\Sdk\Exception\BadRequestException;
use Siren\Sdk\HttpTransport;

/**
 * Trades the app's key for short-lived app tokens and keeps them.
 *
 * Siren takes only an app token at a custom event's send address. This
 * class asks the token route (`POST /oauth/token`, grant
 * `client_credentials`) for a server token the first time a custom event is
 * sent, keeps it, and asks again when fewer than
 * {@see SERVER_REFRESH_MARGIN} seconds of its life are left. It keeps one
 * browser token per event in the same way, with
 * {@see BROWSER_REFRESH_MARGIN}.
 *
 * A PHP request ends with its objects, so a token kept only in memory would
 * cost one grant per page. Tokens are also kept in the `tokenCache` option
 * (a PSR-16 cache) when one is given, or in APCu when it is on, so every
 * worker on the server shares them until they near their end.
 *
 * @internal
 */
final class AppTokens
{
    /** A server token is replaced when fewer than this many seconds are left. */
    public const SERVER_REFRESH_MARGIN = 120;

    /** A browser token is replaced when fewer than this many seconds are left. */
    public const BROWSER_REFRESH_MARGIN = 60;

    /** The scope that asks for a browser token, followed by the event key. */
    public const BROWSER_SCOPE_PREFIX = 'browser:';

    /** @var array<string, array{token: string, expiresAt: float}> Tokens by scope, '' for the server token. */
    private array $held = [];

    /** True once Siren said the key belongs to no app. */
    private bool $keyHasNoApp = false;

    /** @var callable(): float */
    private $clock;

    /**
     * @param callable(): float|null $clock Seconds since the epoch. Defaults to microtime(true).
     * @param object|false|null      $cache A `Psr\SimpleCache\CacheInterface`, false for memory only,
     *                                      or null for APCu when it is on.
     */
    public function __construct(
        private readonly HttpTransport $transport,
        private readonly string $apiKey,
        ?callable $clock = null,
        private readonly object|false|null $cache = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    /**
     * The server token to send a custom event with, or null when the key
     * belongs to no app and the send should carry the key as before.
     *
     * @throws \Siren\Sdk\Exception\SirenException When the token route refuses the key for any other reason.
     */
    public function serverToken(): ?string
    {
        if ($this->keyHasNoApp) {
            return null;
        }

        try {
            return $this->token('', self::SERVER_REFRESH_MARGIN)['token'];
        } catch (BadRequestException $e) {
            if ($e->getErrorCode() !== 'unauthorized_client') {
                throw $e;
            }

            $this->keyHasNoApp = true;

            return null;
        }
    }

    /**
     * Forgets a server token Siren answered 401 to, so the next call asks
     * for a new one.
     */
    public function dropServerToken(string $rejected): void
    {
        $this->drop('', $rejected);
    }

    /**
     * A browser token for one custom event, reused until fewer than
     * {@see BROWSER_REFRESH_MARGIN} seconds are left.
     *
     * @throws \Siren\Sdk\Exception\SirenException When Siren refuses the grant.
     */
    public function browserToken(string $eventKey): BrowserToken
    {
        $held = $this->token(self::BROWSER_SCOPE_PREFIX . $eventKey, self::BROWSER_REFRESH_MARGIN);

        return new BrowserToken(
            $held['token'],
            max(0, (int) round($held['expiresAt'] - ($this->clock)())),
            $this->transport->baseUrl . '/event/' . rawurlencode($eventKey),
        );
    }

    /**
     * @return array{token: string, expiresAt: float}
     */
    private function token(string $scope, int $margin): array
    {
        $now = ($this->clock)();
        $held = $this->held[$scope] ?? $this->fromCache($scope);

        if ($held !== null && $held['expiresAt'] - $now >= $margin) {
            return $this->held[$scope] = $held;
        }

        $body = ['grant_type' => 'client_credentials', 'client_secret' => $this->apiKey];

        if ($scope !== '') {
            $body['scope'] = $scope;
        }

        $answer = $this->transport->request('POST', '/oauth/token', $body, bearer: false)->json();
        $token = $answer['access_token'] ?? null;
        $expiresIn = $answer['expires_in'] ?? null;

        if (!is_string($token) || $token === '' || !is_int($expiresIn) || $expiresIn <= 0) {
            throw new ApiException('Siren answered the token request without an access token.', 200);
        }

        $held = ['token' => $token, 'expiresAt' => ($this->clock)() + $expiresIn];
        $this->held[$scope] = $held;
        $this->toCache($scope, $held, $expiresIn);

        return $held;
    }

    private function drop(string $scope, string $rejected): void
    {
        if (($this->held[$scope]['token'] ?? null) === $rejected) {
            unset($this->held[$scope]);
        }

        $cached = $this->fromCache($scope);

        if ($cached !== null && $cached['token'] === $rejected) {
            $this->deleteFromCache($scope);
        }
    }

    private function cacheKey(string $scope): string
    {
        // PSR-16 keys allow letters, digits, `_` and `.`, so the key, the
        // address and the scope go in as one hash.
        return 'siren_app_token_' . hash('sha256', $this->apiKey . "\0" . $this->transport->baseUrl . "\0" . $scope);
    }

    /**
     * @return array{token: string, expiresAt: float}|null
     */
    private function fromCache(string $scope): ?array
    {
        $key = $this->cacheKey($scope);

        if ($this->cache instanceof \Psr\SimpleCache\CacheInterface) {
            $value = $this->cache->get($key);
        } elseif ($this->cache === null && self::apcuIsOn()) {
            $value = apcu_fetch($key);
        } else {
            return null;
        }

        if (!is_array($value) || !is_string($value['token'] ?? null) || !is_numeric($value['expiresAt'] ?? null)) {
            return null;
        }

        return ['token' => $value['token'], 'expiresAt' => (float) $value['expiresAt']];
    }

    /**
     * @param array{token: string, expiresAt: float} $held
     */
    private function toCache(string $scope, array $held, int $ttl): void
    {
        $key = $this->cacheKey($scope);

        if ($this->cache instanceof \Psr\SimpleCache\CacheInterface) {
            $this->cache->set($key, $held, $ttl);
        } elseif ($this->cache === null && self::apcuIsOn()) {
            apcu_store($key, $held, $ttl);
        }
    }

    private function deleteFromCache(string $scope): void
    {
        $key = $this->cacheKey($scope);

        if ($this->cache instanceof \Psr\SimpleCache\CacheInterface) {
            $this->cache->delete($key);
        } elseif ($this->cache === null && self::apcuIsOn()) {
            apcu_delete($key);
        }
    }

    private static function apcuIsOn(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }
}
