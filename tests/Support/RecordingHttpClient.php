<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests\Support;

use Siren\Sdk\Http\HttpClient;
use Siren\Sdk\Http\HttpRequest;
use Siren\Sdk\Http\HttpResponse;

/**
 * A host-supplied HttpClient double: replays queued responses (or throws
 * queued exceptions) and records every request it was asked to send.
 */
final class RecordingHttpClient implements HttpClient
{
    /** @var list<HttpRequest> */
    public array $requests = [];

    /** @var list<HttpResponse|\Throwable> */
    private array $queue;

    /**
     * @param list<HttpResponse|\Throwable> $queue
     */
    public function __construct(array $queue = [])
    {
        $this->queue = $queue;
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;

        if ($this->queue === []) {
            throw new \LogicException(sprintf('No response queued for %s %s.', $request->getMethod(), $request->getUrl()));
        }

        $next = array_shift($this->queue);

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function last(): HttpRequest
    {
        if ($this->requests === []) {
            throw new \LogicException('No request was sent.');
        }

        return $this->requests[array_key_last($this->requests)];
    }
}
