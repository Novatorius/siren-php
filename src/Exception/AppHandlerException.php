<?php

declare(strict_types=1);

namespace Siren\Sdk\Exception;

/**
 * A mistake in the app's own code that the request handler found, as the
 * Node and Python SDKs' `AppHandlerError` does: a kind Siren never sends, a
 * second function for one feature, an option the handler can't use, or an
 * ask's function that returned nothing or an answer Siren could not take.
 * It is a {@see SirenException}, so one `catch` covers it and Siren's own
 * refusals.
 *
 * `on()`, `onEvent()` and `requestHandler()` throw it at once. An ask's
 * function runs after the reply, so its mistakes reach `onError`, and
 * `simulate()` hands them back as `error`.
 */
final class AppHandlerException extends SirenException
{
}
