<?php

declare(strict_types=1);

/*
 * Writes resources/webhook-event-samples.json, the sample payload of each
 * webhook event, from Siren's own event catalog page, docs/api/webhooks-events.md.
 * Siren\Sdk\Testing\AppRequests::event() and sampleEventData() read it.
 *
 * Each event's "Payload" table names its fields and their types, and this
 * fills each field with a sample value of that type: a number for an id, the
 * first example the description gives for a string (such as `stripe`), and
 * so on. An event whose payload is "the same fields as" another copies that
 * one's sample.
 *
 *     php bin/event-samples.php <siren>/docs/api/webhooks-events.md          rewrites the file
 *     php bin/event-samples.php --check <siren>/docs/api/webhooks-events.md  exits 1 when it has drifted
 */

$args = array_slice($argv, 1);
$check = in_array('--check', $args, true);
$args = array_values(array_filter($args, static fn (string $arg): bool => $arg !== '--check'));

if (count($args) !== 1 || !is_file($args[0])) {
    fwrite(STDERR, "Usage: php bin/event-samples.php [--check] <siren>/docs/api/webhooks-events.md\n");
    exit(2);
}

$target = dirname(__DIR__) . '/resources/webhook-event-samples.json';
$json = json_encode([
    'source' => "Siren's docs/api/webhooks-events.md, by bin/event-samples.php. Each field holds a sample value of the type the page gives it.",
    'events' => samples((string) file_get_contents($args[0])),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

if ($check) {
    if (!is_file($target) || file_get_contents($target) !== $json) {
        fwrite(STDERR, "resources/webhook-event-samples.json differs from what Siren's page gives. Run php bin/event-samples.php <page> to write it again.\n");
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
function samples(string $page): array
{
    $events = [];
    $sameAs = [];
    $event = null;

    foreach (explode("\n", $page) as $line) {
        if (preg_match('/^### ([a-z_]+\.[a-z_]+)\s*$/', $line, $m) === 1) {
            $event = $m[1];
            $events[$event] = [];

            continue;
        }

        if (str_starts_with($line, '## ') || str_starts_with($line, '### ')) {
            $event = null;

            continue;
        }

        if ($event === null) {
            continue;
        }

        if (preg_match('/^\*\*Payload:\*\* the same fields as `([a-z_]+\.[a-z_]+)`/', $line, $m) === 1) {
            $sameAs[$event] = $m[1];

            continue;
        }

        if (preg_match('/^\| `([A-Za-z0-9_]+)` \| ([^|]+) \| (.*) \|\s*$/', $line, $m) === 1) {
            $events[$event][$m[1]] = value($m[1], trim($m[2]), $m[3], count($events[$event]));
        }
    }

    foreach ($sameAs as $event => $other) {
        $events[$event] = $events[$other] ?? throw new RuntimeException(sprintf('%s has the same fields as %s, which the page does not describe.', $event, $other));
    }

    foreach ($events as $name => $fields) {
        if ($fields === []) {
            throw new RuntimeException(sprintf('The page gives %s no payload fields.', $name));
        }
    }

    return $events;
}

function value(string $field, string $type, string $description, int $position): mixed
{
    $number = 101 + $position;

    return match (preg_replace('/ or null$/', '', $type)) {
        'integer' => $number,
        'integer[]' => [$number],
        'number' => 25.5,
        'boolean' => true,
        'array' => [],
        'string' => text($field, $description),
        default => throw new RuntimeException(sprintf('%s has the type %s, which this script does not fill.', $field, $type)),
    };
}

/** The first example the description quotes, such as `stripe`, or a sample built from the field's name. */
function text(string $field, string $description): string
{
    if (preg_match('/`"?([A-Za-z0-9_.\-]+)"?`/', $description, $m) === 1) {
        return $m[1];
    }

    return match (true) {
        str_ends_with($field, 'At') => '2026-11-24T17:00:00+00:00',
        strtolower($field) === 'currency' => 'USD',
        default => 'sample-' . $field,
    };
}
