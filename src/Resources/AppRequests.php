<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\AppAnswerReceipt;
use Siren\Sdk\Exception\ApiException;
use Siren\Sdk\Exception\AuthenticationException;
use Siren\Sdk\Exception\ErrorCode;
use Siren\Sdk\Exception\SirenException;
use Siren\Sdk\HttpTransport;

/**
 * The requests Siren sends an app: answering an ask.
 *
 * The client must be made with the app's own API key, the one Siren gave
 * the app. Mirrors `siren.apps.requests.answer()` in the Node SDK.
 */
final class AppRequests
{
    /** The longest answer Siren takes, in bytes of JSON. Siren refuses past it too. */
    public const MAX_ANSWER_BYTES = 16384;

    /** A request key: `apr_` and 32 lowercase hex characters. */
    public const REQUEST_ID_PATTERN = '/^apr_[0-9a-f]{32}$/';

    /**
     * What the uncoded 401 on the answer route means for an app. Siren's key
     * check answers a missing or unknown key with 401 and no code, before
     * the answer route can send `app_answer_unauthorized`, so the SDK gives
     * that 401 the code, and keeps Siren's error as the previous exception.
     */
    private const UNAUTHORIZED_ANSWER = 'Siren did not accept this key on the answer route. An app answers with its own key, '
        . 'the SIREN_API_KEY saved beside its app file. When that key is lost, run siren apps rotate-key <app key> to make a new one. '
        . 'When the app holds no key, as after its key was revoked, run siren apps add-key <app key>.';

    public function __construct(private readonly HttpTransport $transport)
    {
    }

    /**
     * Answers an ask with `POST /apps/requests/{id}/answer`, sent once and
     * never retried, so a lost connection never sends a second answer. The
     * answer is the JSON body alone.
     *
     * Siren takes the first answer. The same answer again is a duplicate
     * and changes nothing. A different second answer is refused with 409
     * `app_request_already_answered`, as a {@see \Siren\Sdk\Exception\ConflictException}.
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
        if (preg_match(self::REQUEST_ID_PATTERN, $id) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'apps->requests->answer() needs a request id of apr_ and 32 lowercase hex characters, and got %s.',
                json_encode($id),
            ));
        }

        if ($answer === [] || array_is_list($answer)) {
            throw new \InvalidArgumentException(
                "apps->requests->answer() takes the answer as an object, such as ['outcome' => 'paid', 'reference' => 'TX-1'].",
            );
        }

        if (!is_string($answer['outcome'] ?? null) || $answer['outcome'] === '') {
            throw new \InvalidArgumentException("apps->requests->answer() needs a text outcome in the answer, such as 'paid' or 'resolved'.");
        }

        try {
            $json = json_encode($answer, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('apps->requests->answer() could not write the answer as JSON.', 0, $e);
        }

        if (strlen($json) > self::MAX_ANSWER_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                'apps->requests->answer() takes an answer of at most %d bytes as JSON, which Siren also refuses past.',
                self::MAX_ANSWER_BYTES,
            ));
        }

        $path = '/apps/requests/' . $id . '/answer';

        try {
            $response = $this->transport->request('POST', $path, $answer, [], false);
        } catch (AuthenticationException $e) {
            if ($e->getStatusCode() === 401 && ($e->getErrorCode() === null || $e->getErrorCode() === '')) {
                throw new AuthenticationException(self::UNAUTHORIZED_ANSWER, 401, ErrorCode::APP_ANSWER_UNAUTHORIZED, $e->getErrorData(), $e);
            }

            throw $e;
        }

        $reply = json_decode($response->getRawBody(), true);

        if (
            !is_array($reply)
            || ($reply['status'] ?? null) !== 'accepted'
            || (array_key_exists('duplicate', $reply) && !is_bool($reply['duplicate']))
        ) {
            throw new ApiException(
                sprintf('Siren answered POST %s with a body that is not an accepted answer.', $path),
                $response->getStatusCode(),
                null,
                is_array($reply) ? $reply : null,
            );
        }

        return new AppAnswerReceipt('accepted', ($reply['duplicate'] ?? false) === true);
    }
}
