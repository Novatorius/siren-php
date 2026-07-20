<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * URL slugs for the built-in ingestion event types (`POST /event/{slug}`).
 *
 * `Events::sale()`, `Events::refund()`, and `Events::siteVisited()` already
 * use these internally; the constants exist for code that routes slugs
 * dynamically (e.g. wrapping `Events::ingest()`).
 */
final class EventSlug
{
    public const SALE = 'sale';
    public const REFUND = 'refund';
    public const SITE_VISITED = 'site-visited';

    private function __construct()
    {
    }
}
