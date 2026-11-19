<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The README's app token snippets, run as written against a fake Siren on
 * PHP's built-in web server (tests/Support/fake-siren.php). The examples
 * folder holds the same code, which this test also checks.
 */
#[Group('local-server')]
final class ReadmeExamplesTest extends TestCase
{
    private const APP_KEY = 'sk_test_fake_app_key';

    /** @var list<resource> */
    private static array $servers = [];

    private static string $dir = '';

    private static string $sirenUrl = '';

    private static string $log = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/siren-readme-' . bin2hex(random_bytes(4));
        mkdir(self::$dir);
        self::$log = self::$dir . '/siren.log';
        touch(self::$log);

        $port = self::serve(__DIR__ . '/Support/fake-siren.php', null, ['FAKE_SIREN_LOG' => self::$log]);
        self::$sirenUrl = 'http://127.0.0.1:' . $port . '/siren/v1';
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$servers as $server) {
            proc_terminate($server);
            proc_close($server);
        }

        self::$servers = [];

        foreach (glob(self::$dir . '/{,*/}*', GLOB_BRACE) ?: [] as $path) {
            is_dir($path) ? null : unlink($path);
        }

        foreach (glob(self::$dir . '/*', GLOB_ONLYDIR) ?: [] as $path) {
            rmdir($path);
        }

        @rmdir(self::$dir);
    }

    protected function setUp(): void
    {
        file_put_contents(self::$log, '');
    }

    public function testTheExamplesFolderHoldsTheReadmeSnippets(): void
    {
        $files = [
            'server-send' => 'send-custom-event.php',
            'plain-handler' => 'siren-token.php',
            'psr15-handler' => 'psr15-token-handler.php',
        ];

        foreach ($files as $name => $file) {
            $this->assertSame(
                str_replace("require 'vendor/autoload.php';", "require __DIR__ . '/../vendor/autoload.php';", self::snippet($name)),
                file_get_contents(dirname(__DIR__) . '/examples/' . $file),
                $file,
            );
        }

        $this->assertSame(self::snippet('page', 'html'), file_get_contents(dirname(__DIR__) . '/examples/page.html'));
    }

    public function testThePageSnippetSendsABodySirenAccepts(): void
    {
        $page = self::snippet('page', 'html');
        $this->assertSame(1, preg_match("/sirenTrack\\('([\\w-]+)', (\\{.*?\\})\\);/", $page, $call), 'The page snippet calls sirenTrack().');
        // The page's object literal, as JSON: quote the keys and the strings.
        $json = (string) preg_replace(["/([{,]\\s*)(\\w+):/", "/'([^']*)'/"], ['$1"$2":', '"$1"'], $call[2]);
        $data = json_decode($json, true);
        $this->assertIsArray($data, $call[2]);

        $siren = new \Siren\Sdk\Siren(['apiKey' => self::APP_KEY, 'baseUrl' => self::$sirenUrl, 'tokenCache' => false]);
        $token = $siren->events->browserToken($call[1]);
        $send = $token->url . '?access_token=' . rawurlencode($token->token);

        [$status, , $body] = self::fetch($send, 'POST', json_encode($data, JSON_THROW_ON_ERROR));
        $this->assertSame([200, 'recorded'], [$status, $body['status'] ?? null], 'Siren takes the body the README page sends.');

        unset($data['collaboratorId']);
        [$status] = self::fetch($send, 'POST', json_encode($data, JSON_THROW_ON_ERROR));
        $this->assertSame(400, $status, 'The fake refuses a body with no collaboratorId, as Siren does.');
    }

    public function testTheServerSendSnippetTradesTheKeyAndSendsWithTheToken(): void
    {
        $script = self::write('server-send.php', self::runnable('server-send'));
        $env = ['SIREN_API_KEY' => self::APP_KEY, 'SIREN_BASE_URL' => self::$sirenUrl];

        $output = self::runScript([PHP_BINARY, $script], $env);

        $this->assertSame("recorded\n", $output);
        $this->assertSame([
            ['POST', '/siren/v1/oauth/token', '', ['grant_type' => 'client_credentials', 'client_secret' => self::APP_KEY]],
            ['POST', '/siren/v1/event/signup-completed', 'Bearer srv.fake-token', ['collaboratorId' => 12, 'plan' => 'pro']],
        ], self::sirenSaw());
    }

    public function testThePlainPhpSnippetAnswersEachStatus(): void
    {
        $root = self::$dir . '/plain';
        @mkdir($root);
        self::write('plain/siren-token.php', self::runnable('plain-handler'));
        $page = 'http://127.0.0.1:' . self::serve(null, $root, ['SIREN_API_KEY' => self::APP_KEY, 'SIREN_BASE_URL' => self::$sirenUrl]);

        [$status, $headers, $body] = self::fetch($page . '/siren-token.php', 'POST', '{"event":"signup-completed"}');
        $this->assertSame(200, $status);
        $this->assertSame(['token' => 'brw.fake-token', 'expiresIn' => 600, 'url' => self::$sirenUrl . '/event/signup-completed'], $body);
        $this->assertContains('content-type: application/json', $headers);
        $this->assertContains('cache-control: no-store', $headers);

        [$status, , $body] = self::fetch($page . '/siren-token.php?event=signup-completed');
        $this->assertSame([200, 'brw.fake-token'], [$status, $body['token'] ?? null]);

        [$status, , $body] = self::fetch($page . '/siren-token.php');
        $this->assertSame(400, $status);
        $this->assertArrayHasKey('error', $body);

        [$status, , $body] = self::fetch($page . '/siren-token.php?event=trial-started');
        $this->assertSame([400, ['error' => 'This server gives no browser token for that event.']], [$status, $body]);

        // Each page load is its own PHP request, and this server has no APCu,
        // so each asks for its own token. Refused requests never reach Siren.
        $this->assertSame(
            ['browser:signup-completed', 'browser:signup-completed'],
            array_map(static fn (array $call): mixed => $call[3]['scope'] ?? null, self::sirenSaw()),
        );
    }

    public function testThePlainPhpSnippetAnswers502WhenSirenRefusesTheKey(): void
    {
        $root = self::$dir . '/bad-key';
        @mkdir($root);
        self::write('bad-key/siren-token.php', self::runnable('plain-handler'));
        $page = 'http://127.0.0.1:' . self::serve(null, $root, ['SIREN_API_KEY' => 'sk_test_revoked', 'SIREN_BASE_URL' => self::$sirenUrl]);

        [$status, , $body] = self::fetch($page . '/siren-token.php?event=signup-completed');

        $this->assertSame([502, ['error' => 'Siren could not be reached for a browser token. Try again shortly.']], [$status, $body]);
    }

    public function testThePsr15SnippetGivesAHandler(): void
    {
        putenv('SIREN_API_KEY=' . self::APP_KEY);
        putenv('SIREN_BASE_URL=' . self::$sirenUrl);

        try {
            $handler = (static function (string $file): mixed {
                require $file;

                return $handler ?? null;
            })(self::write('psr15.php', self::runnable('psr15-handler')));
        } finally {
            putenv('SIREN_API_KEY');
            putenv('SIREN_BASE_URL');
        }

        $this->assertInstanceOf(RequestHandlerInterface::class, $handler);

        $ok = $handler->handle(new ServerRequest('POST', '/siren/token', ['Content-Type' => 'application/json'], '{"event":"signup-completed"}'));
        $refused = $handler->handle((new ServerRequest('GET', '/siren/token'))->withQueryParams(['event' => 'trial-started']));

        $this->assertSame(200, $ok->getStatusCode());
        $this->assertSame('brw.fake-token', json_decode((string) $ok->getBody(), true)['token']);
        $this->assertSame(400, $refused->getStatusCode());
    }

    private static function snippet(string $name, string $language = 'php'): string
    {
        $readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');
        $found = preg_match('/<!-- readme-test: ' . preg_quote($name, '/') . ' -->\n```' . $language . '\n(.*?)```/s', $readme, $matches);
        self::assertSame(1, $found, 'README has no snippet named ' . $name);

        return $matches[1];
    }

    private static function runnable(string $name): string
    {
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);

        return str_replace("require 'vendor/autoload.php';", 'require ' . $autoload . ';', self::snippet($name));
    }

    private static function write(string $relative, string $code): string
    {
        $path = self::$dir . '/' . $relative;
        file_put_contents($path, $code);

        return $path;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: array<string, mixed>}>
     */
    private static function sirenSaw(): array
    {
        $lines = array_filter(explode("\n", (string) file_get_contents(self::$log)));

        return array_values(array_map(static function (string $line): array {
            $entry = json_decode($line, true);

            return [$entry['method'], $entry['path'], $entry['authorization'], $entry['body']];
        }, $lines));
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     */
    private static function runScript(array $command, array $env): string
    {
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, $env + getenv());
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        self::assertSame(0, $code, $errors . $output);

        return $output;
    }

    /**
     * Starts PHP's built-in server on a free port, with a router script or
     * a document root, and returns the port.
     *
     * @param array<string, string> $env
     */
    private static function serve(?string $router, ?string $root, array $env): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($address, strrpos($address, ':') + 1);

        $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port];
        $command = $router !== null ? [...$command, $router] : [...$command, '-t', (string) $root];
        $process = proc_open($command, [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, null, $env + getenv());
        self::assertIsResource($process);
        self::$servers[] = $process;

        for ($i = 0; $i < 100; $i++) {
            $probe = @fsockopen('127.0.0.1', $port);

            if ($probe !== false) {
                fclose($probe);

                return $port;
            }

            usleep(20_000);
        }

        self::fail('The PHP built-in server did not start.');
    }

    /**
     * @return array{0: int, 1: list<string>, 2: array<string, mixed>}
     */
    private static function fetch(string $url, string $method = 'GET', ?string $body = null): array
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => $body !== null ? "Content-Type: application/json\r\n" : '',
            'content' => $body ?? '',
            'ignore_errors' => true,
            'timeout' => 10,
        ]]);
        $raw = (string) file_get_contents($url, false, $context);
        /** @var list<string> $responseHeaders */
        $responseHeaders = $http_response_header ?? [];
        preg_match('#HTTP/\S+ (\d{3})#', $responseHeaders[0] ?? '', $status);
        $decoded = json_decode($raw, true);

        return [
            (int) ($status[1] ?? 0),
            array_map(static fn (string $h): string => strtolower(preg_replace('/;.*$/', '', $h) ?? $h), $responseHeaders),
            is_array($decoded) ? $decoded : [],
        ];
    }
}
