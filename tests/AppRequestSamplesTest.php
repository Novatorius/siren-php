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
 * `addToCrm()` and `noteShare()`, and the fake Siren's address as the
 * client's `baseUrl` in place of Siren Cloud's default, since `new Siren()`
 * becomes `new Siren(['baseUrl' => <the fake Siren>])`. The key and the
 * secret come from SIREN_API_KEY and SIREN_SIGNING_SECRET, as in an app. The
 * sample's own `require 'vendor/autoload.php';` loads the SDK, through a
 * `vendor` link beside it, as it would in an app. The requests are signed by
 * the SDK's own `Siren\Sdk\Testing\AppRequests::make()`.
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

$siren = new Siren();         // the app's key, from SIREN_API_KEY

$siren->apps->requestHandler() // the signing secret, from SIREN_SIGNING_SECRET
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

    /** The program group structure's sample, the feature page's PHP tab. */
    private const PICK_THE_PROGRAM = <<<'PHP'
    ->on('programGroupSorter', 'pick-the-program', function ($request) {
        // Try the program whose best engagement scores highest first.
        $best = fn ($program) => max([0, ...array_column($program['engagements'], 'score')]);
        $programs = $request->data['programs'];
        usort($programs, fn ($a, $b) => $best($b) <=> $best($a));
        return ['outcome' => 'sorted', 'programIds' => array_column($programs, 'id')];
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

function isRefunded(...$args): bool
{
    file_put_contents(getenv('STUB_LOG'), json_encode(['isRefunded', $args]) . "\n", FILE_APPEND | LOCK_EX);

    return true;
}

function isStaff(...$args): bool
{
    file_put_contents(getenv('STUB_LOG'), json_encode(['isStaff', $args]) . "\n", FILE_APPEND | LOCK_EX);

    return true;
}

function payOnce(...$args): array
{
    file_put_contents(getenv('STUB_LOG'), json_encode(['payOnce', $args]) . "\n", FILE_APPEND | LOCK_EX);

    return ['ok' => true, 'id' => 'acme-ledger-5521'];
}
PHP;

    /** The same handler mounted as PSR-15, as a Slim app would, to run the shutdown path. */
    private const PSR15 = <<<'PHP'
<?php
require 'vendor/autoload.php';
use Siren\Sdk\Siren;

$siren = new Siren();

$handler = $siren->apps->requestHandler()
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
    private static string $sirenUrl = '';

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
            'pick-the-program' => self::sample(self::readme('answer-group-order')),
            'visit-score' => self::sample(self::readme('answer-metric-calculation')),
            'refund-check' => self::sample(self::readme('answer-metric-filter')),
            'plan-lines' => self::sample(self::readme('answer-transaction-filter')),
            'visit-weight' => self::sample(self::readme('answer-engagement-calculation')),
            'staff-check' => self::sample(self::readme('answer-engagement-filter')),
            'team-tree' => self::sample(self::readme('answer-group-structure')),
            'acme-ledger' => self::sample(self::readme('answer-payout')),
            'by-hand' => self::readme('by-hand'),
            'psr15' => self::PSR15,
        ];

        foreach ($samples as $name => $code) {
            self::assertSame(1, substr_count($code, 'new Siren()'), $name . ' makes its client with new Siren().');
            file_put_contents(self::$dir . '/app/' . $name . '.php', str_replace('new Siren()', "new Siren(['baseUrl' => '{$siren}'])", $code));
        }

        file_put_contents(self::$dir . '/prelude.php', self::PRELUDE);
        symlink(dirname(__DIR__) . '/vendor', self::$dir . '/app/vendor');

        self::$app = 'http://127.0.0.1:' . self::serve(
            ['-d', 'auto_prepend_file=' . self::$dir . '/prelude.php', '-S', '127.0.0.1:%d', '-t', self::$dir . '/app'],
            ['SIREN_API_KEY' => self::APP_KEY, 'SIREN_SIGNING_SECRET' => self::secret(), 'STUB_LOG' => self::$stubLog],
        );
        self::$sirenUrl = $siren;
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
     * Signs the kind's request now with the SDK's testing helper, its
     * answerUrl on the fake Siren, and posts it.
     *
     * @return array{0: int, 1: string, 2: float}
     */
    private static function send(string $file, string $kind, bool $test = false): array
    {
        $request = self::signedContract($kind, self::secret(), self::$sirenUrl, $test);
        $started = microtime(true);

        $response = (new Client(['http_errors' => false, 'timeout' => 10]))->request($request->method, self::$app . '/' . $file . '.php', [
            'body' => $request->rawBody,
            'headers' => $request->headers + ['User-Agent' => 'Siren-Webhooks/1.0'],
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

    /**
     * Each ask's sample, by its file and kind. The answer Siren must get is
     * the fixture's, but for the two calculations, whose samples score only
     * the collaborator who triggered it where the fixture's answer scores two.
     *
     * @return array<string, array{0: string, 1: string, 2?: array<string, mixed>}>
     */
    public static function asks(): array
    {
        return [
            'distribution resolver, section 5.3' => ['split-by-score', 'distributionResolver'],
            'incentive resolver, section 5.4' => ['split-the-reward', 'incentiveResolver'],
            'incentive type' => ['pay-on-closed-deals', 'incentiveType'],
            'program group structure' => ['pick-the-program', 'programGroupSorter'],
            'metric calculation, a visit scores 1' => ['visit-score', 'metricCalculation', ['outcome' => 'scores', 'results' => [['collaboratorId' => 42, 'score' => 1]]]],
            'metric filter' => ['refund-check', 'metricFilter'],
            'transaction filter' => ['plan-lines', 'transactionFilter'],
            'engagement calculation, a known customer scores 10' => ['visit-weight', 'engagementCalculation', ['outcome' => 'engagements', 'results' => [['collaboratorId' => 42, 'score' => 10]]]],
            'engagement filter' => ['staff-check', 'engagementFilter'],
            'collaborator group structure' => ['team-tree', 'collaboratorGroupStructure'],
            'payout method' => ['acme-ledger', 'payoutMethod'],
            'the distribution resolver mounted as PSR-15' => ['psr15', 'distributionResolver'],
        ];
    }

    /** @param array<string, mixed>|null $expected */
    #[DataProvider('asks')]
    public function testAnAskGets200AtOnceAndSirenGetsTheFixturesAnswer(string $file, string $kind, ?array $expected = null): void
    {
        [$status, $body, $took] = self::send($file, $kind);

        $this->assertSame([200, '{}'], [$status, $body]);
        $this->assertLessThan(self::ANSWER_DELAY_MS / 1000, $took, 'The reply came before the answer was posted.');
        $this->assertSame([], self::lines(self::$sirenLog), 'Siren had no answer yet when the reply came.');

        $answer = self::awaitAnswer(10.0);

        $this->assertNotNull($answer, 'The fake Siren got no answer.');
        $this->assertSame('POST', $answer['method']);
        $this->assertSame('Bearer ' . self::APP_KEY, $answer['authorization']);
        $this->assertSame($expected ?? self::contract($kind)['answer'], $answer['body']);

        $data = self::contract($kind)['request']['data'];
        $this->assertNull(\Siren\Sdk\AppAnswerCheck::problem($kind, $data, $answer['body']), 'Siren takes the sample\'s answer, by its own checks.');

        // The helpers a sample leaves to the app get the question's own values.
        $helpers = [
            'metricFilter' => [['isRefunded', [$data['sale']['transactionId'] ?? null]]],
            'engagementFilter' => [['isStaff', [$data['collaborator']['id'] ?? null, $data['opportunity']['customerId'] ?? null]]],
            'payoutMethod' => [['payOnce', [$data['payout']['id'] ?? null, $data['collaborator'] ?? null, $data['amount'] ?? null]]],
        ];
        $this->assertSame($helpers[$kind] ?? [], self::lines(self::$stubLog));
    }

    public function testThePayoutSampleAnswersATestRequestWithoutPaying(): void
    {
        [$status, $body] = self::send('acme-ledger', 'payoutMethod', true);

        $this->assertSame([200, '{}'], [$status, $body]);
        $answer = self::awaitAnswer(10.0);
        $this->assertNotNull($answer, 'The fake Siren got no answer.');
        $this->assertSame(['outcome' => 'failed', 'reason' => 'A test request, so nothing was paid.'], $answer['body']);
        $this->assertNull(\Siren\Sdk\AppAnswerCheck::problem('payoutMethod', self::contract('payoutMethod')['request']['data'], $answer['body']));
        $this->assertSame([], self::lines(self::$stubLog), 'A test request reaches no payment provider.');
    }

    public function testTheByHandSampleChecksTheRequestAndAnswersIt(): void
    {
        [$status] = self::send('by-hand', 'payoutMethod');

        $this->assertSame(200, $status);
        $answer = self::awaitAnswer(1.0);
        $this->assertNotNull($answer, 'The by-hand sample answered before it replied.');
        $this->assertSame('Bearer ' . self::APP_KEY, $answer['authorization']);
        $this->assertSame(['outcome' => 'paid', 'reference' => 'TX-1'], $answer['body']);
    }

    public function testTheByHandSampleRefusesAnUnsignedRequest(): void
    {
        $response = (new Client(['http_errors' => false, 'timeout' => 10]))->post(self::$app . '/by-hand.php', [
            'body' => json_encode(self::contract('payoutMethod')['request'], JSON_THROW_ON_ERROR),
            'headers' => ['Content-Type' => 'application/json', 'X-Siren-Event' => 'app.request'],
        ]);

        $this->assertSame(500, $response->getStatusCode(), 'verifyRequest() throws, so plain PHP answers 500.');
        $this->assertSame([], self::lines(self::$sirenLog));
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
            'answer-group-order' => self::PICK_THE_PROGRAM,
        ];
        $at = [];

        foreach ($samples as $name => $code) {
            $this->assertStringContainsString("<!-- readme-test: {$name} -->\n```php\n{$code}\n```", $readme, $name);
            $at[] = strpos($readme, "<!-- readme-test: {$name} -->");
        }

        $handler = strpos($readme, "\n## Answer Siren in a few lines\n");
        $kinds = strpos($readme, "\n## Feature kinds\n");
        $payout = strpos($readme, "\n## Payout method (`payoutMethod`)\n");
        $this->assertNotFalse($handler);
        $this->assertNotFalse($kinds);
        $this->assertNotFalse($payout);
        $this->assertLessThan(strpos($readme, '### What the handler replies'), $at[0], 'The whole sample leads the handler section.');
        $this->assertLessThan($handler, strpos($readme, "\n## Install\n"), 'Install comes before the first sample.');
        $this->assertStringNotContainsString('baseUrl', substr($readme, $at[0], strpos($readme, "\n## Try it without Siren\n") - $at[0]), 'The first sample and its words leave baseUrl out.');
        $this->assertGreaterThan($handler, $at[0]);
        $this->assertLessThan(strpos($readme, "\n## Quickstart\n"), $handler, 'The handler comes before the quickstart.');

        foreach (['answer-allocation', 'answer-distribution', 'answer-incentive', 'answer-award', 'answer-group-order', 'answer-metric-calculation', 'answer-metric-filter', 'answer-transaction-filter', 'answer-engagement-calculation', 'answer-engagement-filter', 'answer-group-structure'] as $name) {
            $offset = strpos($readme, "<!-- readme-test: {$name} -->");
            $this->assertNotFalse($offset, $name);
            $this->assertTrue($offset > $kinds && $offset < $payout, $name . ' sits under Feature kinds.');
        }

        $this->assertGreaterThan($payout, strpos($readme, '<!-- readme-test: answer-payout -->'));
        $this->assertGreaterThan(strpos($readme, "\n## Check and answer by hand\n"), strpos($readme, "\$request = \$siren->apps->verifyRequest("), 'verifyRequest is under the low-level path.');
    }

    public function testTheTryItWithoutSirenSamplePassesUnderPhpUnit(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');
        $first = strpos($readme, '<!-- readme-test: answer-tell -->');
        $try = strpos($readme, "\n## Try it without Siren\n");
        $this->assertNotFalse($try);
        $this->assertGreaterThan($first, $try, 'Try it without Siren follows the first sample.');
        $this->assertLessThan(strpos($readme, "\n## Feature kinds\n"), $try);
        $this->assertSame($try, strpos($readme, "\n## ", $first), 'No section sits between the first sample and Try it without Siren.');

        $file = self::$dir . '/SplitByScoreTest.php';
        file_put_contents($file, self::readme('try-it'));

        $command = [PHP_BINARY, dirname(__DIR__) . '/vendor/bin/phpunit', '--no-configuration', '--bootstrap', dirname(__DIR__) . '/vendor/autoload.php', $file];
        $env = getenv();
        unset($env['SIREN_API_KEY'], $env['SIREN_SIGNING_SECRET']);
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, self::$dir, $env);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $code = proc_close($process);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('OK (1 test, 2 assertions)', $out);
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
