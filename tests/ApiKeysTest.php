<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use Siren\Sdk\Exception\ApiException;

final class ApiKeysTest extends TestCase
{
    public function testCreatePostsAndReturnsTheRawKey(): void
    {
        $siren = $this->mockSiren([
            self::jsonResponse(201, [
                'id' => 3,
                'keyPrefix' => 'sk_live_a1b2c3d4',
                'label' => 'Production server',
                'status' => 'active',
                'rawKey' => 'sk_live_' . str_repeat('f', 64),
            ]),
        ]);

        $key = $siren->apiKeys->create(['label' => 'Production server']);

        $request = $this->lastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.sirenaffiliates.com/siren/v1/api-keys', (string) $request->getUri());
        $this->assertSame(['label' => 'Production server'], $this->lastRequestJson());
        $this->assertSame('sk_live_' . str_repeat('f', 64), $key['rawKey']);
    }

    public function testCreateIsNeverAutoRetried(): void
    {
        $siren = $this->mockSiren([
            self::errorResponse(503, 'unavailable'),
            self::jsonResponse(201, ['id' => 1]),
        ]);

        try {
            $siren->apiKeys->create(['label' => 'CI']);
            $this->fail('Expected an ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }

        $this->assertCount(1, $this->history, 'A failed key creation must not be retried.');
    }

    public function testCreateRequiresALabel(): void
    {
        $siren = $this->mockSiren([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('label');

        $siren->apiKeys->create([]);
    }

    public function testListReturnsTheBareJsonArray(): void
    {
        $siren = $this->mockSiren([
            self::jsonResponse(200, [['id' => 1, 'keyPrefix' => 'sk_live_a1b2c3d4']]),
        ]);

        $keys = $siren->apiKeys->list();

        $this->assertSame('GET', $this->lastRequest()->getMethod());
        $this->assertSame('https://api.sirenaffiliates.com/siren/v1/api-keys', (string) $this->lastRequest()->getUri());
        $this->assertSame([['id' => 1, 'keyPrefix' => 'sk_live_a1b2c3d4']], $keys);
    }

    public function testRevokeTargetsTheId(): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200)]);

        $siren->apiKeys->revoke(3);

        $request = $this->lastRequest();
        $this->assertSame('DELETE', $request->getMethod());
        $this->assertSame('https://api.sirenaffiliates.com/siren/v1/api-keys/3', (string) $request->getUri());
    }
}
