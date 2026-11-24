# Siren SDK for PHP

Affiliate and incentive tracking for any commerce stack. Record sales, answer Siren from your own app, verify signed webhooks and reconcile your ledger in a few lines of PHP.

[![Packagist Version](https://img.shields.io/packagist/v/siren/sdk.svg)](https://packagist.org/packages/siren/sdk)
[![CI](https://github.com/Novatorius/siren-php/actions/workflows/ci.yml/badge.svg)](https://github.com/Novatorius/siren-php/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](./LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/siren/sdk.svg)](https://packagist.org/packages/siren/sdk)

## What is Siren?

[Siren](https://sirenaffiliates.com) is an affiliate, referral, and incentive
platform. You track the events that matter — sales, refunds, referred visits —
and Siren attributes them to collaborators, calculates rewards, and manages
payouts.

## What you can do

This SDK is the official PHP client for the Siren API. With it you can:

- Answer Siren from an app you host, in a few lines. One request handler
  checks each signed request, runs the function you register for each
  feature, replies, and posts an ask's answer. Mount it in plain PHP, Slim,
  Mezzio, Laravel or any framework. See
  [Answer Siren in a few lines](#answer-siren-in-a-few-lines).
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
- Manage webhook subscriptions, with an API key or a signed-in dashboard
  session's token, and API keys, with a session's token.
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

## Requirements

- PHP 8.1+
- `ext-json`
- Optional: `guzzlehttp/guzzle` ^7.8. The SDK uses Guzzle when it is installed
  and PHP's own HTTP streams when it is not, so a host that already ships its
  own HTTP stack never has to load a second copy of Guzzle.

## Install

```bash
composer require siren/sdk
```

## Answer Siren in a few lines

A Siren app is a service you host that acts as part of Siren's engine. Each of
its features joins one of Siren's pickers, and Siren sends the app's address a
signed request whenever a record that picked the feature needs it. A tell
says what happened and waits for a 2xx. An ask waits for an answer.

`$siren->apps->requestHandler()` answers those requests for you. Register a
function for each feature by its kind and key, and mount the handler at the
app's address. It checks Siren's signature, finds the function, replies, and
for an ask posts the answer the function returns once the reply has gone.
Make the client with the app's own key, since the handler posts answers with
it, and pass the app's signing secret, which Siren showed once when it made
the app's address.

With no framework, put this in the file the app's address points at:

<!-- readme-test: answer-tell -->
```php
<?php
require 'vendor/autoload.php';
use Siren\Sdk\Siren;

$siren = new Siren(['apiKey' => getenv('SIREN_API_KEY'), 'baseUrl' => 'https://cloud.example.com/siren/v1']);

$siren->apps->requestHandler(getenv('SIREN_SIGNING_SECRET'))
    ->on('postConversionAction', 'tell-the-crm', function ($request) {
        // Siren may send it twice, so add it once, keyed by $request->id.
        addToCrm($request->id, $request->data['conversion'], $request->data['reward']);
    })
    ->serve();
```

`baseUrl` is your Siren Cloud's API address. `serve()` reads `php://input`
and the headers, and for an ask it sends the reply, ends the response with
`fastcgi_finish_request()` or `litespeed_finish_request()`, or with
`Content-Length`, `Connection: close` and a flush on other servers, and then
runs the function. Only the function changes from feature to feature, and
[Feature kinds](#feature-kinds) gives one for each kind.

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
$answers = $siren->apps->requestHandler(getenv('SIREN_SIGNING_SECRET'), [
    'toleranceSeconds' => 300,                          // as verifyRequest(), 1 to 3600
    'runLater' => fn ($work) => app()->terminating($work), // where an ask's work runs after the reply
    'onError' => fn (\Throwable $error, ?object $about) => report($error), // default error_log()
]);
```

`onError` gets each failure the reply can't carry, with the `AppRequest` or
`WebhookEvent` it came from: a function that threw, an answer that could not
be sent, and Siren's refusal of one. The default writes one `error_log()`
line.

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

### Register functions

`on($kind, $key, $fn)` registers one feature, and `on($kind, $fn)` every
feature of a kind. A function for the key wins. Each function gets the
`AppRequest`, whose `id`, `mode`, `item` (`kind`, `key` and `id`) and `data`
hold what Siren sent. A tell's return value is not read. An ask's function
returns the answer as an array, or `null` when the app will answer later with
`apps->requests->answer()`.

`onEvent($type, $fn)` takes a webhook event Siren sends to the same address,
such as `conversion.approved`, as a `WebhookEvent`. The handler checks it by
its signature and time, as it checks an app request.

### What the handler replies

| Request | Reply |
|---------|-------|
| Not a POST | 405 `{"error"}` |
| A body over 1 MiB, more than Siren sends | 413 `{"error"}` |
| A body a parser already read, so there are no bytes to check | 500 `{"error"}` that says to mount the handler before the JSON parser, or give the route a raw body. `onError` hears it too. |
| `X-Siren-Signature-V2` missing or wrong, `X-Siren-Timestamp` different from the signed time, or a time more than `toleranceSeconds` from the clock | 401 `{"error"}` with `verifyRequest()`'s words |
| A signed body that is not a JSON object, or an `app.request` that is not a request of version 1 | 400 `{"error"}` |
| A tell with a function | 200 `{}` once the function returns, or 500 when it throws, so Siren tries again about 1, 2, 4 and 8 minutes later |
| An ask with a function | 200 `{}` at once, then the function runs and the answer it returns is posted |
| An `app.request` for a feature with no function | 404 `{"error": "No function handles distributionResolver split-by-score."}`, so Siren tries again and then shows the request under Needs attention |
| Another `X-Siren-Event` with a function from `onEvent()` | 200 `{}` once it returns, or 500 when it throws |
| Another `X-Siren-Event` with no function | 200 `{}` |

Every reply is JSON with `Cache-Control: no-store`, and no reply holds the
secret or the body. Siren waits 8 seconds for a reply. The handler keeps no
state, and Siren may deliver one request twice, so a tell's function writes
once by `$request->id`.

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
kind's value, which `$request->item['kind']` carries and `AppItemKind` names.
`AppFeatureKind` sorts the kinds into `TELLS` and `ASKS` and holds the shape
of each question and answer as PHPStan types, for `@phpstan-import-type`:

```php
/** @phpstan-import-type AppDistributionAnswer from \Siren\Sdk\AppFeatureKind */
```

Every amount in a question or an answer is the stored whole number, so 1500
in US dollars is $15.00. Divide by 10 to the power of the currency's `scale`
to show it. Each sample is the function for the handler above, and runs as
written in this repository's tests. The SDK checks only what every answer
needs, a plain array with a text `outcome` of at most 16384 bytes as JSON.
Siren checks the rest and refuses an answer that doesn't fit with 422
`app_answer_invalid` and the reason.

How long Siren waits for an ask's answer before it sends the ask again is
the feature's answer window, set in its settings, 60 minutes when none is
set. See [Errors and retries](#errors-and-retries) for what happens when no
answer comes.

### Post-conversion action (`postConversionAction`)

A tell. Siren sends it when a conversion is approved in a program that picks
this action. A test sale's conversion is never sent. `data` is an
`AppConversionTell`: the `conversion` (its id, type, status and the ids of its
engagement, opportunity, collaborator, transaction and obligation), the
`reward` it earned with its `value`, `units` and `currency`, or null, and the
`program`'s id and name. Siren reads only the reply's status. The sample at
the top of [Answer Siren in a few lines](#answer-siren-in-a-few-lines) is
this kind's.

### Post-allocation action (`postAllocationAction`)

A tell. Siren sends it each time an allocation completes for a distributor
that picks this action. `data` is an `AppAllocationTell`: the `allocation`
(the distribution, the obligation and the collaborator it went to, its status,
`value`, `units` and `currency`) and the `distributor`'s id and name.

<!-- readme-test: answer-allocation -->
```php
    ->on('postAllocationAction', 'note-the-share', function ($request) {
        // Siren may send it twice, so note it once, keyed by $request->id.
        noteShare($request->id, $request->data['allocation']);
    })
```

### Distribution resolver (`distributionResolver`)

An ask. Siren asks it when a distribution closes for a distributor that picks
this resolver, and pays no one until the answer. The question is an
`AppDistributionQuestion`: the `distribution` and its period, the
`distributor`, the `pool` (`value` is what this answer may hand out, `total`
the whole pool and `paid` what collaborators already hold from it), and the
`metrics`, each collaborator Siren would pay with their `metricId`, `score` and
`triggerCount`. The answer is an `AppDistributionAnswer`: `outcome`
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
`AppRewardQuestion`: `reason` (`sale`, or `refund` when a partial refund asks
again about rewards already made), the `program`, the `conversionType`, the
`transaction` (null for a lead), the reward `pool`, and the `conversions`,
each with its engagement `score`. The answer is an `AppRewardAnswer`:
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
`AppIncentiveTypeQuestion`: `reason`, the `program` with its `units`, the
`conversionType`, the `opportunity`, the `transaction` with its `total` and
`lines` (null for a lead, which names its `binding` instead), and the
`engagements` that could convert, at most 250. A refund's question also lists
the award's `conversions`, and its answer changes only the pool. The answer
is an `AppIncentiveTypeAnswer`: `outcome` `award` with the program's `units`,
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
pays no one until then. The question is an `AppMetricCalculationQuestion`:
the `distributor`, the `distribution` the scores land in, the `metricType`
(such as `referredSiteVisit` or `awardedSale`), the
`distributorMetricTypeId`, the `triggeringCollaborator`, the `opportunityId`,
`points` (an amount a person entered, which replaces every score, or null),
the `cause` (its own random `id`, and the `type` and `causeId` of what caused
a trigger that can be taken back) and the `sale` behind a sale trigger, or
null. The answer is an `AppMetricCalculationAnswer`: `outcome` `scores` and
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
filters allow it. The question is an `AppMetricFilterQuestion`: the
`distributor`, the `distribution`, the `collaborator` the metric would credit,
the `metricType`, the `opportunityId`, `points`, the `cause` and the `sale`,
as a metric calculation's. The answer is an `AppMetricFilterAnswer`:
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
sale's own lines count. The question is an `AppTransactionFilterQuestion`:
what the filter is `for` (a program or a distributor), the `transaction` with
its `units` and `currency`, and the sale's `lines`. Each line is an
`AppTransactionLine` with its `id`, its `type` (`lineItem`, `discount`, `fee`,
`shipping` or `tax`), its `name`, a whole `quantity`, its unit `value` and a
`productId` or null. `distribution` is null for a program, and for a
distributor the period the sale's value lands in. `refundDistribution` is
null, or the later period a refund came in while the ask was open. The answer
is an `AppTransactionFilterAnswer`: `outcome` `filtered` and in `include` the
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
`AppEngagementCalculationQuestion`: the `program`, the `engagementType`, the
`programEngagementTypeId`, the `args` a person gave that engagement type, the
`opportunity` with the `customerId` Siren matched it to (or null), the
`triggeringCollaborator` and the `cause`, whose `occurredAt` dates the
engagements the answer makes. The answer is an
`AppEngagementCalculationAnswer`: `outcome` `engagements` and `results`, who
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
`AppEngagementFilterQuestion`: the `program`, the `collaborator` the
engagement would credit, the `opportunity`, the `engagementType` and the
`cause`. The answer is an `AppEngagementFilterAnswer`:
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
`AppGroupOrderQuestion`: the `group`, the `conversionType`, the
`opportunity`, the `transaction` with its `total` (null for a lead), and the
`programs` of the group that take the conversion, in the group's order, each
with its active `engagements` on the opportunity, oldest first. The answer is
an `AppGroupOrderAnswer`: `outcome` `sorted` and `programIds`, every program
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
answer. The question is an `AppCollaboratorGroupStructureQuestion`: the
`group`, its `members` with each one's `collaboratorId`, `name`, `status` at
the trigger, which Siren credits the member by, and `metadata`, what the
cascade is `for`, the `triggeringCollaborator` and the `cause`. It also
carries the fields of the trigger it interrupts. A distributor's ask carries
a metric calculation's fields, typed as
`AppCollaboratorGroupStructureDistributorQuestion`, and a program's carries an
engagement calculation's, typed as
`AppCollaboratorGroupStructureProgramQuestion`. The answer is an
`AppCollaboratorGroupStructureAnswer`, a chain or a tree:

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
`AppPayoutQuestion` and holds:

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

The answer is an `AppPayoutAnswer`. An `AppPayoutPaidAnswer` is
`['outcome' => 'paid', 'reference' => '...']`, with the payment's id in your
own system of 1 to 191 characters. An `AppPayoutFailedAnswer` is
`['outcome' => 'failed', 'reason' => '...']`, with 1 to 500 characters, which
marks the payout failed so someone can send it again. Pay each payout id at
most once, and when Siren asks again about a payout you already paid, answer
`paid` with the first reference.

<!-- readme-test: answer-payout -->
```php
    ->on('payoutMethod', 'acme-ledger', function ($request) {
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

$siren = new Siren([
    'apiKey'  => getenv('SIREN_API_KEY'), // the app's key
    'baseUrl' => getenv('SIREN_BASE_URL') ?: Siren::DEFAULT_BASE_URL,
]);

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

$siren = new Siren([
    'apiKey'  => getenv('SIREN_API_KEY'),
    'baseUrl' => getenv('SIREN_BASE_URL') ?: Siren::DEFAULT_BASE_URL,
]);

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

$siren = new Siren([
    'apiKey'  => getenv('SIREN_API_KEY'),
    'baseUrl' => getenv('SIREN_BASE_URL') ?: Siren::DEFAULT_BASE_URL,
]);

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

$siren = new Siren(['apiKey' => getenv('SIREN_API_KEY'), 'baseUrl' => 'https://cloud.example.com/siren/v1']);

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
`SignatureVerificationException` with the reason. Pass the body exactly as
received. Siren may send one request more than once, so drop a repeat by
`$request->id`.

The request Siren signs is this envelope, with the kind's question in `data`.
`answerBy` and `answerUrl` appear only on an ask:

```json
{"version": 1, "id": "apr_9c60190a4501399d0ae55f041c237b39", "type": "app.request", "mode": "ask",
 "app": "acme-crm", "item": {"kind": "distributionResolver", "key": "split-by-score", "id": "app:9"},
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

When the function throws or no answer lands, Siren asks again after
`answerBy`. To act on a refusal, read it in `onError`:

<!-- readme-test: on-error -->
```php
$answers = $siren->apps->requestHandler(getenv('SIREN_SIGNING_SECRET'), [
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

Siren signs every delivery with `X-Siren-Signature: sha256=<hex hmac>` — an
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

Create a subscription (the `signingSecret` is returned **once** — store it).
An API key that holds `webhook-subscriptions:manage` can do this, and so can
a signed-in dashboard session's access token:

```php
$siren = new Siren(['apiKey' => 'sk_live_...']); // a key with ApiKeyScope::WEBHOOK_SUBSCRIPTIONS_MANAGE

$subscription = $siren->webhooks->subscriptions->create([
    'targetUrl' => 'https://example.com/webhooks/siren',
    'events'    => [WebhookEventType::CONVERSION_APPROVED, WebhookEventType::PAYOUT_PAID],
    // or [WebhookEventType::ALL] to subscribe to everything
]);

$secret = $subscription['signingSecret'];

$siren->webhooks->subscriptions->events(); // the live catalog: [{event, category, label}, ...]
```

### Check and change a subscription

`retrieve($id)` reads one subscription, never with its signing secret, and
`deliveries($id)` reads its latest 50 deliveries, newest first. Each delivery
carries the payload Siren sent, its status (`pending`, `delivered` or
`failed`), the tries it took and the first 200 bytes of your endpoint's
answer. Both take `webhook-subscriptions:read` or
`webhook-subscriptions:manage`, and both retry as reads.

`update($id, $params)` changes the target URL, the events, the status or the
description, and sends only the fields you give. Any other key throws
`InvalidArgumentException` before sending. So does an update with no field
that is not `null`, because Siren skips a `null` field and cannot apply an
update with nothing to change. `test($id)` makes Siren send one
signed `webhook.test` delivery now and answers what your endpoint did. Siren
answers 200 either way, so read `delivered`. Both take
`webhook-subscriptions:manage`, and each is sent once.

```php
$result = $siren->webhooks->subscriptions->test($subscription['id']);

if (!$result['delivered']) {
    $latest = $siren->webhooks->subscriptions->deliveries($subscription['id'])[0] ?? null;
    error_log(sprintf('%s %s', $latest['httpStatusCode'] ?? '-', $latest['responseBody'] ?? ''));
}

// Stop deliveries while you fix the endpoint, then start them again.
$siren->webhooks->subscriptions->update($subscription['id'], ['status' => WebhookSubscriptionStatus::PAUSED]);
$siren->webhooks->subscriptions->update($subscription['id'], ['status' => WebhookSubscriptionStatus::ACTIVE]);
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
| `webhooks->subscriptions` | An API key holding `webhook-subscriptions:read` for `list()`, `events()`, `retrieve()` and `deliveries()`, or `webhook-subscriptions:manage` for every call. A dashboard session's access token also works. |
| `apps->verifyRequest()` | None. It reads nothing over the network, and checks the request with the app's signing secret. |
| `apps->requests->answer()` | The app's own API key. Owning the feature is what lets the key answer, so it needs no scope. |
| `apiKeys` | A signed-in dashboard session's access token. No API key scope reaches them. |
| Other record resources, and the writes on `conversions` and `transactions` | A dashboard session's access token, or a connected site's token on the routes Siren Cloud opens to connectors. See [Connected sites](#connected-sites). |

`ApiKeyScope` names every scope a new key may hold:

```php
use Siren\Sdk\ApiKeyScope;

$key = $siren->apiKeys->create([
    'label'  => 'Accounting export',
    'scopes' => [
        ApiKeyScope::ALL_EVENTS,
        ApiKeyScope::LEDGER_CONVERSIONS_READ,
        ApiKeyScope::LEDGER_PAYOUTS_READ,
        ApiKeyScope::WEBHOOK_SUBSCRIPTIONS_MANAGE,
    ],
]);
```

| Scope | What the key can do |
|-------|---------------------|
| `ApiKeyScope::ALL_EVENTS`, `ApiKeyScope::event($slug)` | Send events |
| `ApiKeyScope::LEDGER_CONVERSIONS_READ` | `conversions->list()`, `conversions->retrieve()` |
| `ApiKeyScope::LEDGER_TRANSACTIONS_READ` | `transactions->list()`, `transactions->retrieve()` |
| `ApiKeyScope::LEDGER_OBLIGATIONS_READ` | `obligations->list()` |
| `ApiKeyScope::LEDGER_PAYOUTS_READ` | `payouts->list()`, `payouts->retrieve()` |
| `ApiKeyScope::WEBHOOK_SUBSCRIPTIONS_READ` | `webhooks->subscriptions->list()`, `events()`, `retrieve()`, `deliveries()` |
| `ApiKeyScope::WEBHOOK_SUBSCRIPTIONS_MANAGE` | Every `webhooks->subscriptions` call, `update()` and `test()` included |

`ApiKeyRecordScope` names all thirty record scopes Siren issues, in the order
the dashboard offers them, and `ApiKeyRecordScope::ALL` lists them. Each
constant has the name Siren's own `ApiKeyRecordScopes` gives it, so
`ApiKeyRecordScope::FULFILLMENTS_READ` is `ledger:fulfillments:read`. The six
ledger and webhook scopes above carry the same strings under both classes.

| Group | `ApiKeyRecordScope` constants |
|-------|-------------------------------|
| The ledger, read only | `ENGAGEMENTS_READ`, `OPPORTUNITIES_READ`, `CONVERSIONS_READ`, `TRANSACTIONS_READ`, `OBLIGATIONS_READ`, `FULFILLMENTS_READ`, `PAYOUTS_READ` |
| Webhook subscriptions | `WEBHOOKS_READ`, `WEBHOOKS_MANAGE` |
| Records a merchant sets up | `PRODUCTS_READ`, `PRODUCTS_MANAGE`, `COUPONS_READ`, `COUPONS_MANAGE`, `COLLABORATORS_READ`, `COLLABORATORS_MANAGE`, `COLLABORATOR_GROUPS_READ`, `COLLABORATOR_GROUPS_MANAGE`, `PROGRAMS_READ`, `PROGRAMS_MANAGE`, `PROGRAM_GROUPS_READ`, `PROGRAM_GROUPS_MANAGE`, `DISTRIBUTORS_READ`, `DISTRIBUTORS_MANAGE`, `APPLICATION_FORMS_READ`, `APPLICATION_FORMS_MANAGE`, `APPLICATIONS_READ`, `APPLICATIONS_MANAGE`, `NOTIFICATION_RULES_READ`, `NOTIFICATION_RULES_MANAGE`, `PROPERTIES_READ` |

A manage scope reads everything its read scope reads.

```php
use Siren\Sdk\ApiKeyRecordScope;

$key = $siren->apiKeys->create([
    'label'  => 'Payout app',
    'scopes' => [
        ApiKeyRecordScope::PAYOUTS_READ,
        ApiKeyRecordScope::FULFILLMENTS_READ,
        ApiKeyRecordScope::WEBHOOKS_MANAGE,
    ],
]);
```

Siren grants the ledger, webhook and record scopes only by their exact names.
`*`, `event:*`, wildcards such as `ledger:*`, and older names such as
`conversions:read` grant none of them, so a key made before these scopes
existed reads nothing until you replace it with one that holds them. A key's
requests count against the organization's API rate limit.

A key created with a `propertyId` is bound to that store. It reads only its
own store's conversions, transactions and obligations, and Siren refuses it on
payouts, fulfillments and webhook subscriptions with
`StoreKeyNotAllowedException`. An
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
    'apiKey'     => 'sk_live_...',                              // or 'accessToken', see below
    'baseUrl'    => 'https://api.sirenaffiliates.com/siren/v1', // default
    'timeout'    => 30,                                         // seconds, default 30
    'maxRetries' => 2,                                          // default 2
    'httpClient' => $client,                                    // optional, see below
    'tokenCache' => $psr16Cache,                                // optional, default APCu when on
]);
```

Pass `apiKey` or `accessToken`, not both. `tokenCache` keeps app tokens
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

Webhook verification failures throw `SignatureVerificationException`.

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
| `webhooks->subscriptions->retrieve($id)` | `webhooks.subscriptions.retrieve(id)` | `GET /webhooks/{id}` | `webhook-subscriptions:read` or `:manage` |
| `webhooks->subscriptions->update($id, $params)` | `webhooks.subscriptions.update(id, params)` | `PUT /webhooks/{id}` | `webhook-subscriptions:manage` |
| `webhooks->subscriptions->test($id)` | `webhooks.subscriptions.test(id)` | `POST /webhooks/{id}/test` | `webhook-subscriptions:manage` |
| `webhooks->subscriptions->deliveries($id)` | `webhooks.subscriptions.deliveries(id)` | `GET /webhooks/{id}/deliveries` | `webhook-subscriptions:read` or `:manage` |
| `ApiKeyRecordScope`, `ApiKeyRecordScope::ALL` | `ApiKeyRecordScope`, `API_KEY_RECORD_SCOPES` | none | none |
| `events->ingest($slug, $payload)` | `siren.events.ingest(slug, payload)` | `POST /oauth/token`, then `POST /event/{slug}` | the app's key, traded for a token |
| `events->browserToken($eventKey)` | `siren.events.browserToken(eventKey)` | `POST /oauth/token` with `scope=browser:{eventKey}` | the app's key |
| `events->browserTokenHandler($options)`, `events->serveBrowserToken($options)` | `siren.events.browserTokenHandler(options)` | `POST /oauth/token` | the app's key |
| `apps->verifyRequest($rawBody, $headers, $secret, $toleranceSeconds, $now)` | `siren.apps.verifyRequest(rawBody, headers, secret, options)` | none | none |
| `apps->requests->answer($id, $answer)` | `siren.apps.requests.answer(id, answer)` | `POST /apps/requests/{id}/answer` | the app's key |
| `apps->requestHandler($signingSecret, $options)`, with `on`, `onEvent`, `serve`, `psr15` and `answer` | `siren.apps.requestHandler(options)`, with `on`, `onEvent`, `handle` and its Node, Web and Fastify mounts | `POST /apps/requests/{id}/answer` for an ask's answer | the app's key |
| `Apps::splitPool($pool, $weights)` | `siren.apps.splitPool(pool, weights)` | none | none |

Every route above but the token route takes an organization-wide key only.
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
