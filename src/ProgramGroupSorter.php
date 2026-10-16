<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * How a program group picks the winning program when several could claim the
 * same engagement.
 */
final class ProgramGroupSorter
{
    /** The program the opportunity was bound to first wins. */
    public const OLDEST_BINDING_WINS = 'oldestBindingWins';

    /** The program the opportunity was bound to most recently wins. */
    public const NEWEST_BINDING_WINS = 'newestBindingWins';

    private function __construct()
    {
    }
}
