<?php

declare(strict_types=1);

namespace Siren\Sdk;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;

/**
 * The request handler's logger when none is passed: one `error_log()` line
 * for each warning and above, and nothing for info and debug. Pass your
 * framework's PSR-3 logger as the handler's `logger` option to see the
 * debug line for each request too.
 */
final class ErrorLogLogger implements LoggerInterface
{
    use LoggerTrait;

    private const WRITTEN = [LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL, LogLevel::ERROR, LogLevel::WARNING];

    /**
     * @param mixed        $level
     * @param string|\Stringable $message
     * @param array<mixed> $context Not written: the message already holds what it needs.
     */
    public function log($level, $message, array $context = []): void
    {
        if (in_array($level, self::WRITTEN, true)) {
            error_log((string) $message);
        }
    }
}
