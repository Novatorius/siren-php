<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * The SDK inside a WordPress-shaped host with no Guzzle and no Composer
 * autoloader: the transport wraps wp_remote_request(), as the README shows,
 * and the new connector calls run end to end through it.
 *
 * The host runs in its own PHP process, so the Guzzle this repository
 * installs for its own tests cannot leak in.
 */
final class WordPressHostTest extends BaseTestCase
{
    /** @var array<string, mixed> */
    private static array $report;

    public static function setUpBeforeClass(): void
    {
        $command = [PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/Support/wordpress-host.php'];
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);

        if (!is_resource($process)) {
            self::fail('Could not start the host process.');
        }

        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        self::assertSame(0, $status, "The host process failed:\n" . $err . $out);

        $report = json_decode($out, true);
        self::assertIsArray($report, "The host printed no report:\n" . $err . $out);
        self::$report = $report;
    }

    public function testNoGuzzleAndNoComposerLoaded(): void
    {
        $this->assertSame([], self::$report['guzzleLoaded']);
        $this->assertFalse(self::$report['composerLoaded']);
        $this->assertSame('Siren\\Sdk\\Http\\StreamHttpClient', self::$report['fallbackClient']);
    }

    public function testReadsGoThroughWpRemoteRequestWithTheQueryInTheUrl(): void
    {
        $call = self::$report['calls'][0];

        $this->assertSame('https://cloud.example.test/siren/v1/collaborator-aliases?code=SAVE10&type=coupon&number=1', $call['url']);
        $this->assertSame('GET', $call['args']['method']);
        $this->assertNull($call['args']['body']);
        $this->assertSame(3, $call['args']['timeout']);
        $this->assertSame('Bearer first.access.token', $call['args']['headers']['Authorization']);
        $this->assertSame(['code' => 'SAVE10', 'type' => 'coupon', 'collaboratorId' => 4, 'propertyId' => 5], self::$report['alias']);
    }

    public function testAHoldRefusalArrivesAsItsTypedReason(): void
    {
        $call = self::$report['calls'][1];

        $this->assertSame('POST', $call['args']['method']);
        $this->assertSame('application/json', $call['args']['headers']['Content-Type']);
        $this->assertSame(
            ['collaboratorId' => 4, 'currency' => 'STORE_CREDIT', 'amount' => 1000, 'token' => '0b6f3c2e-8a1d-4c55-9a0e-0d6c1b7f4e21', 'platformId' => 'woocommerce', 'ttl' => 600],
            json_decode($call['args']['body'], true),
        );
        $this->assertSame('no_credit', self::$report['hold']);
    }

    public function testAnExpiredTokenIsRefreshedAndTheWriteRepeatedOnce(): void
    {
        $this->assertSame('Bearer first.access.token', self::$report['calls'][2]['args']['headers']['Authorization']);
        $this->assertSame('Bearer second.access.token', self::$report['calls'][3]['args']['headers']['Authorization']);
        $this->assertSame(self::$report['calls'][2]['args']['body'], self::$report['calls'][3]['args']['body']);
        $this->assertSame('wordpress_user', self::$report['mapping']['externalType']);
    }

    public function testRepeatedHeadersFromWordPressAreRead(): void
    {
        $this->assertSame(3, self::$report['enrollmentTotal']);
    }

    public function testAWpErrorBecomesAConnectionException(): void
    {
        $this->assertStringContainsString('cURL error 28: Operation timed out', self::$report['timeout']);
        $this->assertCount(6, self::$report['calls'], 'With retries off, a failed read is not repeated.');
    }
}
