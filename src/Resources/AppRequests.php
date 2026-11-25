<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\AppAnswerPoster;
use Siren\Sdk\AppAnswerReceipt;
use Siren\Sdk\Exception\ApiException;
use Siren\Sdk\Exception\AuthenticationException;
use Siren\Sdk\Exception\SirenException;
use Siren\Sdk\HttpTransport;

/**
 * The requests Siren sends the organization's apps, `/apps/requests`.
 *
 * {@see answer()} takes the app's own API key, the one Siren gave the app,
 * or an admin's session to answer by hand. {@see list()}, {@see retrieve()}
 * and {@see resend()} take a signed-in admin's session token, and Siren
 * refuses an app's key there with 401 `api_key_not_accepted`. Mirrors
 * `siren.apps.requests` in the Node SDK.
 *
 * @phpstan-type AppRequestRecord array{
 *     id: string,
 *     appId: int,
 *     feature: string,
 *     kind: string,
 *     mode: string,
 *     subject: array{type: string, id: string},
 *     status: string,
 *     sendCount: int,
 *     answerBy: string|null,
 *     answeredBy: string|null,
 *     answeredAt: string|null,
 *     lastError: string|null,
 *     createdAt: string,
 *     ...
 * }
 * @phpstan-type AppRequestDetail array{
 *     id: string,
 *     feature: string,
 *     status: string,
 *     data: array<string, mixed>,
 *     answer: array<string, mixed>|null,
 *     answeredByUser: array{id: int, email: string}|null,
 *     ...
 * }
 */
final class AppRequests
{
    /** The params {@see list()} sends, the ones `GET /apps/requests` reads (ListAppRequests.php in Siren). */
    public const LIST_FILTERS = ['status', 'limit', 'before'];

    /** The most requests one page holds. Siren lists 50 when `limit` is absent. */
    public const MAX_LIST_LIMIT = 100;

    /**
     * The statuses of a request Siren sent an app (lib/Apps/Core/Models/AppRequest.php
     * in Siren, its `STATUS_*` constants, in its order). A tell ends at
     * `delivered`. An ask's answer is `answered`, then `applying` while Siren
     * does its work, then `applied`. `needs_attention` waits for a person or
     * a late answer, and an ask that holds a reward ends at `dropped` when
     * Siren went on without it. {@see list()} takes any of them as its
     * `status` filter.
     */
    public const STATUSES = ['pending', 'sent', 'delivered', 'answered', 'applying', 'applied', 'needs_attention', 'dropped'];

    /** The longest answer Siren takes, in bytes of JSON. Siren refuses past it too. */
    public const MAX_ANSWER_BYTES = 16384;

    /** A request key: `apr_` and 32 lowercase hex characters. */
    public const REQUEST_ID_PATTERN = '/^apr_[0-9a-f]{32}$/';

    private readonly AppAnswerPoster $poster;

    /**
     * @param AppAnswerPoster|null $poster What posts and checks answers, shared with the request handler. Made from the transport when not given.
     */
    public function __construct(private readonly HttpTransport $transport, ?AppAnswerPoster $poster = null)
    {
        $this->poster = $poster ?? new AppAnswerPoster($transport);
    }

    /**
     * Answers an ask with `POST /apps/requests/{id}/answer`, sent once and
     * never retried, so a lost connection never sends a second answer. The
     * answer is the JSON body alone.
     *
     * Siren takes the first answer. The same answer again is a duplicate
     * and changes nothing. A different second answer is refused with 409
     * `app_request_already_answered`, as a {@see \Siren\Sdk\Exception\ConflictException}.
     * An answer after Siren stopped waiting for the ask, such as an incentive
     * resolver's 24 hours after its sale's reward began to wait, is refused with
     * 409 `app_request_dropped`, also a `ConflictException`.
     * An answer that doesn't fit the feature's kind is refused with 422.
     *
     * Refused before sending, with `\InvalidArgumentException`: an id that is
     * not a request key, an answer that is a list rather than an object, one
     * with no text `outcome`, and one longer than {@see MAX_ANSWER_BYTES} as
     * JSON.
     *
     * @param string $id The request's `id`, from {@see \Siren\Sdk\AppRequest::$id}.
     * @param array<string, mixed> $answer An object with a text `outcome`, such as `['outcome' => 'paid', 'reference' => 'TX-1']`.
     *
     * @throws \InvalidArgumentException When the id or the answer can't be sent.
     * @throws AuthenticationException With code `app_answer_unauthorized` when Siren takes no answer from this key.
     * @throws ApiException When Siren answers 2xx with a body that is not an accepted answer.
     * @throws SirenException When Siren refuses the answer.
     */
    public function answer(string $id, array $answer): AppAnswerReceipt
    {
        return $this->poster->post($id, $answer, false, 'apps->requests->answer()');
    }

    /**
     * The organization's latest requests, newest first, from
     * `GET /apps/requests`. Retried as a read.
     *
     * - `status` keeps one status, one of {@see STATUSES}.
     * - `limit` is how many a page holds, 1 to {@see MAX_LIST_LIMIT}. Siren lists 50 without it.
     * - `before` is a request id. The page then holds only requests older
     *   than it, so the last id of one page reads the next. Siren lists none
     *   for an id the organization has no request for.
     *
     * Siren ignores `limit` and `before` for `needs_attention`, whose list
     * comes whole.
     *
     * @param array{status?: string, limit?: int, before?: string} $params
     *
     * @return list<AppRequestRecord>
     *
     * @throws \InvalidArgumentException For a param other than {@see LIST_FILTERS}, a status outside {@see STATUSES}, a `limit` that is not a whole number from 1 to 100, or a `before` that is not a request id.
     * @throws ApiException When Siren answers 2xx with no list of requests.
     * @throws SirenException When Siren refuses it.
     */
    public function list(array $params = []): array
    {
        foreach (array_keys($params) as $key) {
            if (!in_array($key, self::LIST_FILTERS, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'apps->requests->list() does not read %s, and Siren would ignore it and answer every request. It reads %s.',
                    $key,
                    implode(', ', self::LIST_FILTERS),
                ));
            }
        }

        $status = $params['status'] ?? null;

        if ($status !== null && !in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException(sprintf('apps->requests->list() takes a status of %s.', implode(', ', self::STATUSES)));
        }

        $query = $status === null ? [] : ['status' => $status];

        if (array_key_exists('limit', $params)) {
            $limit = $params['limit'];

            if (!is_int($limit) || $limit < 1 || $limit > self::MAX_LIST_LIMIT) {
                throw new \InvalidArgumentException(sprintf('apps->requests->list() takes a limit that is a whole number from 1 to %d.', self::MAX_LIST_LIMIT));
            }

            $query['limit'] = $limit;
        }

        if (array_key_exists('before', $params)) {
            $before = $params['before'];

            if (!is_string($before) || preg_match(self::REQUEST_ID_PATTERN, $before) !== 1) {
                throw new \InvalidArgumentException('apps->requests->list() takes a before that is a request id, such as the last id of the page before.');
            }

            $query['before'] = $before;
        }

        $body = $this->transport->request('GET', '/apps/requests', null, $query)->json();
        $rows = $body['requests'] ?? null;

        if (!is_array($rows) || !array_is_list($rows) || array_filter($rows, static fn ($row): bool => !is_array($row)) !== []) {
            throw new ApiException('Siren answered GET /apps/requests with no list of requests in `requests`.', 200, null, $body);
        }

        /** @var list<AppRequestRecord> $rows */
        return $rows;
    }

    /**
     * One request by its id, however old, from `GET /apps/requests/{id}`:
     * the list's fields, the `data` Siren sent the app, the `answer` it took
     * or null, and `answeredByUser`, the person who answered by hand, or
     * null. Retried as a read. Siren answers 404 `app_request_not_found` for
     * a request the organization doesn't have.
     *
     * @return AppRequestDetail
     *
     * @throws \InvalidArgumentException For an id that is not `apr_` and 32 lowercase hex characters.
     * @throws ApiException When Siren answers 2xx with another request, or no object in `data`.
     * @throws SirenException When Siren refuses it.
     */
    public function retrieve(string $id): array
    {
        $path = '/apps/requests/' . self::requestId($id, 'apps->requests->retrieve()');
        $body = $this->transport->request('GET', $path)->json();

        if (($body['id'] ?? null) !== $id || !is_array($body['data'] ?? null)) {
            throw new ApiException(sprintf('Siren answered GET %s with a body that is not this request with its data.', $path), 200, null, $body);
        }

        /** @var AppRequestDetail $body */
        return $body;
    }

    /**
     * Sends a request again under the same id, with
     * `POST /apps/requests/{id}/resend`, sent once, and answers the request
     * as it now stands. An ask gets a new answer window. A request that holds
     * an answer Siren hasn't applied is not sent to the app again: Siren
     * applies its stored answer, even while the app is off. Siren answers 409
     * `app_request_not_resendable` for a request that is `applied`, a
     * `delivered` tell, or one it would send while its app is off, and 404
     * for a request the organization doesn't have (ResendAppRequest.php in
     * Siren).
     *
     * @return AppRequestRecord
     *
     * @throws \InvalidArgumentException For an id that is not `apr_` and 32 lowercase hex characters.
     * @throws SirenException When Siren refuses it.
     */
    public function resend(string $id): array
    {
        $path = '/apps/requests/' . self::requestId($id, 'apps->requests->resend()') . '/resend';

        /** @var AppRequestRecord */
        return $this->transport->request('POST', $path, null, [], false)->json();
    }

    private static function requestId(string $id, string $method): string
    {
        if (preg_match(self::REQUEST_ID_PATTERN, $id) !== 1) {
            throw new \InvalidArgumentException(sprintf('%s needs a request id of apr_ and 32 lowercase hex characters, and got %s.', $method, json_encode($id)));
        }

        return $id;
    }
}
