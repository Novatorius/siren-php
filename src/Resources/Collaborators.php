<?php

declare(strict_types=1);

namespace Siren\Sdk\Resources;

use Siren\Sdk\CollaboratorStatus;
use Siren\Sdk\HttpTransport;
use Siren\Sdk\ListResult;

/**
 * Collaborators (`/collaborators`): the affiliates, partners, and referrers
 * a program pays.
 *
 * Tracking codes (aliases) live on {@see $aliases}, and program enrollment
 * on {@see $enrollments}.
 */
class Collaborators extends ListResource
{
    public const BULK_ACTIVATE = 'activate';
    public const BULK_DEACTIVATE = 'deactivate';
    public const BULK_RESTORE = 'restore';
    public const BULK_DELETE = 'delete';
    public const BULK_PERMANENT_DELETE = 'permanent_delete';
    public const BULK_ADD_TO_PROGRAM = 'addToProgram';
    public const BULK_REMOVE_FROM_PROGRAM = 'removeFromProgram';
    public const BULK_ADD_TO_DISTRIBUTOR = 'addToDistributor';
    public const BULK_REMOVE_FROM_DISTRIBUTOR = 'removeFromDistributor';
    public const BULK_SEND_SIGNUP_EMAIL = 'sendSignupEmail';

    /**
     * The fields that bind a collaborator to programs, distributors and
     * groups. Siren treats a list that is present as the whole new set.
     */
    public const BINDING_FIELDS = ['programs', 'distributors', 'groups'];

    /** The only fields Siren Cloud lets a connected site send when it changes a collaborator. */
    public const SITE_UPDATABLE_FIELDS = ['id', 'fullName', 'nickname', 'fields'];

    /** A collaborator's tracking codes. */
    public readonly CollaboratorAliases $aliases;

    /** Which programs a collaborator is enrolled in. */
    public readonly ProgramEnrollments $enrollments;

    public function __construct(HttpTransport $transport)
    {
        parent::__construct($transport, '/collaborators');

        $this->aliases = new CollaboratorAliases($transport);
        $this->enrollments = new ProgramEnrollments($transport);
    }

    /**
     * Lists collaborators.
     *
     * Filters: `id`, `status`, `fullName`, `nickname`, `email` (exact match;
     * a list means "any of"), `s` (search across name, nickname, email, and
     * tracking codes), `programId` (enrolled in the program), and
     * `distributorId` (covered by the distributor). Sort with `orderBy`
     * (`id`, `status`, `fullName`, `nickname`, `email`, `dateCreated`,
     * `dateModified`) and `order` (`ASC` or `DESC`). `fields` picks the
     * returned fields, for example `['id', 'email', 'referralCode']`.
     *
     * @param array<string, mixed> $params
     */
    public function list(array $params = []): ListResult
    {
        return parent::list($params);
    }

    /**
     * Gets one collaborator. Pass `['fields' => [...]]` to choose fields,
     * such as `aliases`, `referralCode`, `programs`, `distributors`,
     * `creditBalances`, or `groups`.
     *
     * A connected site may ask only for `id`, `status`, `fullName`,
     * `nickname`, `email` and the dates. Siren refuses a computed field such
     * as `aliases` or `programs` from a site with a 403, so a site reads
     * codes through `aliases->query()` and enrollments through
     * `enrollments->list()`.
     *
     * @param array<string, mixed> $params
     *
     * @return array<int|string, mixed>
     */
    public function retrieve(int $id, array $params = []): array
    {
        return $this->read($this->recordPath($id), $params);
    }

    /**
     * Creates a collaborator.
     *
     * Required: `fullName`, `nickname`, `email`, `status` (see
     * {@see \Siren\Sdk\CollaboratorStatus}; `active`, `inactive`, or
     * `pending` on create). Optional: `trackingId` (the tracking code, one
     * is generated when empty), `programs`, `distributors`, and `groups`
     * (lists of ids).
     *
     * Siren answers 422 with {@see \Siren\Sdk\Exception\ErrorCode::RESERVED_ADDRESS}
     * when the email belongs to a held record that waits for its person to
     * confirm.
     *
     * @param array<string, mixed> $params
     *
     * @return array<int|string, mixed> The created collaborator.
     */
    public function create(array $params): array
    {
        return $this->write('POST', $this->path, $params);
    }

    /**
     * Creates a collaborator the way a connected site must: as `pending`, so
     * the organization approves them, and with no `programs`,
     * `distributors` or `groups`. Siren refuses those fields from a
     * connected site whatever their value, an empty list included, because
     * the binding listeners read a list that is present as the whole new set.
     * Enroll the collaborator afterwards with
     * {@see ProgramEnrollments::add()}.
     *
     * Required: `fullName`, `nickname` and `email`. Optional: `trackingId`.
     *
     * When the organization already has a collaborator with that email and
     * this site does not, Siren Cloud answers 201 with a held record for this
     * site, not with the existing collaborator. Its `id` is the held
     * record's own, its status is `pending`, it gets no tracking code (a
     * `trackingId` sent is dropped), and Siren emails the person to confirm.
     * Once they confirm, Siren merges the held record into their existing
     * collaborator and deletes it, so the held id stops resolving. Look the
     * collaborator up again by email after that.
     *
     * @param array<string, mixed> $params
     *
     * @return array<int|string, mixed> The created collaborator.
     */
    public function createPending(array $params): array
    {
        self::refuse($params, self::BINDING_FIELDS, 'a connected site may not send it, so enroll through $siren->collaborators->enrollments->add()');
        self::refuse($params, ['status'], 'createPending() always creates the collaborator as pending');

        return $this->create(['status' => CollaboratorStatus::PENDING] + $params);
    }

    /**
     * Updates a collaborator the way a connected site must: only `fullName`,
     * `nickname` and custom `fields`. Siren refuses a status or email change
     * from a connected site, because Cloud claims accounts by email and
     * approval stays with the organization, and it refuses the binding lists
     * for the reason {@see createPending()} gives. It also refuses
     * `trackingId`, so a site changes codes with `aliases->create()` and
     * `aliases->free()`. The SDK refuses every other field before sending.
     *
     * @param array<string, mixed> $params
     *
     * @return array<int|string, mixed> The updated collaborator.
     */
    public function updateDetails(int $id, array $params): array
    {
        self::refuse($params, self::BINDING_FIELDS, 'a connected site may not send it, so enroll through $siren->collaborators->enrollments->add()');
        self::refuse($params, ['status', 'email'], 'a connected site may not change it');
        self::refuse($params, ['trackingId'], 'a connected site may not change it, so change codes through $siren->collaborators->aliases');
        self::refuse(
            $params,
            array_values(array_diff(array_map('strval', array_keys($params)), self::SITE_UPDATABLE_FIELDS)),
            'a connected site may send only ' . implode(', ', self::SITE_UPDATABLE_FIELDS),
        );

        return $this->update($id, $params);
    }

    /**
     * Updates a collaborator with `PUT /collaborators/{id}`. Send only the
     * fields to change: `fullName`, `nickname`, `email`, `status`,
     * `trackingId`, or the full replacement lists `programs`,
     * `distributors`, and `groups`.
     *
     * @param array<string, mixed> $params
     *
     * @return array<int|string, mixed> The updated collaborator.
     */
    public function update(int $id, array $params): array
    {
        return $this->write('PUT', $this->recordPath($id), $params);
    }

    /**
     * Deletes a collaborator. The first call marks the record `deleted` and
     * returns it. Calling it again on a deleted record removes it for good
     * and returns an empty array.
     *
     * @return array<int|string, mixed>
     */
    public function delete(int $id): array
    {
        return $this->remove($this->recordPath($id));
    }

    /**
     * Runs a bulk action on several collaborators. Use the `BULK_*`
     * constants. `addToProgram` and `removeFromProgram` need
     * `['programId' => ...]` in `$extra`, and the distributor actions need
     * `['distributorId' => ...]`.
     *
     * @param list<int>            $ids
     * @param array<string, mixed> $extra
     *
     * @return array<int|string, mixed>
     */
    public function bulk(string $action, array $ids, array $extra = []): array
    {
        return $this->bulkAction($action, $ids, $extra);
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string>         $fields
     */
    private static function refuse(array $params, array $fields, string $why): void
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $params)) {
                throw new \InvalidArgumentException(sprintf('Leave out "%s": %s.', $field, $why));
            }
        }
    }

    /**
     * The collaborator statuses Siren accepts, with their labels.
     *
     * @return array<int|string, mixed>
     */
    public function statusTypes(): array
    {
        return $this->read($this->path . '/status-types');
    }

    /**
     * Asks Siren for an unused tracking code to offer a new collaborator.
     *
     * @return array<int|string, mixed>
     */
    public function generateReferralCode(): array
    {
        return $this->read($this->path . '/generate-referral-code');
    }
}
