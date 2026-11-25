<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests\Support;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

/** A PSR-3 logger that keeps every line, for tests of the handler's `logger` option. */
final class CollectingLogger implements LoggerInterface
{
    use LoggerTrait;

    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $lines = [];

    /**
     * @param mixed        $level
     * @param array<mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->lines[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    /** @return list<string> The messages logged at one level. */
    public function at(string $level): array
    {
        return array_values(array_map(
            static fn (array $line): string => $line['message'],
            array_filter($this->lines, static fn (array $line): bool => $line['level'] === $level),
        ));
    }
}
