<?php

declare(strict_types=1);

namespace Siren\Sdk\Exception;

/**
 * Raised for a 403 that refuses an API key bound to one store on records
 * that are not kept by store, such as payouts and webhook subscriptions
 * ({@see ErrorCode::STORE_KEY_NOT_ALLOWED}).
 *
 * Retrying with the same key fails again. Use an organization-wide key,
 * one created without a `propertyId`.
 */
class StoreKeyNotAllowedException extends PermissionException
{
    /**
     * Whether a 403 is Siren's store-bound key refusal: the
     * {@see ErrorCode::STORE_KEY_NOT_ALLOWED} code, or, from a Cloud
     * deployment that sends no code, the message.
     */
    public static function matches(string $message, ?string $code = null): bool
    {
        if (ErrorCode::isKnown($code)) {
            return $code === ErrorCode::STORE_KEY_NOT_ALLOWED;
        }

        return stripos($message, 'bound to one store') !== false;
    }

    /** The store the key is bound to, from `error.context.propertyId`, when Siren named it. */
    public function getPropertyId(): ?int
    {
        $propertyId = $this->getErrorData()['propertyId'] ?? null;

        return is_int($propertyId) ? $propertyId : null;
    }
}
