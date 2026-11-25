# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `$answers->later()` gives the value an ask's function returns when the app
  will answer the request itself with `apps->requests->answer()`. The handler
  then posts nothing and reports nothing.
- `apps->requestHandler()` takes `extraKinds`, a list of kinds `on()` takes
  beyond the thirteen the SDK knows, for a kind a newer Siren sends first.
- `simulate()` checks an ask's answer for its kind as Siren does, ported from
  each kind's `validate()` on Siren's feat/apps branch, such as a share for
  every metric of the question, no share above the pool, whole currency
  steps, and ids that were in the question. An answer Siren would refuse comes
  back as `error`, a `ValidationException` with code `app_answer_invalid`
  (`ErrorCode::APP_ANSWER_INVALID`) and Siren's own words. The live handler
  posts as before and leaves those checks to Siren.
- The default `onError` line for an ask that failed after its 200 says when
  Siren sends it again, from `answerBy`, with the day when it isn't today,
  and how to resend it sooner: with `siren apps resend <id>`, since the API
  resends a pending ask and Siren Cloud offers Send again only once a request
  needs attention. A tell's line says Siren sends it again in about a minute.
- `FeatureKind::SENT` lists the thirteen kinds Siren sends requests for, and
  `FeatureKind::NAMES` the name Siren Cloud's screens give each kind.
- The package ships Siren's kinds snapshot, `docs/api/app-kinds.json` in
  Siren, byte for byte as `resources/app-kinds.json`. `FeatureKind::catalog()`
  reads it: each kind's `kind`, Siren's own `term`, the screen `name`, a
  `description`, its `mode` and its `direction`. `composer feature-kinds`
  writes `SENT`, `TELLS`, `ASKS`, `NAMES` and the first line of each kind
  constant's docblock from it, so an editor shows a kind's screen name on
  hover. A test fails when the class drifts from the file, and another
  compares the file with Siren's when `SIREN_APP_KINDS` names Siren's copy.
- The README opens with "How Siren talks to your app", the same six lines in
  all three SDKs. Its reply table gains "When your function fails", and it
  says an ask's function can run more than once for one request, so it writes
  once by `$request->id` and returns the same answer each time. The
  `AppRequest` docblock names `id` as the one id to dedupe on.

- `Siren\Sdk\Testing\AppRequests::make($kind, $key, $options)` builds a
  request as Siren signs it, a `SignedRequest` with `method`, `headers` and
  `rawBody`, so a handler can be tried without Siren. Its question defaults to
  `AppRequests::sampleData($kind)`, Siren's own sample for the kind, shipped
  in the package and held equal to Siren's feature contracts by a test. Its
  secret defaults to `SIREN_SIGNING_SECRET`.
- `AppRequestHandler::simulate($request)` runs the whole handler on a
  `SignedRequest` and returns an `AppRequestSimulation` with the four fields
  Node and Python return: the reply's `status` and its JSON `body` decoded,
  and for an ask the `answer` the function gave, checked as the post would
  check it and never posted. A failure `onError` would hear comes back as
  `error`. `answer` is null for a tell, after `later()`, beside a refused
  answer and on any failure.
- `new Siren()` reads `SIREN_API_KEY` when neither `apiKey` nor `accessToken`
  is passed, and `apps->requestHandler()` reads `SIREN_SIGNING_SECRET` when no
  secret is passed. A value passed in code wins. With neither, each still
  refuses and names both the option and the variable.

- `$siren->events->ingest($slug, $payload)` sends an app's custom event with a
  short-lived token. With a client built from the app's key, the SDK trades
  the key for a 20-minute token at `POST /oauth/token`, gets a new one when
  fewer than two minutes are left and after a 401, and sends once more. It
  keeps tokens in APCu when it is on, or in the PSR-16 cache passed as the new
  `tokenCache` client option, or in memory with `tokenCache => false`. `sale`,
  `refund` and `siteVisited` keep sending the key.
- `$siren->events->browserToken($eventKey)` gets a `BrowserToken` that lets a
  web page send one custom event for ten minutes from a domain the event
  allows. `events->serveBrowserToken($options)` serves it from a plain PHP
  file, `events->browserTokenHandler($options)` as a PSR-15
  `BrowserTokenHandler`, and `BrowserTokenEndpoint::answer($body, $query)` in
  any framework. Each answers 200 with `token`, `expiresIn` and `url`, 400 for
  an event outside `events` or one Siren refuses, and 502 when Siren can't be
  reached.
- `FeatureKind::PAYOUT_METHOD` (`payoutMethod`) in `ASKS`, so the request
  handler answers a payout method's ask: `['outcome' => 'paid', 'reference'
  => ...]` or `['outcome' => 'failed', 'reason' => ...]`.
- The PHPStan types `PayoutMethodQuestion` (`payout` with `id`, `fulfillmentId`
  and `attempt`, `amount` with `value`, `units` and `currency`, and
  `collaborator` with `id`, `name` and an `email` that may be null, as Siren
  builds it), `PayoutMethodPaidAnswer`, `PayoutMethodFailedAnswer` and
  `PayoutMethodAnswer` in `FeatureKind`. The kind's docblock now gives that
  question, in place of a `payoutId`, `amount` and `currency` Siren never
  sent.
- openapi.yaml describes `POST /apps/requests/{requestKey}/answer` with its
  200 and duplicate receipts and each refusal (401, 403 `app_inactive` and
  `app_answer_forbidden`, 404 `app_request_not_found`, 409
  `app_request_already_answered`, `app_request_dropped` and
  `app_request_not_needing_attention`, 422 `app_answer_invalid`), and
  `POST /oauth/token` with the `client_credentials` grant an app's key
  trades for a server or browser token. `POST /event/{eventSlug}` names the
  app token it takes, as the new `appToken` security scheme.
- `FeatureKind` holds, besides the types named below, the PHPStan types
  `AppCurrency`, `PostConversionActionTell`, `PostAllocationActionTell`,
  `DistributionResolverQuestion`, `DistributionResolverAnswer`, `IncentiveResolverQuestion`,
  `IncentiveResolverAnswer`, `MetricCalculationQuestion`,
  `MetricCalculationAnswer`, `MetricFilterQuestion`,
  `MetricFilterAnswer` and `AppEngagementCause`.
- `AppRequest` holds a checked request (`id`, `mode`, `app`, `feature`,
  `createdAt`, `answerBy`, `answerUrl`, `data`, and `isAsk()`),
  `AppAnswerReceipt` what Siren answered to a posted answer, and
  `AppRequestReply` the `status`, `headers`, `body` and `after` of
  `AppRequestHandler::answer()`.

- `$siren->apps->features` adds, reads, changes and removes one app's
  features on their own routes, as Node's `siren.apps.features` does:
  `create($appKey, $params)` (`POST /apps/{app}/features`), `retrieve`
  (`GET /apps/{app}/features/{id}`), `update` (`PATCH`) and `delete`
  (`DELETE`, which answers `['archived' => ..., 'feature' => ...]`). It throws
  `InvalidArgumentException` before sending for a bad app key, a field the
  route doesn't read, a create with no `kind` or `name`, the old kind
  `fulfillmentMethod`, and an update that changes `kind` or `key`.
- `apps->requests` gains `list($params)` (`GET /apps/requests`, with a
  `status` filter of `STATUSES`), `retrieve($id)` (`GET /apps/requests/{id}`,
  with the `data` Siren sent and the `answer` it took) and `resend($id)`
  (`POST /apps/requests/{id}/resend`, sent once), as Node's do. Each takes an
  admin's session token. openapi.yaml describes the four feature routes and
  the three request routes.
- PHPStan types each `on()` function by its kind, with no class and no cast.
  `AppRequest` takes `@template TData`, and `on()` carries a conditional type
  generated from the kinds snapshot, so an inline closure's `$request->data`
  is the kind's question and what it returns must be the kind's answer or
  `later()`. A misspelled field such as `$request->data['pools']`, a wrong
  `outcome` such as `'allocate'`, and a kind Siren never sends are errors at
  `level: max`. A test runs PHPStan on such a file and on the same file
  spelled right.
- Each kind constant's hover text is the sentence the three SDKs share: the
  screen name, then "Siren's code calls it the <term>." when the term
  differs, then the snapshot's description and "An ask: Siren waits for the
  app to answer." or "A tell: Siren lets the app know."

- `FeatureKind::TRANSACTION_FILTER`
  (`transactionFilter`), the feature kind a program or a distributor picks to
  let the app say which of a sale's own lines count. Siren's catalog calls it
  a transaction compiler. Siren asks it once per sale for each program or
  distributor that picks it, and a refund reuses the stored answer.
  `FeatureKind` lists it in `ASKS` and holds the PHPStan types
  `TransactionFilterQuestion`, `AppTransactionLine` and
  `TransactionFilterAnswer`, the ids of the lines to include. Siren refuses
  a line id the question lacks with 422 `app_answer_invalid`. The README shows
  the question and a handler that counts only `lineItem` lines.
- `FeatureKind::COLLABORATOR_GROUP_STRUCTURE` (`collaboratorGroupStructure`),
  the feature kind a collaborator group picks to let the app give the group's
  shape. Siren asks it once per cascade trigger on the group, for a program or
  a distributor. `FeatureKind` lists it in `ASKS` and holds the PHPStan
  types `CollaboratorGroupStructureQuestion`,
  `CollaboratorGroupStructureDistributorQuestion`,
  `CollaboratorGroupStructureProgramQuestion` and
  `CollaboratorGroupStructureAnswer`, a chain or a tree. Each member
  carries its `status` at the trigger, which Siren credits it by. Siren
  refuses an answer that leaves out or repeats a member, names one outside the group, or
  makes a cycle with 422 `app_answer_invalid`. The README shows the question
  and both answers.
- `$siren->apps->requestHandler($signingSecret, $options)` answers Siren's
  requests to an app in a few lines and returns an `AppRequestHandler`.
  `on($kind, $key, $fn)` and `on($kind, $fn)` register a function per
  feature, `onEvent($type, $fn)` one per webhook event at the same address,
  and `serve()`, `psr15()` (an `AppRequestPsrHandler`) and `answer()` (an
  `AppRequestReply`) mount it. It replies 405 to a method other than POST,
  413 to a body over 1 MiB, 500 to a body a parser already read, 401 to a
  request `verifyRequest()` refuses, 400 to a body that is not a JSON object
  or not a version 1 request, 200
  after a tell's function returns and 500 when it throws, 200 at once to an
  ask, 404 to a feature with no function, and 200 to any other event. An
  ask's function runs after the reply, and the handler posts the answer it
  returns to `POST /apps/requests/{id}/answer`, sent again after a lost
  connection, a 429 or a 5xx inside `maxRetries`, and never after a 4xx
  refusal, which goes to `onError`. Options: `toleranceSeconds`, `runLater`
  and `onError`. On RoadRunner, Swoole, Octane or FrankenPHP's worker mode,
  `psr15()` with no `runLater` answers an ask 500 and tells `onError` to
  pass one, since those workers run shutdown functions only when they stop.
- `Apps::splitPool($pool, $weights)` splits a resolver's pool by weight in
  whole steps of the pool's currency, rounded down so the sum never passes
  `pool.value`, and evenly when every weight is 0. It throws
  `InvalidArgumentException` for a negative, non-finite or non-number weight.
- `FeatureKind::INCENTIVE_TYPE`
  (`incentiveType`), the kind a program picks as its incentive type to let the
  app decide which engagements each sale, lead or renewal converts and the
  pool the program's resolver divides. `FeatureKind` holds its question and
  answer as the PHPStan types `IncentiveTypeQuestion` and
  `IncentiveTypeAnswer`, `['outcome' => 'award', 'units' => ..., 'pool' =>
  ..., 'engagementIds' => [...]]` or `['outcome' => 'none']`, and the README
  shows the handler's sample for it.
- `FeatureKind::ENGAGEMENT_CALCULATION`
  (`engagementCalculation`), and `FeatureKind::ENGAGEMENT_FILTER` (`engagementFilter`), the kinds a
  program's engagement type and a program pick to let the app decide who
  earns each engagement and whether it is made. `FeatureKind` holds their
  questions and answers as the PHPStan types `EngagementCalculationQuestion`,
  `EngagementCalculationAnswer`, `EngagementFilterQuestion` and
  `EngagementFilterAnswer`, and the README shows the answer each takes.
- `FeatureKind::PROGRAM_GROUP_SORTER`
  (`programGroupSorter`), the kind a program group picks as its structure to
  let the app order its programs for each sale or lead. `FeatureKind`
  holds its question and answer as the PHPStan types `ProgramGroupSorterQuestion`
  and `ProgramGroupSorterAnswer`, `['outcome' => 'sorted', 'programIds' => [...]]`,
  and the README shows the handler's sample for it.
- `FeatureKind` holds each kind's question and answer as PHPStan types.
- `FeatureKind` names the kinds of feature an app can add, as an app
  request's `feature['kind']` carries them, among them the new
  `METRIC_CALCULATION` (`metricCalculation`), `METRIC_FILTER` (`metricFilter`)
  and `INCENTIVE_RESOLVER` (`incentiveResolver`), which a program picks to let
  the app split each sale's reward. The README shows the answer each of the
  three takes, and the 409 `app_request_dropped` Siren answers once it stopped
  waiting, 24 hours after the sale's reward began to wait.
- `$siren->apps->verifyRequest($rawBody, $headers, $secret, $toleranceSeconds,
  $now)` checks a request Siren signed and sent an app and returns an
  `AppRequest`. It checks `X-Siren-Event`, the `X-Siren-Signature-V2`
  signature over `"{t}."` and the raw body, `X-Siren-Timestamp`, a replay
  window of 300 seconds by default (at most 3600), and the version 1
  envelope, and throws `SignatureVerificationException` with the reason. It
  reads nothing over the network.
- `$siren->apps->requests->answer($id, $answer)` sends an ask's answer once
  with `POST /apps/requests/{id}/answer` and returns an `AppAnswerReceipt`.
  It throws `InvalidArgumentException` before sending for a bad id, a list,
  an answer with no text `outcome`, and one over 16384 bytes of JSON, and
  gives Siren's uncoded 401 the code `ErrorCode::APP_ANSWER_UNAUTHORIZED`.
- `payouts->retrieve($id, $params)` reads one payout with `GET /payouts/{id}`.
  It takes `fields` and `include` (`extended` adds the collaborator's name and
  email), and an API key that holds `ledger:payouts:read`. `$siren->payouts`
  is now a `Payouts` resource, which is still a `ListResource`, so `list()`
  keeps passing every key as a filter.
- `$siren->fulfillments`, a `Fulfillments` resource, reads the batches Siren
  pays payouts in with an API key that holds `ledger:fulfillments:read`.
  `list()` sends `GET /fulfillments` with the filters `status`,
  `payoutStatus`, `currency`, `from` and `to`. It throws
  `InvalidArgumentException` before sending for a `payoutStatus` or
  `currency` of `""` or one that is not a string, and for a `from` or `to`
  that is not a real `YYYY-MM-DD` day, because Siren reads each of those as
  no filter or matches none. `retrieve($id, $params)` sends
  `GET /fulfillments/{id}`, and `include => 'extended'` adds each payout.
- `webhooks->subscriptions->retrieve($id)` reads one subscription with
  `GET /webhooks/{id}`, and `deliveries($id)` reads its latest 50 deliveries,
  newest first, with `GET /webhooks/{id}/deliveries`. Both take
  `webhook-subscriptions:read` or `:manage`, and both retry as reads.
- `webhooks->subscriptions->update($id, $params)` changes a subscription with
  `PUT /webhooks/{id}`. It sends only `targetUrl`, `events`, `status` and
  `description`, named in `WebhookSubscriptions::UPDATE_FIELDS`, and throws
  `InvalidArgumentException` before sending for any other key, and for an
  update with no field that is not `null`, since Siren skips a `null` field
  and answers 500 when nothing is left to change.
  `webhooks->subscriptions->test($id)` sends one signed test delivery with
  `POST /webhooks/{id}/test` and answers what the endpoint did. Both take
  `webhook-subscriptions:manage`, and each is sent once.
- `ApiKeyRecordScope` names the thirty record scopes Siren issues, under the
  constant names Siren's `ApiKeyRecordScopes` uses, and
  `ApiKeyRecordScope::ALL` lists them in Siren's order.
- Every call above throws `InvalidArgumentException` before sending for an id
  below 1.
- `openapi.yaml` describes `GET /payouts/{id}`, `GET /fulfillments`,
  `GET /fulfillments/{id}`, `PUT /webhooks/{id}` and
  `GET /webhooks/{id}/deliveries`, with the `WebhookSubscriptionUpdate` and
  `WebhookDelivery` schemas.
- `apps->requestHandler()` takes `logger`, a PSR-3 logger, and the package
  now requires `psr/log`. The handler writes a `debug` line for each request
  it handled, with its kind, key and id (or event type and delivery id), the
  status and the milliseconds. It writes a `warning` for a refused signature
  and a slow tell, and an `error` for the rest of what `onError` gets. Without
  `logger`, `ErrorLogLogger` writes warnings and errors to `error_log()`. It
  also takes `clock`, for tests.
- A refused signature reaches `onError` as a `SignatureVerificationException`
  whose code says why, the same codes Node's and Python's
  `SignatureVerificationError` carry: `signature_missing`,
  `signature_malformed`, `timestamp_mismatch`, `signature_mismatch` or
  `timestamp_out_of_tolerance`. The reply stays a 401. The default warning
  says what to check, such as SIREN_SIGNING_SECRET, the clock, or a proxy
  that drops Siren's headers. It writes five a minute and counts the rest in
  one line, with no timer.
- A tell or an event whose function takes over 8 seconds
  (`AppRequestHandler::TELL_TIME_LIMIT_SECONDS`, Siren's delivery timeout)
  reaches `onError` as an `AppSlowTellException`, since Siren stopped waiting
  and sends it again. The reply is still 200, and `simulate()` returns the
  error.
- `AppRequests::event($type, $data)` signs a webhook event with Siren's own
  headers, for testing an `onEvent()` function, and
  `AppRequests::sampleEventData($type)` gives a payload for each of the 60
  events Siren's `docs/api/webhooks-events.md` describes. The package ships
  them as `resources/webhook-event-samples.json`, which
  `bin/event-samples.php` writes from that page by the Node SDK's rules, so
  the three SDKs hold the same values. A test compares the file with Node's,
  and with the page when `SIREN_CHECKOUT` names a Siren checkout.
- `AppRequests::fromRecord($record, $key)` signs a stored request again, with
  its own id, kind, mode, question, `answerBy` and `test`. It reads the file
  `siren apps requests <id> --json` saves, taking the feature's key from
  `featureKey` and the app from `appKey`, so no key needs passing. `$key` is
  for a record with no key, such as the row `GET /apps/requests/{id}` gives
  or a saved request whose app is gone, and a record with neither throws
  `AppHandlerException`, as Node's and Python's `AppHandlerError`.
- `AppRequest::$test` is true for a test request, as `siren trigger ask`
  sends one, and false when the body has no `test` field.
  `AppRequests::make()` takes `'test' => true`. The README says a handler
  must never move money or write records on a test request, and the payout
  method sample answers one `failed` before any payment.
- The README gains "Develop on your machine": a sandbox key,
  `siren apps apply` with no address, `siren listen --forward-to` and
  `siren trigger ask`, with no tunnel, and how to run any `siren` command
  through `npx @novatorius/siren`.

### Changed

- The README's samples that write return on a test request before they
  write, and a test holds every writing sample to that. "Develop on your
  machine" gives the steps that run the first time: `siren apps apply` writes
  the key, `siren listen` writes the signing secret, and the app starts with
  `.env.sandbox` exported. The replay sample reads a request saved with
  `siren apps requests <id> --json`.
- The README no longer documents `apiKeys` or `webhooks->subscriptions->create()`,
  which call `/api-keys` and `POST /webhooks`. Siren Cloud answers those with 404
  and 405, because an app owns its key and its address. The README points at
  `siren apps apply` and `siren apps rotate-key` instead, says every
  `/webhooks` route takes a session only, and drops the two retired webhook
  scopes.
- The README leads with what the SDK can do and the request handler, with a
  sample for plain PHP, PSR-15 frameworks such as Slim and Mezzio, and
  Laravel. It gives each kind of app feature a section under the name Siren
  Cloud's screens use, with its question, its answer and a handler sample,
  then the payout method, custom events with short-lived tokens, checking and
  answering by hand, and errors and retries. Every handler sample runs in the
  tests, from the README's own text, against Siren's own request for its kind
  (tests/Fixtures/apps-feature-contracts/requests.json, now with all
  thirteen kinds Siren sends).
- The README's tagline names answering Siren from an app, and Install says
  the apps code is unreleased and shows how to install the `feat/apps` branch
  from GitHub or a local checkout. A "Try it without Siren" section follows
  the first sample, and its PHPUnit test runs in the tests. The first sample
  drops `baseUrl` and reads the key and the secret from the environment. The
  README says once, under Configuration, that `baseUrl` is only for a local
  or sandbox Siren. The webhook section says an app's address gets the V2
  signature, which the request handler checks.
- The default `onError` line is shorter. It names the error's class without
  its namespace, adds its code, and folds a cause in as `(cause: ...)`. It
  says where the error was thrown only when your own function threw it.
- After Siren refuses an answer with 422 `app_answer_invalid`, or the SDK's
  own check refuses it, the line no longer says Siren sends it again. It says
  to reproduce the answer with `simulate()`, fix it, then resend.
- The envelope check refuses a `test` that is not true or false with a 400
  that names the field.
- The 400 for a signed body that is not an app request of version 1, and
  `verifyRequest()`'s `SignatureVerificationException` for it, name the first
  field that fails and its rule, such as "its `id` must be `apr_` followed by
  32 hex characters".

### Breaking

- `apiKeys` (`create()`, `list()`, `scopes()` and `revoke()`) and
  `webhooks->subscriptions->create()` are gone, with
  `ApiKeyScope::WEBHOOK_SUBSCRIPTIONS_READ` and `WEBHOOK_SUBSCRIPTIONS_MANAGE`
  and `ApiKeyRecordScope::WEBHOOKS_READ` and `WEBHOOKS_MANAGE`, which
  `ApiKeyRecordScope::ALL` no longer lists. Siren serves no `/api-keys` route
  and no `POST /webhooks`, and retired the two webhook scopes, because an app
  owns its key and its address. Make an app with its key and address with
  `siren apps apply`. Give an app with no key a new one with
  `apps->createKey()`, replace it with `apps->rotateKey()` or
  `siren apps rotate-key`, and revoke it with `apps->revokeKey()`. List the
  scopes a key may hold with `apps->scopes()`. An address changes with the
  app, through `siren apps apply` or Siren Cloud, since this SDK has no
  `apps->update()`. openapi.yaml drops the removed operations and lists the
  app key routes. The other `/webhooks` routes take a signed-in session
  only.
- Each kind's PHPStan types are named for the kind: the kind in PascalCase,
  then `Tell`, `Question` or `Answer`, the same names in the three SDKs.
  `AppConversionTell` is now `PostConversionActionTell`, `AppAllocationTell`
  `PostAllocationActionTell`, `AppDistributionQuestion` and `Answer`
  `DistributionResolverQuestion` and `Answer`, `AppRewardQuestion` and
  `Answer` `IncentiveResolverQuestion` and `Answer`, `AppIncentiveTypeQuestion`
  and `Answer` `IncentiveTypeQuestion` and `Answer`, `AppGroupOrderQuestion`
  and `Answer` `ProgramGroupSorterQuestion` and `Answer`, and
  `AppPayoutQuestion`, `AppPayoutPaidAnswer`, `AppPayoutFailedAnswer` and
  `AppPayoutAnswer` `PayoutMethodQuestion`, `PayoutMethodPaidAnswer`,
  `PayoutMethodFailedAnswer` and `PayoutMethodAnswer`. The metric,
  engagement, transaction filter and collaborator group structure types drop
  the `App` prefix. No old name is kept.
- The types match what Siren builds. A post-conversion action's `reward` and
  a transaction filter's `transaction.currency` may be null. `score` and a
  line's `quantity` are `int`. A metric's `cause` and `sale` have their own
  shapes, `AppMetricCause` and `AppMetricSale`. A collaborator group
  structure question is a distributor's or a program's, each in full, and
  its answer `CollaboratorGroupStructureChainAnswer` or `TreeAnswer`.
- `AppRequestSimulation` holds `status`, `body`, `answer` and `error`, as
  Node's and Python's do. `headers` is gone, and `body` is the decoded JSON
  array, not the raw string.
- The request handler throws `AppHandlerException`, a `SirenException`, for
  every mistake in the app's own code, as Node and Python throw
  `AppHandlerError`: an unknown kind, a second function for one feature, a
  bad option, a function that returns nothing or not an array, and an
  answer `requests->answer()` would refuse. It throws no
  `InvalidArgumentException` or `UnexpectedValueException`. The messages are
  unchanged.
- `on()` refuses a kind Siren never sends with an `AppHandlerException`
  that says what to write instead: the kind a typo is closest to, the kind
  for a screen name such as `attributionModel`, the camelCase form of
  `distribution_resolver`, `onEvent()` for a webhook event, `events->ingest()`
  for `customEvent`, and `payoutMethod` for `fulfillmentMethod`. Before, such
  a function registered and every request for it got 404.
- An ask's function that returns nothing is an error, which `onError` hears
  and `simulate()` returns, and nothing is posted. Return `$answers->later()`
  to answer later. Before, null meant answer later and nothing was logged.
- Siren calls what an app adds a feature in every name a developer sees. The
  request envelope carries `feature` (`kind`, `key` and `id`) where it carried
  `item`, and `AppRequest::$feature` replaces `AppRequest::$item`. A signed
  body with `item` and no `feature` gets 400 naming `feature`.
- `FeatureKind` replaces `AppItemKind` and `AppFeatureKind`, and holds
  everything both held: the kind constants, `TELLS`, `ASKS` and the PHPStan
  shapes. Import the shapes `from FeatureKind`. Its `SENT`, `TELLS` and
  `ASKS` follow the order of Siren's "New feature" chooser.
- `on()` names a kind for a screen name as "The kind for Attribution model is
  incentiveResolver.", the same words in the three SDKs.

- The request handler answers an ask only when its `answerUrl` is on the same
  Siren as the client's `baseUrl`, by scheme, host and port. Siren Cloud's
  `api.sirenaffiliates.com` and `app.sirenaffiliates.com` count as one Siren
  over https. Any other ask gets 500, runs nothing and posts nothing, and
  `onError` gets a `SirenException` that names both addresses and the
  `baseUrl` to set. The answer still goes only to the client's `baseUrl`.
  Before, such an answer went to the client's Siren and was lost.

## [0.3.0] - 2026-10-19

This is a minor bump, because the changes below break existing callers. Each
one follows a rule Siren already enforces.

### Breaking

- `apiKeys->create()` throws `InvalidArgumentException` without a non-empty
  `scopes` list. Siren answers 400 to a key without scopes.
- `collaborators->updateDetails()` throws `InvalidArgumentException` for
  `trackingId`, `referralCode` and every parameter other than `fullName`,
  `nickname`, `fields` and `id`. Siren Cloud refuses them from a connected
  site with a 403.
- `webhooks->constructEvent()` on a bare payload returns the whole body as
  `getData()`, and throws `SignatureVerificationException` when `$eventType`
  is not passed. The three-argument call from 0.2.0 used to return an event
  with an empty type for every bare payload, which no handler matched. Pass
  the `X-Siren-Event` header, or call `constructEventFromHeaders()`. A body
  is read as a connected site's envelope only when its `id` and `type`
  match the `X-Siren-Delivery` and `X-Siren-Event` values, or when neither
  header is passed, so a custom event's payload that holds `version`,
  `type` and `data` keys stays whole.

### Added

- An API key reads Siren's ledger and manages webhook subscriptions, as Siren
  Cloud 3.6.259 allows. `conversions` and `transactions` (`list()` and
  `retrieve()`), `obligations->list()` and `payouts->list()` take a key that
  holds the matching ledger scope. `webhooks->subscriptions->list()` and
  `events()` take a key with `webhook-subscriptions:read` or
  `webhook-subscriptions:manage`, and `create()` and `delete()` take one with
  `webhook-subscriptions:manage`. A dashboard session's token still works on
  all of them. Since Siren Cloud 3.6.260, `webhook-subscriptions:manage` also
  sends a test delivery, and `openapi.yaml` lists that route,
  `POST /webhooks/{id}/test`.
- `ApiKeyScope::LEDGER_CONVERSIONS_READ`, `LEDGER_TRANSACTIONS_READ`,
  `LEDGER_OBLIGATIONS_READ`, `LEDGER_PAYOUTS_READ`,
  `WEBHOOK_SUBSCRIPTIONS_READ` and `WEBHOOK_SUBSCRIPTIONS_MANAGE`, and
  `ApiKeyScope::LEDGER_READ`, the four ledger scopes together.
  `apiKeys->create()` sends them as given. Siren grants each only by its
  exact name, so `*`, `event:*` and older names such as `conversions:read`
  grant none of them.
- `ApiKeyNotAcceptedException`, a 401 `AuthenticationException` for
  `api_key_not_accepted`, which Siren sends when a route takes a session and
  not an API key, as every `apiKeys` route does.
- `StoreKeyNotAllowedException`, a 403 `PermissionException` for
  `store_key_not_allowed`, which Siren sends when a key bound to one store
  asks for payouts or webhook subscriptions. `getPropertyId()` names the
  key's store.
- `MissingScopeException::getAnyOf()` lists every scope that opens the route,
  from `error.context.anyOf`.
- `ErrorCode::API_KEY_NOT_ACCEPTED` and `ErrorCode::STORE_KEY_NOT_ALLOWED`.
- The ledger readers document the `propertyId` filter, which narrows an
  organization-wide read to one store. A key bound to one store reads only
  its own store's rows. `openapi.yaml` lists it, and the `X-Siren-Property`
  header, on each ledger read.
- `EventResult` now carries what Siren reports about an ingested event:
  `getHttpStatus()`, `getStatus()`, `isQueued()`, `isAttributed()`,
  `getReason()`, `getMessage()`, `getTransactionId()`, `getConversions()`
  and `toArray()`. `EventStatus` names the statuses and reasons. A sale
  answers `created`, `recorded`, `duplicate`, `unattributed` or `ignored`,
  and a site visit answers `attributed`, `recorded` or `not_attributed`.
- `EventResult::isQueued()` reports the 202 Siren sends when the
  organization's engine is paused and it queues the event instead of
  refusing it. A queued visit's opportunity id is read from the body when
  the `X-Siren-OID` header is absent.
- `Webhooks::constructEventFromHeaders()`, which reads `X-Siren-Signature`,
  `X-Siren-Event` and `X-Siren-Delivery` from the request headers, and
  `WebhookEvent::getBody()`.
- `webhooks->subscriptions->events()` reads the live catalog from
  `GET /webhooks/events`.
- `WebhookEventType::COLLABORATOR_UPDATED`, `COLLABORATOR_DEACTIVATED` and
  `PROGRAM_CREATED`, which Siren now delivers.
- `ApiKeyScope`, the scopes an API key may hold, and `apiKeys->scopes()`
  for `GET /api-keys/scopes`. `apiKeys->list()` takes `number` and `offset`.
- `ObligationStatus::DRAFT`, `APPROVED`, `REJECTED` and `DELETED`, which
  Siren uses. `FULFILLED` stays for compatibility and is documented as
  retired, because Siren no longer accepts it.
- `ListResource::MAX_BULK_IDS`. A bulk action with more than 500 ids is
  refused before it is sent, as Siren refuses it with a 400.
- `ErrorCode` knows the codes Siren Cloud added: `collaborator_not_on_site`,
  `site_filter_required` and `report_not_allowed`, which raise a
  `PolicyException`, `migration_closed`, which stays a plain
  `PermissionException`, `reserved_address` (422) and `plan_unavailable`
  (503).

### Changed

- `apiKeys->create()` requires `scopes` and no longer requires `label`,
  as Siren does. Siren answers 400 to a key without scopes and to an older
  resource scope such as `programs:read`.
- The README and `openapi.yaml` say which credential each call takes. An API
  key sends events, reads the ledger with a ledger scope, and manages webhook
  subscriptions with a webhook scope. API keys are managed with a signed-in
  dashboard session's token. The other record routes take that or a
  connected site's token. `openapi.yaml` puts the ledger and webhook
  operations on the `apiKey` scheme with the scopes each one needs, and
  lists the new error codes.
- `collaborators->updateDetails()` sends only `fullName` and `nickname`,
  plus `fields` to choose the returned fields. Siren Cloud now refuses `trackingId` and every other
  field from a connected site with a 403, so the SDK refuses them before
  sending. A site changes codes through `aliases->create()` and
  `aliases->free()`.
- Docblocks, the README and `openapi.yaml` describe what Siren Cloud now
  holds a connected site to: the fields it may read, the filters
  opportunity and payout lists need, the held record a create returns for
  someone the organization already has, the `held` amount on credit
  balances, the `platformId` length after Cloud adds the Property, and the
  empty body `conversions->update()` returns.
- `openapi.yaml` lists the obligation and payout filters, answers 204 for
  group deletes, and says a page size above 200 is lowered to 200. The page
  size schema no longer sets a maximum, `externalId` on a sale, a refund
  and a line item takes a string or a number, and `GET /ping` is listed
  with the credentials it takes.
- `HoldLimitException` and `ErrorCode::HOLD_LIMIT` are documented as
  retired. Siren no longer caps credit holds, so it never sends `hold_limit`.

### Fixed

- `constructEvent()` read the event type and delivery id from the body, but
  a subscription made with `subscriptions->create()` receives the flat event
  payload, with the type in `X-Siren-Event` and the delivery id in
  `X-Siren-Delivery`. Such events came back with an empty type and empty
  data. `constructEvent()` now takes the two header values as arguments,
  returns the payload as the data, and refuses a payload that arrives
  without the event type. The versioned envelope a connected site's
  subscription receives is read from the body and needs no headers.
- `BadRequestException::getFieldErrors()` is documented as the list Siren
  sends, one `{field, message, type, context}` entry per broken rule. It
  said the messages were keyed by field name.
- The `sale()` docblock and `openapi.yaml` said a sale without `currency`
  defaults to USD. Siren records it in the organization's default currency.
- `openapi.yaml` described event validation failures as 422. Siren answers
  400 with `error.context.failedValidations`, which `BadRequestException`
  reads.

## [0.2.0] - 2026-10-17

### Added

- Typed taxonomy exports so integrations use SDK constants instead of magic
  strings: `EventSlug` (built-in ingestion slugs) and the status vocabularies
  `ConversionStatus`, `TransactionStatus`, `ObligationStatus`, `PayoutStatus`,
  `FulfillmentStatus`, `OpportunityStatus`, `ApiKeyStatus`, and
  `WebhookSubscriptionStatus`.
- `WebhookEventType`: added the missing `CREDIT_ISSUED`, `CREDIT_REDEEMED`,
  `CURRENCY_CREATED`, and `CURRENCY_DELETED` events (also added to
  `openapi.yaml`), matching the full set Siren dispatches.
- PHPStan static analysis (level 6) with the PHPNomad stack-elevator ruleset,
  wired as `composer lint` and a CI lint job.
- Record resources for the routes a connector needs: `collaborators` (with
  `aliases` for tracking codes and `enrollments` for program enrollment),
  `programs`, `programGroups`, `opportunities`, `transactions` (create,
  details, bulk, credit to a collaborator), `conversions` (create, update,
  delete, bulk), `engagements`, `distributors`, `customCurrencies`,
  `credits`, and `collaboratorGroups` with their `members`. Each resource
  has a method only where Siren has the route.
- A host-supplied HTTP seam, `Siren\Sdk\Http\HttpClient`, passed as the
  `httpClient` option. `GuzzleHttpClient` wraps Guzzle and
  `StreamHttpClient` uses PHP streams with no dependencies.
- A host-supplied access token, `Siren\Sdk\Auth\AccessTokenSource`, passed
  as the `accessToken` option. After a 401 the SDK asks the source for a
  fresh token once and repeats the request. `CallbackAccessTokenSource`
  builds a source from two callables.
- `CollaboratorStatus`, `ProgramStatus`, `DistributorStatus`,
  `ProgramGroupSorter`, and `CustomCurrencyType`, plus a `BULK_*` constant for
  every bulk action.
- `ListResult::getTotalPages()` and `ListResult::hasMore()`.
- `BadRequestException::getFieldErrors()`. Siren's record routes answer a
  failed validation with 400 and `error.context.failedValidations`, which the
  SDK now reads, with `VALIDATION_FAILED` as the error code.

- The record routes Siren Cloud opens to a connected site's token (Siren
  PR 384), each with the route's own filters, paging and input rules:
  - `collaborators->aliases`: `findByCode()`, `forCollaborator()`,
    `query()`, `create()` and `free()` on `/collaborator-aliases`.
  - `collaborators->enrollments`: `list()`, `add()` and `remove()` on
    `/collaborator-programs`.
  - `$siren->mappings`, a new resource: `list()`, `forExternal()`,
    `forLocal()`, `create()`, `deleteForCollaborator()` and
    `deleteForExternal()`. Every call names an external type.
  - `$siren->settings`, a new resource: `collaborator()` and `value()` on
    `/configs`.
  - `credits`: `checkoutBalances()` and `checkoutBalance()` on
    `/credit-balances`, `entries()`, `holds()`, `placeHold()` and the
    `holdReference()` helper.
  - `customCurrencies->retrieve()` by code.
  - `collaborators->createPending()` and `collaborators->updateDetails()`,
    which leave out the fields Siren refuses from a connected site.
- `ConnectorScope`, the OAuth scopes a connected site asks for, including the
  explicit `connect:records:read` and `connect:records:write`.
- `PropertyQualifier`. Codes and mappings now come back with the plain type
  and the site's Property in `propertyId`, and every type, platform and
  token is checked against Siren's name rule before it is sent.
- Typed refusals: `MissingScopeException` and `PolicyException` for 403,
  `SiteDisconnectedException` for a revoked site's 401,
  `CodeConflictException` and `HoldConflictException` for 409, and
  `HoldLimitException` for the per-site hold cap's 429. Each extends the
  exception the status already raised.
- `ListResult::map()`.
- `Credits::releaseHold()` releases a store credit hold early with
  `DELETE /credit-holds/{id}`, so an abandoned checkout stops reserving
  credit before its `ttl` runs out. Releasing a hold that already ended is
  not an error. Never release the hold of a checkout that became an order,
  because settlement consumes it.
- `ErrorCode`, the stable `error.code` values Siren Cloud sends with a
  connector's 401, 403, 409 and hold cap 429, and with a plan refusal
  (`plan_required`) or an admin-only refusal (`admin_required`). Those two
  stay a plain `PermissionException`.

### Changed

- The typed refusals now follow Siren Cloud's `error.code` first, so a
  reworded message no longer changes which exception a host catches. A
  reply with no code, from a Cloud deployment older than the codes, is still
  read by its wording. `MissingScopeException::getRequiredScope()` reads
  `error.context.scope` when Cloud sends it.

- Guzzle is no longer required. It moved to `suggest`, and the SDK uses it
  when installed and PHP streams when not. Code that passes its own
  `GuzzleHttp\ClientInterface` as `httpClient` works as before but must
  require Guzzle itself.
- `$siren->conversions` and `$siren->transactions` are now `Conversions` and
  `Transactions` resources. Both still extend `ListResource`, so `list()`
  behaves as before.

### Fixed

- List readers sent `page` and `perPage`, which Siren ignores, so every call
  returned the first ten rows. The SDK now turns them into Siren's `number`
  and `offset`, and passes `number` and `offset` through when given.
- List readers now read Siren's list envelope
  (`{items, total, page, perPage, totalPages}`). Before, `getData()`
  returned the envelope's values instead of the records.
- A page size above 200 is lowered to 200 before a page-based offset is
  worked out, as Siren lowers it, so no rows are skipped between pages.
- `openapi.yaml` described list responses as bare arrays and every response
  as wrapped in `data`. It now describes the list envelope and bare record
  bodies, and uses OpenAPI 3.1 null types for the API key dates.

## [0.1.0] - 2026-07-11

### Added

- Initial public release of the Siren SDK for PHP.
- `Siren` client with configurable base URL, timeout, and retry policy.
- Event ingestion: `events->sale()`, `events->refund()`, `events->siteVisited()`, and custom event types via `events->ingest()`.
- Webhook signature verification: `webhooks->constructEvent()` and the lower-level constant-time `webhooks->verifySignature()`.
- Webhook subscription management: create, list, and delete.
- API key management: create, list, and revoke.
- Reconciliation readers with pagination over conversions, transactions, obligations, and payouts.
- Automatic retries with exponential backoff on idempotent operations.
- Typed exception hierarchy under `Siren\Sdk\Exception`.

[Unreleased]: https://github.com/Novatorius/siren-php/compare/0.3.0...HEAD
[0.3.0]: https://github.com/Novatorius/siren-php/compare/0.2.0...0.3.0
[0.2.0]: https://github.com/Novatorius/siren-php/compare/0.1.0...0.2.0
[0.1.0]: https://github.com/Novatorius/siren-php/releases/tag/0.1.0
