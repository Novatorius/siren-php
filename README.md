# Siren SDK for PHP

Answer Siren from your own PHP app in a few lines. One handler checks each signed request Siren sends, runs your function, and posts the answer back. The same client records sales, verifies webhooks and reconciles your ledger.

[![Packagist Version](https://img.shields.io/packagist/v/siren/sdk.svg)](https://packagist.org/packages/siren/sdk)
[![CI](https://github.com/Novatorius/siren-php/actions/workflows/ci.yml/badge.svg)](https://github.com/Novatorius/siren-php/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](./LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/siren/sdk.svg)](https://packagist.org/packages/siren/sdk)

[Siren](https://sirenaffiliates.com) is an affiliate, referral and incentive
platform. You track the events that matter, such as sales, refunds and
referred visits, and Siren credits collaborators, works out rewards and pays
them.

## How Siren talks to your app

- **An app** is your own code that works with Siren. It holds a key, which it calls Siren with, and an address, where Siren sends it signed requests.
- **The signing secret** belongs to the address. Siren signs every request with it, and the handler checks each one.
- **A feature** is what the app adds to one of Siren's pickers. Its kind says what it does, such as `distributionResolver`, and its key names it in your app.
- **A tell** lets the app know something happened, such as an approved conversion. The handler runs your function and replies 200.
- **An ask** needs an answer, and the work it's about waits for it. The handler replies 200 at once, runs your function and posts what it returns.
- **A custom event** goes the other way: the app sends it to Siren.

## What you can do

This SDK is the official PHP client for the Siren API. With it you can:

- Answer Siren from an app you host, in a few lines. One request handler
  checks each signed request, runs the function you register for each
  feature, replies, and posts an ask's answer. Mount it in plain PHP, Slim,
  Mezzio, Laravel or any framework. See
  [Answer Siren in a few lines](#answer-siren-in-a-few-lines).
- Try a handler without Siren. `Siren\Sdk\Testing\AppRequests::make()`
  builds a signed request with Siren's own sample question, and
  `simulate()` runs the handler on it and gives back the answer without
  posting it. See [Try it without Siren](#try-it-without-siren).
- Answer every kind of app feature, from post-conversion and post-allocation
  actions to resolvers, filters, calculations, group structures and payout
  methods, with PHPStan types for each question and answer. See
  [Feature kinds](#feature-kinds) and [Payout method](#payout-method-payoutmethod).
- Split a resolver's pool in whole currency steps with `apps->splitPool()`.
- Send an app's custom events with short-lived tokens the SDK gets and keeps
  fresh, and give a web page a browser token for one event. See
  [Custom events with short-lived tokens](#custom-events-with-short-lived-tokens).
- Check a request and answer it yourself with `apps->verifyRequest()` and
  `apps->requests->answer()`. See [Check and answer by hand](#check-and-answer-by-hand).
- Record commerce and tracking events (`sale`, `refund`, `siteVisited`, and custom event types).
- Verify signed webhooks in a single, constant-time call.
- Read, change, test and remove an app's webhook address, and read its
  deliveries, with a signed-in dashboard session's token.
- Reconcile Siren's ledger (conversions, transactions, obligations, payouts
  and fulfillments) against your own, with an API key, a dashboard session's
  token or a connected site's token.
- Read and write the records a connector needs: collaborators and their
  tracking codes and program enrollments, mappings, programs, program groups,
  opportunities, transactions, conversions, engagements, distributors, custom
  currencies, store credit and its holds, shared settings, and collaborator
  groups.
- Run inside any host: bring your own HTTP client and your own OAuth access token.

The full API surface is described in the [OpenAPI spec](./openapi.yaml).

## Install

The SDK needs PHP 8.1 or later and `ext-json`. It uses `guzzlehttp/guzzle`
^7.8 when it is installed and PHP's own HTTP streams when it is not, so a host
that already ships its own HTTP stack never loads a second copy of Guzzle.

```bash
composer require siren/sdk
```

The apps code in this README is unreleased. That covers the request handler,
`Siren\Sdk\Testing`, `verifyRequest()`, `requests->answer()`, `splitPool()`
and app tokens for custom events. The `siren/sdk` on Packagist lacks it until
the apps pull request merges and a release ships. Until then, install the
`feat/apps` branch from GitHub:

```bash
composer config repositories.siren vcs https://github.com/Novatorius/siren-php
composer require siren/sdk:dev-feat/apps
```

Or install it from a checkout beside your project:

```bash
composer config repositories.siren path ../siren-php
composer require siren/sdk:@dev
```

A checkout has no version tag, so Composer reads it as a dev version. `@dev`
lets your project take it even when its `minimum-stability` is `stable`.

## Answer Siren in a few lines

A Siren app is a service you host that acts as part of Siren's engine. Each of
its features joins one of Siren's pickers, and Siren sends the app's address a
signed request whenever a record that picked the feature needs it. A tell
says what happened and waits for a 2xx. An ask waits for an answer.

`$siren->apps->requestHandler()` answers those requests for you. Register a
function for each feature by its kind and key, and mount the handler at the
app's address. It checks Siren's signature, finds the function, replies, and
for an ask posts the answer the function returns once the reply has gone.
`new Siren()` reads the app's own key from `SIREN_API_KEY`, and the handler
posts answers with it, to the Siren that `SIREN_BASE_URL` names, or Siren
Cloud when it is unset. `requestHandler()` reads the app's signing secret,
which Siren showed once when it made the app's address, from
`SIREN_SIGNING_SECRET`. Pass either one in code to override the variable.

With no framework, put this in the file the app's address points at:

<!-- readme-test: answer-tell -->
```php
<?php
require 'vendor/autoload.php';
use Siren\Sdk\Siren;

$siren = new Siren();         // the app's key, from SIREN_API_KEY

$siren->apps->requestHandler() // the signing secret, from SIREN_SIGNING_SECRET
    ->on('postConversionAction', 'tell-the-crm', function ($request) {
        if ($request->test) {
            return; // a test from `siren trigger`: write nothing
        }
        // Siren may send it twice, so add it once, keyed by $request->id.
        addToCrm($request->id, $request->data['conversion'], $request->data['reward']);
    })
    ->serve();
```

`serve()` reads `php://input` and the headers, and for an ask it sends the
reply, ends the response with `fastcgi_finish_request()` or
`litespeed_finish_request()`, or with `Content-Length`, `Connection: close`
and a flush on other servers, and then runs the function. Only the function
changes from feature to feature, and [Feature kinds](#feature-kinds) gives
one for each kind.

## Try it without Siren

`Siren\Sdk\Testing\AppRequests::make($kind, $key)` builds the request Siren
would send, signed, with Siren's own sample question for the kind.
`simulate()` runs the whole handler on it, from the signature check to the
checks Siren makes of an answer, and gives back the reply and the answer
without posting anything. This PHPUnit test runs a distribution resolver:

<!-- readme-test: try-it -->
```php
<?php
use PHPUnit\Framework\TestCase;
use Siren\Sdk\Siren;
use Siren\Sdk\Testing\AppRequests;

final class SplitByScoreTest extends TestCase
{
    public function testItSplitsThePoolByScore(): void
    {
        $siren = new Siren(['apiKey' => 'sk_test_unused']); // simulate() posts nothing
        $answers = $siren->apps->requestHandler('test-secret')
            ->on('distributionResolver', 'split-by-score', fn ($request) => [
                'outcome' => 'allocated',
                'units' => $request->data['pool']['units'],
                'shares' => $siren->apps->splitPool($request->data['pool'], array_column($request->data['metrics'], 'score', 'metricId')),
            ]);

        $request = AppRequests::make('distributionResolver', 'split-by-score', ['secret' => 'test-secret']);
        $result = $answers->simulate($request);

        $this->assertSame(200, $result->status);
        $this->assertSame(['41' => 6000, '43' => 4000], $result->answer['shares']);
    }
}
```

`$result` carries the same four fields in the three SDKs: `status`, `body`,
the reply's JSON decoded, such as `[]` for `{}`, `answer`, and `error`, which
holds what `onError` would have heard, such as a function that threw, one that returned
nothing, or an answer with no `outcome`. `simulate()` also checks the answer
for the kind as Siren does, such as a share for every metric of the question
and no share above the pool. An answer Siren would refuse comes back as a
`ValidationException` with code `app_answer_invalid` and Siren's own words,
and `$result->answer` is null, as it is for a tell and after `later()`. Siren also refuses a collaborator who isn't in
the organization or is in the trash, which only Siren can check. `make()` takes `data` for your own question, and `secret`,
which defaults to `SIREN_SIGNING_SECRET`. `AppRequests::sampleData($kind)`
gives the sample question alone. To try a running app, post the same
request's `method`, `headers` and `rawBody` to its address with any HTTP
client.

`siren trigger ask` sends a test request, and `$request->test` is true for
it. `make()` takes `'test' => true` to build one. Siren checks a test ask's
answer with the kind's own rules and applies nothing, so a handler must
never move money or write records on a test request. Answer it as usual, so
Siren checks the answer, and stop there. Each sample here that writes reads
`$request->test` first: the tells return before they write, and the payout
method sample answers `failed`, the one answer that pays nothing.

A request Siren sent becomes a test case. Save it with
`siren apps requests <id> --json > request.json`, which arrives with the
CLI's next release. The file holds Siren's request with its app's and
feature's keys, `appKey` and `featureKey`, beside it:

<!-- readme-test: replay-record -->
```json
{"id": "apr_0123456789abcdef0123456789abcdef", "appId": 12, "feature": "app:31", "kind": "distributionResolver",
 "mode": "ask", "status": "needs_attention", "sendCount": 3, "answerBy": "2026-11-24T18:00:00+00:00",
 "data": {"distribution": {"id": 21, "distributorId": 2, "periodStart": "2026-10-01T00:00:00+00:00", "periodEnd": "2026-11-01T00:00:00+00:00"},
          "distributor": {"id": 2, "name": "Partner pool"},
          "pool": {"value": 10000, "total": 10000, "paid": 0, "units": "USD", "currency": {"id": "USD", "symbol": "$",
                   "position": "before", "name": "US Dollar", "scale": 2, "decimalPlaces": 2, "type": "monetary"}},
          "metrics": [{"metricId": 41, "collaboratorId": 42, "score": 30, "triggerCount": 3},
                      {"metricId": 43, "collaboratorId": 57, "score": 20, "triggerCount": 2}]},
 "answer": null, "appKey": "acme-crm", "featureKey": "split-by-score", "sends": []}
```

`AppRequests::fromRecord()` reads that file and signs the request again
with its own id, kind, mode, question, `answerBy` and `test`:

<!-- readme-test: replay-a-request -->
```php
<?php
use PHPUnit\Framework\TestCase;
use Siren\Sdk\Siren;
use Siren\Sdk\Testing\AppRequests;

final class ReplayTest extends TestCase
{
    public function testTheStuckRequestGetsAnAnswerSirenTakes(): void
    {
        $siren = new Siren(['apiKey' => 'sk_test_unused']); // simulate() posts nothing
        $answers = $siren->apps->requestHandler('test-secret')
            ->on('distributionResolver', 'split-by-score', fn ($request) => [
                'outcome' => 'allocated',
                'units' => $request->data['pool']['units'],
                'shares' => $siren->apps->splitPool($request->data['pool'], array_column($request->data['metrics'], 'score', 'metricId')),
            ]);

        // Saved with `siren apps requests apr_0123... --json > request.json`.
        $request = AppRequests::fromRecord(file_get_contents(__DIR__ . '/request.json'), options: ['secret' => 'test-secret']);
        $result = $answers->simulate($request);

        $this->assertNull($result->error);
        $this->assertSame(['41' => 6000, '43' => 4000], $result->answer['shares']);
    }
}
```

When the app is gone, `featureKey` is null, so pass the feature's key as
the second argument, such as `fromRecord($record, 'split-by-score')`.
`fromRecord()` also takes the row an admin's session reads with
`apps->requests->retrieve()`, which names the feature only as `app:31`, with
the key passed the same way. A key you pass wins over the saved one, so a
request replays against a renamed feature. A record with no key and none
passed throws `AppHandlerException`.

## Develop on your machine

Siren can send real requests to the handler on your laptop, with no tunnel.
It works with a sandbox Siren only, and takes three terminals.

1. Run `siren apps apply` with a sandbox key, one that starts with
   `sk_test_`. On a sandbox the app needs no address, and apply writes the
   app's key to `.env.sandbox` as `SIREN_API_KEY`, beside `SIREN_BASE_URL`,
   the sandbox's address. `new Siren()` reads both, so the handler answers a
   sandbox ask on the sandbox. An app with no address
   gets no signing secret, so the file holds no `SIREN_SIGNING_SECRET` yet.
2. In a terminal of its own, run
   `siren listen --forward-to localhost:3000/siren` and leave it open. When
   it first opens a session it writes the signing secret to `.env.sandbox`
   as `SIREN_SIGNING_SECRET`, and from then on Siren sends that app's asks,
   tells and webhook events to the handler on your machine, signed with it.
3. Start the app with the file loaded. The handler reads the process's
   environment, and nothing reads `.env.sandbox` by itself, so export its
   lines first. With the sample above in `app.php`, which `php -S` runs
   for every path, `/siren` included:

   ```sh
   set -a; . ./.env.sandbox; set +a; php -S localhost:3000 app.php
   ```

   Restart the app whenever `siren listen` says it saved a new secret.
4. Run `siren trigger ask <app> <feature>` to send the feature's sample
   request through it now, marked as a test. On a live Siren it needs
   `--live` and a yes, and it refuses a payout method.

`siren listen`, `siren trigger`, and `siren apps apply` with no address on
a sandbox arrive with the CLI's next release.

The `siren` command comes with the Node package. Without a Node project, run
any `siren` command in this README through npx, such as
`npx @novatorius/siren listen --forward-to localhost:3000/siren`,
`npx @novatorius/siren apps resend <id>`, or
`npx @novatorius/siren apps rotate-key <app key>`, which the 401 on the
answer route names when Siren does not accept the app's key.

## Run the handler in your app

### Mount it in a framework

Build the handler once, as above, and keep it as `$answers` instead of calling
`serve()`. Then mount it in one of these ways.

Slim, Mezzio and any framework that routes to a PSR-15 handler take
`psr15()`. It needs `psr/http-server-handler`, and runs an ask's work from a
shutdown function once the framework has sent the response:

<!-- readme-test: mount-psr15 -->
```php
$app->post('/siren', $answers->psr15());
```

Laravel, Symfony and any other framework take `answer($method, $rawBody,
$headers)`. It returns an `AppRequestReply` with `status`, `headers`, `body`
and `after`. Send the reply, then run `after` when it is not null:

<!-- readme-test: mount-laravel -->
```php
Route::post('/siren', function (Illuminate\Http\Request $request) use ($answers) {
    $reply = $answers->answer($request->method(), $request->getContent(), $request->headers->all());

    if ($reply->after !== null) {
        app()->terminating($reply->after);
    }

    return response($reply->body, $reply->status, $reply->headers);
});
```

Pass the body exactly as it arrived, such as Laravel's `getContent()` or
Symfony's `$request->getContent()`. A body a framework already decoded can't
be checked, and gets a 500 that says so. Leave the route out of CSRF checks,
since Siren signs the request instead.

### Options

<!-- readme-test: handler-options -->
```php
$answers = $siren->apps->requestHandler(options: [ // the secret, from SIREN_SIGNING_SECRET
    'toleranceSeconds' => 300,                          // as verifyRequest(), 1 to 3600
    'runLater' => fn ($work) => app()->terminating($work), // where an ask's work runs after the reply
    'onError' => fn (\Throwable $error, ?object $about) => report($error), // default error_log()
    'extraKinds' => ['payoutReview'],                   // kinds a newer Siren sends that on() should take
    'logger' => logger(),                               // a PSR-3 logger, such as Laravel's. Default error_log() at warning and above
]);
```

`logger` takes your framework's PSR-3 logger. The handler writes one
`debug` line for each request it handled, such as
`Siren request handler: distributionResolver split-by-score apr_9c60... replied 200 in 4 ms`,
a `warning` for a refused signature and for a tell that ran too long, and an
`error` for the rest of what `onError` gets. Each failure is one line with
the request's name, the error's class and code, its message, and a cause
folded in as `(cause: ...)`. When your own function threw, the line ends
with where, such as `(thrown at /app/src/Crm.php:41)`, and the error goes
in the context as `exception`, so Monolog can add the stack. Without
`logger`, warnings and errors go to `error_log()` and the debug lines go
nowhere. A custom `onError` replaces the default warning and error lines,
and the logger still writes the debug lines. `clock`, a function that
returns the time in seconds, is for tests.

A request whose signature fails gets its 401, and `onError` hears it as a
`SignatureVerificationException` whose code says why, the same codes Node
and Python use: `signature_missing`, `signature_malformed`,
`timestamp_mismatch`, `signature_mismatch` or `timestamp_out_of_tolerance`.
The default
warning says what to check, such as ``Siren request handler: the request
signature does not match. Check SIREN_SIGNING_SECRET: it must be this app's
signing secret, and it changes when the secret is rotated.`` A wrong secret
fails every request, so the default writes five refusals a minute and counts
the rest, in one line when the next refusal after that minute comes:
``12 more requests failed the signature check in the last minute. When every
request fails, SIREN_SIGNING_SECRET is wrong or was rotated.`` A server that
starts PHP fresh for each request, such as PHP-FPM, writes one line for
each refusal.

`onError` gets each failure the reply can't carry, with the `AppRequest` or
`WebhookEvent` it came from: a function that threw, an answer that could not
be sent, and Siren's refusal of one. The default writes one `error_log()`
line. For an ask that failed after its 200, the line also says when Siren
sends it again and how to resend it sooner, such as ``Siren sends it again
after 16:58 UTC, when its answer window closes. Resend it sooner with
`siren apps resend apr_9c60...`.`` A window that ends on another day adds
the day, such as `after 16:58 UTC on 2026-11-18`. The command resends a
pending ask. Siren Cloud offers Send again only once a request needs
attention. An answer Siren refused with 422 `app_answer_invalid` fails the
same way on every send, so its line says instead: ``Your function's answer
broke Siren's rule. Reproduce it with simulate() on this request's data, fix
it, then resend with `siren apps resend apr_9c60...`.``

`extraKinds` lists kinds `on()` takes beyond the thirteen this SDK knows, for
a kind a newer Siren sends before the SDK names it.

RoadRunner, Swoole, Octane and FrankenPHP's worker mode keep one PHP worker
running for many requests and run shutdown functions only when it stops. On
those servers pass `runLater`, a function that runs the work once the
response has gone, such as `fn ($work) => app()->terminating($work)` under
Octane or `fn ($work) => \Swoole\Coroutine::create($work)` under Swoole. Under
RoadRunner, keep the work in a list and run it after `$worker->respond()`.
Without `runLater`, `psr15()` finds such a server where it can and answers an
ask 500, with `onError` told to pass `runLater`, rather than hold the answer
until the worker stops. With `runLater`, `answer()` hands the work to it and
`after` is null.

`runLater` is your own function, so a tell's function can call it too.
Heavy work belongs there or in your own queue: a tell must finish within 8
seconds, and so must an event's function.

### Register functions

`on($kind, $key, $fn)` registers one feature, and `on($kind, $fn)` every
feature of a kind. A function for the key wins. Each function gets the
`AppRequest`, whose `id`, `mode`, `feature` (`kind`, `key` and `id`) and `data`
hold what Siren sent. `test` is true on a test request, such as one from
`siren trigger`, which must write nothing, and `isAsk()` says whether Siren
waits for an answer. A tell's return value is not read. An ask's function
returns the answer as an array, or `$answers->later()` when the app will
answer later with `apps->requests->answer()`. An ask's function that returns
nothing is an error, which `onError` hears, and nothing is posted.

`on()` takes the thirteen kinds Siren sends, `FeatureKind::SENT`, and refuses
any other at once with an `AppHandlerException` that says what to write
instead. A typo gets the kind it's closest to, a screen name such as
`attributionModel` gets its kind, `incentiveResolver`, and a webhook event
such as `conversion.approved` is sent to `onEvent()`.

Every mistake the handler finds in your code is a
`Siren\Sdk\Exception\AppHandlerException`, a `SirenException`: an unknown
kind, a second function for one feature, a bad option, and, through
`onError` or `simulate()`'s `error`, a function that returns nothing, one
that returns something other than an array, and an answer with no text
`outcome`. Node and Python call it `AppHandlerError`.

PHPStan types each function by its kind. An inline closure needs no type:
its `$request->data` is the kind's question, such as
`DistributionResolverQuestion`, and what it returns must be the kind's
answer or `$answers->later()`. A function written elsewhere takes the
types from `FeatureKind`:

```php
use Siren\Sdk\AppRequest;

/**
 * @phpstan-import-type DistributionResolverQuestion from \Siren\Sdk\FeatureKind
 * @phpstan-import-type DistributionResolverAnswer from \Siren\Sdk\FeatureKind
 */
final class SplitByScore
{
    /**
     * @param AppRequest<DistributionResolverQuestion> $request
     * @return DistributionResolverAnswer
     */
    public function __invoke(AppRequest $request): array
    {
        return [
            'outcome' => 'allocated',
            'units' => $request->data['pool']['units'],
            'shares' => array_column($request->data['metrics'], 'score', 'metricId'),
        ];
    }
}

$answers->on('distributionResolver', 'split-by-score', new SplitByScore());
```

At `level: max`, `$request->data['pools']` is "Offset 'pools' does not
exist", `'outcome' => 'allocate'` does not fit `'allocated'`, and
`on('distributionResolvr', ...)` names the kinds `on()` takes. A kind from
`extraKinds` is one PHPStan doesn't know, so that one call needs
`@phpstan-ignore argument.type`.

`onEvent($type, $fn)` takes a webhook event Siren sends to the same address,
such as `conversion.approved`, as a `WebhookEvent`. The handler checks it by
its signature and time, as it checks an app request.

`AppRequests::event($type, $data)` signs a webhook event as Siren signs one
to an app's address, and `simulate()` takes it as it takes an app request.
With no `$data` it sends `AppRequests::sampleEventData($type)`, a payload
with each field Siren's event catalog (`docs/api/webhooks-events.md` in
Siren) lists for the type, filled by its type:

<!-- readme-test: test-an-event -->
```php
<?php
use PHPUnit\Framework\TestCase;
use Siren\Sdk\Siren;
use Siren\Sdk\Testing\AppRequests;
use Siren\Sdk\WebhookEvent;

final class ConversionApprovedTest extends TestCase
{
    public function testItNotesTheApprovedConversion(): void
    {
        $approved = [];
        $siren = new Siren(['apiKey' => 'sk_test_unused']); // simulate() posts nothing
        $answers = $siren->apps->requestHandler('test-secret')
            ->onEvent('conversion.approved', function (WebhookEvent $event) use (&$approved) {
                $approved[] = $event->getData()['conversionId'];
            });

        $result = $answers->simulate(AppRequests::event('conversion.approved', ['conversionId' => 7], ['secret' => 'test-secret']));

        $this->assertSame(200, $result->status);
        $this->assertNull($result->error);
        $this->assertSame([7], $approved);
    }
}
```

### What the handler replies

| Request | Reply |
|---------|-------|
| Not a POST | 405 `{"error"}` |
| A body over 1 MiB, more than Siren sends | 413 `{"error"}` |
| A body a parser already read, so there are no bytes to check | 500 `{"error"}` that says to mount the handler before the JSON parser, or give the route a raw body. `onError` hears it too. |
| `X-Siren-Signature-V2` missing or wrong, `X-Siren-Timestamp` different from the signed time, or a time more than `toleranceSeconds` from the clock | 401 `{"error"}` with `verifyRequest()`'s words. `onError` hears it, and the default writes a warning that says what to check. |
| A signed body that is not a JSON object, or an `app.request` that is not a request of version 1 | 400 `{"error"}` naming the first field that fails, such as ``its `id` must be `apr_` followed by 32 hex characters`` |
| A tell with a function | 200 `{}` once the function returns, or 500 when it throws, so Siren tries again about 1, 2, 4 and 8 minutes later |
| A tell or event whose function takes over 8 seconds | A tell must finish within 8 seconds, as long as Siren waits for a reply. The reply is still 200, but Siren has stopped waiting and sends it again, so the function runs twice. `onError` hears an `AppSlowTellException`, the default writes a warning, and `simulate()` returns it as `error`. |
| When your function fails | A tell gets 500, and Siren tries again about 1, 2, 4 and 8 minutes later. An ask already got its 200, so Siren sends it again when its answer window closes, 60 minutes by default. Resend it sooner with `siren apps resend <id>`. `onError` hears why either way. |
| An ask whose `answerUrl` is on another Siren than the client's `baseUrl` | 500 `{"error"}` naming both addresses and the `baseUrl` to set, and `onError` hears it. Nothing runs and nothing is posted. |
| An ask with a function | 200 `{}` at once, then the function runs and the answer it returns is posted to the client's own `baseUrl` |
| An `app.request` for a feature with no function | 404 `{"error": "No function handles distributionResolver split-by-score."}`, so Siren tries again and then shows the request under Needs attention |
| Another `X-Siren-Event` with a function from `onEvent()` | 200 `{}` once it returns, or 500 when it throws |
| Another `X-Siren-Event` with no function | 200 `{}` |

Every reply is JSON with `Cache-Control: no-store`, and no reply holds the
secret or the body. Siren waits 8 seconds for a reply. The handler keeps no
state, and Siren may deliver one request twice, so a tell's function writes
once by `$request->id`. That id is the same on every send. `feature['id']` names
the feature and `deliveryId` changes on every send, so neither one serves.

An ask's function can also run more than once for one request: on a repeat
delivery, and again on each send after its answer window closes. Write once
by `$request->id` there too. Siren takes the same answer again as a duplicate
and refuses a different one with 409 `app_request_already_answered`, so return
the same answer each time.

### Split a pool

`apps->splitPool($pool, $weights)` takes the question's `pool` and a weight
by id, which `array_column($rows, 'score', 'metricId')` gives in one call. It
answers an amount by id, each a whole number of the currency's step
(`10 ** (scale - decimalPlaces)` in the stored unit, so 100 for yen at scale
2), rounded down so the amounts never add up to more than `pool.value`. When
every weight is 0, it splits the pool evenly. It throws
`InvalidArgumentException` for a negative or non-finite weight, a weight that
is not a number, and a pool with no whole `value` or no currency. On Siren's
sample question, a pool of 10000 with scores 30 and 20, it gives
`['41' => 6000, '43' => 4000]`.

## Feature kinds

Each heading below is the name Siren Cloud's screens give the kind, with the
kind's value, which `$request->feature['kind']` carries and `FeatureKind` names.
`FeatureKind` also sorts the kinds into `TELLS` and `ASKS`, gives each one's
screen name in `NAMES`, and holds the shape of each question and answer as
PHPStan types, for `@phpstan-import-type`:

```php
/** @phpstan-import-type DistributionResolverAnswer from \Siren\Sdk\FeatureKind */
```

The kinds and their names come from Siren's own list of kinds, which this
package ships as `resources/app-kinds.json`. `FeatureKind::catalog()` reads
it: each kind's screen name, Siren's own term for it, a description, and
whether Siren tells it, asks it or receives it from the app. Hover a
constant such as `FeatureKind::INCENTIVE_RESOLVER` and your editor shows its
screen name, Attribution model.

Every amount in a question or an answer is the stored whole number, so 1500
in US dollars is $15.00. Divide by 10 to the power of the currency's `scale`
to show it. Each sample is the function for the handler above, and runs as
written in this repository's tests. The handler checks only what every
answer needs before it posts, a plain array with a text `outcome` of at most
16384 bytes as JSON. Siren checks the rest and refuses an answer that doesn't
fit with 422 `app_answer_invalid` and the reason. `simulate()` runs Siren's
checks too, so a test finds such an answer first.

How long Siren waits for an ask's answer before it sends the ask again is
the feature's answer window, set in its settings, 60 minutes when none is
set. See [Errors and retries](#errors-and-retries) for what happens when no
answer comes.

### Post-conversion action (`postConversionAction`)

A tell. Siren sends it when a conversion is approved in a program that picks
this action. A test sale's conversion is never sent. `data` is an
`PostConversionActionTell`: the `conversion` (its id, type, status and the ids of its
engagement, opportunity, collaborator, transaction and obligation), the
`reward` it earned with its `value`, `units` and `currency`, or null, and the
`program`'s id and name. Siren reads only the reply's status. The sample at
the top of [Answer Siren in a few lines](#answer-siren-in-a-few-lines) is
this kind's.

### Post-allocation action (`postAllocationAction`)

A tell. Siren sends it each time an allocation completes for a distributor
that picks this action. `data` is a `PostAllocationActionTell`: the `allocation`
(the distribution, the obligation and the collaborator it went to, its status,
`value`, `units` and `currency`) and the `distributor`'s id and name.

<!-- readme-test: answer-allocation -->
```php
    ->on('postAllocationAction', 'note-the-share', function ($request) {
        if ($request->test) {
            return; // a test from `siren trigger`: write nothing
        }
        // Siren may send it twice, so note it once, keyed by $request->id.
        noteShare($request->id, $request->data['allocation']);
    })
```

### Distribution resolver (`distributionResolver`)

An ask. Siren asks it when a distribution closes for a distributor that picks
this resolver, and pays no one until the answer. The question is an
`DistributionResolverQuestion`: the `distribution` and its period, the
`distributor`, the `pool` (`value` is what this answer may hand out, `total`
the whole pool and `paid` what collaborators already hold from it), and the
`metrics`, each collaborator Siren would pay with their `metricId`, `score` and
`triggerCount`. The answer is a `DistributionResolverAnswer`: `outcome`
`allocated`, the pool's `units`, and in `shares` every `metricId` with a whole
amount of 0 or more. The shares may add up to less than `pool.value`, never
more.

<!-- readme-test: answer-distribution -->
```php
    ->on('distributionResolver', 'split-by-score', fn ($request) => [
        'outcome' => 'allocated',
        'units' => $request->data['pool']['units'],
        // Whole currency steps, never more than the pool, split evenly when every score is 0.
        'shares' => $siren->apps->splitPool($request->data['pool'], array_column($request->data['metrics'], 'score', 'metricId')),
    ])
```

### Attribution model (`incentiveResolver`)

An ask. Siren's code calls this an incentive resolver. Siren asks it when a
sale, renewal or lead converts in a program that picks this model, and splits
the program's reward among the collaborators it converted. The question is an
`IncentiveResolverQuestion`: `reason` (`sale`, or `refund` when a partial refund asks
again about rewards already made), the `program`, the `conversionType`, the
`transaction` (null for a lead), the reward `pool`, and the `conversions`,
each with its engagement `score`. The answer is an `IncentiveResolverAnswer`:
`outcome` `rewards`, the pool's `units`, and in `rewards` every
`conversionId` as text with a whole amount from 0 up to `pool.value`, or null
for a collaborator who earns nothing. The rewards may add up to more than the
pool.

<!-- readme-test: answer-incentive -->
```php
    ->on('incentiveResolver', 'split-the-reward', fn ($request) => [
        'outcome' => 'rewards',
        'units' => $request->data['pool']['units'],
        'rewards' => $siren->apps->splitPool($request->data['pool'], array_column($request->data['conversions'], 'score', 'conversionId')),
    ])
```

### Incentive structure (`incentiveType`)

An ask. Siren's code calls this an incentive type. Siren asks it when a sale,
lead or renewal comes in for a program that picks this structure, for the
conversions its `conversionTypes` setting lists. The question is an
`IncentiveTypeQuestion`: `reason`, the `program` with its `units`, the
`conversionType`, the `opportunity`, the `transaction` with its `total` and
`lines` (null for a lead, which names its `binding` instead), and the
`engagements` that could convert, at most 250. A refund's question also lists
the award's `conversions`, and its answer changes only the pool. The answer
is an `IncentiveTypeAnswer`: `outcome` `award` with the program's `units`,
a `pool` of 0 or more and the `engagementIds` that convert, each once, or
`['outcome' => 'none']`. The program's attribution model divides the pool.

<!-- readme-test: answer-award -->
```php
    ->on('incentiveType', 'pay-on-closed-deals', function ($request) {
        // A tenth of the sale, shared by every engagement. A lead earns nothing here.
        $sale = $request->data['transaction'];
        if (!$sale || $sale['units'] !== $request->data['program']['units']) {
            return ['outcome' => 'none'];
        }
        return ['outcome' => 'award', 'units' => $sale['units'], 'pool' => intdiv($sale['total'], 10), 'engagementIds' => array_column($request->data['engagements'], 'engagementId')];
    })
```

### Metric calculation (`metricCalculation`)

An ask. Siren's catalog calls this a calculation strategy. Siren asks it each
time a metric triggers for a distributor whose metric type picks this
calculation. The metric scores nothing until the answer, and the distribution
pays no one until then. The question is a `MetricCalculationQuestion`:
the `distributor`, the `distribution` the scores land in, the `metricType`
(such as `referredSiteVisit` or `awardedSale`), the
`distributorMetricTypeId`, the `triggeringCollaborator`, the `opportunityId`,
`points` (an amount a person entered, which replaces every score, or null),
the `cause` (its own random `id`, and the `type` and `causeId` of what caused
a trigger that can be taken back) and the `sale` behind a sale trigger, or
null. The answer is a `MetricCalculationAnswer`: `outcome` `scores` and
`results`, at most 50, each a `collaboratorId` and a whole `score` of 0 or
more, with no collaborator twice. An empty list scores nobody.

<!-- readme-test: answer-metric-calculation -->
```php
    ->on('metricCalculation', 'visit-score', fn ($request) => [
        'outcome' => 'scores',
        // Score the collaborator who triggered it, more for a sale.
        'results' => [['collaboratorId' => $request->data['triggeringCollaborator']['id'], 'score' => $request->data['metricType'] === 'awardedSale' ? 10 : 1]],
    ])
```

### Customer filter for distributors (`metricFilter`)

An ask. Siren's code calls this a metric filter. Siren asks it each time a
metric triggers for a distributor that picks this filter, after its built-in
filters allow it. The question is a `MetricFilterQuestion`: the
`distributor`, the `distribution`, the `collaborator` the metric would credit,
the `metricType`, the `opportunityId`, `points`, the `cause` and the `sale`,
as a metric calculation's. The answer is a `MetricFilterAnswer`:
`['outcome' => 'allow']`, or `outcome` `suppress` with a plain text `reason`
of 1 to 200 characters, which Siren notes on the distributor.

<!-- readme-test: answer-metric-filter -->
```php
    ->on('metricFilter', 'refund-check', function ($request) {
        // Suppress a sale the CRM knows was refunded. Allow everything else.
        if ($request->data['sale'] && isRefunded($request->data['sale']['transactionId'])) {
            return ['outcome' => 'suppress', 'reason' => 'The order was refunded within 30 days.'];
        }
        return ['outcome' => 'allow'];
    })
```

### Transaction filter (`transactionFilter`)

An ask. Siren's catalog calls this a transaction compiler. Siren asks it once
per sale for each program or distributor that picks it, to learn which of the
sale's own lines count. The question is a `TransactionFilterQuestion`:
what the filter is `for` (a program or a distributor), the `transaction` with
its `units` and `currency`, and the sale's `lines`. Each line is an
`AppTransactionLine` with its `id`, its `type` (`lineItem`, `discount`, `fee`,
`shipping` or `tax`), its `name`, a whole `quantity`, its unit `value` and a
`productId` or null. `distribution` is null for a program, and for a
distributor the period the sale's value lands in. `refundDistribution` is
null, or the later period a refund came in while the ask was open. The answer
is a `TransactionFilterAnswer`: `outcome` `filtered` and in `include` the
ids of the lines that count, each at most once. An empty list includes
nothing. When a record picks more than one filter, a line counts when any of
them includes it. A refund reuses the stored answer, so Siren never sends
refund lines.

<!-- readme-test: answer-transaction-filter -->
```php
    ->on('transactionFilter', 'plan-lines', fn ($request) => [
        'outcome' => 'filtered',
        'include' => array_column(array_filter($request->data['lines'], fn ($line) => $line['type'] === 'lineItem'), 'id'),
    ])
```

### Engagement calculation (`engagementCalculation`)

An ask. Siren's catalog calls this a calculation strategy. Siren asks it each
time an engagement triggers for a program whose engagement type picks this
calculation, and makes no engagement until the answer. The question is an
`EngagementCalculationQuestion`: the `program`, the `engagementType`, the
`programEngagementTypeId`, the `args` a person gave that engagement type, the
`opportunity` with the `customerId` Siren matched it to (or null), the
`triggeringCollaborator` and the `cause`, whose `occurredAt` dates the
engagements the answer makes. The answer is an
`EngagementCalculationAnswer`: `outcome` `engagements` and `results`, who
earns an engagement and its score, with the same limits as a metric
calculation. An empty list makes no engagement.

<!-- readme-test: answer-engagement-calculation -->
```php
    ->on('engagementCalculation', 'visit-weight', fn ($request) => [
        'outcome' => 'engagements',
        // Credit the collaborator who triggered it, more when Siren knows the customer.
        'results' => [['collaboratorId' => $request->data['triggeringCollaborator']['id'], 'score' => $request->data['opportunity']['customerId'] ? 10 : 1]],
    ])
```

### Customer filter for programs (`engagementFilter`)

An ask. Siren's code calls this an engagement filter. Siren asks it each time
an engagement triggers for a program that picks this filter, and makes no
engagement until the answer. The question is an
`EngagementFilterQuestion`: the `program`, the `collaborator` the
engagement would credit, the `opportunity`, the `engagementType` and the
`cause`. The answer is an `EngagementFilterAnswer`:
`['outcome' => 'allow']`, or `outcome` `suppress` with a plain text `reason`
of 1 to 200 characters, which Siren notes on the opportunity.

<!-- readme-test: answer-engagement-filter -->
```php
    ->on('engagementFilter', 'staff-check', function ($request) {
        // Suppress a visit from someone who works for the partner. Allow everything else.
        if (isStaff($request->data['collaborator']['id'], $request->data['opportunity']['customerId'])) {
            return ['outcome' => 'suppress', 'reason' => 'The visitor works for the partner.'];
        }
        return ['outcome' => 'allow'];
    })
```

A sale on that program and opportunity waits for both engagement kinds'
answers before it earns a reward. When a sale waits on the answer, the ask
ends with that sale's wait, 24 hours after the sale reached Siren, which can
be sooner than 24 hours after the ask.

### Program group structure (`programGroupSorter`)

An ask. Siren's catalog calls this a group sorter. Siren asks it when a sale
or lead reaches a program group that picks this structure. No program in the
group is awarded until the answer. A renewal doesn't ask, and goes to the
program whose engagement is oldest. The question is an
`ProgramGroupSorterQuestion`: the `group`, the `conversionType`, the
`opportunity`, the `transaction` with its `total` (null for a lead), and the
`programs` of the group that take the conversion, in the group's order, each
with its active `engagements` on the opportunity, oldest first. The answer is
a `ProgramGroupSorterAnswer`: `outcome` `sorted` and `programIds`, every program
id of the question exactly once, in the order Siren tries them. The first
program that converts wins the group.

<!-- readme-test: answer-group-order -->
```php
    ->on('programGroupSorter', 'pick-the-program', function ($request) {
        // Try the program whose best engagement scores highest first.
        $best = fn ($program) => max([0, ...array_column($program['engagements'], 'score')]);
        $programs = $request->data['programs'];
        usort($programs, fn ($a, $b) => $best($b) <=> $best($a));
        return ['outcome' => 'sorted', 'programIds' => array_column($programs, 'id')];
    })
```

### Collaborator group structure (`collaboratorGroupStructure`)

An ask. Siren asks it each time a cascade runs over a collaborator group that
picks this structure, for a program (an engagement cascade) or a distributor
(a metric cascade). The cascade credits nobody above or below until the
answer. The question is a `CollaboratorGroupStructureQuestion`: the
`group`, its `members` with each one's `collaboratorId`, `name`, `status` at
the trigger, which Siren credits the member by, and `metadata`, what the
cascade is `for`, the `triggeringCollaborator` and the `cause`. It also
carries the fields of the trigger it interrupts. A distributor's ask carries
a metric calculation's fields, typed as
`CollaboratorGroupStructureDistributorQuestion`, and a program's carries an
engagement calculation's, typed as
`CollaboratorGroupStructureProgramQuestion`. The answer is an
`CollaboratorGroupStructureAnswer`, a chain or a tree:

- A chain, `['outcome' => 'structure', 'shape' => 'chain', 'order' => [63, 57, 42]]`,
  lists every member's `collaboratorId` once in `order`, top first.
- A tree, `['outcome' => 'structure', 'shape' => 'tree', 'parents' => ['42' => 57, '57' => 63, '63' => null]]`,
  maps every member's `collaboratorId` as text to another member's id, or to
  null for a top member, with no cycle.

<!-- readme-test: answer-group-structure -->
```php
    ->on('collaboratorGroupStructure', 'team-tree', function ($request) {
        // Top first, by the rank your CRM keeps on each member.
        $members = $request->data['members'];
        usort($members, fn ($a, $b) => $a['metadata']['rank'] <=> $b['metadata']['rank']);
        return ['outcome' => 'structure', 'shape' => 'chain', 'order' => array_column($members, 'collaboratorId')];
    })
```

## Payout method (`payoutMethod`)

An ask. Siren asks it when it sends a payout in a currency whose
autofulfiller picks this payout method, after payouts are made and when
someone sends or retries one. The feature names the currencies it pays in,
and the payout shows as processing until the answer. `data` is an
`PayoutMethodQuestion` and holds:

- `payout`: its `id`, the `fulfillmentId` it belongs to, and `attempt`, which
  starts at 1 and rises when Siren asks again about the same payout, such as
  after a failed answer and a retry.
- `amount`: what to pay, its `value` as a stored whole number with its
  `units` and `currency`.
- `collaborator`: who to pay, their `id`, `name` and `email`, which is null
  when they have none.

```json
{"payout": {"id": 88, "fulfillmentId": 31, "attempt": 1},
 "amount": {"value": 2500, "units": "USD", "currency": {"id": "USD", "symbol": "$", "position": "before",
            "name": "US Dollar", "scale": 2, "decimalPlaces": 2, "type": "monetary"}},
 "collaborator": {"id": 42, "name": "Ana Ruiz", "email": "ana@example.com"}}
```

The answer is a `PayoutMethodAnswer`. An `PayoutMethodPaidAnswer` is
`['outcome' => 'paid', 'reference' => '...']`, with the payment's id in your
own system of 1 to 191 characters. An `PayoutMethodFailedAnswer` is
`['outcome' => 'failed', 'reason' => '...']`, with 1 to 500 characters, which
marks the payout failed so someone can send it again. Pay each payout id at
most once, and when Siren asks again about a payout you already paid, answer
`paid` with the first reference.

<!-- readme-test: answer-payout -->
```php
    ->on('payoutMethod', 'acme-ledger', function ($request) {
        if ($request->test) {
            // A test from `siren trigger ask`: answer so Siren checks it, and pay nothing.
            return ['outcome' => 'failed', 'reason' => 'A test request, so nothing was paid.'];
        }

        // Pay each payout once, keyed by its id, and answer with your reference.
        $paid = payOnce($request->data['payout']['id'], $request->data['collaborator'], $request->data['amount']);
        return $paid['ok'] ? ['outcome' => 'paid', 'reference' => $paid['id']] : ['outcome' => 'failed', 'reason' => $paid['error']];
    })
```

When no answer comes within 24 hours of the first ask, Siren marks the payout
failed, with words that tell the merchant to check with your app whether it
paid before sending it again.

## Custom events with short-lived tokens

An app can send Siren events of its own, which programs and distributors can
credit. Add the event to the app in Siren first.

### Send an app's custom event

A custom event belongs to one app, and Siren takes only that app's short-lived
token at its address. Build the client with the app's key and send. The SDK
trades the key for a token the first time, keeps it, gets a new one when fewer
than two minutes of its 20 are left, and on a 401 gets a new one and sends once
more. You write no token code.

<!-- readme-test: server-send -->
```php
<?php
require 'vendor/autoload.php';

use Siren\Sdk\Siren;

$siren = new Siren(); // the app's key and Siren's address, from SIREN_API_KEY and SIREN_BASE_URL

$result = $siren->events->ingest('signup-completed', [
    'collaboratorId' => 12,
    'plan'           => 'pro',
]);

echo $result->getStatus(), "\n";
```

`sale`, `refund`, `siteVisited` and every other call keep sending the key. A
key that belongs to no app sends custom events with the key, and a client
built with `accessToken` sends with that token.

PHP forgets everything when a request ends, so the SDK keeps tokens in APCu
when it is on, and every worker on the server shares them. On several servers,
or without APCu, pass a PSR-16 cache such as Redis as `tokenCache`, or each
request asks Siren for its own token. Siren allows 600 token requests an hour
from one address.

### Send a custom event from a browser

A page never holds the app's key. It asks the app's own server for a browser
token, which lasts ten minutes, works for one event only, and works only from
a domain the event allows. Add the site's domain to the event in Siren first,
because an event with no domains refuses every browser.

Mount the token address on the app's server in one line. With no framework,
put this in a file of its own, such as `siren-token.php`:

<!-- readme-test: plain-handler -->
```php
<?php
require 'vendor/autoload.php';

use Siren\Sdk\Siren;

$siren = new Siren(); // the app's key and Siren's address, from SIREN_API_KEY and SIREN_BASE_URL

$siren->events->serveBrowserToken(['events' => ['signup-completed']]);
```

With a framework that routes to a PSR-15 handler, such as Slim or Mezzio, use
`browserTokenHandler()`. It needs `psr/http-server-handler`, and finds
Guzzle's, Nyholm's, Slim's or Laminas's PSR-17 factories, or takes yours as
`responseFactory` and `streamFactory`:

<!-- readme-test: psr15-handler -->
```php
<?php
require 'vendor/autoload.php';

use Siren\Sdk\Siren;

$siren = new Siren(); // the app's key and Siren's address, from SIREN_API_KEY and SIREN_BASE_URL

$handler = $siren->events->browserTokenHandler(['events' => ['signup-completed']]);

// Slim 4:   $app->map(['GET', 'POST'], '/siren/token', $handler);
// Mezzio:   $app->route('/siren/token', $handler, ['GET', 'POST']);
```

Either one reads the event from a JSON body `{"event": "..."}` or the query
`?event=`, and answers:

| Status | Body | When |
|--------|------|------|
| 200 | `{"token", "expiresIn", "url"}` | Siren gave a browser token. |
| 400 | `{"error"}` | The request names no event, names one outside `events`, or Siren refuses the event (the app has no such event, or it allows no domain). |
| 502 | `{"error"}` | Siren can't be reached, or refuses the app's key. |

The handler reuses one token per event until fewer than 60 seconds are left,
so a busy page costs one grant every nine minutes or so. In another framework,
`(new BrowserTokenEndpoint($siren->events, ['events' => [...]]))->answer($body, $query)`
returns the same status and body for you to send. `$siren->events->browserToken('signup-completed')`
gives the `BrowserToken` itself.

The page asks for the token, then posts the event's data to `url` with the
token in the query. Siren needs `collaboratorId`, the collaborator who gets
credit, at the top of the body:

<!-- readme-test: page -->
```html
<script>
async function sirenTrack(event, data) {
  const asked = await fetch('/siren-token.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ event }),
  });
  if (!asked.ok) return null;
  const { token, url } = await asked.json();
  const sent = await fetch(url + '?access_token=' + encodeURIComponent(token), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
  });
  return sent.ok ? sent.json() : null;
}

sirenTrack('signup-completed', { collaboratorId: 12, plan: 'pro' });
</script>
```

A page built with JavaScript tools can use `track()` from
`@novatorius/siren/storefront`, which keeps the token and tries once more
after a 401.

This keeps other websites from firing the event from their visitors'
browsers, and a leaked token dies within minutes. It does not stop a script
outside a browser, which can send any `Origin`, and anyone who fetches a token
from your page can send that event for ten minutes. A browser send carries
whatever the page's script puts in it, so don't let a page name who gets
credit unless you check it on your server.

The [examples](examples) folder holds each of these as a file.

## Check and answer by hand

`apps->verifyRequest()` and `apps->requests->answer()` are the calls under
the handler, for an app that queues the work or answers after a person
decides. Check a request with `verifyRequest()`, then answer an ask with
`requests->answer()`, using a client made with the app's own key. This
payout method answers every payout it is asked about:

<!-- readme-test: by-hand -->
```php
<?php
require 'vendor/autoload.php';
use Siren\Sdk\Siren;

$siren = new Siren(); // the app's key, from SIREN_API_KEY

$request = $siren->apps->verifyRequest(
    file_get_contents('php://input'),
    getallheaders(),
    getenv('SIREN_SIGNING_SECRET'),
);

if ($request->isAsk()) {
    $receipt = $siren->apps->requests->answer($request->id, ['outcome' => 'paid', 'reference' => 'TX-1']);
}
```

Reply 200 within Siren's 8 seconds, and answer an ask by its `answerBy`. A
real app replies first and answers later, as the handler does.

`verifyRequest()` reads nothing over the network. It checks, in this order,
that `X-Siren-Event` is `app.request` when present, that
`X-Siren-Signature-V2` (`t=<seconds>,v1=<hex>`) signs `"{t}."` and the raw
body, that `X-Siren-Timestamp` matches `t`, that `t` is within
`$toleranceSeconds` of the clock (300 by default, at most 3600), and that the
body is an app request of version 1. Each failure throws
`SignatureVerificationException` with the reason, which for the body names
the first field that fails. Pass the body exactly as
received. Siren may send one request more than once, so drop a repeat by
`$request->id`.

The request Siren signs is this envelope, with the kind's question in `data`.
`answerBy` and `answerUrl` appear only on an ask:

```json
{"version": 1, "id": "apr_9c60190a4501399d0ae55f041c237b39", "type": "app.request", "mode": "ask",
 "app": "acme-crm", "feature": {"kind": "distributionResolver", "key": "split-by-score", "id": "app:9"},
 "createdAt": "2026-11-17T12:20:03+00:00", "answerBy": "2026-11-17T13:20:03+00:00",
 "answerUrl": "https://cloud.example.com/siren/v1/apps/requests/apr_9c60190a4501399d0ae55f041c237b39/answer",
 "data": {}}
```

`answer()` sends `POST /apps/requests/{id}/answer` once and never retries it,
and returns an `AppAnswerReceipt`. It throws `InvalidArgumentException`
before sending for an id that is not a request key, an answer that is a list,
one with no text `outcome`, and one over 16384 bytes as JSON. A different
second answer throws `ConflictException` with code
`app_request_already_answered`, and the first answer stands. When Siren takes
no answer from the key, `AuthenticationException` carries
`ErrorCode::APP_ANSWER_UNAUTHORIZED`.

## Errors and retries

What Siren does when an app doesn't answer:

- **A tell.** Siren waits 8 seconds for a reply. A timeout or any status
  outside 2xx is tried again about 1, 2, 4 and 8 minutes later, five tries in
  all. Then the request shows under Needs attention, where an admin can send
  it again.
- **An ask.** A failed delivery is tried the same way, and the answer can
  still come any time before `answerBy`. With no answer by then, Siren sends
  the request again with a new window, three sends in all, and then the
  request needs attention.
- **The 24-hour limit.** Every ask but a distribution resolver's waits at
  most 24 hours. Then Siren drops it: the work goes on as if the app answered
  with nothing, so the sale earns no reward from it, the metric scores
  nothing, and distributors pay on their own schedule. A payout is marked
  failed. A distribution resolver's distribution waits until an admin sends
  the request again or answers it by hand. An answer after that throws `ConflictException` with code
  `app_request_dropped` and changes nothing. While an app's requests wait too
  long, its problem contacts, or the organization's admins when it names
  none, get an email about twice a week.

What the handler does with an ask's answer: it checks the answer as
`requests->answer()` does and posts it with the client's key. Unlike
`requests->answer()`, it sends the answer again after a lost connection, a
429 or a 5xx, inside the client's `maxRetries`, since it sends the very same
answer and Siren takes that again as a duplicate. A refusal is not sent
again, and goes to `onError`:

| Siren answers | Exception | `getErrorCode()` | What it means |
|---------------|-----------|------------------|---------------|
| 422 | `ValidationException` | `app_answer_invalid` | The answer doesn't fit the kind. Siren keeps nothing, so send a corrected one. |
| 409 | `ConflictException` | `app_request_already_answered` | Siren already took a different answer, which stands. |
| 409 | `ConflictException` | `app_request_dropped` | Siren stopped waiting after 24 hours, and the answer was not used. |
| 401 | `AuthenticationException` | `app_answer_unauthorized` | The client's key is not the app's own. |
| 403, 404 | `PermissionException`, `NotFoundException` | Siren's code | The key can't answer this request, or there is no such request. |

When the function throws, returns nothing, or no answer lands, Siren asks
again after `answerBy`, and the default `onError` line says when. Resend it
sooner with `siren apps resend <id>`, or with `apps->requests->resend($id)`
from an admin's session.
To act on a refusal, read it in `onError`:

<!-- readme-test: on-error -->
```php
$answers = $siren->apps->requestHandler(options: [ // the secret, from SIREN_SIGNING_SECRET
    'onError' => function (\Throwable $error, ?object $about) {
        if ($error instanceof \Siren\Sdk\Exception\ConflictException && $error->getErrorCode() === 'app_request_dropped') {
            // Siren stopped waiting, so undo whatever the app did for this request.
            undoWork($about->id);
            return;
        }
        error_log($error->getMessage());
    },
]);
```

[Errors](#errors) lists every exception the SDK throws.

## An app's features and requests

An admin's session token, the one a person signed in to Siren Cloud holds,
reads and changes an app's features and the requests Siren sent its apps.
Siren refuses an app's own key on these routes with 401
`api_key_not_accepted`.

`apps->features` adds one feature without declaring the whole app. A
feature's kind and key never change once Siren makes it.

```php
$siren = new Siren(['accessToken' => $sessionToken]);

$feature = $siren->apps->features->create('acme-payouts', [
    'kind' => 'payoutMethod',
    'name' => 'Pay by Acme ledger',
    'answerWindowMinutes' => 120,
]);

$siren->apps->features->update('acme-payouts', $feature['id'], ['name' => 'Acme ledger']);
$siren->apps->features->retrieve('acme-payouts', $feature['id']); // with uses and usedBy
$siren->apps->features->delete('acme-payouts', $feature['id']);   // ['archived' => bool, 'feature' => ...]
```

`create()` sends the fields of `AppFeatures::CREATE_FIELDS` and `update()`
those of `UPDATE_FIELDS`. Each throws `InvalidArgumentException` before
sending for an app key that is not lowercase letters and digits joined by
single dashes, any other field, a create with no `kind` or `name`, the old
kind `fulfillmentMethod`, and an update that changes `kind` or `key`. Siren
answers 201 for a new feature, 200 when an archived one of the same kind and
key came back, and 409 `app_features_need_key_and_address` when the app has
no key or address yet. `delete()` archives a feature while a record picks it
and deletes it otherwise.

`apps->requests` reads the requests and sends one again:

```php
$waiting = $siren->apps->requests->list(['status' => 'needs_attention']); // newest first, at most 50
$request = $siren->apps->requests->retrieve($waiting[0]['id']);           // with data and answer
$siren->apps->requests->resend($request['id']);                          // a new answer window
```

`list()` takes `status`, one of `AppRequests::STATUSES`, and pages with
`limit` (1 to 100, 50 when absent) and `before`, a request id. A page with
`before` holds only requests older than that one, so the last id of a page
reads the next:

```php
$page = $siren->apps->requests->list(['limit' => 20]);
if ($page !== []) {
    $next = $siren->apps->requests->list(['limit' => 20, 'before' => $page[array_key_last($page)]['id']]);
}
```

Siren answers `needs_attention` whole, so `list()` refuses `limit` or
`before` with it.

`idPrefix` finds requests by the start of their id, `apr_` and 4 to 32 hex
characters, as the CLI prints an id short. Siren answers at most two of the
organization's matches, newest first, so two rows mean the prefix fits more
than one request. It takes no other param beside it:

```php
$matches = $siren->apps->requests->list(['idPrefix' => 'apr_9f2c']);
```
`resend()` is sent once. Siren applies a stored answer it hasn't applied
instead of sending the request again, and answers 409
`app_request_not_resendable` for a request that is `applied`, a `delivered`
tell, or one it would send while its app is off.

## Quickstart

### Record a sale

Mint an API key in the Siren dashboard (Settings → API Keys) with the
`event:*` scope, then:

```php
use Siren\Sdk\Siren;

$siren = new Siren(['apiKey' => 'sk_live_...']);

$result = $siren->events->sale([
    'source'     => 'stripe',            // your commerce source
    'externalId' => 'cs_test_a1b2c3',    // your order id — used to match refunds later
    'total'      => 49.99,               // major units: 49.99 = $49.99
    'trackingId' => 4021,                // opportunity id from the Siren tracking cookie
    'currency'   => 'USD',               // optional, defaults to the organization's currency
    'items'      => [                    // optional; omit to treat total as one line
        ['name' => 'Pro Plan (annual)', 'amount' => 49.99, 'quantity' => 1],
    ],
]);

echo $result->getOpportunityId(); // read from the X-Siren-OID response header
echo $result->getStatus();        // EventStatus::CREATED, RECORDED, DUPLICATE, UNATTRIBUTED or IGNORED
```

Siren answers 202 instead of 200 when the organization's engine is paused,
such as when it is over its usage threshold. The event is queued and runs
once the engine resumes, so `$result->isQueued()` is true and the event must
not be sent again.

Refunds reverse a sale by `(externalId, source)`:

```php
$siren->events->refund([
    'source'     => 'stripe',
    'externalId' => 'cs_test_a1b2c3',
]);
```

### Verify a webhook

This section is for webhook subscriptions. An app's address gets the V2
signature, `X-Siren-Signature-V2`, and the request handler checks it, so an
app needs none of the code below.

Siren signs every delivery with `X-Siren-Signature: sha256=<hex hmac>`, an
HMAC-SHA256 of the **raw request body** keyed by your subscription's signing
secret. The body is the event's payload. Siren names the event in the
`X-Siren-Event` header and the delivery in `X-Siren-Delivery`, which stays
the same when a delivery is retried.

> ⚠️ **Pass the raw body bytes.** The HMAC is computed over the exact bytes
> Siren sent. Read `php://input` directly — if you `json_decode` and re-encode
> the payload, verification **will fail**.

```php
use Siren\Sdk\Siren;
use Siren\Sdk\WebhookEventType;
use Siren\Sdk\Exception\SignatureVerificationException;

$siren = new Siren(['apiKey' => 'sk_live_...']);

try {
    $event = $siren->webhooks->constructEventFromHeaders(
        file_get_contents('php://input'),
        getallheaders(),
        $signingSecret,
    );
} catch (SignatureVerificationException $e) {
    http_response_code(400);
    exit;
}

match ($event->getType()) {
    WebhookEventType::CONVERSION_APPROVED => handleConversion($event->getData()),
    WebhookEventType::PAYOUT_PAID         => handlePayout($event->getData()),
    default                               => null,
};
```

`constructEvent($rawBody, $signature, $secret, $eventType, $deliveryId)`
does the same with the three header values passed one by one. A
delivery whose body is the bare payload is refused without `$eventType`,
because only the `X-Siren-Event` header names its event.

An address belongs to an app. Siren Cloud has no `POST /webhooks` route and
answers it with 405. Give an app its address with `siren apps apply`, from the
app file's `url` and `events`, or on the app's page in Siren Cloud. Siren
shows the signing secret once, when it makes the address, and `siren apps
apply` writes it to the app's `.env` file. The CLI comes with the Node
package, so a PHP project runs it with `npx @novatorius/siren apps apply`.

```php
$siren->webhooks->subscriptions->events(); // the live catalog: [{event, category, label}, ...]
```

### Check and change a subscription

`retrieve($id)` reads one subscription, never with its signing secret, and
`deliveries($id)` reads its latest 50 deliveries, newest first. Each delivery
carries the payload Siren sent, its status (`pending`, `delivered` or
`failed`), the tries it took and the first 200 bytes of your endpoint's
answer. Both retry as reads.

`update($id, $params)` changes the target URL, the events, the status or the
description, and sends only the fields you give. Any other key throws
`InvalidArgumentException` before sending. So does an update with no field
that is not `null`, because Siren skips a `null` field and cannot apply an
update with nothing to change. `test($id)` makes Siren send one
signed `webhook.test` delivery now and answers what your endpoint did. Siren
answers 200 either way, so read `delivered`. Each is sent once.

Every `webhooks->subscriptions` call takes a signed-in dashboard session's
access token. Siren refuses an API key on every `/webhooks` route with 401
`api_key_not_accepted`, so a leaked key can never move an app's address.

```php
$siren = new Siren(['accessToken' => $sessionToken]); // a signed-in admin's token

$result = $siren->webhooks->subscriptions->test($subscriptionId);

if (!$result['delivered']) {
    $latest = $siren->webhooks->subscriptions->deliveries($subscriptionId)[0] ?? null;
    error_log(sprintf('%s %s', $latest['httpStatusCode'] ?? '-', $latest['responseBody'] ?? ''));
}

// Stop deliveries while you fix the endpoint, then start them again.
$siren->webhooks->subscriptions->update($subscriptionId, ['status' => WebhookSubscriptionStatus::PAUSED]);
$siren->webhooks->subscriptions->update($subscriptionId, ['status' => WebhookSubscriptionStatus::ACTIVE]);
```

Siren refuses a test for a paused subscription with a 409, raised as
`ConflictException`. From one address it allows 20 tests an hour for one
subscription and 60 in all, and answers more with a 429, raised as
`RateLimitException`.

### Which credential does each call need?

| Calls | Credential |
|-------|------------|
| `events` | An API key holding the event's scope (`event:*`, or `event:{slug}`). A connected site's token sends only the events its integrations forward and `site-visited`. A custom event takes only its app's short-lived token, which the SDK gets from the app's key. See [Send an app's custom event](#send-an-apps-custom-event). |
| `conversions`, `transactions`, `obligations` and `payouts`: `list()`, and `retrieve()` where the SDK has it | An API key holding the matching ledger scope, such as `ledger:conversions:read`. A dashboard session's access token, or a connected site's token, also works. |
| `fulfillments`: `list()` and `retrieve()` | An API key holding `ledger:fulfillments:read` (`ApiKeyRecordScope::FULFILLMENTS_READ`). A dashboard session's access token also works. |
| `webhooks->subscriptions` | A signed-in dashboard session's access token. Siren refuses an API key with 401 `api_key_not_accepted`. |
| `apps->verifyRequest()` | None. It reads nothing over the network, and checks the request with the app's signing secret. |
| `apps->requests->answer()` | The app's own API key. Owning the feature is what lets the key answer, so it needs no scope. |
| Other record resources, and the writes on `conversions` and `transactions` | A dashboard session's access token, or a connected site's token on the routes Siren Cloud opens to connectors. See [Connected sites](#connected-sites). |

An API key is part of an app. Siren Cloud has no `/api-keys` routes, and
answers them with 404. A key is made, given scopes, rotated and revoked
through the app, with a signed-in session: name the scopes in the app file
and run `siren apps apply`, or edit the app in Siren Cloud. `siren apps
rotate-key` gives the app a new key. A PHP project runs both with `npx
@novatorius/siren`. Siren refuses an API key on every `/apps` route with 401
`api_key_not_accepted`, so a leaked key can never make, change or revoke
keys.

In code, a signed-in admin's session token does the same through `apps`.
`createKey()` gives an app with no key a new one, `rotateKey()` replaces
it, and both answer the app with `rawKey`, shown this once. `revokeKey()`
revokes it, and `scopes()` lists the scopes a key may hold, grouped as
`events`, `ledger` and each `records:` resource. This SDK has no call that
makes an app or gives it an address. Use `siren apps apply` or Siren Cloud.

```php
$siren = new Siren(['accessToken' => $sessionToken]);

$app = $siren->apps->createKey('acme-crm', ['scopes' => [ApiKeyScope::ALL_EVENTS]]);
$app = $siren->apps->rotateKey('acme-crm'); // $app['rawKey'] is the new key
$siren->apps->revokeKey('acme-crm');
$groups = $siren->apps->scopes();           // ['events' => [...], 'ledger' => [...], ...]
```

`ApiKeyScope` and `ApiKeyRecordScope` name the scopes a key may hold:

| Scope | What the key can do |
|-------|---------------------|
| `ApiKeyScope::ALL_EVENTS`, `ApiKeyScope::event($slug)` | Send events |
| `ApiKeyScope::LEDGER_CONVERSIONS_READ` | `conversions->list()`, `conversions->retrieve()` |
| `ApiKeyScope::LEDGER_TRANSACTIONS_READ` | `transactions->list()`, `transactions->retrieve()` |
| `ApiKeyScope::LEDGER_OBLIGATIONS_READ` | `obligations->list()` |
| `ApiKeyScope::LEDGER_PAYOUTS_READ` | `payouts->list()`, `payouts->retrieve()` |

The two webhook scopes are retired. A key that still holds one keeps the
string, and it opens nothing.

`ApiKeyRecordScope` names the record scopes Siren issues, in the order the
dashboard offers them, and `ApiKeyRecordScope::ALL` lists them. Each constant
has the name Siren's own `ApiKeyRecordScopes` gives it, so
`ApiKeyRecordScope::FULFILLMENTS_READ` is `ledger:fulfillments:read`. The
ledger scopes above carry the same strings under both classes.

| Group | `ApiKeyRecordScope` constants |
|-------|-------------------------------|
| The ledger, read only | `ENGAGEMENTS_READ`, `OPPORTUNITIES_READ`, `CONVERSIONS_READ`, `TRANSACTIONS_READ`, `OBLIGATIONS_READ`, `FULFILLMENTS_READ`, `PAYOUTS_READ` |
| Records a merchant sets up | `PRODUCTS_READ`, `PRODUCTS_MANAGE`, `COUPONS_READ`, `COUPONS_MANAGE`, `COLLABORATORS_READ`, `COLLABORATORS_MANAGE`, `COLLABORATOR_GROUPS_READ`, `COLLABORATOR_GROUPS_MANAGE`, `PROGRAMS_READ`, `PROGRAMS_MANAGE`, `PROGRAM_GROUPS_READ`, `PROGRAM_GROUPS_MANAGE`, `DISTRIBUTORS_READ`, `DISTRIBUTORS_MANAGE`, `APPLICATION_FORMS_READ`, `APPLICATION_FORMS_MANAGE`, `APPLICATIONS_READ`, `APPLICATIONS_MANAGE`, `NOTIFICATION_RULES_READ`, `NOTIFICATION_RULES_MANAGE`, `PROPERTIES_READ` |

A manage scope reads everything its read scope reads.

Siren grants the ledger and record scopes only by their exact names.
`*`, `event:*`, wildcards such as `ledger:*`, and older names such as
`conversions:read` grant none of them, so a key made before these scopes
existed reads nothing until you replace it with one that holds them. A key's
requests count against the organization's API rate limit.

A key created with a `propertyId` is bound to that store. It reads only its
own store's conversions, transactions and obligations, and Siren refuses it on
payouts and fulfillments with `StoreKeyNotAllowedException`. An
organization-wide key reads the whole organization, and narrows a ledger read
to one store with the `propertyId` filter:

```php
use Siren\Sdk\ConversionStatus;

$siren = new Siren(['apiKey' => 'sk_live_...']); // organization-wide, with ledger:conversions:read

$page = $siren->conversions->list(['propertyId' => 5, 'status' => ConversionStatus::APPROVED]);
```

## Features

- **Event ingestion** — `sale`, `refund`, `siteVisited`, and custom event types via `ingest`, with what Siren recorded (`EventStatus`) and whether it queued the event.
- **App requests** — `apps->requestHandler()` checks each request Siren sends an app, runs the function registered for the feature, replies, and posts an ask's answer, with `serve()`, `psr15()` or `answer()` to mount it, and `apps->splitPool()` splits a resolver's pool in whole currency steps.
- **App tokens** — `ingest` trades the app's key for a short-lived token and keeps it fresh, and `browserTokenHandler()` or `serveBrowserToken()` gives a page a browser token for one event.
- **Webhook verification** — one-call `constructEventFromHeaders` or `constructEvent`, plus a lower-level constant-time `verifySignature` boolean check.
- **Subscription management** — create, list, read, update, test, and delete webhook subscriptions, and read the event catalog and each subscription's deliveries, with an API key or a dashboard session.
- **API key management** — create, list, and revoke keys, and read the scopes a key may hold.
- **Reconciliation readers** — paginated iterators over conversions, transactions, obligations, payouts, and fulfillments, with an API key that holds the ledger scopes.
- **Record resources**: collaborators (with tracking codes and program enrollments), mappings, settings, programs, program groups, opportunities, transactions and their details, conversions, engagements, distributors, custom currencies, store credit, and collaborator groups. See [Records](#records).
- **Host-supplied HTTP and tokens**: plug in your platform's HTTP client and an OAuth access token that refreshes after a 401. See [Connecting a host](#connecting-a-host).
- **Automatic retries** — network errors, 429s, and 5xx are retried with exponential backoff on idempotent operations; management writes are never auto-retried.
- **Typed exceptions** — every error extends `Siren\Sdk\Exception\SirenException` and carries the status code, error code, and error data.
- **Typed taxonomy** — Siren's domain vocabulary as constants, so no magic strings cross the boundary: `WebhookEventType`, `EventSlug`, and the status vocabularies (`ConversionStatus`, `TransactionStatus`, `ObligationStatus`, `PayoutStatus`, `FulfillmentStatus`, `OpportunityStatus`, `ApiKeyStatus`, `WebhookSubscriptionStatus`, `CollaboratorStatus`, `ProgramStatus`, `DistributorStatus`), `EventStatus`, `ApiKeyScope`, `ApiKeyRecordScope`, plus `ProgramGroupSorter`, `CustomCurrencyType`, and a `BULK_*` constant for every bulk action.

```php
use Siren\Sdk\ConversionStatus;

$approved = $siren->conversions->list(['status' => ConversionStatus::APPROVED]);
```

### Configuration

```php
$siren = new Siren([
    'apiKey'     => 'sk_live_...',                              // default SIREN_API_KEY, or 'accessToken', see below
    'baseUrl'    => 'https://api.sirenaffiliates.com/siren/v1', // default SIREN_BASE_URL, else Siren Cloud
    'timeout'    => 30,                                         // seconds, default 30
    'maxRetries' => 2,                                          // default 2
    'httpClient' => $client,                                    // optional, see below
    'tokenCache' => $psr16Cache,                                // optional, default APCu when on
]);
```

Pass `apiKey` or `accessToken`, not both. With neither, the client reads
`SIREN_API_KEY`, and with no key there either it refuses to start, naming the
option and the variable.

The client talks to Siren Cloud unless `baseUrl` or the `SIREN_BASE_URL`
environment variable names another Siren, such as a sandbox or a local one.
A `baseUrl` passed in code wins over the variable. The request handler posts an ask's answer only to `baseUrl`, never to
an address from the request, so an ask from another Siren gets a 500 whose
error says which `baseUrl` to set. Siren Cloud's two hosts,
`api.sirenaffiliates.com` and `app.sirenaffiliates.com`, count as one Siren.

`tokenCache` keeps app tokens
between requests: a PSR-16 `Psr\SimpleCache\CacheInterface`, or `false` for
memory only. `httpClient` takes a
`Siren\Sdk\Http\HttpClient` or a preconfigured `GuzzleHttp\ClientInterface`.

### Errors

Every exception extends `Siren\Sdk\Exception\SirenException` and carries
`getStatusCode()`, `getErrorCode()`, and `getErrorData()`.

| Status          | Exception                                  |
|-----------------|--------------------------------------------|
| 400             | `BadRequestException`                      |
| 401             | `AuthenticationException`, or its subclasses `SiteDisconnectedException` when Siren says the site is no longer connected, and `ApiKeyNotAcceptedException` when the route takes a session and not an API key |
| 403             | `PermissionException`, or its subclasses `MissingScopeException` (`getRequiredScope()`, `getAnyOf()`), `StoreKeyNotAllowedException` (`getPropertyId()`) and `PolicyException` (`getParam()`) |
| 404             | `NotFoundException`                        |
| 409             | `ConflictException`, or `CodeConflictException` and `HoldConflictException` (`getReason()`) from the calls that raise them |
| 422             | `ValidationException`, `getFieldErrors()`  |
| 429             | `RateLimitException` with `getRetryAfter()` |
| 5xx / other     | `ApiException`                             |
| network/timeout | `ConnectionException`                      |

Webhook verification failures throw `SignatureVerificationException`. The
request handler throws `AppHandlerException` for a mistake in the app's own
code, such as a kind Siren never sends.

## Records

> **Credentials on record routes.** The record routes accept a signed-in
> dashboard session, and a connected site's OAuth token reaches the routes
> Siren Cloud opens to connectors once the site holds the record scopes. See
> [Connected sites](#connected-sites). An API key reads conversions,
> transactions, obligations, payouts and fulfillments with a ledger scope. See
> [Which credential does each call need?](#which-credential-does-each-call-need)

Every record resource follows the same shape: `list()`, `retrieve()`,
`create()`, `update()`, `delete()`, and `bulk()`, where Siren has the route.
A resource has no method for a route Siren lacks. Engagements, for example,
are read only.

```php
use Siren\Sdk\CollaboratorStatus;
use Siren\Sdk\Resources\Collaborators;

// A page of active collaborators in one program, 50 at a time.
$page = $siren->collaborators->list([
    'status'    => CollaboratorStatus::ACTIVE,
    'programId' => 12,
    'fields'    => ['id', 'email', 'referralCode'],
    'page'      => 2,
    'perPage'   => 50,
]);

foreach ($page as $collaborator) {
    // ...
}

$page->getTotal();      // records across all pages
$page->getTotalPages();
$page->hasMore();

$ada = $siren->collaborators->create([
    'fullName'   => 'Ada Lovelace',
    'nickname'   => 'ada',
    'email'      => 'ada@example.com',
    'status'     => CollaboratorStatus::ACTIVE,
    'trackingId' => 'ADA',  // omit to let Siren generate one
]);

$siren->collaborators->update($ada['id'], ['nickname' => 'countess']);
$siren->collaborators->bulk(Collaborators::BULK_DEACTIVATE, [$ada['id']]);
```

Lists page with `page` and `perPage`, or with Siren's own `number` (page
size, 1 to 200) and `offset`. Any other key is a filter. A list value is
sent comma-separated, which Siren reads as "any of". Each resource's
docblock names the filters and sort columns its route accepts.

Deletes are two-stage for collaborators, programs, conversions, and
distributors. The first call marks the record `deleted` and returns it, and
a second call removes it. Program groups and collaborator groups are
removed on the first call. Writes and deletes are never retried
automatically.

| Resource | Routes |
|----------|--------|
| `$siren->collaborators` | list, retrieve, create, update, delete, bulk, `createPending`, `updateDetails`, `statusTypes`, `generateReferralCode` |
| `$siren->collaborators->aliases` | `findByCode`, `forCollaborator`, `query`, `create`, `free`, `list`, `trackingCode`, `setTrackingCode` |
| `$siren->collaborators->enrollments` | `list`, `add`, `remove`, `programsFor`, `collaboratorsIn`, `enroll`, `unenroll`, `replace` |
| `$siren->programs` | list, retrieve, create, update, delete, bulk, `statusTypes`, `incentiveTypes`, `currencyTypes` |
| `$siren->programGroups` | list, retrieve, create, update, delete, bulk |
| `$siren->opportunities` | list, retrieve, update |
| `$siren->transactions` | list, retrieve, `details`, create, bulk, `creditCollaborator`, `sources` |
| `$siren->conversions` | list, retrieve, create, update, delete, bulk, `types` |
| `$siren->engagements` | list, retrieve, `types`, `calculations` |
| `$siren->payouts` | list, retrieve |
| `$siren->fulfillments` | list, retrieve |
| `$siren->distributors` | list, retrieve, create, update, delete, bulk, `creditMetric` |
| `$siren->customCurrencies` | list, retrieve, create, update, delete (keyed by currency code) |
| `$siren->credits` | `checkoutBalances`, `checkoutBalance`, `entries`, `holds`, `placeHold`, `releaseHold`, `adjust`, `balances` |
| `$siren->mappings` | list, `forExternal`, `forLocal`, create, `deleteForCollaborator`, `deleteForExternal` |
| `$siren->settings` | `collaborator`, `value` |
| `$siren->collaboratorGroups` | list, retrieve, create, update, delete, `structures` |
| `$siren->collaboratorGroups->members` | `list`, `add`, `set`, `remove` |

Tracking codes and enrollments have their own routes, and the collaborator
record carries them too:

```php
$siren->collaborators->aliases->findByCode('SAVE10', 'coupon');         // who holds this coupon, or null
$siren->collaborators->aliases->forCollaborator(42, 'tracking');        // their newest tracking code, or null
$siren->collaborators->aliases->create('SAVE10', 'coupon', 42);
$siren->collaborators->aliases->free('SAVE10', 'coupon');

$siren->collaborators->enrollments->list(['collaboratorId' => 42, 'programId' => [12, 15]]);
$siren->collaborators->enrollments->add(42, 12);
$siren->collaborators->enrollments->remove(42, 12);

$siren->collaborators->enrollments->replace(42, [12, 15]);    // exactly these programs, through the collaborator
$siren->collaborators->aliases->setTrackingCode(42, 'ADA2');  // through the collaborator's trackingId

$siren->transactions->details(901);                    // line items
$siren->credits->adjust(42, 'PTS', -500, 'Redeemed at checkout');
$siren->credits->balances(42);                         // the collaborator's creditBalances field
```

Payouts and fulfillments only read, since every payout and fulfillment write
moves money and Siren keeps those for a signed-in admin. A fulfillment is the
batch Siren pays payouts in. `fulfillments->list()` reads `status`,
`payoutStatus` (only fulfillments holding a payout in that status, so
`failed` finds the ones that need attention), `currency`, and `from` and
`to`, each a day as `YYYY-MM-DD`:

```php
use Siren\Sdk\PayoutStatus;

$attention = $siren->fulfillments->list(['payoutStatus' => PayoutStatus::FAILED, 'from' => '2026-11-01']);

foreach ($attention as $fulfillment) {
    $batch = $siren->fulfillments->retrieve($fulfillment['id'], ['include' => 'extended']);
    // $batch['payouts'] holds each payout, with its collaboratorName
}

$siren->payouts->retrieve(31, ['include' => 'extended']); // adds collaboratorName and collaboratorEmail
```

Siren reads a `payoutStatus` or `currency` of `""`, and a `from` or `to`
that is not a real day, as no filter, and would answer every fulfillment. So
`list()` throws `InvalidArgumentException` before sending for each of those,
and for a `payoutStatus` or `currency` that is not a string, since Siren
matches each as one value.

## Connecting a host

A connector, such as the Siren WordPress plugin connected to Siren Cloud,
usually has its own HTTP functions and its own OAuth grant. The SDK takes
both.

### Bring your own HTTP client

Implement `Siren\Sdk\Http\HttpClient`. Send the request as given, return an
`HttpResponse` for every status (including 4xx and 5xx), and throw
`HttpClientException` only when no response arrived. The SDK keeps the
retries, error mapping, and token refresh.

```php
use Siren\Sdk\Http\HttpClient;
use Siren\Sdk\Http\HttpClientException;
use Siren\Sdk\Http\HttpRequest;
use Siren\Sdk\Http\HttpResponse;

final class WordPressHttpClient implements HttpClient
{
    public function send(HttpRequest $request): HttpResponse
    {
        $response = wp_remote_request($request->getUrl(), [
            'method'  => $request->getMethod(),
            'headers' => $request->getHeaders(),
            'body'    => $request->getBody(),
            'timeout' => $request->getTimeout(),
        ]);

        if (is_wp_error($response)) {
            throw new HttpClientException($response->get_error_message());
        }

        return new HttpResponse(
            (int) wp_remote_retrieve_response_code($response),
            wp_remote_retrieve_headers($response)->getAll(),
            (string) wp_remote_retrieve_body($response),
        );
    }
}

$siren = new Siren(['apiKey' => 'sk_live_...', 'httpClient' => new WordPressHttpClient()]);
```

### Bring your own access token

Pass an `accessToken` instead of an `apiKey`. A plain string works for a
token that never changes. For an OAuth grant, implement
`Siren\Sdk\Auth\AccessTokenSource`, or build one from two callables:

```php
use Siren\Sdk\Auth\CallbackAccessTokenSource;

$siren = new Siren([
    'accessToken' => new CallbackAccessTokenSource(
        fn (): string => $grant->accessToken(),
        fn (string $rejected): ?string => $grant->refresh($rejected), // store the new pair, return the new access token
    ),
    'httpClient' => new WordPressHttpClient(),
]);
```

The SDK reads the token before every request. When Siren answers 401, it
calls the refresh callable once, with the token Siren rejected, and repeats
the request with the new token. A request that was rejected with a 401 did
nothing, so this repeat applies to writes as well. If the refresh returns
null, or the repeated request gets another 401, the SDK raises
`AuthenticationException`, which means the site needs to reconnect.

### Connected sites

A WordPress site connected to Siren Cloud reads and writes the
organization's records with its OAuth token. Ask for the record scopes by
name when the site connects. `connect:*` does not grant them:

```php
use Siren\Sdk\ConnectorScope;

$authorizeUrl .= '&scope=' . rawurlencode(ConnectorScope::CONNECTED_SITE);
// connect:* connect:webhooks connect:records:read connect:records:write
```

`connect:records:read` reads collaborators, codes, enrollments, mappings,
programs, program groups, distributors, opportunities, transactions,
engagements, conversions, obligations, payouts, custom currencies, store
credit and the shared settings. `connect:records:write` writes only the
records a person manages on the site:

```php
use Siren\Sdk\Resources\Credits;
use Siren\Sdk\Resources\Mappings;

// Collaborators are created pending, with no program, distributor or group lists.
$ada = $siren->collaborators->createPending(['fullName' => 'Ada', 'nickname' => 'ada', 'email' => 'ada@example.com']);
$siren->collaborators->updateDetails($ada['id'], ['nickname' => 'countess']);   // fullName and nickname only
$siren->collaborators->enrollments->add($ada['id'], 12);                        // programs that run on this site only

// Codes and mappings, by plain type.
$siren->collaborators->aliases->create('SAVE10', 'coupon', $ada['id']);
$siren->mappings->create($ada['id'], (string) $wpUserId, Mappings::EXTERNAL_TYPE_WORDPRESS_USER);
$siren->mappings->forExternal('77', 'wc_product', 'collaborator');

// Checkout: read what is available, then reserve it.
$reference = Credits::holdReference('woocommerce', $sessionToken);
$row = $siren->credits->checkoutBalance($ada['id'], 'STORE_CREDIT', $reference);

if ($row !== null && $row['available'] > 0) {
    $hold = $siren->credits->placeHold($ada['id'], 'STORE_CREDIT', $row['available'], $sessionToken, 'woocommerce');
    // $hold['amount'] may be lower than asked.
}

// The shopper took the store credit off a cart that is still a cart: give
// it back now, not at the ttl. Never do this once the cart became an order.
$siren->credits->releaseHold($hold['id']);

$siren->settings->value('collaborator', 'urlReference', 'key');   // the referral link's query parameter
$siren->customCurrencies->retrieve('STORE_CREDIT');
```

Cloud keeps each site's codes, mappings and holds apart by filing them
under the site's Property, as in `coupon@property:5`. Send plain names. Every
type, platform and token must be letters, digits, underscores and hyphens,
and the SDK refuses anything else before it sends the request. Codes and
mappings come back with the plain type and the Property in `propertyId`.
Hold references come back as Cloud stores them. `PropertyQualifier` strips
the suffix when a host needs the plain form.

Cloud's own `user` mapping type links a collaborator to a Cloud account, and
a site may not use it. A WordPress user is `wordpress_user`. The SDK serves
every platform and sends the type it is given, so the WordPress host
translates its `user` to `wordpress_user` at its own boundary. A missed
translation fails loudly with a `PolicyException`.

A site reads only the fields its datastores need: computed fields such as
a collaborator's `aliases`, `programs` or `creditBalances`, and `include`,
get a 403. It lists opportunities by `collaboratorId` or `id`, and payouts
by `id`, `collaboratorId` or `fulfillmentId`. Other lists of those get a
403 with `ErrorCode::SITE_FILTER_REQUIRED`.

When a site creates someone the organization already has and the site does
not, Cloud answers with a held record for the site, not the existing
collaborator. The held record is `pending` with no tracking code, and Cloud
emails the person to confirm. Once they confirm, Cloud merges it into their
collaborator and deletes it, so look the collaborator up again by email.

Opportunities, transactions, engagements, conversions, obligations, payouts
and the credit ledger are read only for a site. They reach Cloud as events.
A credit hold lapses at its `ttl`, placing again for the same session
replaces it, and Cloud settles it from the order's redemption event. A site
can also release one of its holds early with `releaseHold()`. Releasing a
hold that already ended returns it unchanged, so a retry is safe.

> **Never release the hold of a checkout that became an order.** Settlement
> consumes that hold when the order reaches Cloud. Released first, the credit
> is free for another checkout to reserve and spend, and the order's
> settlement then finds nothing left to debit, so the store gives the same
> credit twice. WooCommerce empties the cart right after an order is placed,
> so its cart-emptied hook is not a safe place to release. Release only when
> the shopper abandons the credit, or it no longer applies to a cart that
> never became an order.

Each refusal has its own exception, so a host can react without parsing
messages. Cloud names each refusal with a stable `error.code`, listed in
`ErrorCode`, and the SDK picks the exception from the code first. A reply
with no code, from an older Cloud deployment, is read by its wording.

| Exception | Meaning | What to do |
|-----------|---------|------------|
| `MissingScopeException` | The token lacks `getRequiredScope()`, usually because the site connected before the record scopes existed. | Reconnect the site. |
| `SiteDisconnectedException` | The organization revoked the site. The SDK already tried one fresh token. | Stop calling Siren until the site reconnects. |
| `PolicyException` | Cloud's rules refuse this request, such as a field in `getParam()` a site may not send or a program that does not run on the site. | Do not repeat it. |
| `CodeConflictException` | Someone holds the code under that type, possibly another site. | Free it first, or choose another. |
| `HoldConflictException` | `getReason()` is `no_credit` (nothing available) or `token_in_use` (another site's checkout used the token). | Apply no credit, or mint a new token. |
| `RateLimitException` | The site spent its request budget. `getRetryAfter()` says how long to wait. | Back off. |

A page or a checkout should never wait on a slow Siren. Give the client it
uses there a short `timeout` and `maxRetries` of 0, so a timeout or a rate
limit fails at once with an exception the host can fall back from:

```php
$checkoutSiren = new Siren([
    'accessToken' => $tokens,
    'httpClient'  => new WordPressHttpClient(),
    'timeout'     => 3,
    'maxRetries'  => 0,
]);
```

## Parity with the Node SDK

This SDK and the Node SDK, `@novatorius/siren`, carry the same calls under
the same names. The Node SDK gained the calls below first, for services a
customer hosts, and this SDK follows it. The routes and scopes below follow
Siren's `saas/Auth/ApiKeyRoutes.php` and the controllers it names.

| PHP | Node | Siren route | API key scope |
|-----|------|-------------|---------------|
| `payouts->retrieve($id, $params)` | `payouts.retrieve(id, params)` | `GET /payouts/{id}` | `ledger:payouts:read` |
| `fulfillments->list($params)` | `fulfillments.list(params)` | `GET /fulfillments` | `ledger:fulfillments:read` |
| `fulfillments->retrieve($id, $params)` | `fulfillments.retrieve(id, params)` | `GET /fulfillments/{id}` | `ledger:fulfillments:read` |
| `webhooks->subscriptions->retrieve($id)` | `webhooks.subscriptions.retrieve(id)` | `GET /webhooks/{id}` | none, a session only |
| `webhooks->subscriptions->update($id, $params)` | `webhooks.subscriptions.update(id, params)` | `PUT /webhooks/{id}` | none, a session only |
| `webhooks->subscriptions->test($id)` | `webhooks.subscriptions.test(id)` | `POST /webhooks/{id}/test` | none, a session only |
| `webhooks->subscriptions->deliveries($id)` | `webhooks.subscriptions.deliveries(id)` | `GET /webhooks/{id}/deliveries` | none, a session only |
| `ApiKeyRecordScope`, `ApiKeyRecordScope::ALL` | `ApiKeyRecordScope`, `API_KEY_RECORD_SCOPES` | none | none |
| `events->ingest($slug, $payload)` | `siren.events.ingest(slug, payload)` | `POST /oauth/token`, then `POST /event/{slug}` | the app's key, traded for a token |
| `events->browserToken($eventKey)` | `siren.events.browserToken(eventKey)` | `POST /oauth/token` with `scope=browser:{eventKey}` | the app's key |
| `events->browserTokenHandler($options)`, `events->serveBrowserToken($options)` | `siren.events.browserTokenHandler(options)` | `POST /oauth/token` | the app's key |
| `apps->verifyRequest($rawBody, $headers, $secret, $toleranceSeconds, $now)` | `siren.apps.verifyRequest(rawBody, headers, secret, options)` | none | none |
| `apps->requests->answer($id, $answer)` | `siren.apps.requests.answer(id, answer)` | `POST /apps/requests/{id}/answer` | the app's key |
| `apps->requests->list($params)` | `siren.apps.requests.list(params)` | `GET /apps/requests` | an admin's session |
| `apps->requests->retrieve($id)` | `siren.apps.requests.retrieve(id)` | `GET /apps/requests/{id}` | an admin's session |
| `apps->requests->resend($id)` | `siren.apps.requests.resend(id)` | `POST /apps/requests/{id}/resend` | an admin's session |
| `apps->scopes()` | `siren.apps.scopes()` | `GET /apps/scopes` | an admin's session |
| `apps->createKey($appKey, $params)` | `siren.apps.createKey(key, params)` | `POST /apps/{key}/key` | an admin's session |
| `apps->rotateKey($appKey)` | `siren.apps.rotateKey(key)` | `POST /apps/{key}/key/rotate` | an admin's session |
| `apps->revokeKey($appKey)` | `siren.apps.revokeKey(key)` | `DELETE /apps/{key}/key` | an admin's session |
| `apps->features->create($appKey, $params)` | `siren.apps.features.create(appKey, params)` | `POST /apps/{app}/features` | an admin's session |
| `apps->features->retrieve($appKey, $id)` | `siren.apps.features.retrieve(appKey, id)` | `GET /apps/{app}/features/{id}` | an admin's session |
| `apps->features->update($appKey, $id, $params)` | `siren.apps.features.update(appKey, id, params)` | `PATCH /apps/{app}/features/{id}` | an admin's session |
| `apps->features->delete($appKey, $id)` | `siren.apps.features.delete(appKey, id)` | `DELETE /apps/{app}/features/{id}` | an admin's session |
| `apps->requestHandler($signingSecret, $options)`, with `on`, `onEvent`, `serve`, `psr15`, `answer` and `simulate`. The secret defaults to `SIREN_SIGNING_SECRET`. | `siren.apps.requestHandler(options)`, with `on`, `onEvent`, `handle`, `simulate` and its Node, Web and Fastify mounts | `POST /apps/requests/{id}/answer` for an ask's answer, to the client's `baseUrl` only | the app's key |
| `$answers->later()`, which an ask's function returns to answer later. Returning nothing is an error. | `answers.later()` | none | none |
| `requestHandler()`'s `logger`, a PSR-3 logger | `requestHandler()`'s `logger`, anything with `debug`, `info`, `warn` and `error` | none | none |
| `AppRequestHandler::TELL_TIME_LIMIT_SECONDS`, `AppSlowTellException` | `TELL_TIME_LIMIT_SECONDS`, `AppSlowTellError` | none | none |
| `SignatureVerificationException` to `onError` on a refused signature | Node's signature error to `onError` | none | none |
| `AppRequest::$test`, `AppRequests::make($kind, $key, ['test' => true])` | `request.test`, `appRequest(kind, key, { test: true })` | none | none |
| `AppRequests::fromRecord($record, $key)` | `appRequestFromRecord(record, { key })` | none | none |
| `AppRequests::event($type, $data)`, `AppRequests::sampleEventData($type)` | `webhookEvent(type, data)`, `sampleEventData(type)` | none | none |
| `simulate()` returns `status`, `body`, `answer` and `error` | `simulate()` returns the same four | none | none |
| `AppHandlerException`, a `SirenException`, for a mistake in the app's code | `AppHandlerError`, a `SirenError` | none | none |
| PHPStan types each `on()` function by its kind, and each kind's types are named for it, such as `DistributionResolverQuestion` | TypeScript does, with the same names | none | none |
| The `extraKinds` option, kinds `on()` takes beyond the thirteen Siren sends today. `on()` refuses any other kind. | The `extraKinds` option | none | none |
| `FeatureKind`, with `SENT`, `TELLS`, `ASKS`, `NAMES` and `catalog()`, from Siren's kinds snapshot | `FeatureKind` | `GET /apps/kinds` serves the same kinds | none |
| `Siren\Sdk\Testing\AppRequests::make($kind, $key, $options)`, `AppRequests::sampleData($kind)` | `appRequest(kind, key, options)`, `sampleData(kind)` from `@novatorius/siren/testing` | none | none |
| `new Siren()` reads `SIREN_API_KEY` | `new Siren()` reads `SIREN_API_KEY` | none | none |
| `Apps::splitPool($pool, $weights)` | `siren.apps.splitPool(pool, weights)` | none | none |

The app routes take the credential their row names. Every other route above but the token route takes an organization-wide key only.
Node's handler takes Node's `(req, res)` or a Web `Request`. PHP's comes as a
PSR-15 handler and as `serveBrowserToken()` for plain PHP, and PHP keeps
tokens in APCu or the `tokenCache` you pass, since a PHP request keeps nothing
in memory for the next one. `Payouts` and
`Fulfillments` extend `ListResource`, as the other record resources do, and
the subscription calls sit on `WebhookSubscriptions` beside `create`, `list`,
`events` and `delete`.

| Call | Retried | Refused before sending |
|------|---------|------------------------|
| `payouts->retrieve` | yes | an id below 1 |
| `fulfillments->list` | yes | a `payoutStatus` or `currency` of `""` or not a string, and a `from` or `to` that is not a real `YYYY-MM-DD` day |
| `fulfillments->retrieve` | yes | an id below 1 |
| `webhooks->subscriptions->retrieve` | yes | an id below 1 |
| `webhooks->subscriptions->update` | no | an id below 1, any key outside `UPDATE_FIELDS` whatever its value, and an update with no field that is not `null` |
| `webhooks->subscriptions->test` | no | an id below 1 |
| `webhooks->subscriptions->deliveries` | yes | an id below 1 |
| `apps->requests->list` | yes | a key other than `status`, `limit`, `before` and `idPrefix`, a status outside `STATUSES`, a `limit` outside 1 to 100, a `before` that is not a request id, either with `needs_attention`, an `idPrefix` that is not `apr_` and 4 to 32 hex characters, and any other key with `idPrefix` |
| `apps->requests->retrieve` | yes | an id that is not `apr_` and 32 lowercase hex characters |
| `apps->requests->resend` | no | an id that is not `apr_` and 32 lowercase hex characters |
| `apps->features->create` | no | a bad app key, a field outside `CREATE_FIELDS`, no `kind` or `name`, and `fulfillmentMethod` |
| `apps->features->retrieve` | yes | a bad app key, and an id below 1 |
| `apps->features->update` | no | a bad app key, an id below 1, and a field outside `UPDATE_FIELDS`, `kind` and `key` included |
| `apps->features->delete` | no | a bad app key, and an id below 1 |

A read retries on a lost connection, a 429 and a 5xx inside `maxRetries`. A
write is sent once, apart from the one resend after a 401 that every request
gets. `update` may be repeated by the caller after a lost answer, since it
sets the same values. `test` makes Siren send a request each time, and Siren
limits tests to 20 an hour for one subscription and 60 in all from one
address.

The PHP SDK keeps its own rules where they already differ from Node's:

- A refusal is an `InvalidArgumentException`, where Node throws `SirenError`.
  An id refusal says "Record ids are positive whole numbers.", as every PHP
  resource does, where Node names the method.
- `fulfillments->list()` sends every other key as a filter, as every PHP
  list does. Node refuses a key outside its five filters and the paging
  keys, `propertyId` included, an empty filter list, and a comma in a
  filter.
- A 2xx answer of the wrong shape is read as PHP reads every answer. A body
  that is not a JSON array or object gives `[]`. `deliveries()` keeps each
  member of what it decoded that is an array, as `events()` does, so it
  drops a row that is not an array and reads each array member of a JSON
  object as a row. Node raises `UnexpectedResponseError` for each.
- `retrieve` on payouts and fulfillments passes any key as a query
  parameter. Node sends only `fields`, `include` and `propertyId`.
- `ApiKeyRecordScope` keeps Siren's constant names, such as
  `FULFILLMENTS_READ`, where Node writes them in Pascal case, such as
  `FulfillmentsRead`.

`webhooks->subscriptions->update()` refuses any key outside `UPDATE_FIELDS`,
as Node does. This is the PHP SDK's rule for a write that holds the list of
fields its route reads, the rule `collaborators->updateDetails()` follows
with `SITE_UPDATABLE_FIELDS`. The other PHP `update()` methods hold no such
list, so they send the array as given. Refusing here keeps a key such as
`signingSecret` or `propertyId` out of the body, where Siren would ignore it
and the caller would think it changed. Both SDKs also refuse an update with
no field that is not `null`, because Siren cannot apply it and answers 500.

`tests/ApiKeyRecordScopeTest.php` pins the thirty scope strings. Run it with
`SIREN_CHECKOUT` set to a Siren checkout and it also loads Siren's
`lib/ApiKeys/Core/Enums/ApiKeyRecordScopes.php` and fails when a name, a
value or the order differs. Without the variable that one test is skipped.
The other tests in `tests/Resources/PayoutsFulfillmentsTest.php` and
`tests/Resources/WebhookSubscriptionCallsTest.php` send each call through the
real transport to a recording `HttpClient`, with answers written from
Siren's source rather than captured from a running Siren.

`events->forward`, `conversions->recoverCreate` and
`transactions->recoverCreditCollaborator` are still only in the Node SDK.
Neither SDK reads one obligation by id, though Siren serves
`GET /obligations/{id}`.

## Other SDKs

Siren also ships official clients for other stacks:

- **Node.js** — [Novatorius/siren-node](https://github.com/Novatorius/siren-node)
- **Python** — [Novatorius/siren-python](https://github.com/Novatorius/siren-python)

## Links

- Website: [sirenaffiliates.com](https://sirenaffiliates.com)
- API reference: [openapi.yaml](./openapi.yaml)

## Contributing

Contributions are welcome. See [CONTRIBUTING.md](./CONTRIBUTING.md) for how to
set up the project, run the tests, and open a pull request. By participating you
agree to the [Code of Conduct](./CODE_OF_CONDUCT.md).

```bash
composer install
composer test
```

Tests mock the HTTP layer — no live network calls. The `StreamHttpClient`
tests start PHP's built-in web server on 127.0.0.1 for the length of the
test class.

## License

Released under the [MIT License](./LICENSE). Copyright © 2026 Novatorius LLC.
