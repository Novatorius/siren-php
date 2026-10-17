<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * The OAuth scopes a connected site asks Siren Cloud for.
 *
 * Siren grants these through its consent screen, so a host that runs the
 * OAuth flow itself puts {@see CONNECTED_SITE} in the authorization request's
 * `scope` parameter. `connect:webhooks` and the two record scopes are
 * explicit only: no wildcard grants them, `connect:*` and `*` included, so a
 * site holds one only when it asked for it by name. A site that connected
 * before the record scopes existed holds neither, and Cloud answers its record
 * calls with a 403 raised as {@see Exception\MissingScopeException}. The fix
 * is to reconnect.
 */
final class ConnectorScope
{
    /** Every `connect:` operation that a wildcard grants: read status, ingest events, run a migration. */
    public const CONNECT_ALL = 'connect:*';

    /** Manage the webhook subscription that sends the site's own events back to it. Explicit only. */
    public const WEBHOOKS = 'connect:webhooks';

    /** Read the organization's records through the record routes. Explicit only. */
    public const RECORDS_READ = 'connect:records:read';

    /** Change the records a person manages: collaborators, codes, enrollments, mappings, credit holds. Explicit only. */
    public const RECORDS_WRITE = 'connect:records:write';

    /** The space-separated `scope` value a connected WordPress site asks for. */
    public const CONNECTED_SITE = self::CONNECT_ALL . ' ' . self::WEBHOOKS . ' ' . self::RECORDS_READ . ' ' . self::RECORDS_WRITE;

    private function __construct()
    {
    }

    /**
     * The scopes a connected site asks for, one per entry.
     *
     * @return list<string>
     */
    public static function forConnectedSite(): array
    {
        return explode(' ', self::CONNECTED_SITE);
    }
}
