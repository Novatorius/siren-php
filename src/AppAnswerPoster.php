<?php

declare(strict_types=1);

namespace Siren\Sdk;

use Siren\Sdk\Exception\ApiException;
use Siren\Sdk\Exception\AuthenticationException;
use Siren\Sdk\Exception\ErrorCode;
use Siren\Sdk\Exception\SirenException;
use Siren\Sdk\Resources\AppRequests;

/**
 * Posts and checks an app's answers for `$siren->apps->requests->answer()`
 * and the request handler, which share one. It keeps what only the handler
 * needs (a retried post, the answer address, a check that sends nothing)
 * off the requests resource, so a developer's autocomplete there shows
 * `answer`, `list`, `retrieve` and `resend` alone.
 *
 * @internal Made by {@see \Siren\Sdk\Resources\Apps}. Not part of the SDK's API.
 */
final class AppAnswerPoster
{
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
     * Answers an ask as {@see AppRequests::answer()} does, with the same
     * checks, but sends it again after a lost connection, a 429 or a 5xx,
     * inside the client's `maxRetries`. The resend is safe because it carries
     * the very same answer, which Siren takes again as a duplicate. The
     * request handler calls it after the function it runs returns an answer.
     *
     * @param array<int|string, mixed> $answer
     *
     * @throws \InvalidArgumentException When the id or the answer can't be sent.
     * @throws SirenException When Siren refuses the answer, or the last try fails.
     */
    public function answerRetried(string $id, array $answer): AppAnswerReceipt
    {
        return $this->post($id, $answer, true, 'The request handler');
    }

    /**
     * The base address this client sends answers to, with no trailing slash,
     * which the request handler checks against a request's `answerUrl`.
     */
    public function baseUrl(): string
    {
        return $this->transport->baseUrl;
    }

    /**
     * Checks an answer as {@see AppRequests::answer()} does before sending,
     * and sends nothing. The request handler's `simulate()` calls it.
     *
     * @param array<int|string, mixed> $answer
     *
     * @throws \InvalidArgumentException When the id or the answer can't be sent.
     */
    public function check(string $id, array $answer, string $caller = 'The request handler'): void
    {
        $this->encode($id, $answer, $caller);
    }

    /**
     * Checks an answer and posts it to `POST /apps/requests/{id}/answer`,
     * retried after a lost connection, a 429 or a 5xx only when `$retryable`.
     *
     * @param array<int|string, mixed> $answer
     *
     * @throws \InvalidArgumentException When the id or the answer can't be sent.
     * @throws AuthenticationException With code `app_answer_unauthorized` when Siren takes no answer from this key.
     * @throws ApiException When Siren answers 2xx with a body that is not an accepted answer.
     * @throws SirenException When Siren refuses the answer.
     */
    public function post(string $id, array $answer, bool $retryable, string $caller): AppAnswerReceipt
    {
        $this->encode($id, $answer, $caller);

        $path = '/apps/requests/' . $id . '/answer';

        try {
            $response = $this->transport->request('POST', $path, $answer, [], $retryable);
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

    /**
     * @param array<int|string, mixed> $answer
     *
     * @throws \InvalidArgumentException When the id or the answer can't be sent.
     */
    private function encode(string $id, array $answer, string $caller): string
    {
        if (preg_match(AppRequests::REQUEST_ID_PATTERN, $id) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                '%s needs a request id of apr_ and 32 lowercase hex characters, and got %s.',
                $caller,
                json_encode($id),
            ));
        }

        if ($answer === [] || array_is_list($answer)) {
            throw new \InvalidArgumentException(sprintf(
                "%s takes the answer as an object, such as ['outcome' => 'paid', 'reference' => 'TX-1'].",
                $caller,
            ));
        }

        if (!is_string($answer['outcome'] ?? null) || $answer['outcome'] === '') {
            throw new \InvalidArgumentException(sprintf("%s needs a text outcome in the answer, such as 'paid' or 'resolved'.", $caller));
        }

        try {
            $json = json_encode($answer, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException(sprintf('%s could not write the answer as JSON.', $caller), 0, $e);
        }

        if (strlen($json) > AppRequests::MAX_ANSWER_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                '%s takes an answer of at most %d bytes as JSON, which Siren also refuses past.',
                $caller,
                AppRequests::MAX_ANSWER_BYTES,
            ));
        }

        return $json;
    }
}
