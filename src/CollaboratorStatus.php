<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * Collaborator statuses, as Siren's collaborator status provider defines them.
 *
 * `ACTIVE`, `INACTIVE`, and `PENDING` can be set on create. `REJECTED` can
 * also be set on update. `DELETED` is set by the first DELETE call and by the
 * `delete` bulk action, never directly.
 */
final class CollaboratorStatus
{
    public const ACTIVE = 'active';
    public const INACTIVE = 'inactive';
    public const PENDING = 'pending';
    public const REJECTED = 'rejected';
    public const DELETED = 'deleted';

    private function __construct()
    {
    }
}
