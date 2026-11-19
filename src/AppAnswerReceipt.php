<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * Siren's receipt for an app's answer, from
 * {@see Resources\AppRequests::answer()}. Siren took the answer. It applies
 * it on its own, after this returns.
 */
final class AppAnswerReceipt
{
    /**
     * @param bool $duplicate True when Siren already held this same answer, so it changed nothing.
     */
    public function __construct(
        public readonly string $status,
        public readonly bool $duplicate,
    ) {
    }
}
