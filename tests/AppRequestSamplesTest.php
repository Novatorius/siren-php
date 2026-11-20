<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use GuzzleHttp\Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The PHP samples of section 5 of the design note
 * (design/sdk-answer-handler.md in the run folder), copied here as written,
 * each run on PHP's built-in web server. The test plays Siren: it signs each
 * kind's request from tests/Fixtures/apps-feature-contracts/requests.json
 * with a test secret and posts it, and a fake Siren
 * (tests/Support/fake-siren.php) takes the answer an ask posts back.
 *
 * Each runs from the README's own text, which must equal the copy here. Only
 * two things are added around a sample: a prepended file that stubs
 * `addToCrm()` and `noteShare()`, and the fake Siren's address in place of
 * `https://cloud.example.com/siren/v1`, which the feature page fills in. The
 * sample's own `require 'vendor/autoload.php';` loads the SDK, through a
 * `vendor` link beside it, as it would in an app.
 */
#[Group('local-server')]
final class AppRequestSamplesTest extends TestCase
{
    private const APP_KEY = 'sk_test_fake_app_key';

    /** How long the fake Siren holds an answer before writing it down. */
    private const ANSWER_DELAY_MS = 1500;

    /** Section 5.1, the whole PHP sample. */
    private const TELL_THE_CRM = <<<'PHP'
<?php
require 'vendor/autoload.php';
use Siren\Sdk\Siren;

$siren = new Siren(['apiKey' => getenv('SIREN_API_KEY'), 'baseUrl' => 'https://cloud.example.com/siren/v1']);

$siren->apps->requestHandler(getenv('SIREN_SIGNING_SECRET'))
    ->on('postConversionAction', 'tell-the-crm', function ($request) {
        // Siren may send it twice, so add it once, keyed by $request->id.
        addToCrm($request->id, $request->data['conversion'], $request->data['reward']);
    })
    ->serve();
PHP;

    /** Section 5.2. Only the function changes from 5.1. */
    private const NOTE_THE_SHARE = <<<'PHP'
    ->on('postAllocationAction', 'note-the-share', function ($request) {
        // Siren may send it twice, so note it once, keyed by $request->id.
        noteShare($request->id, $request->data['allocation']);
    })
PHP;

    /** Section 5.3. */
    private const SPLIT_BY_SCORE = <<<'PHP'
    ->on('distributionResolver', 'split-by-score', fn ($request) => [
        'outcome' => 'allocated',
        'units' => $request->data['pool']['units'],
        // Whole currency steps, never more than the pool, split evenly when every score is 0.
        'shares' => $siren->apps->splitPool($request->data['pool'], array_column($request->data['metrics'], 'score', 'metricId')),
    ])
PHP;

    /** Section 5.4. */
    private const SPLIT_THE_REWARD = <<<'PHP'
    ->on('incentiveResolver', 'split-the-reward', fn ($request) => [
        'outcome' => 'rewards',
        'units' => $request->data['pool']['units'],
        'rewards' => $siren->apps->splitPool($request->data['pool'], array_column($request->data['conversions'], 'score', 'conversionId')),
    ])
PHP;

    /** The incentive type's sample, the feature page's PHP tab. */
    private const PAY_ON_CLOSED_DEALS = <<<'PHP'
    ->on('incentiveType', 'pay-on-closed-deals', function ($request) {
        // A tenth of the sale, shared by every engagement. A lead earns nothing here.
        $sale = $request->data['transaction'];
        if (!$sale || $sale['units'] !== $request->data['program']['units']) {
            return ['outcome' => 'none'];
        }
        return ['outcome' => 'award', 'units' => $sale['units'], 'pool' => intdiv($sale['total'], 10), 'engagementIds' => array_column($request->data['engagements'], 'engagementId')];
    })
PHP;

    /** The stubs for the sample's own helpers, prepended to every request. */
    private const PRELUDE = <<<'PHP'
<?php
function addToCrm(...$args): void
{
    file_put_contents(getenv('STUB_LOG'), json_encode(['addToCrm', $args]) . "\n", FILE_APPEND | LOCK_EX);
}

function noteShare(...$args): void
{
    file_put_contents(getenv('STUB_LOG'), json_encode(['noteShare', $args]) . "\n", FILE_APPEND | LOCK_EX);
}
PHP;

    /** The same handler mounted as PSR-15, as a Slim app would, to run the shutdown path. */
    private const PSR15 = <<<'PHP'
<?php
require 'vendor/autoload.php';
use Siren\Sdk\Siren;

$siren = new Siren(['apiKey' => getenv('SIREN_API_KEY'), 'baseUrl' => 'https://cloud.example.com/siren/v1']);

$handler = $siren->apps->requestHandler(getenv('SIREN_SIGNING_SECRET'))
    ->on('distributionResolver', 'split-by-score', fn ($request) => [
        'outcome' => 'allocated',
        'units' => $request->data['pool']['units'],
        'shares' => $siren->apps->splitPool($request->data['pool'], array_column($request->data['metrics'], 'score', 'metricId')),
    ])
    ->psr15();

$response = $handler->handle(\GuzzleHttp\Psr7\ServerRequest::fromGlobals());
http_response_code($response->getStatusCode());

foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header($name . ': ' . $value, false);
    }
}

echo $response->getBody();
PHP;

    /** @var list<resource> */
    private static array $servers = [];

    private static string $dir = '';
    private static string $sirenLog = '';
    private static string $stubLog = '';
    private static string $app = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/siren-samples-' . bin2hex(random_bytes(4));
        mkdir(self::$dir . '/app', 0777, true);
        self::$sirenLog = self::$dir . '/siren.log';
        self::$stubLog = self::$dir . '/stub.log';
        touch(self::$sirenLog);
        touch(self::$stubLog);

        $siren = 'http://127.0.0.1:' . self::serve(['-S', '127.0.0.1:%d', __DIR__ . '/Support/fake-siren.php'], [
            'FAKE_SIREN_LOG' => self::$sirenLog,
            'FAKE_SIREN_ANSWER_DELAY_MS' => (string) self::ANSWER_DELAY_MS,
        ]) . '/siren/v1';

        $samples = [
            'tell-the-crm' => self::readme('answer-tell'),
            'note-the-share' => self::sample(self::readme('answer-allocation')),
            'split-by-score' => self::sample(self::readme('answer-distribution')),
            'split-the-reward' => self::sample(self::readme('answer-incentive')),
            'pay-on-closed-deals' => self::sample(self::readme('answer-award')),
            'psr15' => self::PSR15,
        ];

        foreach ($samples as $name => $code) {
            file_put_contents(self::$dir . '/app/' . $name . '.php', str_replace('https://cloud.example.com/siren/v1', $siren, $code));
        }

        file_put_contents(self::$dir . '/prelude.php', self::PRELUDE);
        symlink(dirname(__DIR__) . '/vendor', self::$dir . '/app/vendor');

        self::$app = 'http://127.0.0.1:' . self::serve(
            ['-d', 'auto_prepend_file=' . self::$dir . '/prelude.php', '-S', '127.0.0.1:%d', '-t', self::$dir . '/app'],
            ['SIREN_API_KEY' => self::APP_KEY, 'SIREN_SIGNING_SECRET' => self::secret(), 'STUB_LOG' => self::$stubLog],
        );
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$servers as $server) {
            proc_terminate($server);
            proc_close($server);
        }

        self::$servers = [];

        foreach (['/app/*.php', '/*.php', '/*.log'] as $pattern) {
            foreach (glob(self::$dir . $pattern) ?: [] as $file) {
                unlink($file);
            }
        }

        @unlink(self::$dir . '/app/vendor');
        @rmdir(self::$dir . '/app');
        @rmdir(self::$dir);
    }

    protected function setUp(): void
    {
        file_put_contents(self::$sirenLog, '');
        file_put_contents(self::$stubLog, '');
    }

    /** A sample from the README, by its readme-test name. */
    private static function readme(string $name): string
    {
        $found = preg_match('/<!-- readme-test: ' . preg_quote($name, '/') . ' -->\n```php\n(.*?)\n```/s', (string) file_get_contents(dirname(__DIR__) . '/README.md'), $matches);
        self::assertSame(1, $found, 'The README has no sample named ' . $name);

        return $matches[1];
    }

    /** 5.1 with its function swapped for another section's, as the note says only the function changes. */
    private static function sample(string $function): string
    {
        $tell = <<<'PHP'
    ->on('postConversionAction', 'tell-the-crm', function ($request) {
        // Siren may send it twice, so add it once, keyed by $request->id.
        addToCrm($request->id, $request->data['conversion'], $request->data['reward']);
    })
PHP;
        $whole = self::readme('answer-tell');
        self::assertStringContainsString($tell, $whole);

        return str_replace($tell, $function, $whole);
    }

    private static function secret(): string
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/apps-exchange/signed-request.json'), true, 512, JSON_THROW_ON_ERROR);

        return $fixture['secret'];
    }

    /** @return array<string, mixed> */
    private static function contract(string $kind): array
    {
        $contracts = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/apps-feature-contracts/requests.json'), true, 512, JSON_THROW_ON_ERROR);

        return $contracts['kinds'][$kind];
    }

    /**
     * Signs the kind's request now, as Siren's WebhookSignature::headers() does, and posts it.
     *
     * @return array{0: int, 1: string, 2: float}
     */
    private static function send(string $file, string $kind): array
    {
        $body = json_encode(self::contract($kind)['request'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $t = time();
        $started = microtime(true);

        $response = (new Client(['http_errors' => false, 'timeout' => 10]))->post(self::$app . '/' . $file . '.php', [
            'body' => $body,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Siren-Event' => 'app.request',
                'X-Siren-Delivery' => 'apr_9c60190a4501399d0ae55f041c237b39.1',
                'User-Agent' => 'Siren-Webhooks/1.0',
                'X-Siren-Timestamp' => (string) $t,
                'X-Siren-Signature-V2' => 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, self::secret()),
                'Idempotency-Key' => 'apr_9c60190a4501399d0ae55f041c237b39',
            ],
        ]);

        return [$response->getStatusCode(), (string) $response->getBody(), microtime(true) - $started];
    }

    /** @return list<array<string, mixed>> */
    private static function lines(string $file): array
    {
        return array_values(array_map(
            static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_filter(explode("\n", (string) file_get_contents($file))),
        ));
    }

    /** @return array<string, mixed>|null The answer the fake Siren took, once it lands. */
    private static function awaitAnswer(float $seconds): ?array
    {
        $until = microtime(true) + $seconds;

        do {
            foreach (self::lines(self::$sirenLog) as $line) {
                if ($line['path'] === '/siren/v1/apps/requests/apr_9c60190a4501399d0ae55f041c237b39/answer') {
                    return $line;
                }
            }

            usleep(50_000);
        } while (microtime(true) < $until);

        return null;
    }

    public function testTheTellToTheCrmGets200AfterItsFunctionRan(): void
    {
        $request = self::contract('postConversionAction')['request'];

        [$status, $body] = self::send('tell-the-crm', 'postConversionAction');

        $this->assertSame([200, '{}'], [$status, $body]);
        $this->assertSame([['addToCrm', [$request['id'], $request['data']['conversion'], $request['data']['reward']]]], self::lines(self::$stubLog));
        $this->assertSame([], self::lines(self::$sirenLog), 'A tell posts nothing to Siren.');
    }

    public function testTheTellToNoteAShareGets200AfterItsFunctionRan(): void
    {
        $request = self::contract('postAllocationAction')['request'];

        [$status, $body] = self::send('note-the-share', 'postAllocationAction');

        $this->assertSame([200, '{}'], [$status, $body]);
        $this->assertSame([['noteShare', [$request['id'], $request['data']['allocation']]]], self::lines(self::$stubLog));
        $this->assertSame([], self::lines(self::$sirenLog));
    }

    /** @return array<string, array{string, string}> */
    public static function asks(): array
    {
        return [
            'distribution resolver, section 5.3' => ['split-by-score', 'distributionResolver'],
            'incentive resolver, section 5.4' => ['split-the-reward', 'incentiveResolver'],
            'incentive type' => ['pay-on-closed-deals', 'incentiveType'],
            'the distribution resolver mounted as PSR-15' => ['psr15', 'distributionResolver'],
        ];
    }

    #[DataProvider('asks')]
    public function testAnAskGets200AtOnceAndSirenGetsTheFixturesAnswer(string $file, string $kind): void
    {
        [$status, $body, $took] = self::send($file, $kind);

        $this->assertSame([200, '{}'], [$status, $body]);
        $this->assertLessThan(self::ANSWER_DELAY_MS / 1000, $took, 'The reply came before the answer was posted.');
        $this->assertSame([], self::lines(self::$sirenLog), 'Siren had no answer yet when the reply came.');

        $answer = self::awaitAnswer(10.0);

        $this->assertNotNull($answer, 'The fake Siren got no answer.');
        $this->assertSame('POST', $answer['method']);
        $this->assertSame('Bearer ' . self::APP_KEY, $answer['authorization']);
        $this->assertSame(self::contract($kind)['answer'], $answer['body']);
    }

    public function testTheReadmeLeadsWithTheSamplesAsWritten(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');
        $samples = [
            'answer-tell' => self::TELL_THE_CRM,
            'answer-allocation' => self::NOTE_THE_SHARE,
            'answer-distribution' => self::SPLIT_BY_SCORE,
            'answer-incentive' => self::SPLIT_THE_REWARD,
            'answer-award' => self::PAY_ON_CLOSED_DEALS,
        ];
        $at = [];

        foreach ($samples as $name => $code) {
            $this->assertStringContainsString("<!-- readme-test: {$name} -->\n```php\n{$code}\n```", $readme, $name);
            $at[] = strpos($readme, "<!-- readme-test: {$name} -->");
        }

        $section = strpos($readme, '### Answer Siren in a few lines');
        $this->assertNotFalse($section);
        $this->assertSame($at, array_values(array_filter($at, static fn($offset): bool => $offset > $section)), 'The samples sit in the section.');
        $this->assertLessThan(strpos($readme, '#### What the handler replies'), max($at), 'The samples lead the section.');
        $this->assertGreaterThan(strpos($readme, '### Check and answer by hand'), strpos($readme, "\$request = \$siren->apps->verifyRequest("), 'verifyRequest is under the low-level path.');
    }

    public function testABodyOverOneMebibyteGets413(): void
    {
        $response = (new Client(['http_errors' => false, 'timeout' => 10]))->post(self::$app . '/tell-the-crm.php', [
            'body' => str_repeat('a', 1024 * 1024 + 1),
            'headers' => ['Content-Type' => 'application/json', 'X-Siren-Event' => 'app.request'],
        ]);

        $this->assertSame(413, $response->getStatusCode());
        $this->assertSame([], self::lines(self::$stubLog));
    }

    public function testAnUnsignedRequestGets401AndRunsNothing(): void
    {
        $response = (new Client(['http_errors' => false, 'timeout' => 10]))->post(self::$app . '/tell-the-crm.php', [
            'body' => json_encode(self::contract('postConversionAction')['request'], JSON_THROW_ON_ERROR),
            'headers' => ['Content-Type' => 'application/json', 'X-Siren-Event' => 'app.request'],
        ]);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertSame([], self::lines(self::$stubLog));
    }

    /**
     * @param list<string>          $arguments PHP's arguments, with `%d` where the port goes.
     * @param array<string, string> $env
     */
    private static function serve(array $arguments, array $env): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr($address, strrpos($address, ':') + 1);

        $command = [PHP_BINARY, ...array_map(static fn(string $argument): string => sprintf($argument, $port), $arguments)];
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
}
