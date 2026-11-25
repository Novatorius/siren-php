<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use PHPUnit\Framework\TestCase;
use Siren\Sdk\Resources\AppRequests;

/**
 * `$siren->apps->requests` shows a developer only what they call: `answer`,
 * and for an admin `list`, `retrieve` and `resend`. What the request handler
 * needs to post and check answers lives elsewhere, so it never shows in
 * autocomplete beside them (pass 3 re-check).
 */
final class AppRequestsSurfaceTest extends TestCase
{
    public function testTheRequestsResourceOffersOnlyItsPublicApi(): void
    {
        $methods = array_values(array_filter(
            array_map(
                static fn (\ReflectionMethod $method): string => $method->getName(),
                (new \ReflectionClass(AppRequests::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
            ),
            static fn (string $name): bool => $name !== '__construct',
        ));
        sort($methods);

        self::assertSame(['answer', 'list', 'resend', 'retrieve'], $methods);
    }
}
