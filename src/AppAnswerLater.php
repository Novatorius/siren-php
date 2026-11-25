<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * What an ask's function returns, from {@see AppRequestHandler::later()},
 * when the app will answer the request itself with
 * `$siren->apps->requests->answer()`. The handler then posts nothing and
 * reports nothing.
 */
final class AppAnswerLater
{
    private static ?self $instance = null;

    private function __construct()
    {
    }

    /** @internal Made by {@see AppRequestHandler::later()}. */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }
}
