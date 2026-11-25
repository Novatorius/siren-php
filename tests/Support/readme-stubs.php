<?php

declare(strict_types=1);

/*
 * What the README's mounting samples leave to the framework, so
 * ReadmeHandlerMountsTest can run them as written: Laravel's Route facade,
 * Illuminate\Http\Request and the app(), response() and report() helpers,
 * and the app's own undoWork(). Each one records what it was handed in
 * ReadmeStubs. None of these frameworks is a dependency of this package.
 */

namespace Siren\Sdk\Tests\Support {
    final class ReadmeStubs
    {
        /** @var list<array{string, string, callable}> Each route Route::post() registered. */
        public static array $routes = [];

        /** @var list<callable> Each piece of work app()->terminating() was handed. */
        public static array $terminating = [];

        /** @var list<\Throwable> Each error report() was handed. */
        public static array $reported = [];

        /** @var list<string> Each request id undoWork() was handed. */
        public static array $undone = [];

        public static function reset(): void
        {
            self::$routes = [];
            self::$terminating = [];
            self::$reported = [];
            self::$undone = [];
        }
    }

    final class StubLaravelApp
    {
        public function terminating(callable $work): void
        {
            ReadmeStubs::$terminating[] = $work;
        }
    }

    final class StubLaravelResponse
    {
        /** @param array<string, string> $headers */
        public function __construct(public readonly string $body, public readonly int $status, public readonly array $headers)
        {
        }
    }

    final class StubHeaderBag
    {
        /** @param array<string, list<string>> $headers */
        public function __construct(private readonly array $headers)
        {
        }

        /** @return array<string, list<string>> */
        public function all(): array
        {
            return $this->headers;
        }
    }
}

namespace Illuminate\Http {
    if (!class_exists(Request::class)) {
        final class Request
        {
            public readonly \Siren\Sdk\Tests\Support\StubHeaderBag $headers;

            /** @param array<string, list<string>> $headers Laravel's shape: each name to a list of values. */
            public function __construct(private readonly string $method, private readonly string $content, array $headers)
            {
                $this->headers = new \Siren\Sdk\Tests\Support\StubHeaderBag($headers);
            }

            public function method(): string
            {
                return $this->method;
            }

            public function getContent(): string
            {
                return $this->content;
            }
        }
    }
}

namespace {
    use Siren\Sdk\Tests\Support\ReadmeStubs;
    use Siren\Sdk\Tests\Support\StubLaravelApp;
    use Siren\Sdk\Tests\Support\StubLaravelResponse;

    if (!class_exists('Route')) {
        final class Route
        {
            public static function post(string $path, callable $action): void
            {
                ReadmeStubs::$routes[] = ['POST', $path, $action];
            }
        }
    }

    if (!function_exists('app')) {
        function app(): StubLaravelApp
        {
            return new StubLaravelApp();
        }
    }

    if (!function_exists('response')) {
        /** @param array<string, string> $headers */
        function response(string $body, int $status, array $headers): StubLaravelResponse
        {
            return new StubLaravelResponse($body, $status, $headers);
        }
    }

    if (!function_exists('logger')) {
        function logger(): \Psr\Log\LoggerInterface
        {
            return new \Psr\Log\NullLogger();
        }
    }

    if (!function_exists('report')) {
        function report(\Throwable $error): void
        {
            ReadmeStubs::$reported[] = $error;
        }
    }

    if (!function_exists('undoWork')) {
        function undoWork(string $requestId): void
        {
            ReadmeStubs::$undone[] = $requestId;
        }
    }
}
