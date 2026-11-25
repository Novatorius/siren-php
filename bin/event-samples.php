<?php

declare(strict_types=1);

/*
 * Writes resources/webhook-event-samples.json, the sample payload of each
 * webhook event, from Siren's own event reference, docs/api/webhooks-events.md
 * in a Siren checkout. Siren\Sdk\Testing\AppRequests::event() and
 * sampleEventData() read it.
 *
 * It follows the Node SDK's scripts/siren-event-samples.mjs rule for rule,
 * so the three SDKs hold the same values. Each `### <event>` heading's
 * `| Field | Type | Description |` table gives one field per row:
 *
 * - `integer`, `integer or null`: 101, 102 and on for a field named `...Id`,
 *   in the table's order, and 1 for any other.
 * - `integer[]`: a list holding the next id.
 * - `number`: 25.
 * - `boolean`: true.
 * - `string`, `string or null`: the first quoted value the description shows
 *   in backticks (`"stripe"`), else the first other backticked word that is
 *   not a field of the table, `null`, `true` or `false`, else "example".
 * - `array`: an empty list.
 *
 * An event whose payload reads "the same fields as `<event>`" gets a copy of
 * that event's sample. An event with no table gets none.
 *
 *     php bin/event-samples.php <siren checkout>          rewrites the file
 *     php bin/event-samples.php --check <siren checkout>  exits 1 when it has drifted
 *     php bin/event-samples.php --print <siren checkout>  prints the samples as JSON
 */

const DOC = 'docs/api/webhooks-events.md';

$args = array_slice($argv, 1);
$flags = array_values(array_filter($args, static fn (string $arg): bool => str_starts_with($arg, '--')));
$args = array_values(array_filter($args, static fn (string $arg): bool => !str_starts_with($arg, '--')));

if (count($args) !== 1 || !is_file($args[0] . '/' . DOC) || array_diff($flags, ['--check', '--print']) !== [] || count($flags) > 1) {
    fwrite(STDERR, "Usage: php bin/event-samples.php [--check | --print] <siren checkout>\n");
    exit(2);
}

$samples = samples((string) file_get_contents($args[0] . '/' . DOC));

if ($flags === ['--print']) {
    echo json_encode($samples, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
    exit(0);
}

$target = dirname(__DIR__) . '/resources/webhook-event-samples.json';
$json = json_encode([
    'source' => "Siren's docs/api/webhooks-events.md, by bin/event-samples.php, which follows the Node SDK's scripts/siren-event-samples.mjs. Each field holds a sample value of the type the page gives it.",
    'events' => $samples,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

if ($flags === ['--check']) {
    if (!is_file($target) || file_get_contents($target) !== $json) {
        fwrite(STDERR, "resources/webhook-event-samples.json differs from what Siren's page gives. Run php bin/event-samples.php <siren checkout> to write it again.\n");
        exit(1);
    }

    echo "resources/webhook-event-samples.json matches Siren's page.\n";
    exit(0);
}

file_put_contents($target, $json);
echo 'Wrote ' . $target . "\n";

/**
 * @return array<string, array<string, mixed>>
 */
function samples(string $markdown): array
{
    $samples = [];
    $same = [];
    $lines = explode("\n", $markdown);
    $count = count($lines);

    for ($i = 0; $i < $count; $i++) {
        if (preg_match('/^### ([a-z_]+\.[a-z_]+)\s*$/', $lines[$i], $heading) !== 1) {
            continue;
        }

        $event = $heading[1];
        $j = $i + 1;

        while ($j < $count && !str_starts_with($lines[$j], '### ') && !str_starts_with($lines[$j], '## ') && preg_match('/^\|\s*Field\s*\|\s*Type\s*\|/', $lines[$j]) !== 1) {
            if (preg_match('/^\*\*Payload:\*\* the same fields as `([a-z_]+\.[a-z_]+)`/', $lines[$j], $copy) === 1) {
                $same[] = [$event, $copy[1]];
            }

            $j++;
        }

        if ($j >= $count || !str_starts_with($lines[$j], '|')) {
            continue;
        }

        $rows = [];

        for ($j += 2; $j < $count && str_starts_with($lines[$j], '|'); $j++) {
            $cells = array_map('trim', array_slice(explode('|', $lines[$j]), 1, -1));

            if (preg_match('/^`([A-Za-z0-9_]+)`$/', $cells[0] ?? '', $name) !== 1) {
                continue;
            }

            $rows[] = ['name' => $name[1], 'type' => $cells[1] ?? '', 'description' => implode('|', array_slice($cells, 2))];
        }

        $fields = array_column($rows, 'name');
        $data = [];
        $nextId = 101;

        foreach ($rows as ['name' => $name, 'type' => $type, 'description' => $description]) {
            $data[$name] = match (preg_replace('/ or null$/', '', $type)) {
                'integer' => str_ends_with($name, 'Id') ? $nextId++ : 1,
                'integer[]' => [$nextId++],
                'number' => 25,
                'boolean' => true,
                'array' => [],
                'string' => stringSample($description, $fields),
                default => throw new RuntimeException(sprintf('%s: %s.%s has a type this script does not know: %s', DOC, $event, $name, $type)),
            };
        }

        $samples[$event] = $data;
    }

    foreach ($same as [$event, $from]) {
        $samples[$event] = $samples[$from] ?? throw new RuntimeException(sprintf('%s: %s copies %s, which has no table', DOC, $event, $from));
    }

    if ($samples === []) {
        throw new RuntimeException(DOC . ': no events found');
    }

    return $samples;
}

/** @param list<string> $fields */
function stringSample(string $description, array $fields): string
{
    preg_match_all('/`([^`]+)`/', $description, $matches);

    foreach ($matches[1] as $value) {
        if (preg_match('/^"[^"]*"$/', $value) === 1) {
            return substr($value, 1, -1);
        }
    }

    foreach ($matches[1] as $value) {
        if (preg_match('/^[a-z0-9_.-]+$/i', $value) === 1 && !in_array($value, $fields, true) && !in_array($value, ['null', 'true', 'false'], true)) {
            return $value;
        }
    }

    return 'example';
}
