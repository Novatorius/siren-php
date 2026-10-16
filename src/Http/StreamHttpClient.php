<?php

declare(strict_types=1);

namespace Siren\Sdk\Http;

/**
 * An {@see HttpClient} built on PHP's own HTTP stream wrapper, with no
 * package dependencies.
 *
 * The SDK uses it when no `httpClient` option is given and Guzzle is not
 * installed. HTTPS needs the `openssl` extension, which nearly every PHP
 * build includes.
 */
final class StreamHttpClient implements HttpClient
{
    public function send(HttpRequest $request): HttpResponse
    {
        $headerLines = [];

        foreach ($request->getHeaders() as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $http = [
            'method' => $request->getMethod(),
            'header' => implode("\r\n", $headerLines),
            'timeout' => $request->getTimeout(),
            'ignore_errors' => true,
            'protocol_version' => 1.1,
        ];

        if ($request->getBody() !== null) {
            $http['content'] = $request->getBody();
        }

        $context = stream_context_create(['http' => $http]);

        $error = null;
        set_error_handler(static function (int $severity, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            $stream = fopen($request->getUrl(), 'rb', false, $context);
            $body = $stream === false ? false : stream_get_contents($stream);
            $meta = $stream === false ? [] : stream_get_meta_data($stream);
        } finally {
            restore_error_handler();
        }

        if ($stream === false || $body === false) {
            throw new HttpClientException(sprintf(
                'The request to %s failed: %s',
                $request->getUrl(),
                $error ?? 'no response was received',
            ));
        }

        $timedOut = (bool) ($meta['timed_out'] ?? false);
        fclose($stream);

        if ($timedOut) {
            throw new HttpClientException(sprintf('The request to %s timed out.', $request->getUrl()));
        }

        /** @var list<string> $rawHeaders */
        $rawHeaders = is_array($meta['wrapper_data'] ?? null) ? array_values($meta['wrapper_data']) : [];

        [$status, $headers] = self::parseHeaders($rawHeaders);

        if ($status === 0) {
            throw new HttpClientException(sprintf('The response from %s had no HTTP status line.', $request->getUrl()));
        }

        return new HttpResponse($status, $headers, $body);
    }

    /**
     * Reads the last response's status and headers. After a redirect the
     * stream wrapper reports every hop in order, so only the final block
     * counts.
     *
     * @param list<string> $lines
     *
     * @return array{0: int, 1: array<string, list<string>>}
     */
    private static function parseHeaders(array $lines): array
    {
        $status = 0;
        $headers = [];

        foreach ($lines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
                $status = (int) $matches[1];
                $headers = [];
                continue;
            }

            $separator = strpos($line, ':');

            if ($separator === false) {
                continue;
            }

            $name = trim(substr($line, 0, $separator));
            $headers[$name][] = trim(substr($line, $separator + 1));
        }

        return [$status, $headers];
    }
}
