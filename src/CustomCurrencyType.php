<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * Custom currency types.
 */
final class CustomCurrencyType
{
    /** Points or store credit that live only inside Siren. Credit adjustments need this type. */
    public const INTERNAL = 'internal';

    /** A currency backed by an external payment processor. */
    public const MONETARY = 'monetary';

    private function __construct()
    {
    }
}
