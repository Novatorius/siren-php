<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use Siren\Sdk\FeatureKind;
use Siren\Sdk\Testing\AppRequests;

/**
 * The feature rename (contract/feature-rename.md in the run folder): Siren
 * calls what an app adds a feature everywhere a developer looks, and the
 * kinds' names come from Siren's kinds snapshot, docs/api/app-kinds.json,
 * which this SDK ships byte for byte as resources/app-kinds.json.
 */
final class FeatureRenameTest extends TestCase
{
    private const SECRET = 'whsec_feature_rename_test_secret';

    private static function shipped(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/resources/app-kinds.json');
    }

    public function testTheShippedKindsSnapshotIsSirensByteForByte(): void
    {
        $siren = getenv('SIREN_APP_KINDS');

        if ($siren === false || $siren === '') {
            $this->markTestSkipped('Set SIREN_APP_KINDS to the path of Siren\'s docs/api/app-kinds.json to compare the shipped copy with it.');
        }

        $this->assertFileExists($siren);
        $this->assertSame(hash_file('sha256', $siren), hash('sha256', self::shipped()), 'resources/app-kinds.json differs from ' . $siren . '. Copy Siren\'s file over it and run composer feature-kinds.');
    }

    public function testTheShippedKindsSnapshotHasSirensFormat(): void
    {
        $bytes = self::shipped();
        $kinds = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($kinds);
        $this->assertTrue(array_is_list($kinds));
        $this->assertCount(14, $kinds);

        foreach ($kinds as $kind) {
            $this->assertSame(['kind', 'term', 'name', 'description', 'mode', 'direction'], array_keys($kind));
            $this->assertContains($kind['mode'], ['tell', 'ask', 'receive']);
            $this->assertContains($kind['direction'], ['out', 'in']);
        }

        $this->assertStringEndsWith("}\n]\n", $bytes);
        $this->assertSame(json_encode($kinds, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", $bytes);
    }

    public function testCatalogReadsTheShippedSnapshot(): void
    {
        $this->assertSame(json_decode(self::shipped(), true, 512, JSON_THROW_ON_ERROR), FeatureKind::catalog());
    }

    public function testEveryKindHasAConstantWhoseHoverDocsCarryItsNameAndTerm(): void
    {
        $class = new \ReflectionClass(FeatureKind::class);
        $byValue = [];

        foreach ($class->getReflectionConstants() as $constant) {
            if (is_string($constant->getValue())) {
                $byValue[$constant->getValue()] = $constant;
            }
        }

        foreach (FeatureKind::catalog() as $kind) {
            $this->assertArrayHasKey($kind['kind'], $byValue, 'FeatureKind has no constant for ' . $kind['kind'] . '. Run composer feature-kinds.');
            $doc = (string) $byValue[$kind['kind']]->getDocComment();
            $lines = preg_split('/\R/', $doc) ?: [];
            $summary = trim(preg_replace('/^\s*\*\s?/', '', $lines[1] ?? '') ?? '');
            $expected = strcasecmp($kind['term'], $kind['name']) === 0
                ? $kind['name'] . '.'
                : $kind['name'] . '. Siren\'s own term: ' . $kind['term'] . '.';

            $this->assertSame($expected, $summary, 'The hover docs for ' . $kind['kind'] . ' start with its name.');
        }
    }

    public function testTheGeneratedKindDocsMatchTheSnapshot(): void
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/feature-kinds.php') . ' --check 2>&1';
        exec($command, $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
    }

    public function testATestRequestCarriesItsFeature(): void
    {
        $body = json_decode(AppRequests::make('distributionResolver', 'split-by-score', ['secret' => self::SECRET])->rawBody, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['kind' => 'distributionResolver', 'key' => 'split-by-score', 'id' => 'app:1'], $body['feature']);
        $this->assertArrayNotHasKey('item', $body);
        $this->assertSame(['version', 'id', 'type', 'mode', 'app', 'feature', 'createdAt', 'answerBy', 'answerUrl', 'data'], array_keys($body));
    }

    public function testTheHandlerReadsTheFeatureItAnswers(): void
    {
        $seen = null;
        $handler = $this->mockSiren([])->apps->requestHandler(self::SECRET, ['onError' => static function (): void {
        }])->on('postConversionAction', 'tell-the-crm', function ($request) use (&$seen): void {
            $seen = $request->feature;
        });
        $request = AppRequests::make('postConversionAction', 'tell-the-crm', ['secret' => self::SECRET]);

        $this->assertSame(200, $handler->answer($request->method, $request->rawBody, $request->headers)->status);
        $this->assertSame(['kind' => 'postConversionAction', 'key' => 'tell-the-crm', 'id' => 'app:1'], $seen);
    }

    public function testABodyWithTheOldItemAndNoFeatureIsRefused(): void
    {
        $body = json_decode(AppRequests::make('postConversionAction', 'tell-the-crm', ['secret' => self::SECRET])->rawBody, true, 512, JSON_THROW_ON_ERROR);
        $body['item'] = $body['feature'];
        unset($body['feature']);
        $request = AppRequests::make('postConversionAction', 'tell-the-crm', ['secret' => self::SECRET, 'body' => $body]);
        $handler = $this->mockSiren([])->apps->requestHandler(self::SECRET, ['onError' => static function (): void {
        }]);

        $reply = $handler->answer($request->method, $request->rawBody, $request->headers);

        $this->assertSame(400, $reply->status);
        $this->assertStringContainsString('its `feature` must be an object', $reply->body);
    }
}
