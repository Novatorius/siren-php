<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Siren\Sdk\ConnectorScope;
use Siren\Sdk\PropertyQualifier;

final class ConnectorScopeTest extends BaseTestCase
{
    public function testAConnectedSiteAsksForTheRecordScopesByName(): void
    {
        // Siren's OAuthConnectService asks for exactly this string. The
        // record scopes are explicit only, so connect:* never grants them.
        $this->assertSame('connect:* connect:webhooks connect:records:read connect:records:write', ConnectorScope::CONNECTED_SITE);
        $this->assertSame(
            ['connect:*', 'connect:webhooks', 'connect:records:read', 'connect:records:write'],
            ConnectorScope::forConnectedSite(),
        );
    }

    public function testUnqualifyTakesOffOnlyAPropertySuffix(): void
    {
        $this->assertSame('coupon', PropertyQualifier::unqualify('coupon@property:5'));
        $this->assertSame('wordpress_user', PropertyQualifier::unqualify('wordpress_user@property:123'));
        $this->assertSame('coupon', PropertyQualifier::unqualify('coupon'));
        $this->assertSame('coupon@property:x', PropertyQualifier::unqualify('coupon@property:x'));
    }

    public function testPropertyOfReadsTheProperty(): void
    {
        $this->assertSame(5, PropertyQualifier::propertyOf('coupon@property:5'));
        $this->assertNull(PropertyQualifier::propertyOf('coupon'));
    }

    public function testNamesFollowSirensPattern(): void
    {
        foreach (['coupon', 'wc_product', 'credit_coupon_STORE_CREDIT', '0b6f3c2e-8a1d-4c55-9a0e-0d6c1b7f4e21'] as $name) {
            $this->assertTrue(PropertyQualifier::isValidName($name), $name);
        }

        foreach (['', 'a,b', 'a b', 'a:b', 'a@property:5', "a\n", 'ä'] as $name) {
            $this->assertFalse(PropertyQualifier::isValidName($name), json_encode($name, JSON_THROW_ON_ERROR));
        }
    }

    public function testPlainRecordLeavesARecordWithoutTheFieldAlone(): void
    {
        $this->assertSame(['code' => 'A'], PropertyQualifier::plainRecord(['code' => 'A'], 'type'));
    }
}
