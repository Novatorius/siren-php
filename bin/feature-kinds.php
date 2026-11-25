<?php

declare(strict_types=1);

/*
 * Writes the parts of src/FeatureKind.php and src/AppRequestHandler.php that
 * come from Siren's kinds snapshot, resources/app-kinds.json, a byte-for-byte
 * copy of Siren's docs/api/app-kinds.json:
 *
 * - the first two paragraphs of each kind constant's docblock, which an
 *   editor shows on hover: the name and Siren's term, then the snapshot's
 *   description and whether Siren tells or asks, in the one sentence the
 *   Node and Python SDKs give too;
 * - the SENT, TELLS, ASKS and NAMES lists;
 * - in AppRequestHandler, the type imports and the conditional types on
 *   on()'s arguments, so PHPStan hands a function its kind's question and
 *   checks the answer it returns.
 *
 *     composer feature-kinds            rewrites both files
 *     php bin/feature-kinds.php --check  exits 1 when either file has drifted
 *
 * A kind the snapshot adds needs its constant written by hand first, and
 * its types added to TYPES below, and this script says so.
 */

/**
 * Each kind Siren sends: the type of the request's `data`, and for an ask the
 * type of its answer. The names follow the kind, the same in all three SDKs.
 */
const TYPES = [
    'postConversionAction' => ['PostConversionActionTell', null],
    'postAllocationAction' => ['PostAllocationActionTell', null],
    'payoutMethod' => ['PayoutMethodQuestion', 'PayoutMethodAnswer'],
    'distributionResolver' => ['DistributionResolverQuestion', 'DistributionResolverAnswer'],
    'metricCalculation' => ['MetricCalculationQuestion', 'MetricCalculationAnswer'],
    'metricFilter' => ['MetricFilterQuestion', 'MetricFilterAnswer'],
    'incentiveResolver' => ['IncentiveResolverQuestion', 'IncentiveResolverAnswer'],
    'transactionFilter' => ['TransactionFilterQuestion', 'TransactionFilterAnswer'],
    'incentiveType' => ['IncentiveTypeQuestion', 'IncentiveTypeAnswer'],
    'engagementCalculation' => ['EngagementCalculationQuestion', 'EngagementCalculationAnswer'],
    'engagementFilter' => ['EngagementFilterQuestion', 'EngagementFilterAnswer'],
    'programGroupSorter' => ['ProgramGroupSorterQuestion', 'ProgramGroupSorterAnswer'],
    'collaboratorGroupStructure' => ['CollaboratorGroupStructureQuestion', 'CollaboratorGroupStructureAnswer'],
];

/** The sentence that says what Siren does with each mode. */
const MODES = [
    'ask' => 'An ask: Siren waits for the app to answer.',
    'tell' => 'A tell: Siren lets the app know.',
    'receive' => 'The app sends it: Siren sends nothing.',
];

$root = dirname(__DIR__);
$kindsFile = $root . '/src/FeatureKind.php';
$handlerFile = $root . '/src/AppRequestHandler.php';
$kinds = json_decode((string) file_get_contents($root . '/resources/app-kinds.json'), true, 512, JSON_THROW_ON_ERROR);

$constantFor = static fn (string $kind): string => strtoupper((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $kind));
$quote = static fn (string $text): string => "'" . addcslashes($text, "'\\") . "'";
$fail = static function (string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(1);
};

/** Wraps text into docblock lines at the given indent, at most 80 characters wide. */
$wrap = static function (string $text, string $indent): string {
    $lines = explode("\n", wordwrap($text, 80 - strlen($indent) - 3, "\n", false));

    return implode("\n", array_map(static fn (string $line): string => $indent . ' * ' . $line, $lines));
};

// FeatureKind: each constant's first two paragraphs.

$kindsSource = (string) file_get_contents($kindsFile);
$kindsGenerated = $kindsSource;

foreach ($kinds as $kind) {
    $constant = $constantFor($kind['kind']);
    $summary = strcasecmp($kind['term'], $kind['name']) === 0
        ? $kind['name'] . '.'
        : $kind['name'] . ". Siren's code calls it the " . $kind['term'] . '.';
    $mode = MODES[$kind['mode']] ?? $fail(sprintf("resources/app-kinds.json gives %s the mode %s, which this script doesn't know.", $kind['kind'], $kind['mode']));
    $pattern = '/    \/\*\*\n((?:     \*[^\n]*\n)*?)     \*\/\n    public const ' . $constant . " = '" . preg_quote($kind['kind'], '/') . "';/";

    if (preg_match($pattern, $kindsGenerated, $match) !== 1) {
        $fail(sprintf("src/FeatureKind.php has no documented constant %s = '%s'. Write it by hand, with its question and answer, then run this again.", $constant, $kind['kind']));
    }

    // The hand-written paragraphs follow the first line and any paragraph this script wrote before, which ends with a mode sentence.
    $paragraphs = explode("\n     *\n", rtrim($match[1], "\n"));
    array_shift($paragraphs);

    $flat = $paragraphs === [] ? '' : str_replace("\n     * ", ' ', $paragraphs[0]);

    if (preg_match('/(' . implode('|', array_map(static fn (string $s): string => preg_quote($s, '/'), MODES)) . ')$/', $flat) === 1) {
        array_shift($paragraphs);
    }

    $docs = array_merge(['     * ' . $summary, $wrap($kind['description'] . ' ' . $mode, '    ')], $paragraphs);
    $block = "    /**\n" . implode("\n     *\n", $docs) . "\n     */\n    public const " . $constant . ' = ' . $quote($kind['kind']) . ';';
    $kindsGenerated = str_replace($match[0], $block, $kindsGenerated);
}

$list = static function (string $doc, string $name, array $kinds) use ($constantFor): string {
    $lines = array_map(static fn (array $kind): string => '        self::' . $constantFor($kind['kind']) . ',', $kinds);

    return "    /** {$doc} */\n    public const {$name} = [\n" . implode("\n", $lines) . "\n    ];\n";
};
$mode = static fn (string ...$modes): array => array_values(array_filter($kinds, static fn (array $kind): bool => in_array($kind['mode'], $modes, true)));
$names = array_map(static fn (array $kind): string => '        self::' . $constantFor($kind['kind']) . ' => ' . $quote($kind['name']) . ',', $kinds);

$block = "    // generated:start, by bin/feature-kinds.php from resources/app-kinds.json\n\n"
    . $list("The kinds Siren sends requests for, the snapshot's tells and asks in its order, which {@see AppRequestHandler::on()} takes.", 'SENT', $mode('tell', 'ask'))
    . "\n" . $list('The kinds Siren tells, which wait for no answer.', 'TELLS', $mode('tell'))
    . "\n" . $list('The kinds Siren asks, which wait for an answer.', 'ASKS', $mode('ask'))
    . "\n    /** The name Siren Cloud's screens give each kind. */\n    public const NAMES = [\n" . implode("\n", $names) . "\n    ];\n"
    . "\n    // generated:end";

$kindsGenerated = (string) preg_replace('/    \/\/ generated:start.*?\/\/ generated:end/s', str_replace(['\\', '$'], ['\\\\', '\\$'], $block), $kindsGenerated, 1);

// AppRequestHandler: the imports and on()'s conditional types.

$sent = $mode('tell', 'ask');

foreach ($sent as $kind) {
    if (!isset(TYPES[$kind['kind']])) {
        $fail(sprintf('bin/feature-kinds.php has no types for %s. Add them to TYPES, with the shapes in src/FeatureKind.php, then run this again.', $kind['kind']));
    }
}

$imports = [];

foreach ($sent as $kind) {
    foreach (TYPES[$kind['kind']] as $type) {
        if ($type !== null) {
            $imports[] = ' * @phpstan-import-type ' . $type . ' from FeatureKind';
        }
    }
}

/** The callable a kind's function must be: its request in, its answer or later() out. */
$callable = static function (array $kind): string {
    [$question, $answer] = TYPES[$kind['kind']];

    return sprintf('callable(AppRequest<%s>): %s', $question, $answer === null ? 'mixed' : '(' . $answer . '|AppAnswerLater)');
};

/** `(TKind is 'a' ? A : (TKind is 'b' ? B : ... Z))`, with the last kind as the last branch. */
$conditional = static function (callable $typeFor) use ($sent, $quote): string {
    $last = array_pop($sent);
    $type = $typeFor($last);

    foreach (array_reverse($sent) as $kind) {
        $type = sprintf("(TKind is %s ? %s : %s)", $quote($kind['kind']), $typeFor($kind), $type);
    }

    return $type;
};

$kindUnion = implode('|', array_map(static fn (array $kind): string => $quote($kind['kind']), $sent));
$onDocs = "     * generated:start, by bin/feature-kinds.php from resources/app-kinds.json\n"
    . "     * @template TKind of {$kindUnion}\n"
    . "     * @param TKind \$kind The feature's kind, such as `distributionResolver`, one of {@see FeatureKind::SENT}.\n"
    . '     * @param ' . $conditional(static fn (array $kind): string => '(string|' . $callable($kind) . ')') . " \$key The feature's key, or the function for every key of the kind.\n"
    . '     * @param ' . $conditional(static fn (array $kind): string => '(' . $callable($kind) . ')|null') . " \$fn The function, when `\$key` names a key.\n"
    . '     * generated:end';
$importDocs = " * generated:start, by bin/feature-kinds.php from resources/app-kinds.json\n" . implode("\n", $imports) . "\n * generated:end";

$handlerSource = (string) file_get_contents($handlerFile);
$handlerGenerated = (string) preg_replace('/^ \* generated:start.*?^ \* generated:end/sm', str_replace(['\\', '$'], ['\\\\', '\\$'], $importDocs), $handlerSource, 1, $importCount);
$handlerGenerated = (string) preg_replace('/^     \* generated:start.*?^     \* generated:end/sm', str_replace(['\\', '$'], ['\\\\', '\\$'], $onDocs), $handlerGenerated, 1, $onCount);

if ($importCount !== 1 || $onCount !== 1) {
    $fail('src/AppRequestHandler.php lacks a generated:start and generated:end pair in its class docblock or on on(). Put them back, then run this again.');
}

$drifted = [];

foreach ([[$kindsFile, $kindsSource, $kindsGenerated], [$handlerFile, $handlerSource, $handlerGenerated]] as [$file, $source, $generated]) {
    if ($generated !== $source) {
        $drifted[] = 'src/' . basename($file);
    }
}

if (in_array('--check', $argv, true)) {
    if ($drifted !== []) {
        $fail(implode(' and ', $drifted) . ' drifted from resources/app-kinds.json. Run composer feature-kinds.');
    }

    echo "src/FeatureKind.php and src/AppRequestHandler.php match resources/app-kinds.json.\n";
    exit(0);
}

file_put_contents($kindsFile, $kindsGenerated);
file_put_contents($handlerFile, $handlerGenerated);
echo $drifted === [] ? "Both files were already up to date.\n" : 'Wrote ' . implode(' and ', $drifted) . " from resources/app-kinds.json.\n";
