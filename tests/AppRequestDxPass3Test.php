<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Siren\Sdk\AppRequestHandler;
use Siren\Sdk\AppRequestSimulation;
use Siren\Sdk\Exception\AppHandlerException;
use Siren\Sdk\Exception\SirenException;
use Siren\Sdk\FeatureKind;
use Siren\Sdk\Testing\AppRequests;
use Siren\Sdk\Testing\SignedRequest;

/**
 * DX pass 3, types and discoverability (review/sdk-dx/pass-3-review.md and
 * pass-3-builder-spec.md in the run folder): each type is named for its
 * kind, the function `on()` registers gets its kind's question and answer
 * under PHPStan, the types match what Siren builds, the handler's surface
 * matches the Node and Python SDKs, and apps->features and the request
 * routes are here as in Node.
 */
final class AppRequestDxPass3Test extends TestCase
{
    private const SECRET = 'whsec_dx_pass_three_test_secret';

    /** The per-kind type names, the same in all three SDKs (pass-3-builder-spec.md, finding 4). */
    private const TYPE_NAMES = [
        'postConversionAction' => ['PostConversionActionTell'],
        'postAllocationAction' => ['PostAllocationActionTell'],
        'distributionResolver' => ['DistributionResolverQuestion', 'DistributionResolverAnswer'],
        'incentiveResolver' => ['IncentiveResolverQuestion', 'IncentiveResolverAnswer'],
        'incentiveType' => ['IncentiveTypeQuestion', 'IncentiveTypeAnswer'],
        'metricCalculation' => ['MetricCalculationQuestion', 'MetricCalculationAnswer'],
        'metricFilter' => ['MetricFilterQuestion', 'MetricFilterAnswer'],
        'transactionFilter' => ['TransactionFilterQuestion', 'TransactionFilterAnswer'],
        'engagementCalculation' => ['EngagementCalculationQuestion', 'EngagementCalculationAnswer'],
        'engagementFilter' => ['EngagementFilterQuestion', 'EngagementFilterAnswer'],
        'programGroupSorter' => ['ProgramGroupSorterQuestion', 'ProgramGroupSorterAnswer'],
        'collaboratorGroupStructure' => [
            'CollaboratorGroupStructureQuestion',
            'CollaboratorGroupStructureAnswer',
            'CollaboratorGroupStructureDistributorQuestion',
            'CollaboratorGroupStructureProgramQuestion',
            'CollaboratorGroupStructureChainAnswer',
            'CollaboratorGroupStructureTreeAnswer',
        ],
        'payoutMethod' => ['PayoutMethodQuestion', 'PayoutMethodAnswer', 'PayoutMethodPaidAnswer', 'PayoutMethodFailedAnswer'],
    ];

    /** The names pass 3 replaced, none of which may remain. */
    private const OLD_TYPE_NAMES = [
        'AppConversionTell', 'AppAllocationTell', 'AppDistributionQuestion', 'AppDistributionAnswer', 'AppRewardQuestion',
        'AppRewardAnswer', 'AppIncentiveTypeEngagement', 'AppIncentiveTypeQuestion', 'AppIncentiveTypeAwardAnswer',
        'AppIncentiveTypeNoneAnswer', 'AppIncentiveTypeAnswer', 'AppPayoutQuestion', 'AppPayoutPaidAnswer', 'AppPayoutFailedAnswer',
        'AppPayoutAnswer', 'AppMetricCalculationQuestion', 'AppMetricCalculationAnswer', 'AppMetricFilterQuestion',
        'AppMetricFilterAnswer', 'AppEngagementCalculationQuestion', 'AppEngagementCalculationAnswer', 'AppEngagementFilterQuestion',
        'AppEngagementFilterAnswer', 'AppGroupOrderQuestion', 'AppGroupOrderAnswer', 'AppTransactionFilterQuestion',
        'AppTransactionFilterAnswer', 'AppCollaboratorGroupStructureQuestion', 'AppCollaboratorGroupStructureDistributorQuestion',
        'AppCollaboratorGroupStructureProgramQuestion', 'AppCollaboratorGroupStructureAnswer',
    ];

    /** @var list<array{0: \Throwable, 1: object|null}> */
    private array $errors = [];

    /**
     * @param list<\Psr\Http\Message\ResponseInterface|\Throwable> $responses
     * @param array<string, mixed> $options
     */
    private function handler(array $responses = [], array $options = []): AppRequestHandler
    {
        $this->errors = [];

        return $this->mockSiren($responses)->apps->requestHandler(self::SECRET, $options + ['onError' => function (\Throwable $error, ?object $about): void {
            $this->errors[] = [$error, $about];
        }]);
    }

    /** @param array<string, mixed> $data */
    private static function request(string $kind, string $key = 'the-key', ?array $data = null): SignedRequest
    {
        $options = ['secret' => self::SECRET];

        if ($data !== null) {
            $options['data'] = $data;
        }

        return AppRequests::make($kind, $key, $options);
    }

    private static function featureKindDocs(): string
    {
        return (string) (new \ReflectionClass(FeatureKind::class))->getDocComment();
    }

    /** @return array<string, mixed> */
    private static function contracts(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/Fixtures/apps-feature-contracts/requests.json'), true, 512, JSON_THROW_ON_ERROR)['kinds'];
    }

    // Finding 4: each type is named for its kind, the same in all three SDKs.

    public function testEachKindsTypesAreNamedForTheKind(): void
    {
        preg_match_all('/@phpstan-type (\w+)/', self::featureKindDocs(), $matches);
        $declared = $matches[1];

        foreach (self::TYPE_NAMES as $kind => $names) {
            foreach ($names as $name) {
                $this->assertContains($name, $declared, sprintf('FeatureKind names no %s for %s.', $name, $kind));
            }
        }

        $this->assertSame([], array_values(array_intersect(self::OLD_TYPE_NAMES, $declared)));
        $this->assertEqualsCanonicalizing(FeatureKind::SENT, array_keys(self::TYPE_NAMES));
    }

    public function testNoFileStillNamesAnOldType(): void
    {
        $root = dirname(__DIR__);
        $files = array_merge(glob($root . '/src/*.php') ?: [], glob($root . '/src/*/*.php') ?: [], [$root . '/README.md']);

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            foreach (self::OLD_TYPE_NAMES as $old) {
                // Siren's own classes keep their names, as in `lib/Apps/Service/Payouts/AppPayoutQuestion.php` or `AppGroupOrderQuestion::validate()`.
                $bare = preg_replace('#(lib/[\w/]*' . $old . '\.php|' . $old . '::\w+)#', '', $source);
                $this->assertStringNotContainsString('`' . $old . '`', (string) $bare, sprintf('%s still names %s.', basename($file), $old));
                $this->assertDoesNotMatchRegularExpression('/(@phpstan-import-type|@return|@param|@var) ' . $old . '\b/', (string) $bare, sprintf('%s still names %s.', basename($file), $old));
            }
        }
    }

    /** @return array<string, array{string}> */
    public static function allKinds(): array
    {
        $kinds = json_decode((string) file_get_contents(dirname(__DIR__) . '/resources/app-kinds.json'), true, 512, JSON_THROW_ON_ERROR);
        $rows = [];

        foreach ($kinds as $kind) {
            $rows[$kind['kind']] = [$kind['kind']];
        }

        return $rows;
    }

    #[DataProvider('allKinds')]
    public function testEachKindsHoverIsTheOneSentenceTheThreeSdksShare(string $kind): void
    {
        $entry = array_values(array_filter(FeatureKind::catalog(), static fn (array $k): bool => $k['kind'] === $kind))[0];
        $constant = strtoupper((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $kind));
        $docs = (string) (new \ReflectionClassConstant(FeatureKind::class, $constant))->getDocComment();
        $lines = array_map(static fn (string $line): string => (string) preg_replace('/^\s*\*\s?/', '', $line), explode("\n", $docs));
        $text = trim(implode("\n", array_slice($lines, 1, -1)));
        [$first, $second] = explode("\n\n", $text . "\n\n");

        $summary = strcasecmp($entry['term'], $entry['name']) === 0
            ? $entry['name'] . '.'
            : $entry['name'] . ". Siren's code calls it the " . $entry['term'] . '.';
        $mode = match ($entry['mode']) {
            'ask' => 'An ask: Siren waits for the app to answer.',
            'tell' => 'A tell: Siren lets the app know.',
            default => 'The app sends it: Siren sends nothing.',
        };

        $this->assertSame($summary, $first);
        $this->assertSame($entry['description'] . ' ' . $mode, str_replace("\n", ' ', $second));
    }

    // Finding 1: the function on() registers gets its kind's types under PHPStan.

    /**
     * Runs PHPStan at level max on one file, with this package's autoloader,
     * and answers its errors as line => messages.
     *
     * @return array<int, list<string>>
     */
    private static function phpstan(string $source): array
    {
        $dir = sys_get_temp_dir() . '/siren-php-phpstan-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $file = $dir . '/check.php';
        file_put_contents($file, $source);
        $root = dirname(__DIR__);
        $tmp = is_dir('/dev/shm') ? '/dev/shm/siren-php-phpstan-' . basename($dir) : $dir . '/tmp';
        file_put_contents($dir . '/phpstan.neon', "parameters:\n    level: max\n    tmpDir: {$tmp}\n    paths:\n        - {$file}\n    scanDirectories:\n        - {$root}/src\n");

        $command = sprintf(
            '%s %s analyse --no-progress --error-format=json --memory-limit=1G -c %s --autoload-file=%s 2>/dev/null',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($root . '/vendor/phpstan/phpstan/phpstan'),
            escapeshellarg($dir . '/phpstan.neon'),
            escapeshellarg($root . '/vendor/autoload.php'),
        );
        $output = (string) shell_exec($command);
        exec('rm -rf ' . escapeshellarg($dir) . ' ' . escapeshellarg($tmp));
        $report = json_decode($output, true);

        if (!is_array($report)) {
            throw new \RuntimeException('PHPStan gave no report: ' . $output);
        }

        $errors = [];

        foreach ($report['files'] ?? [] as $found) {
            foreach ($found['messages'] as $message) {
                $errors[$message['line']][] = $message['message'];
            }
        }

        $errors[0] = $report['errors'] ?? [];

        if ($errors[0] === []) {
            unset($errors[0]);
        }

        ksort($errors);

        return $errors;
    }

    /** The review's two typos, written in the README's closure style, and a misspelled kind. */
    private const TYPOS = <<<'PHP'
        <?php

        declare(strict_types=1);

        $siren = new \Siren\Sdk\Siren(['apiKey' => 'sk_test_x']);
        $answers = $siren->apps->requestHandler('whsec_x');

        $answers->on('distributionResolver', 'split-by-score', fn ($request) => [
            'outcome' => 'allocated',
            'units' => $request->data['pools']['units'],
            'shares' => [],
        ]);

        $answers->on('distributionResolver', 'even', fn ($request) => [
            'outcome' => 'allocate',
            'units' => $request->data['pool']['units'],
            'shares' => [],
        ]);

        $answers->on('distributionResolvr', fn ($request) => $answers->later());
        PHP;

    #[Group('phpstan')]
    public function testPhpstanCatchesTheTwoTyposInAClosureWithNoClassAndNoCast(): void
    {
        $errors = self::phpstan(self::TYPOS);

        $this->assertArrayHasKey(10, $errors, 'PHPStan missed data.pools: ' . json_encode($errors));
        $this->assertStringContainsString("'pools'", implode(' ', $errors[10]));
        $this->assertArrayHasKey(14, $errors, "PHPStan missed outcome 'allocate': " . json_encode($errors));
        $this->assertStringContainsString("'allocate'", implode(' ', $errors[14]));
        $this->assertArrayHasKey(20, $errors, 'PHPStan missed the misspelled kind: ' . json_encode($errors));
        $this->assertStringContainsString("'distributionResolvr'", implode(' ', $errors[20]));
    }

    #[Group('phpstan')]
    public function testPhpstanTakesTheSameHandlerSpelledRight(): void
    {
        $right = str_replace(["['pools']", "'allocate',", "'distributionResolvr'"], ["['pool']", "'allocated',", "'distributionResolver'"], self::TYPOS);

        $this->assertSame([], self::phpstan($right));
    }

    #[Group('phpstan')]
    public function testPhpstanTypesATellAndATwoArgumentOnByTheKind(): void
    {
        $errors = self::phpstan(<<<'PHP'
            <?php

            declare(strict_types=1);

            $answers = (new \Siren\Sdk\Siren(['apiKey' => 'sk_test_x']))->apps->requestHandler('whsec_x');

            $answers->on('postConversionAction', function ($request): void {
                \PHPStan\dumpType($request->data['reward']);
            });

            $answers->on('payoutMethod', fn ($request) => ['outcome' => 'paid', 'reference' => (string) $request->data['payout']['id']]);
            PHP);

        $this->assertCount(1, $errors, json_encode($errors) ?: '');
        $this->assertStringContainsString('array{value: int, units: string, currency:', $errors[8][0]);
        $this->assertStringContainsString('|null', $errors[8][0]);
    }

    // Finding 3: the types match what Siren builds, every branch included.

    /**
     * A file that returns Siren's own fixture request and answer for every
     * kind from a function typed with the kind's question and answer, plus
     * the branches the fixture lacks: a conversion with no reward
     * (AppActionSubjects.php, `$reward = null`), a sale with no lines
     * (AppTransactionFilterQuestion.php, `'currency' => $first === null ? null : ...`)
     * and an engagement type with no args, which Siren sends as `[]`
     * (AppEngagementQuestion::args()).
     */
    private static function fixtureCheck(): string
    {
        $contracts = self::contracts();
        $source = "<?php\n\ndeclare(strict_types=1);\n\n/**\n";
        // An import on a class only reaches that class, so each check is a static method of one.
        $imports = [];
        $functions = '';
        $n = 0;

        $add = static function (string $type, mixed $value) use (&$imports, &$functions, &$n): void {
            $imports[$type] = true;
            ++$n;
            $functions .= sprintf("    /** @param %s \$value */\n    public static function take_%d(array \$value): void\n    {\n    }\n\n", $type, $n);
            $functions .= sprintf("    public static function check_%d(): void\n    {\n        self::take_%d(%s);\n    }\n\n", $n, $n, var_export($value, true));
        };

        foreach (self::TYPE_NAMES as $kind => $names) {
            $entry = $contracts[$kind];
            $data = $entry['request']['data'];

            if ($kind === 'collaboratorGroupStructure') {
                $add(($data['for']['type'] ?? '') === 'program' ? 'CollaboratorGroupStructureProgramQuestion' : 'CollaboratorGroupStructureDistributorQuestion', $data);
            } else {
                $add($names[0], $data);
            }

            if (isset($entry['answer'])) {
                $add($names[1], $entry['answer']);
            }
        }

        $conversion = $contracts['postConversionAction']['request']['data'];
        $conversion['reward'] = null;
        $add('PostConversionActionTell', $conversion);

        $filter = $contracts['transactionFilter']['request']['data'];
        $filter['transaction']['currency'] = null;
        $filter['transaction']['units'] = '';
        $filter['lines'] = [];
        $add('TransactionFilterQuestion', $filter);

        $engagement = $contracts['engagementCalculation']['request']['data'];
        $engagement['args'] = [];
        $add('EngagementCalculationQuestion', $engagement);

        foreach (array_keys($imports) as $type) {
            $source .= " * @phpstan-import-type {$type} from \\Siren\\Sdk\\FeatureKind\n";
        }

        return $source . " */\nfinal class FixtureCheck\n{\n" . $functions . "}\n";
    }

    #[Group('phpstan')]
    public function testSirensFixtureAndItsMissingBranchesFitTheTypesUnderPhpstan(): void
    {
        $this->assertSame([], self::phpstan(self::fixtureCheck()));
    }

    public function testEmptyEngagementArgsReachTheFunctionAsAnEmptyArrayEitherWay(): void
    {
        $data = self::contracts()['engagementCalculation']['request']['data'];
        $seen = [];

        foreach (['[]' => [], '{}' => new \stdClass()] as $shape => $args) {
            $handler = $this->handler();
            $handler->on('engagementCalculation', function ($request) use (&$seen, $shape): array {
                $seen[$shape] = $request->data['args'];

                return ['outcome' => 'engagements', 'results' => []];
            });
            $request = self::request('engagementCalculation', 'the-key', ['args' => $args] + $data);

            $this->assertSame(200, $handler->simulate($request)->status);
        }

        $this->assertSame(['[]' => [], '{}' => []], $seen);
    }

    // Finding 5: one surface across the three SDKs.

    public function testSimulateAnswersTheSameFourFieldsAsNodeAndPython(): void
    {
        $properties = array_map(
            static fn (\ReflectionProperty $p): string => $p->getName(),
            (new \ReflectionClass(AppRequestSimulation::class))->getProperties(),
        );

        $this->assertSame(['status', 'body', 'answer', 'error'], $properties);

        $handler = $this->handler();
        $handler->on('distributionResolver', fn ($request) => [
            'outcome' => 'allocated',
            'units' => $request->data['pool']['units'],
            'shares' => \Siren\Sdk\Resources\Apps::splitPool($request->data['pool'], array_column($request->data['metrics'], 'score', 'metricId')),
        ]);
        $result = $handler->simulate(self::request('distributionResolver'));

        $this->assertSame([], $result->body);
        $this->assertNull($result->error);
        $this->assertSame('allocated', $result->answer['outcome'] ?? null);

        $missing = $this->handler()->simulate(self::request('distributionResolver'));
        $this->assertSame(['error' => 'No function handles distributionResolver the-key.'], $missing->body);
        $this->assertNull($missing->answer);
    }

    public function testSimulateAnswersNullAfterLaterAndBesideARefusedAnswer(): void
    {
        $handler = $this->handler();
        $handler->on('distributionResolver', 'later', fn ($request) => $handler->later());
        $handler->on('distributionResolver', 'refused', fn ($request) => ['outcome' => 'allocated', 'units' => 'EUR', 'shares' => []]);

        $later = $handler->simulate(self::request('distributionResolver', 'later'));
        $refused = $handler->simulate(self::request('distributionResolver', 'refused'));

        $this->assertNull($later->answer);
        $this->assertNull($later->error);
        $this->assertNull($refused->answer);
        $this->assertNotNull($refused->error);
    }

    /** @return array<string, array{\Closure(AppRequestHandler): mixed}> */
    public static function developerMistakes(): array
    {
        return [
            'an unknown kind' => [fn (AppRequestHandler $h) => $h->on('distributionResolvr', fn () => null)],
            'an empty kind' => [fn (AppRequestHandler $h) => $h->on('', fn () => null)],
            'a key and no function' => [fn (AppRequestHandler $h) => $h->on('distributionResolver', 'split')],
            'an empty key' => [fn (AppRequestHandler $h) => $h->on('distributionResolver', '', fn () => null)],
            'a second function for one feature' => [fn (AppRequestHandler $h) => $h->on('payoutMethod', 'a', fn () => null)->on('payoutMethod', 'a', fn () => null)],
            'a second function for a kind' => [fn (AppRequestHandler $h) => $h->on('payoutMethod', fn () => null)->on('payoutMethod', fn () => null)],
            'app.request on onEvent' => [fn (AppRequestHandler $h) => $h->onEvent('app.request', fn () => null)],
            'a second function for an event' => [fn (AppRequestHandler $h) => $h->onEvent('conversion.approved', fn () => null)->onEvent('conversion.approved', fn () => null)],
        ];
    }

    #[DataProvider('developerMistakes')]
    public function testEveryMistakeInTheDevelopersCodeIsAnAppHandlerException(\Closure $mistake): void
    {
        $this->expectException(AppHandlerException::class);

        $mistake($this->handler());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function badOptions(): array
    {
        return [
            'an option it lacks' => [['retries' => 3]],
            'a tolerance of 0' => [['toleranceSeconds' => 0]],
            'runLater that is not a function' => [['runLater' => 'later']],
            'onError that is not a function' => [['onError' => 'no such function']],
            'extraKinds that is not a list' => [['extraKinds' => 'payoutReview']],
        ];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('badOptions')]
    public function testABadOptionIsAnAppHandlerException(array $options): void
    {
        $this->expectException(AppHandlerException::class);

        $this->mockSiren([])->apps->requestHandler(self::SECRET, $options);
    }

    public function testAnAppHandlerExceptionIsASirenException(): void
    {
        $this->assertTrue(is_subclass_of(AppHandlerException::class, SirenException::class));
    }

    /** @return array<string, array{\Closure(AppRequestHandler): mixed, string}> */
    public static function badAnswers(): array
    {
        return [
            'nothing' => [fn () => null, 'returned nothing'],
            'a string' => [fn () => 'paid', 'returns the answer as an array'],
            'no outcome' => [fn () => ['reference' => 'TX-1'], 'needs a text outcome'],
            'a list' => [fn () => [['outcome' => 'paid']], 'takes the answer as an object'],
        ];
    }

    #[DataProvider('badAnswers')]
    public function testAFunctionsBadAnswerIsAnAppHandlerException(\Closure $fn, string $words): void
    {
        $handler = $this->handler();
        $handler->on('payoutMethod', $fn);

        $error = $handler->simulate(self::request('payoutMethod'))->error;

        $this->assertInstanceOf(AppHandlerException::class, $error);
        $this->assertStringContainsString($words, $error->getMessage());
    }

    public function testTheResendHintNamesTheCommandAloneSinceSirenCloudOffersSendAgainOnlyOnceTheAskNeedsAttention(): void
    {
        $handler = $this->mockSiren([])->apps->requestHandler(self::SECRET);
        $handler->on('distributionResolver', function (): never {
            throw new \RuntimeException('ledger down');
        });
        $request = self::request('distributionResolver');
        $id = json_decode($request->rawBody, true)['id'];

        $log = (string) tempnam(sys_get_temp_dir(), 'siren-log');
        $previous = ini_set('error_log', $log);

        try {
            $reply = $handler->answer($request->method, $request->rawBody, $request->headers);
            ($reply->after)();
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $logged = (string) file_get_contents($log);
        unlink($log);

        $this->assertStringContainsString(', when its answer window closes. Resend it sooner with `siren apps resend ' . $id . '`.', $logged);
        $this->assertStringNotContainsString('Needs attention', $logged);
    }

    // Parity: apps->features, and the request routes.

    public function testFeaturesCreateAddsOneFeatureToTheApp(): void
    {
        $feature = ['id' => 12, 'kind' => 'payoutMethod', 'key' => 'bank-transfer', 'name' => 'Bank transfer', 'uses' => 0, 'usedBy' => []];
        $siren = $this->mockSiren([self::jsonResponse(201, $feature)]);

        $made = $siren->apps->features->create('acme-payouts', ['kind' => 'payoutMethod', 'key' => 'bank-transfer', 'name' => 'Bank transfer', 'currencies' => ['USD']]);

        $this->assertSame($feature, $made);
        $this->assertSame('POST', $this->lastRequest()->getMethod());
        $this->assertSame('/siren/v1/apps/acme-payouts/features', $this->lastRequest()->getUri()->getPath());
        $this->assertSame(['kind' => 'payoutMethod', 'key' => 'bank-transfer', 'name' => 'Bank transfer', 'currencies' => ['USD']], $this->lastRequestJson());
    }

    public function testFeaturesRetrieveUpdateAndDeleteUseTheFeatureRoute(): void
    {
        $feature = ['id' => 12, 'kind' => 'payoutMethod', 'key' => 'bank-transfer', 'name' => 'Wire'];
        $siren = $this->mockSiren([
            self::jsonResponse(200, $feature),
            self::jsonResponse(200, $feature),
            self::jsonResponse(200, ['archived' => true, 'feature' => $feature]),
        ]);

        $this->assertSame($feature, $siren->apps->features->retrieve('acme-payouts', 12));
        $this->assertSame('GET /siren/v1/apps/acme-payouts/features/12', $this->lastRequest()->getMethod() . ' ' . $this->lastRequest()->getUri()->getPath());

        $this->assertSame($feature, $siren->apps->features->update('acme-payouts', 12, ['name' => 'Wire']));
        $this->assertSame('PATCH /siren/v1/apps/acme-payouts/features/12', $this->lastRequest()->getMethod() . ' ' . $this->lastRequest()->getUri()->getPath());
        $this->assertSame(['name' => 'Wire'], $this->lastRequestJson());

        $this->assertSame(['archived' => true, 'feature' => $feature], $siren->apps->features->delete('acme-payouts', 12));
        $this->assertSame('DELETE /siren/v1/apps/acme-payouts/features/12', $this->lastRequest()->getMethod() . ' ' . $this->lastRequest()->getUri()->getPath());
    }

    /** @return array<string, array{\Closure(\Siren\Sdk\Resources\AppFeatures): mixed, string}> */
    public static function badFeatureCalls(): array
    {
        return [
            'a bad app key' => [fn ($f) => $f->create('Acme Payouts', ['kind' => 'payoutMethod', 'name' => 'x']), 'needs an app key'],
            'a reserved app key' => [fn ($f) => $f->retrieve('requests', 1), 'needs an app key'],
            'a field create does not send' => [fn ($f) => $f->create('acme', ['kind' => 'payoutMethod', 'name' => 'x', 'status' => 'on']), 'does not take status'],
            'no kind' => [fn ($f) => $f->create('acme', ['name' => 'x']), 'needs a kind'],
            'no name' => [fn ($f) => $f->create('acme', ['kind' => 'payoutMethod']), 'needs a name'],
            'the old payout kind' => [fn ($f) => $f->create('acme', ['kind' => 'fulfillmentMethod', 'name' => 'x']), 'needs kind payoutMethod'],
            'a changed kind' => [fn ($f) => $f->update('acme', 1, ['kind' => 'payoutMethod']), 'cannot change kind'],
            'a changed key' => [fn ($f) => $f->update('acme', 1, ['key' => 'other']), 'cannot change key'],
            'an id of 0' => [fn ($f) => $f->delete('acme', 0), 'positive whole number'],
        ];
    }

    #[DataProvider('badFeatureCalls')]
    public function testFeaturesRefuseABadCallBeforeSending(\Closure $call, string $words): void
    {
        $siren = $this->mockSiren([]);

        try {
            $call($siren->apps->features);
            $this->fail('The call was not refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($words, $e->getMessage());
        }

        $this->assertSame([], $this->history);
    }

    public function testRequestsListReadsTheOrgsRequestsWithAStatusFilter(): void
    {
        $row = ['id' => 'apr_' . str_repeat('a', 32), 'appId' => 3, 'feature' => 'app:12', 'kind' => 'payoutMethod', 'status' => 'needs_attention'];
        $siren = $this->mockSiren([self::jsonResponse(200, ['requests' => [$row]])]);

        $this->assertSame([$row], $siren->apps->requests->list(['status' => 'needs_attention']));
        $this->assertSame('GET /siren/v1/apps/requests', $this->lastRequest()->getMethod() . ' ' . $this->lastRequest()->getUri()->getPath());
        $this->assertSame('status=needs_attention', $this->lastRequest()->getUri()->getQuery());
    }

    public function testRequestsRetrieveAndResendUseTheRequestsId(): void
    {
        $id = 'apr_' . str_repeat('b', 32);
        $detail = ['id' => $id, 'feature' => 'app:12', 'status' => 'sent', 'data' => ['payout' => ['id' => 4]], 'answer' => null, 'answeredByUser' => null];
        $siren = $this->mockSiren([self::jsonResponse(200, $detail), self::jsonResponse(200, ['id' => $id, 'status' => 'sent'])]);

        $this->assertSame($detail, $siren->apps->requests->retrieve($id));
        $this->assertSame('GET /siren/v1/apps/requests/' . $id, $this->lastRequest()->getMethod() . ' ' . $this->lastRequest()->getUri()->getPath());

        $this->assertSame(['id' => $id, 'status' => 'sent'], $siren->apps->requests->resend($id));
        $this->assertSame('POST /siren/v1/apps/requests/' . $id . '/resend', $this->lastRequest()->getMethod() . ' ' . $this->lastRequest()->getUri()->getPath());
        $this->assertCount(2, $this->history);
    }

    /** @return array<string, array{\Closure(\Siren\Sdk\Resources\AppRequests): mixed, string}> */
    public static function badRequestCalls(): array
    {
        return [
            'a filter Siren does not read' => [fn ($r) => $r->list(['app' => 'acme']), 'does not read app'],
            'an unknown status' => [fn ($r) => $r->list(['status' => 'waiting']), 'takes a status of pending, sent, delivered, answered, applied, needs_attention, dropped'],
            'a bad id to retrieve' => [fn ($r) => $r->retrieve('apr_1'), 'needs a request id'],
            'a bad id to resend' => [fn ($r) => $r->resend('42'), 'needs a request id'],
        ];
    }

    #[DataProvider('badRequestCalls')]
    public function testRequestsRefuseABadCallBeforeSending(\Closure $call, string $words): void
    {
        $siren = $this->mockSiren([]);

        try {
            $call($siren->apps->requests);
            $this->fail('The call was not refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($words, $e->getMessage());
        }

        $this->assertSame([], $this->history);
    }

    public function testOpenapiNamesTheFeatureAndRequestRoutesThisSdkCalls(): void
    {
        $spec = (string) file_get_contents(dirname(__DIR__) . '/openapi.yaml');

        foreach (['/apps/{app}/features:', '/apps/{app}/features/{id}:', '/apps/requests:', '/apps/requests/{requestKey}:', '/apps/requests/{requestKey}/resend:'] as $path) {
            $this->assertStringContainsString("\n  " . $path . "\n", $spec);
        }
    }
}
