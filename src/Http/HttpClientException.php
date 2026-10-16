<?php

declare(strict_types=1);

namespace Siren\Sdk\Http;

/**
 * Thrown by an {@see HttpClient} when a request produced no HTTP response:
 * DNS failure, refused connection, TLS failure, or timeout.
 *
 * The SDK catches it, retries where the operation allows, and then raises
 * {@see \Siren\Sdk\Exception\ConnectionException} to the caller. Pass the
 * host's own error as `$previous` so it stays inspectable.
 */
class HttpClientException extends \RuntimeException
{
}
