<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * A browser token for one custom event, as
 * {@see \Siren\Sdk\Resources\Events::browserToken()} returns it.
 *
 * Hand all three values to the page. The page posts the event's data as
 * JSON to {@see $url} with `?access_token=` set to {@see $token}, from a
 * domain the event allows.
 */
final class BrowserToken implements \JsonSerializable
{
    /**
     * @param string $token     The browser token.
     * @param int    $expiresIn Seconds the token has left.
     * @param string $url       The event's full send address.
     */
    public function __construct(
        public readonly string $token,
        public readonly int $expiresIn,
        public readonly string $url,
    ) {
    }

    /**
     * The JSON the browser token handler answers with.
     *
     * @return array{token: string, expiresIn: int, url: string}
     */
    public function toArray(): array
    {
        return ['token' => $this->token, 'expiresIn' => $this->expiresIn, 'url' => $this->url];
    }

    /**
     * @return array{token: string, expiresIn: int, url: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
