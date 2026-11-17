<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use ReflectionClass;
use Siren\Sdk\ApiKeyRecordScope;
use Siren\Sdk\ApiKeyScope;

/**
 * Holds {@see ApiKeyRecordScope} to Siren's
 * lib/ApiKeys/Core/Enums/ApiKeyRecordScopes.php.
 *
 * The first tests pin the thirty strings Siren issued at 20c47873d, so they
 * run everywhere, CI included. The last one loads the PHP file itself from
 * the Siren checkout named by the SIREN_CHECKOUT environment variable, and
 * compares each constant's name and value and their order in DEFINITIONS.
 * It is skipped when SIREN_CHECKOUT is unset, since this repository holds no
 * copy of Siren.
 */
final class ApiKeyRecordScopeTest extends BaseTestCase
{
    private const SIREN_FILE = 'lib/ApiKeys/Core/Enums/ApiKeyRecordScopes.php';
    private const SIREN_CLASS = 'Siren\\ApiKeys\\Core\\Enums\\ApiKeyRecordScopes';

    /**
     * Each scope constant, name to value, in the order the class declares
     * them. `ALL` is the list, not a scope.
     *
     * @return array<string, string>
     */
    private static function scopeConstants(): array
    {
        $constants = (new ReflectionClass(ApiKeyRecordScope::class))->getConstants();
        unset($constants['ALL']);

        /** @var array<string, string> $constants */
        return $constants;
    }

    public function testPinsTheThirtyScopeStrings(): void
    {
        $this->assertSame([
            'ENGAGEMENTS_READ' => 'ledger:engagements:read',
            'OPPORTUNITIES_READ' => 'ledger:opportunities:read',
            'CONVERSIONS_READ' => 'ledger:conversions:read',
            'TRANSACTIONS_READ' => 'ledger:transactions:read',
            'OBLIGATIONS_READ' => 'ledger:obligations:read',
            'FULFILLMENTS_READ' => 'ledger:fulfillments:read',
            'PAYOUTS_READ' => 'ledger:payouts:read',
            'WEBHOOKS_READ' => 'webhook-subscriptions:read',
            'WEBHOOKS_MANAGE' => 'webhook-subscriptions:manage',
            'PRODUCTS_READ' => 'records:products:read',
            'PRODUCTS_MANAGE' => 'records:products:manage',
            'COUPONS_READ' => 'records:coupons:read',
            'COUPONS_MANAGE' => 'records:coupons:manage',
            'COLLABORATORS_READ' => 'records:collaborators:read',
            'COLLABORATORS_MANAGE' => 'records:collaborators:manage',
            'COLLABORATOR_GROUPS_READ' => 'records:collaborator-groups:read',
            'COLLABORATOR_GROUPS_MANAGE' => 'records:collaborator-groups:manage',
            'PROGRAMS_READ' => 'records:programs:read',
            'PROGRAMS_MANAGE' => 'records:programs:manage',
            'PROGRAM_GROUPS_READ' => 'records:program-groups:read',
            'PROGRAM_GROUPS_MANAGE' => 'records:program-groups:manage',
            'DISTRIBUTORS_READ' => 'records:distributors:read',
            'DISTRIBUTORS_MANAGE' => 'records:distributors:manage',
            'APPLICATION_FORMS_READ' => 'records:application-forms:read',
            'APPLICATION_FORMS_MANAGE' => 'records:application-forms:manage',
            'APPLICATIONS_READ' => 'records:applications:read',
            'APPLICATIONS_MANAGE' => 'records:applications:manage',
            'NOTIFICATION_RULES_READ' => 'records:notification-rules:read',
            'NOTIFICATION_RULES_MANAGE' => 'records:notification-rules:manage',
            'PROPERTIES_READ' => 'records:properties:read',
        ], self::scopeConstants());
    }

    public function testAllListsEveryScopeConstantInOrder(): void
    {
        $this->assertSame(array_values(self::scopeConstants()), ApiKeyRecordScope::ALL);
    }

    public function testAgreesWithTheLedgerAndWebhookNamesApiKeyScopeCarries(): void
    {
        $this->assertSame(ApiKeyRecordScope::CONVERSIONS_READ, ApiKeyScope::LEDGER_CONVERSIONS_READ);
        $this->assertSame(ApiKeyRecordScope::TRANSACTIONS_READ, ApiKeyScope::LEDGER_TRANSACTIONS_READ);
        $this->assertSame(ApiKeyRecordScope::OBLIGATIONS_READ, ApiKeyScope::LEDGER_OBLIGATIONS_READ);
        $this->assertSame(ApiKeyRecordScope::PAYOUTS_READ, ApiKeyScope::LEDGER_PAYOUTS_READ);
        $this->assertSame(ApiKeyRecordScope::WEBHOOKS_READ, ApiKeyScope::WEBHOOK_SUBSCRIPTIONS_READ);
        $this->assertSame(ApiKeyRecordScope::WEBHOOKS_MANAGE, ApiKeyScope::WEBHOOK_SUBSCRIPTIONS_MANAGE);
    }

    public function testCannotBeBuilt(): void
    {
        $this->assertFalse((new ReflectionClass(ApiKeyRecordScope::class))->isInstantiable());
    }

    public function testEqualsSirensListWhenACheckoutIsNamed(): void
    {
        $checkout = getenv('SIREN_CHECKOUT');

        if (!is_string($checkout) || $checkout === '') {
            self::markTestSkipped('Set SIREN_CHECKOUT to a Siren checkout to compare against ApiKeyRecordScopes.php.');
        }

        $file = rtrim($checkout, '/') . '/' . self::SIREN_FILE;
        $this->assertFileExists($file);

        if (!class_exists(self::SIREN_CLASS, false)) {
            require_once $file;
        }

        $siren = new ReflectionClass(self::SIREN_CLASS);
        $definitions = $siren->getConstant('DEFINITIONS');
        $this->assertIsArray($definitions);
        $this->assertNotEmpty($definitions);

        // Each scope constant, in the order DEFINITIONS lists its value.
        $byValue = array_flip(array_filter($siren->getConstants(), 'is_string'));
        $expected = [];

        foreach (array_keys($definitions) as $scope) {
            $this->assertArrayHasKey($scope, $byValue, sprintf('A constant in %s holds %s', self::SIREN_FILE, $scope));
            $expected[$byValue[$scope]] = $scope;
        }

        $this->assertSame($expected, self::scopeConstants());
        $this->assertSame(array_keys($definitions), ApiKeyRecordScope::ALL);
    }
}
