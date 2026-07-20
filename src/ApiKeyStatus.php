<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * Statuses an API key can hold (the `apiKeys` resource).
 */
final class ApiKeyStatus
{
    public const ACTIVE = 'active';
    public const REVOKED = 'revoked';

    private function __construct()
    {
    }
}
