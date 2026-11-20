<?php

declare(strict_types=1);

namespace Siren\Sdk;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Siren\Sdk\Exception\SignatureVerificationException;
use Siren\Sdk\Exception\SirenException;
use Siren\Sdk\Resources\AppRequests;
use Siren\Sdk\Resources\Apps;
use Siren\Sdk\Resources\Webhooks;

/**
 * Answers Siren's requests to an app at the app's one address. Register a
 * function for each feature with {@see on()} and for each webhook event
 * with {@see onEvent()}, then mount it with {@see serve()} in plain PHP,
 * {@see psr15()} in Slim or Mezzio, or {@see answer()} anywhere else.
 * Make it with `$siren->apps->requestHandler($signingSecret, $options)`.
 *
 * ```php
 * $siren->apps->requestHandler(getenv('SIREN_SIGNING_SECRET'))
 *     ->on('distributionResolver', 'split-by-score', fn ($request) => [
 *         'outcome' => 'allocated',
 *         'units' => $request->data['pool']['units'],
 *         'shares' => $siren->apps->splitPool($request->data['pool'], array_column($request->data['metrics'], 'score', 'metricId')),
 *     ])
 *     ->serve();
 * ```
 *
 * One request gets one reply, each JSON with `Cache-Control: no-store`:
 *
 * | Request | Reply |
 * |---|---|
 * | Not a POST | 405 `{"error"}` |
 * | A body a parser already read, so no bytes to check | 500 `{"error"}`, and `onError` hears why |
 * | A failed check of {@see Apps::verifyRequest()}: the signature, the timestamp header or the time | 401 `{"error"}` with its words |
 * | A signed body that is not JSON, or an `app.request` that is not a request of version 1 | 400 `{"error"}` |
 * | A tell with a function | 200 `{}` once the function returns, or 500 when it throws |
 * | An ask with a function | 200 `{}` at once. The function runs after the reply, and the answer it returns is posted |
 * | An `app.request` for a feature with no function | 404 `{"error": "No function handles <kind> <key>."}` |
 * | Another event with a function from `onEvent` | 200 `{}` once it returns, or 500 when it throws |
 * | Another event with no function | 200 `{}` |
 *
 * An ask's function returns the answer as an array, or null when the app
 * answers later with `$siren->apps->requests->answer()`. The handler checks
 * the answer as `requests->answer()` does and posts it to
 * `POST /apps/requests/{id}/answer` with the client's own key. Unlike
 * `requests->answer()`, it sends it again after a lost connection, a 429 or
 * a 5xx, inside the client's `maxRetries`, since it sends the very same
 * answer and Siren takes that again as a duplicate. A refusal, such as 422
 * `app_answer_invalid` or 409 `app_request_already_answered`, goes to
 * `onError` and is not sent again. When the function throws or nothing
 * lands, Siren asks again after `answerBy`.
 *
 * The handler keeps no state. Siren may deliver one request twice, so a
 * function writes once by `$request->id`.
 */
final class AppRequestHandler
{
    /** Why a body a parser already read gets a 500. Siren's delivery history shows these words. */
    public const RAW_BODY_NEEDED = 'The request handler got a body a parser already read, so it cannot check the signature, '
        . 'which covers the exact bytes Siren sent. Mount the handler before the JSON parser, or give the route a raw body.';

    private const JSON_HEADERS = ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'];

    private const OPTIONS = ['toleranceSeconds', 'runLater', 'onError'];

    private readonly int $tolerance;

    /** @var (callable(callable(): void): void)|null */
    private $runLater;

    /** @var callable(\Throwable, AppRequest|WebhookEvent|null): void */
    private $onError;

    /** @var array<string, array<string, callable(AppRequest): mixed>> */
    private array $byKey = [];

    /** @var array<string, callable(AppRequest): mixed> */
    private array $byKind = [];

    /** @var array<string, callable(WebhookEvent): mixed> */
    private array $byEvent = [];

    /**
     * Made by {@see Apps::requestHandler()}, which documents the options.
     *
     * @internal
     *
     * @param array<string, mixed> $options
     *
     * @throws \InvalidArgumentException For an empty secret or an option it can't use.
     */
    public function __construct(
        private readonly AppRequests $requests,
        #[\SensitiveParameter] private readonly string $secret,
        array $options = [],
    ) {
        if ($secret === '') {
            throw new \InvalidArgumentException("apps->requestHandler() needs the app's signing secret, which Siren showed when it made the app.");
        }

        foreach (array_keys($options) as $name) {
            if (!in_array($name, self::OPTIONS, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'apps->requestHandler() takes the options %s, and not %s.',
                    implode(', ', self::OPTIONS),
                    $name,
                ));
            }
        }

        $tolerance = $options['toleranceSeconds'] ?? Apps::DEFAULT_TOLERANCE_SECONDS;

        if (!is_int($tolerance) || $tolerance < 1 || $tolerance > Apps::MAX_TOLERANCE_SECONDS) {
            throw new \InvalidArgumentException(sprintf(
                'apps->requestHandler() takes toleranceSeconds as a whole number from 1 to %d.',
                Apps::MAX_TOLERANCE_SECONDS,
            ));
        }

        $runLater = $options['runLater'] ?? null;

        if ($runLater !== null && !is_callable($runLater)) {
            throw new \InvalidArgumentException('apps->requestHandler() takes runLater as a function that is handed the work, such as fn ($work) => app()->terminating($work).');
        }

        $onError = $options['onError'] ?? null;

        if ($onError !== null && !is_callable($onError)) {
            throw new \InvalidArgumentException('apps->requestHandler() takes onError as a function of the error and the request or event, or null.');
        }

        $this->tolerance = $tolerance;
        $this->runLater = $runLater;
        $this->onError = $onError ?? self::logError(...);
    }

    /**
     * Registers the function for one feature, `on($kind, $key, $fn)`, or
     * for every feature of a kind, `on($kind, $fn)`. A function for the key
     * wins over one for the whole kind.
     *
     * The function gets the {@see AppRequest}. For a tell its return value
     * is not read. For an ask it returns the answer as an array, such as
     * `['outcome' => 'allocated', 'units' => 'USD', 'shares' => [...]]`, or
     * null to answer later. {@see AppFeatureKind} names the kinds and the
     * shape of each question and answer.
     *
     * @param string                                $kind The feature's kind, such as `distributionResolver`.
     * @param string|callable(AppRequest): mixed    $key  The feature's key, or the function for every key of the kind.
     * @param (callable(AppRequest): mixed)|null    $fn   The function, when `$key` names a key.
     *
     * @throws \InvalidArgumentException For an empty kind or key, no function, or a second function for one feature.
     */
    public function on(string $kind, string|callable $key, ?callable $fn = null): static
    {
        if ($kind === '') {
            throw new \InvalidArgumentException("apps->requestHandler()->on() needs the feature's kind, such as 'distributionResolver'.");
        }

        if ($fn === null) {
            if (is_string($key)) {
                throw new \InvalidArgumentException('apps->requestHandler()->on() needs a function: on($kind, $key, $fn) for one feature, or on($kind, $fn) for every feature of the kind.');
            }

            if (isset($this->byKind[$kind])) {
                throw new \InvalidArgumentException(sprintf('apps->requestHandler()->on() already has a function for every %s feature.', $kind));
            }

            $this->byKind[$kind] = $key;

            return $this;
        }

        if (!is_string($key) || $key === '') {
            throw new \InvalidArgumentException("apps->requestHandler()->on() needs the feature's key as text, such as 'split-by-score'.");
        }

        if (isset($this->byKey[$kind][$key])) {
            throw new \InvalidArgumentException(sprintf('apps->requestHandler()->on() already has a function for %s %s.', $kind, $key));
        }

        $this->byKey[$kind][$key] = $fn;

        return $this;
    }

    /**
     * Registers the function for a webhook event Siren sends to the same
     * address, such as `conversion.approved`. It gets the
     * {@see WebhookEvent}, checked by its V2 signature and its time as an
     * app request is. An event with no function gets 200.
     *
     * @param callable(WebhookEvent): mixed $fn
     *
     * @throws \InvalidArgumentException For an empty type, `app.request`, or a second function for one type.
     */
    public function onEvent(string $type, callable $fn): static
    {
        if ($type === '' || $type === Apps::REQUEST_EVENT) {
            throw new \InvalidArgumentException("apps->requestHandler()->onEvent() needs a webhook event's type, such as 'conversion.approved'. Register app requests with on().");
        }

        if (isset($this->byEvent[$type])) {
            throw new \InvalidArgumentException(sprintf('apps->requestHandler()->onEvent() already has a function for %s.', $type));
        }

        $this->byEvent[$type] = $fn;

        return $this;
    }

    /**
     * Answers one request, in any framework. Send the reply, then run its
     * `after` when it is not null, once the reply has gone.
     *
     * @param string                              $method  The HTTP method.
     * @param string|array<mixed>|null            $rawBody The exact bytes received, such as Laravel's `$request->getContent()`.
     *     A body a framework already decoded can't be checked, and gets a 500 that says so.
     * @param array<string, string|list<string>>  $headers The request headers, matched without regard to case.
     */
    public function answer(string $method, string|array|null $rawBody, array $headers): AppRequestReply
    {
        if (strtoupper($method) !== 'POST') {
            return self::reply(405, 'Siren sends its requests with POST, and this address takes nothing else.', ['Allow' => 'POST']);
        }

        if (!is_string($rawBody)) {
            $this->report(new SirenException(self::RAW_BODY_NEEDED), null);

            return self::reply(500, self::RAW_BODY_NEEDED);
        }

        try {
            $t = Apps::signedTime($rawBody, $headers, $this->secret, $this->tolerance, microtime(true));
        } catch (SignatureVerificationException $e) {
            return self::reply(401, $e->getMessage());
        }

        try {
            $body = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::reply(400, 'The signed body is not JSON.');
        }

        $event = Apps::header($headers, Webhooks::EVENT_HEADER);

        if ($event === null || $event === Apps::REQUEST_EVENT) {
            try {
                return $this->route(Apps::requestFrom($body, $t, $headers));
            } catch (SignatureVerificationException $e) {
                return self::reply(400, $e->getMessage());
            }
        }

        if (!is_array($body)) {
            return self::reply(400, 'The signed body is not a JSON object.');
        }

        $fn = $this->byEvent[$event] ?? null;

        if ($fn === null) {
            return self::reply(200);
        }

        $webhook = Webhooks::eventFrom($body, $event, Apps::header($headers, Webhooks::DELIVERY_HEADER));

        try {
            $fn($webhook);
        } catch (\Throwable $e) {
            $this->report($e, $webhook);

            return self::reply(500, sprintf('The function for %s failed, so Siren will send the event again.', $event));
        }

        return self::reply(200);
    }

    /**
     * Answers the current request in plain PHP: reads `php://input`, the
     * headers and the method, and echoes the reply. For an ask it then ends
     * the response, with `fastcgi_finish_request()` or
     * `litespeed_finish_request()` when the server has one, or else with
     * `Content-Length` and `Connection: close` and a flush, and runs the
     * function and posts its answer. With `runLater`, the work goes there
     * instead.
     */
    public function serve(): void
    {
        $raw = file_get_contents('php://input');
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        $reply = $this->answer(is_string($method) ? $method : '', $raw === false ? null : $raw, self::requestHeaders());

        if (!headers_sent()) {
            http_response_code($reply->status);

            foreach ($reply->headers as $name => $value) {
                header($name . ': ' . $value);
            }

            if ($reply->after !== null) {
                header('Content-Length: ' . strlen($reply->body));
                header('Connection: close');
            }
        }

        echo $reply->body;

        if ($reply->after !== null) {
            self::finishThenRun($reply->after);
        }
    }

    /**
     * This handler as a PSR-15 request handler, for Slim, Mezzio and any
     * framework that routes to one. Needs `psr/http-server-handler`. It
     * finds Guzzle's, Nyholm's, Slim's or Laminas's PSR-17 factories when
     * none are passed.
     */
    public function psr15(?ResponseFactoryInterface $responseFactory = null, ?StreamFactoryInterface $streamFactory = null): AppRequestPsrHandler
    {
        return new AppRequestPsrHandler($this, $responseFactory, $streamFactory);
    }

    /**
     * Ends the response the client is waiting on, then runs the work.
     *
     * @internal Shared with {@see AppRequestPsrHandler}.
     *
     * @param \Closure(): void $work
     */
    public static function finishThenRun(\Closure $work): void
    {
        ignore_user_abort(true);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } elseif (PHP_SAPI !== 'cli') {
            // A command line has no client waiting, so its buffers stay as they are.
            while (ob_get_level() > 0 && ob_end_flush()) {
            }

            flush();
        }

        $work();
    }

    private function route(AppRequest $request): AppRequestReply
    {
        $kind = (string) $request->item['kind'];
        $key = (string) $request->item['key'];
        $fn = $this->byKey[$kind][$key] ?? $this->byKind[$kind] ?? null;

        if ($fn === null) {
            return self::reply(404, sprintf('No function handles %s %s.', $kind, $key));
        }

        if (!$request->isAsk()) {
            try {
                $fn($request);
            } catch (\Throwable $e) {
                $this->report($e, $request);

                return self::reply(500, sprintf('The function for %s %s failed, so Siren will send the request again.', $kind, $key));
            }

            return self::reply(200);
        }

        $work = function () use ($fn, $request): void {
            $this->work($fn, $request);
        };

        if ($this->runLater === null) {
            return self::reply(200, null, [], $work);
        }

        try {
            ($this->runLater)($work);
        } catch (\Throwable $e) {
            $this->report($e, $request);

            return self::reply(500, sprintf('runLater did not take the work for %s %s, so Siren will send the request again.', $kind, $key));
        }

        return self::reply(200);
    }

    /**
     * Runs an ask's function and posts the answer it returns.
     *
     * @param callable(AppRequest): mixed $fn
     */
    private function work(callable $fn, AppRequest $request): void
    {
        try {
            $answer = $fn($request);
        } catch (\Throwable $e) {
            $this->report($e, $request);

            return;
        }

        if ($answer === null) {
            return;
        }

        if (!is_array($answer)) {
            $this->report(new \InvalidArgumentException(sprintf(
                "The function for %s %s returns the answer as an array, such as ['outcome' => '...'], or null to answer later, and it returned %s.",
                $request->item['kind'],
                $request->item['key'],
                get_debug_type($answer),
            )), $request);

            return;
        }

        try {
            $this->requests->answerRetried($request->id, $answer);
        } catch (\Throwable $e) {
            $this->report($e, $request);
        }
    }

    private function report(\Throwable $error, AppRequest|WebhookEvent|null $about): void
    {
        try {
            ($this->onError)($error, $about);
        } catch (\Throwable $failure) {
            self::logError($failure, $about);
        }
    }

    private static function logError(\Throwable $error, AppRequest|WebhookEvent|null $about): void
    {
        $what = match (true) {
            $about instanceof AppRequest => sprintf(' for %s %s, request %s', $about->item['kind'], $about->item['key'], $about->id),
            $about instanceof WebhookEvent => ' for ' . $about->getType(),
            default => '',
        };

        error_log(sprintf('Siren request handler%s: %s: %s', $what, get_class($error), $error->getMessage()));
    }

    /**
     * @param array<string, string> $headers
     * @param (\Closure(): void)|null $after
     */
    private static function reply(int $status, ?string $error = null, array $headers = [], ?\Closure $after = null): AppRequestReply
    {
        $body = $error === null ? '{}' : json_encode(['error' => $error], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return new AppRequestReply($status, self::JSON_HEADERS + $headers, $body, $after);
    }

    /**
     * The current request's headers, from `getallheaders()` or `$_SERVER`.
     *
     * @return array<string, string>
     */
    private static function requestHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();

            if (is_array($headers) && $headers !== []) {
                return $headers;
            }
        }

        $headers = [];

        foreach ($_SERVER as $name => $value) {
            if (is_string($value) && str_starts_with((string) $name, 'HTTP_')) {
                $headers[str_replace('_', '-', substr((string) $name, 5))] = $value;
            }
        }

        foreach (['CONTENT_TYPE' => 'Content-Type', 'CONTENT_LENGTH' => 'Content-Length'] as $name => $header) {
            if (is_string($_SERVER[$name] ?? null)) {
                $headers[$header] = $_SERVER[$name];
            }
        }

        return $headers;
    }
}
