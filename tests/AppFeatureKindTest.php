<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use ReflectionClass;
use Siren\Sdk\AppFeatureKind;
use Siren\Sdk\AppItemKind;

/**
 * `AppFeatureKind`, the kinds the request handler routes, and `AppItemKind`,
 * the kinds Siren stores, name every kind alike. Only `customEvent` is
 * missing from the handler's kinds, since Siren sends it nothing.
 */
final class AppFeatureKindTest extends TestCase
{
    public function testEveryKindSirenSendsARequestForIsATellOrAnAsk(): void
    {
        $items = (new ReflectionClass(AppItemKind::class))->getConstants();
        unset($items['CUSTOM_EVENT']);
        $features = array_merge(AppFeatureKind::TELLS, AppFeatureKind::ASKS);
        sort($features);
        $values = array_values($items);
        sort($values);

        $this->assertSame($values, $features);
        $this->assertSame([], array_intersect(AppFeatureKind::TELLS, AppFeatureKind::ASKS));
    }

    public function testEachNamedKindHasTheSameNameAndValueInBoth(): void
    {
        $items = (new ReflectionClass(AppItemKind::class))->getConstants();
        $features = (new ReflectionClass(AppFeatureKind::class))->getConstants();
        unset($features['TELLS'], $features['ASKS'], $items['CUSTOM_EVENT']);
        ksort($items);
        ksort($features);

        $this->assertSame($items, array_intersect_key($features, $items));
        $this->assertSame(array_keys($items), array_keys(array_intersect_key($items, $features)), 'Every stored kind has a constant of the same name.');
        $this->assertSame([], array_diff_key($features, $items), 'No kind the handler routes is missing from AppItemKind.');
    }
}
