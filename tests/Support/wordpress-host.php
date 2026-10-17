<?php

/**
 * Runs the SDK the way a connected WordPress site does, in a process with no
 * Composer vendor directory: only the SDK's own classes load, so Guzzle is
 * absent, and HTTP goes through stand-ins for WordPress's wp_remote_*
 * functions. Prints a JSON report for WordPressHostTest.
 *
 * The stand-ins return queued responses in order and record every call's
 * URL and arguments, which is what wp_remote_request() would receive.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Siren\\Sdk\\';

    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (is_file($file)) {
            require $file;
        }
    }
});

final class WP_Error
{
    public function __construct(private string $code, private string $message)
    {
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}

final class HeaderDictionary
{
    /** @param array<string, string|list<string>> $headers */
    public function __construct(private array $headers)
    {
    }

    /** @return array<string, string|list<string>> */
    public function getAll(): array
    {
        return $this->headers;
    }
}

/** @var list<array{url: string, args: array<string, mixed>}> */
$GLOBALS['wp_calls'] = [];

/** @var list<array{0: int, 1: array<string, string|list<string>>, 2: string}|WP_Error> */
$GLOBALS['wp_queue'] = [
    // findByCode
    [200, ['content-type' => 'application/json'], json_encode(['items' => [['code' => 'SAVE10', 'type' => 'coupon@property:5', 'collaboratorId' => 4]], 'total' => 1, 'page' => 1, 'perPage' => 1, 'totalPages' => 1])],
    // placeHold: no credit
    [409, ['content-type' => 'application/json'], json_encode(['error' => 'This collaborator has no credit available in that currency.'])],
    // mappings create: an expired token, then success after the refresh
    [401, ['content-type' => 'application/json'], json_encode(['error' => ['message' => 'Authentication required.', 'context' => []]])],
    [201, ['content-type' => 'application/json'], json_encode(['localId' => 4, 'localType' => 'collaborator', 'externalId' => '12', 'externalType' => 'wordpress_user@property:5'])],
    // free: a list read with a repeated header
    [200, ['content-type' => 'application/json', 'x-siren-estimated-count' => ['3', '3']], json_encode([['collaboratorId' => 4, 'programId' => 2]])],
    // a request that never reached Siren
    new WP_Error('http_request_failed', 'cURL error 28: Operation timed out'),
];

function wp_remote_request(string $url, array $args): array|WP_Error
{
    $GLOBALS['wp_calls'][] = ['url' => $url, 'args' => $args];
    $next = array_shift($GLOBALS['wp_queue']);

    if ($next instanceof WP_Error || $next === null) {
        return $next ?? new WP_Error('none', 'No response queued.');
    }

    return ['response' => ['code' => $next[0]], 'headers' => new HeaderDictionary($next[1]), 'body' => $next[2]];
}

function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}

function wp_remote_retrieve_response_code(array $response): int
{
    return $response['response']['code'];
}

function wp_remote_retrieve_headers(array $response): HeaderDictionary
{
    return $response['headers'];
}

function wp_remote_retrieve_body(array $response): string
{
    return $response['body'];
}

/** The transport from the README's "Bring your own HTTP client" section. */
final class WordPressHttpClient implements Siren\Sdk\Http\HttpClient
{
    public function send(Siren\Sdk\Http\HttpRequest $request): Siren\Sdk\Http\HttpResponse
    {
        $response = wp_remote_request($request->getUrl(), [
            'method' => $request->getMethod(),
            'headers' => $request->getHeaders(),
            'body' => $request->getBody(),
            'timeout' => $request->getTimeout(),
        ]);

        if (is_wp_error($response)) {
            throw new Siren\Sdk\Http\HttpClientException($response->get_error_message());
        }

        return new Siren\Sdk\Http\HttpResponse(
            (int) wp_remote_retrieve_response_code($response),
            wp_remote_retrieve_headers($response)->getAll(),
            (string) wp_remote_retrieve_body($response),
        );
    }
}

$tokens = ['first.access.token'];
$siren = new Siren\Sdk\Siren([
    'baseUrl' => 'https://cloud.example.test/siren/v1',
    'timeout' => 3,
    'maxRetries' => 0,
    'httpClient' => new WordPressHttpClient(),
    'accessToken' => new Siren\Sdk\Auth\CallbackAccessTokenSource(
        static function () use (&$tokens): string {
            return $tokens[array_key_last($tokens)];
        },
        static function (string $rejected) use (&$tokens): string {
            $tokens[] = 'second.access.token';

            return 'second.access.token';
        },
    ),
]);

$report = [];
$report['alias'] = $siren->collaborators->aliases->findByCode('SAVE10', 'coupon');

try {
    $siren->credits->placeHold(4, 'STORE_CREDIT', 1000, '0b6f3c2e-8a1d-4c55-9a0e-0d6c1b7f4e21', 'woocommerce', 600);
    $report['hold'] = 'placed';
} catch (Siren\Sdk\Exception\HoldConflictException $e) {
    $report['hold'] = $e->getReason();
}

$report['mapping'] = $siren->mappings->create(4, '12', 'wordpress_user');
$enrollments = $siren->collaborators->enrollments->list(['collaboratorId' => 4]);
$report['enrollmentTotal'] = $enrollments->getTotal();

try {
    $siren->settings->value('collaborator', 'urlReference', 'key');
    $report['timeout'] = 'no error';
} catch (Siren\Sdk\Exception\ConnectionException $e) {
    $report['timeout'] = $e->getMessage();
}

// A Siren client with no httpClient option falls back to PHP streams here.
$fallback = new Siren\Sdk\Siren(['apiKey' => 'sk_test_fallback']);
$transport = (new ReflectionProperty($fallback->credits, 'transport'))->getValue($fallback->credits);
$report['fallbackClient'] = get_class((new ReflectionProperty($transport, 'client'))->getValue($transport));

$report['calls'] = $GLOBALS['wp_calls'];
$report['guzzleLoaded'] = array_values(array_filter(
    [...get_declared_classes(), ...get_declared_interfaces()],
    static fn (string $class): bool => stripos($class, 'GuzzleHttp') === 0,
));
$report['composerLoaded'] = class_exists('Composer\\Autoload\\ClassLoader', false);

echo json_encode($report, JSON_THROW_ON_ERROR);
