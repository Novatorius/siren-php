<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use PHPUnit\Framework\Attributes\Group;
use Siren\Sdk\Exception\BadRequestException;
use Siren\Sdk\Http\HttpClientException;
use Siren\Sdk\Http\HttpRequest;
use Siren\Sdk\Http\StreamHttpClient;
use Siren\Sdk\Siren;

/**
 * The dependency-free client the SDK falls back to when Guzzle is absent.
 *
 * These tests talk to PHP's built-in web server on 127.0.0.1, started for
 * this class only. Nothing leaves the machine.
 */
#[Group('local-server')]
final class StreamHttpClientTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static string $baseUrl = '';

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');

        if ($socket === false) {
            self::markTestSkipped('Could not reserve a local port.');
        }

        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($address, strrpos($address, ':') + 1);

        $command = sprintf(
            '%s -S 127.0.0.1:%d %s',
            escapeshellarg(PHP_BINARY),
            $port,
            escapeshellarg(__DIR__ . '/Support/echo-server.php'),
        );
        $process = proc_open($command, [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);

        if (!is_resource($process)) {
            self::markTestSkipped('Could not start the PHP built-in server.');
        }

        self::$server = $process;
        self::$baseUrl = 'http://127.0.0.1:' . $port . '/siren/v1';

        for ($i = 0; $i < 100; $i++) {
            $probe = @fsockopen('127.0.0.1', $port);

            if ($probe !== false) {
                fclose($probe);

                return;
            }

            usleep(20_000);
        }

        self::markTestSkipped('The PHP built-in server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }

        self::$server = null;
    }

    public function testSendsMethodHeadersAndBody(): void
    {
        $response = (new StreamHttpClient())->send(new HttpRequest(
            'PUT',
            self::$baseUrl . '/echo?fields=id%2Cname',
            ['Authorization' => 'Bearer t0k3n', 'Content-Type' => 'application/json'],
            '{"name":"Partners"}',
            5.0,
        ));

        $this->assertSame(200, $response->getStatusCode());
        $echo = json_decode($response->getBody(), true);
        $this->assertSame('PUT', $echo['method']);
        $this->assertSame('/siren/v1/echo', $echo['path']);
        $this->assertSame('fields=id%2Cname', $echo['query']);
        $this->assertSame('Bearer t0k3n', $echo['headers']['authorization']);
        $this->assertSame('application/json', $echo['headers']['content-type']);
        $this->assertSame('{"name":"Partners"}', $echo['body']);
        $this->assertSame('404040', $response->getHeaderLine('x-siren-oid'));
        $this->assertSame(['one', 'two'], $response->getHeaders()['X-Repeated']);
    }

    public function testReturnsErrorStatusesAsResponses(): void
    {
        $response = (new StreamHttpClient())->send(new HttpRequest('GET', self::$baseUrl . '/status/422'));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['error' => 'status 422'], json_decode($response->getBody(), true));
    }

    public function testFollowsARedirectAndReportsTheFinalResponse(): void
    {
        $response = (new StreamHttpClient())->send(new HttpRequest('GET', self::$baseUrl . '/redirect'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('/siren/v1/echo', json_decode($response->getBody(), true)['path']);
    }

    public function testARefusedConnectionThrowsHttpClientException(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($socket);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $this->expectException(HttpClientException::class);

        (new StreamHttpClient())->send(new HttpRequest('GET', 'http://' . $address . '/nothing-here', [], null, 2.0));
    }

    public function testTheClientRunsTheWholeSdkWithoutGuzzle(): void
    {
        $siren = new Siren([
            'apiKey' => self::API_KEY,
            'baseUrl' => self::$baseUrl,
            'httpClient' => new StreamHttpClient(),
        ]);

        $result = $siren->events->ingest('lead', ['email' => 'lead@example.test']);

        $this->assertSame(404040, $result->getOpportunityId());
    }

    public function testErrorStatusesStillMapToTypedExceptions(): void
    {
        $siren = new Siren([
            'apiKey' => self::API_KEY,
            'baseUrl' => self::$baseUrl . '/status/400',
            'httpClient' => new StreamHttpClient(),
        ]);

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('status 400');

        $siren->collaborators->list();
    }
}
