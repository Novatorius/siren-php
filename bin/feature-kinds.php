<?php

declare(strict_types=1);

/*
 * Writes the parts of src/FeatureKind.php that come from Siren's kinds
 * snapshot, resources/app-kinds.json, a byte-for-byte copy of Siren's
 * docs/api/app-kinds.json: the first line of each kind constant's docblock,
 * which an editor shows on hover, and the SENT, TELLS, ASKS and NAMES lists.
 *
 *     composer feature-kinds            rewrites src/FeatureKind.php
 *     php bin/feature-kinds.php --check  exits 1 when the file has drifted
 *
 * A kind the snapshot adds needs its constant written by hand first, with
 * its question and answer, and this script says so.
 */

$root = dirname(__DIR__);
$classFile = $root . '/src/FeatureKind.php';
$kinds = json_decode((string) file_get_contents($root . '/resources/app-kinds.json'), true, 512, JSON_THROW_ON_ERROR);
$source = (string) file_get_contents($classFile);
$generated = $source;

$constantFor = static fn (string $kind): string => strtoupper((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $kind));
$quote = static fn (string $text): string => "'" . addcslashes($text, "'\\") . "'";

foreach ($kinds as $kind) {
    $constant = $constantFor($kind['kind']);
    $summary = strcasecmp($kind['term'], $kind['name']) === 0
        ? $kind['name'] . '.'
        : $kind['name'] . ". Siren's own term: " . $kind['term'] . '.';
    $pattern = '/(    \/\*\*\n     \* )[^\n]*(\n(?:     \*[^\n]*\n)*?     \*\/\n    public const ' . $constant . " = '" . preg_quote($kind['kind'], '/') . "';)/";

    if (preg_match($pattern, $generated) !== 1) {
        fwrite(STDERR, sprintf("src/FeatureKind.php has no documented constant %s = '%s'. Write it by hand, with its question and answer, then run this again.\n", $constant, $kind['kind']));
        exit(1);
    }

    $generated = (string) preg_replace_callback($pattern, static fn (array $m): string => $m[1] . $summary . $m[2], $generated, 1);
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

$generated = (string) preg_replace('/    \/\/ generated:start.*?\/\/ generated:end/s', str_replace(['\\', '$'], ['\\\\', '\\$'], $block), $generated, 1);

if (in_array('--check', $argv, true)) {
    if ($generated !== $source) {
        fwrite(STDERR, "src/FeatureKind.php has drifted from resources/app-kinds.json. Run composer feature-kinds.\n");
        exit(1);
    }

    echo "src/FeatureKind.php matches resources/app-kinds.json.\n";
    exit(0);
}

file_put_contents($classFile, $generated);
echo $generated === $source ? "src/FeatureKind.php was already up to date.\n" : "Wrote src/FeatureKind.php from resources/app-kinds.json.\n";
