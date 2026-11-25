<?php

declare(strict_types=1);

namespace Siren\Sdk\Exception;

/**
 * A tell's or a webhook event's function ran longer than Siren waits for a
 * reply, {@see \Siren\Sdk\AppRequestHandler::TELL_TIME_LIMIT_SECONDS}. The
 * handler still replied 200, but Siren had stopped waiting, counted a
 * timeout and sends the tell again, so the function runs twice. `onError`
 * hears it, the default writes it as a warning, and `simulate()` hands it
 * back as `error`, so a test fails.
 */
final class AppSlowTellException extends SirenException
{
}
