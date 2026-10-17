<?php

declare(strict_types=1);

namespace Siren\Sdk\Exception;

/**
 * Raised for a 403 that is not about scopes: the token may use the route,
 * but Siren's policy refuses this request.
 *
 * For a connected site that means one of the connector rules, such as
 * sending `status`, `email` or a binding list on a collaborator, writing a
 * mapping of the `user` type, or enrolling a collaborator in a program that
 * does not run on the site. It also covers a record kind the site may never
 * write. {@see getParam()} names the refused field when Siren says which.
 * Repeating the same request will fail again.
 */
class PolicyException extends PermissionException
{
    /** What Siren says when a route's authorization policy refuses the caller. */
    public const DENIED_MESSAGE = "You don't have permission to do that.";

    /**
     * Whether a 403 is a policy refusal: one of the {@see ErrorCode::POLICY}
     * codes. From a Cloud deployment that sends no code, Siren's connector
     * rules name the refused field in `error.context.param` or say what a
     * connected site may not do, and a route's own policy answers
     * {@see DENIED_MESSAGE}.
     *
     * @param array<string, mixed>|null $data
     */
    public static function matches(string $message, ?array $data, ?string $code = null): bool
    {
        if (ErrorCode::isKnown($code)) {
            return in_array($code, ErrorCode::POLICY, true);
        }

        if (is_string($data['param'] ?? null)) {
            return true;
        }

        $message = strtolower($message);

        return $message === strtolower(self::DENIED_MESSAGE)
            || str_contains($message, 'connected site')
            || str_contains($message, 'run on this site');
    }

    /** The request field Siren refused, from `error.context.param`, when it named one. */
    public function getParam(): ?string
    {
        $param = $this->getErrorData()['param'] ?? null;

        return is_string($param) ? $param : null;
    }
}
