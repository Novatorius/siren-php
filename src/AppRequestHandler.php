<?php

declare(strict_types=1);

namespace Siren\Sdk;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Siren\Sdk\Exception\ConflictException;
use Siren\Sdk\Exception\ErrorCode;
use Siren\Sdk\Exception\SignatureVerificationException;
use Siren\Sdk\Exception\SirenException;
use Siren\Sdk\Exception\ValidationException;
use Siren\Sdk\Resources\AppRequests;
use Siren\Sdk\Resources\Apps;
use Siren\Sdk\Resources\Webhooks;
use Siren\Sdk\Testing\SignedRequest;

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
 * | A body over {@see BODY_LIMIT} bytes, more than Siren sends | 413 `{"error"}` |
 * | A body a parser already read, so no bytes to check | 500 `{"error"}`, and `onError` hears why |
 * | A failed check of {@see Apps::verifyRequest()}: the signature, the timestamp header or the time | 401 `{"error"}` with its words |
 * | A signed body that is not a JSON object, or an `app.request` that is not a request of version 1 | 400 `{"error"}` |
 * | A tell with a function | 200 `{}` once the function returns, or 500 when it throws |
 * | An ask whose `answerUrl` is on another Siren than this client's `baseUrl` | 500 `{"error"}` naming both, and `onError` hears it |
 * | An ask with a function | 200 `{}` at once. The function runs after the reply, and the answer it returns is posted |
 * | An `app.request` for a feature with no function | 404 `{"error": "No function handles <kind> <key>."}` |
 * | Another event with a function from `onEvent` | 200 `{}` once it returns, or 500 when it throws |
 * | Another event with no function | 200 `{}` |
 *
 * An ask's function returns the answer as an array, or {@see later()} when
 * the app answers later with `$siren->apps->requests->answer()`. A function
 * that returns nothing is an error, and `onError` hears it. The handler checks
 * the answer as `requests->answer()` does and posts it to
 * `POST /apps/requests/{id}/answer` with the client's own key. Unlike
 * `requests->answer()`, it sends it again after a lost connection, a 429 or
 * a 5xx, inside the client's `maxRetries`, since it sends the very same
 * answer and Siren takes that again as a duplicate. A refusal, such as 422
 * `app_answer_invalid` or 409 `app_request_already_answered`, goes to
 * `onError` and is not sent again. When the function throws or nothing
 * lands, Siren asks again after `answerBy`, and the default `onError` line
 * says when and how to resend it sooner.
 *
 * The handler keeps no state. Siren may deliver one request twice, and sends
 * an ask again each time its answer window closes with no answer, so a
 * function writes once by `$request->id` and an ask's function returns the
 * same answer each time.
 */
final class AppRequestHandler
{
    /** Why a body a parser already read gets a 500. Siren's delivery history shows these words. */
    public const RAW_BODY_NEEDED = 'The request handler got a body a parser already read, so it cannot check the signature, '
        . 'which covers the exact bytes Siren sent. Mount the handler before the JSON parser, or give the route a raw body.';

    /** The largest body the handler reads, 1 MiB. Siren's requests are far smaller. */
    public const BODY_LIMIT = 1048576;

    /**
     * Why a PSR-15 ask gets a 500 on a server that keeps PHP workers running.
     * Such a worker runs shutdown functions only when it stops, so the
     * answer would wait until then.
     */
    public const RUN_LATER_NEEDED = 'This server keeps PHP workers running, as RoadRunner, Swoole, Octane and FrankenPHP\'s worker mode do, '
        . 'so an answer left to a shutdown function would wait until the worker stops. Pass runLater to apps->requestHandler(), '
        . 'a function that runs the work once the response has gone.';

    private const JSON_HEADERS = ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'];

    /**
     * Siren Cloud's two public hosts, which count as one Siren when an ask's
     * `answerUrl` is checked against the client's base address. Cloud builds
     * `answerUrl` from its `APP_URL`, which the repository points at the app
     * host: Siren's .github/workflows/deploy-cloud.yml checks health at
     * https://app.sirenaffiliates.com/health, and publish-product-screenshots.yml
     * sets APP_URL=https://app.sirenaffiliates.com. A client defaults to the
     * api host. Cloudflare proxies both hosts to the same origin, and its
     * nginx serves any host with `server_name _` (docs/origin-lock-runbook.md),
     * so an answer posted to either reaches the same Siren. Both must be https.
     */
    private const CLOUD_HOSTS = ['api.sirenaffiliates.com', 'app.sirenaffiliates.com'];

    private const OPTIONS = ['toleranceSeconds', 'runLater', 'onError', 'extraKinds'];

    private readonly int $tolerance;

    /** @var (callable(callable(): void): void)|null */
    private $runLater;

    /** @var (callable(\Throwable, AppRequest|WebhookEvent|null): void)|null The caller's, or null for the default line. */
    private $onError;

    /** @var array<string, true> The kinds {@see on()} takes: {@see AppItemKind::SENT} and `extraKinds`. */
    private array $kinds;

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
            throw new \InvalidArgumentException(
                "apps->requestHandler() needs the app's signing secret, which Siren showed when it made the app. Pass it, or set SIREN_SIGNING_SECRET.",
            );
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

        $extraKinds = $options['extraKinds'] ?? [];

        if (!is_array($extraKinds) || !array_is_list($extraKinds)
            || array_filter($extraKinds, static fn ($kind): bool => !is_string($kind) || $kind === '') !== []) {
            throw new \InvalidArgumentException("apps->requestHandler() takes extraKinds as a list of kinds, such as ['payoutReview'].");
        }

        /** @var list<string> $extraKinds */
        $this->tolerance = $tolerance;
        $this->runLater = $runLater;
        $this->onError = $onError;
        $this->kinds = array_fill_keys(array_merge(AppItemKind::SENT, $extraKinds), true);
    }

    /**
     * What an ask's function returns when the app will answer the request
     * itself, later, with `$siren->apps->requests->answer()`. The handler
     * then posts nothing and reports nothing.
     *
     * ```php
     * $answers->on('payoutMethod', 'bank-transfer', function ($request) use ($answers, $queue) {
     *     $queue->push($request->id, $request->data);
     *     return $answers->later();
     * });
     * ```
     */
    public function later(): AppAnswerLater
    {
        return AppAnswerLater::instance();
    }

    /**
     * Registers the function for one feature, `on($kind, $key, $fn)`, or
     * for every feature of a kind, `on($kind, $fn)`. A function for the key
     * wins over one for the whole kind.
     *
     * The function gets the {@see AppRequest}. For a tell its return value
     * is not read. For an ask it returns the answer as an array, such as
     * `['outcome' => 'allocated', 'units' => 'USD', 'shares' => [...]]`, or
     * {@see later()} to answer later. Returning nothing from an ask is an
     * error. {@see AppItemKind} names the kinds and the shape of each
     * question and answer.
     *
     * The kind must be one Siren sends, {@see AppItemKind::SENT}, or one of
     * the handler's `extraKinds`. Any other kind is refused at once, with
     * what to write instead, since Siren would never reach its function.
     *
     * @param string                                $kind The feature's kind, such as `distributionResolver`.
     * @param string|callable(AppRequest): mixed    $key  The feature's key, or the function for every key of the kind.
     * @param (callable(AppRequest): mixed)|null    $fn   The function, when `$key` names a key.
     *
     * @throws \InvalidArgumentException For an empty or unknown kind, an empty key, no function, or a second function for one feature.
     */
    public function on(string $kind, string|callable $key, ?callable $fn = null): static
    {
        if ($kind === '') {
            throw new \InvalidArgumentException("apps->requestHandler()->on() needs the feature's kind, such as 'distributionResolver'.");
        }

        if (!isset($this->kinds[$kind])) {
            throw new \InvalidArgumentException(self::unknownKind($kind));
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
        return $this->respond($method, $rawBody, $headers, false);
    }

    /**
     * Runs the whole handler on a request without Siren and without posting
     * anything: the signature check, the routing, the function, and for an
     * ask the check the answer gets before it would be posted. The unit test
     * hook, with {@see \Siren\Sdk\Testing\AppRequests::make()}:
     *
     * ```php
     * $result = $answers->simulate(AppRequests::make('distributionResolver', 'split-by-score'));
     * $this->assertSame('allocated', $result->answer['outcome']);
     * ```
     *
     * An ask's function runs before it returns, not after the reply. A
     * failure `onError` would hear is handed back as `error` instead.
     */
    public function simulate(SignedRequest $request): AppRequestSimulation
    {
        $trace = new \stdClass();
        $trace->answer = null;
        $trace->error = null;

        $reply = $this->respond($request->method, $request->rawBody, $request->headers, false, $trace);

        /** @var array<string, mixed>|null $answer */
        $answer = $trace->answer;
        /** @var \Throwable|null $error */
        $error = $trace->error;

        return new AppRequestSimulation($reply->status, $reply->headers, $reply->body, $answer, $error);
    }

    /**
     * {@see answer()} for {@see AppRequestPsrHandler}, which runs an ask's
     * work from a shutdown function. On a server that keeps workers running
     * and with no `runLater`, an ask gets 500 and `onError` hears why.
     *
     * @internal
     *
     * @param string|array<mixed>|null           $rawBody
     * @param array<string, string|list<string>> $headers
     */
    public function answerForShutdown(string $method, string|array|null $rawBody, array $headers): AppRequestReply
    {
        return $this->respond($method, $rawBody, $headers, $this->runLater === null && self::keepsWorkersRunning());
    }

    /**
     * Whether this PHP process serves many requests in one worker that
     * stays running: RoadRunner sets `RR_MODE`, Octane `LARAVEL_OCTANE` and
     * FrankenPHP's worker mode `FRANKENPHP_WORKER`, and Swoole and OpenSwoole
     * serve each request inside a coroutine.
     *
     * @internal
     */
    public static function keepsWorkersRunning(): bool
    {
        foreach (['RR_MODE', 'LARAVEL_OCTANE', 'FRANKENPHP_WORKER'] as $name) {
            $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

            if (is_scalar($value) && $value !== '' && $value !== false) {
                return true;
            }
        }

        foreach (['Swoole\\Coroutine::getCid', 'OpenSwoole\\Coroutine::getCid'] as $currentCoroutine) {
            // Each answers -1 outside a coroutine, and Swoole serves every request inside one.
            if (is_callable($currentCoroutine) && $currentCoroutine() > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string|array<mixed>|null           $rawBody
     * @param array<string, string|list<string>> $headers
     */
    private function respond(string $method, string|array|null $rawBody, array $headers, bool $workHeld, ?\stdClass $trace = null): AppRequestReply
    {
        if (strtoupper($method) !== 'POST') {
            return self::reply(405, 'Siren sends its requests with POST, and this address takes nothing else.', ['Allow' => 'POST']);
        }

        if (is_string($rawBody) && strlen($rawBody) > self::BODY_LIMIT) {
            return self::reply(413, sprintf('The request body is over %d bytes, more than Siren sends.', self::BODY_LIMIT));
        }

        if (!is_string($rawBody)) {
            $this->report(new SirenException(self::RAW_BODY_NEEDED), null, $trace);

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
                return $this->route(Apps::requestFrom($body, $t, $headers), $workHeld, $trace);
            } catch (SignatureVerificationException $e) {
                return self::reply(400, $e->getMessage());
            }
        }

        // `{}` and `[]` both decode to an empty array, so the first byte tells them apart.
        if (!is_array($body) || !str_starts_with(ltrim($rawBody), '{')) {
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
            $this->report($e, $webhook, $trace);

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
        // One byte past the limit is enough to refuse the body with 413.
        $raw = file_get_contents('php://input', false, null, 0, self::BODY_LIMIT + 1);
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

    private function route(AppRequest $request, bool $workHeld, ?\stdClass $trace = null): AppRequestReply
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
                $this->report($e, $request, $trace, 'Siren sends it again in about a minute.');

                return self::reply(500, sprintf('The function for %s %s failed, so Siren will send the request again.', $kind, $key));
            }

            return self::reply(200);
        }

        $elsewhere = $this->answerGoesElsewhere($request);

        if ($elsewhere !== null) {
            $this->report(new SirenException($elsewhere), $request, $trace);

            return self::reply(500, $elsewhere);
        }

        if ($trace !== null) {
            $this->work($fn, $request, $trace);

            return self::reply(200);
        }

        $work = function () use ($fn, $request): void {
            $this->work($fn, $request);
        };

        if ($this->runLater === null && $workHeld) {
            $this->report(new SirenException(self::RUN_LATER_NEEDED), $request);

            return self::reply(500, self::RUN_LATER_NEEDED);
        }

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
     * Runs an ask's function and posts the answer it returns. In a
     * simulation it checks the answer as Siren would, with Siren's words,
     * and posts nothing.
     *
     * @param callable(AppRequest): mixed $fn
     */
    private function work(callable $fn, AppRequest $request, ?\stdClass $trace = null): void
    {
        $next = self::nextSend($request);

        try {
            $answer = $fn($request);
        } catch (\Throwable $e) {
            $this->report($e, $request, $trace, $next);

            return;
        }

        if ($answer instanceof AppAnswerLater) {
            return;
        }

        if ($answer === null) {
            $this->report(new \UnexpectedValueException(sprintf(
                'The function for %s %s returned nothing. Return the answer, or return $answers->later() when you will answer with apps->requests->answer().',
                $request->item['kind'],
                $request->item['key'],
            )), $request, $trace, $next);

            return;
        }

        if (!is_array($answer)) {
            $this->report(new \InvalidArgumentException(sprintf(
                "The function for %s %s returns the answer as an array, such as ['outcome' => '...'], or \$answers->later() to answer later, and it returned %s.",
                $request->item['kind'],
                $request->item['key'],
                get_debug_type($answer),
            )), $request, $trace, $next);

            return;
        }

        try {
            if ($trace !== null) {
                $this->requests->check($request->id, $answer);
                $problem = AppAnswerCheck::problem((string) $request->item['kind'], $request->data, $answer);

                if ($problem !== null) {
                    throw new ValidationException($problem, 422, ErrorCode::APP_ANSWER_INVALID);
                }

                $trace->answer = $answer;

                return;
            }

            $this->requests->answerRetried($request->id, $answer);
        } catch (ConflictException $e) {
            // Siren took another answer or stopped waiting, so it sends nothing again.
            $sendsAgain = !in_array($e->getErrorCode(), ['app_request_already_answered', 'app_request_dropped'], true);
            $this->report($e, $request, $trace, $sendsAgain ? $next : null);
        } catch (\Throwable $e) {
            $this->report($e, $request, $trace, $next);
        }
    }

    /**
     * What the default `onError` line adds for an ask that failed after its
     * 200: Siren sends it again when its answer window closes, and a person
     * can resend it sooner.
     */
    private static function nextSend(AppRequest $request): string
    {
        $answerBy = $request->answerBy === null ? false : strtotime($request->answerBy);
        $when = $answerBy === false ? '' : ' after ' . gmdate('H:i', $answerBy) . ' UTC';

        return sprintf(
            'Siren sends it again%s, when its answer window closes. Resend it sooner from Needs attention in Siren Cloud, or with `siren apps resend %s`.',
            $when,
            $request->id,
        );
    }

    /**
     * Why `on()` refuses a kind Siren never sends, and what to write instead.
     */
    private static function unknownKind(string $kind): string
    {
        $folded = static fn (string $name): string => strtolower(str_replace(['_', '-', ' '], '', $name));
        $given = $folded($kind);

        if (str_contains($kind, '.')) {
            return sprintf('%s is a webhook event. Register it with onEvent().', $kind);
        }

        if ($given === 'fulfillmentmethod') {
            return sprintf('%s is the old name. Use %s.', $kind, AppItemKind::PAYOUT_METHOD);
        }

        if ($given === $folded(AppItemKind::CUSTOM_EVENT)) {
            return 'Siren never sends a custom event. Your app sends one with events->ingest().';
        }

        foreach (AppItemKind::SENT as $known) {
            if ($given === $folded($known)) {
                return str_contains($kind, '_')
                    ? sprintf('Kinds are camelCase, as Siren sends them: %s.', $known)
                    : sprintf('%s is not a feature kind. Did you mean %s?', $kind, $known);
            }
        }

        foreach (AppItemKind::SENT as $known) {
            $name = AppItemKind::NAMES[$known];

            if ($given === $folded($name)) {
                return sprintf("%s%s kind is %s.", $name, str_ends_with($name, 's') ? "'" : "'s", $known);
            }
        }

        foreach (AppItemKind::SENT as $known) {
            if (levenshtein($kind, $known) <= 2) {
                return sprintf('%s is not a feature kind. Did you mean %s?', $kind, $known);
            }
        }

        return sprintf('%s is not a feature kind. The kinds are: %s.', $kind, implode(', ', AppItemKind::SENT));
    }

    /**
     * Why an ask's answer can't go where Siren takes it, or null when it can.
     * The answer is posted only to this client's base address, never to an
     * address from the request, so the key can't be sent elsewhere. When the
     * request's `answerUrl` is on another Siren, the answer would be lost, so
     * the handler says so on the first request.
     */
    private function answerGoesElsewhere(AppRequest $request): ?string
    {
        $answerUrl = (string) $request->answerUrl;
        $base = $this->requests->baseUrl();
        $sent = self::origin($answerUrl);
        $ours = self::origin($base);

        if ($sent !== null && $ours !== null) {
            if ($sent === $ours) {
                return null;
            }

            if ($sent[0] === 'https' && $ours[0] === 'https' && $sent[2] === 443 && $ours[2] === 443
                && in_array($sent[1], self::CLOUD_HOSTS, true) && in_array($ours[1], self::CLOUD_HOSTS, true)) {
                return null;
            }
        }

        $sirenBase = preg_replace('#/apps/requests/[^/]+/answer$#', '', $answerUrl);

        return sprintf(
            'This request\'s answerUrl is %s, but this client sends answers to %s, so the answer would never reach the Siren that asked. Set baseUrl to %s.',
            $answerUrl,
            $base,
            $sirenBase === null || $sirenBase === $answerUrl ? 'that Siren\'s API address' : $sirenBase,
        );
    }

    /**
     * A URL's scheme, host and port, in lowercase with the default port filled in.
     *
     * @return array{0: string, 1: string, 2: int}|null
     */
    private static function origin(string $url): ?array
    {
        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        return [$scheme, strtolower($parts['host']), $parts['port'] ?? ($scheme === 'https' ? 443 : 80)];
    }

    /**
     * @param string|null $next What happens next, which the default line adds.
     */
    private function report(\Throwable $error, AppRequest|WebhookEvent|null $about, ?\stdClass $trace = null, ?string $next = null): void
    {
        if ($trace !== null) {
            $trace->error ??= $error;

            return;
        }

        if ($this->onError === null) {
            self::logError($error, $about, $next);

            return;
        }

        try {
            ($this->onError)($error, $about);
        } catch (\Throwable $failure) {
            self::logError($failure, $about);
        }
    }

    private static function logError(\Throwable $error, AppRequest|WebhookEvent|null $about, ?string $next = null): void
    {
        $what = match (true) {
            $about instanceof AppRequest => sprintf(' for %s %s, request %s', $about->item['kind'], $about->item['key'], $about->id),
            $about instanceof WebhookEvent => ' for ' . $about->getType(),
            default => '',
        };
        $message = rtrim($error->getMessage());

        if ($next !== null) {
            $message .= (preg_match('/[.!?]$/', $message) === 1 ? ' ' : '. ') . $next;
        }

        error_log(sprintf('Siren request handler%s: %s: %s', $what, get_class($error), $message));
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
