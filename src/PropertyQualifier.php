<?php

declare(strict_types=1);

namespace Siren\Sdk;

/**
 * The namespace Siren Cloud keeps each connected site's names in.
 *
 * Cloud stores a type that a connected site writes with the site's Property
 * attached: a coupon code's type `coupon` becomes `coupon@property:5`, a
 * mapping's `wordpress_user` becomes `wordpress_user@property:5`, and a credit
 * hold's platform `woocommerce` becomes `woocommerce@property:5`. The site
 * always sends the plain name, and Cloud takes the Property from its token,
 * never from the request.
 *
 * Cloud refuses any type, platform or token from a site that is not letters,
 * digits, underscores and hyphens ({@see NAME_PATTERN}). That keeps out the
 * `@` of a qualifier and the comma a list filter splits on. The SDK checks the
 * same rule before it sends a request, so a bad name fails with an
 * `\InvalidArgumentException` instead of a round trip.
 */
final class PropertyQualifier
{
    /** What separates a name from the Property it belongs to. */
    public const SEPARATOR = '@property:';

    /** What a type, platform or token sent to Cloud may contain. `\z`, because `$` also matches before a final newline. */
    public const NAME_PATTERN = '/^[A-Za-z0-9_-]+\z/';

    private function __construct()
    {
    }

    /** The name with any `@property:N` suffix taken off. A plain name comes back unchanged. */
    public static function unqualify(string $name): string
    {
        return (string) preg_replace('/' . preg_quote(self::SEPARATOR, '/') . '\d+$/', '', $name);
    }

    /** The Property a qualified name belongs to, or null for a plain name. */
    public static function propertyOf(string $name): ?int
    {
        if (preg_match('/' . preg_quote(self::SEPARATOR, '/') . '(\d+)$/', $name, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /** Whether a name may be sent to Cloud as a type, platform or token. */
    public static function isValidName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /**
     * Returns the name when Cloud would accept it.
     *
     * @throws \InvalidArgumentException When it is empty or holds anything but letters, digits, underscores and hyphens.
     */
    public static function assertName(string $name, string $field): string
    {
        if (!self::isValidName($name)) {
            throw new \InvalidArgumentException(sprintf(
                'Send %s as letters, digits, underscores and hyphens only. Siren adds the site\'s Property itself.',
                $field,
            ));
        }

        return $name;
    }

    /**
     * Strips the qualifier from one field of a record and records the
     * Property it named under `propertyId`, so a caller sees the plain name
     * it sent and still knows which site's namespace the record lives in.
     * `propertyId` is null for a record in no site's namespace.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    public static function plainRecord(array $record, string $field): array
    {
        $value = $record[$field] ?? null;

        if (!is_string($value)) {
            return $record;
        }

        $record[$field] = self::unqualify($value);
        $record['propertyId'] = self::propertyOf($value);

        return $record;
    }
}
