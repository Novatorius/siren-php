<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * Program statuses. `DELETED` is set by the first DELETE call and by the
 * `delete` bulk action, never directly.
 */
final class ProgramStatus
{
    public const ACTIVE = 'active';
    public const INACTIVE = 'inactive';
    public const DELETED = 'deleted';

    private function __construct()
    {
    }
}
