<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests\Resources;

use Siren\Sdk\Exception\PolicyException;
use Siren\Sdk\Resources\Collaborators;
use Siren\Sdk\Tests\TestCase;

/**
 * Collaborator writes and the currency read a connected site makes under
 * Siren Cloud's connector rules (Siren PR 384).
 */
final class ConnectorRecordReadsTest extends TestCase
{
    public function testCreatePendingSendsPendingAndNoBindings(): void
    {
        $siren = $this->hostSiren([self::hostJson(201, ['id' => 9, 'status' => 'pending'])]);

        $siren->collaborators->createPending(['fullName' => 'Ada Lovelace', 'nickname' => 'ada', 'email' => 'ada@example.com', 'trackingId' => 'ADA']);

        $this->assertSame(['POST', '/siren/v1/collaborators'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame(
            ['status' => 'pending', 'fullName' => 'Ada Lovelace', 'nickname' => 'ada', 'email' => 'ada@example.com', 'trackingId' => 'ADA'],
            $this->sentJson(),
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function refusedOnCreate(): array
    {
        return [
            'programs' => [['programs' => [2]]],
            'empty programs' => [['programs' => []]],
            'distributors' => [['distributors' => [1]]],
            'groups' => [['groups' => null]],
            'status' => [['status' => 'active']],
        ];
    }

    /**
     * @param array<string, mixed> $extra
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedOnCreate')]
    public function testCreatePendingRefusesWhatSirenRefusesFromASite(array $extra): void
    {
        $siren = $this->hostSiren([]);

        try {
            $siren->collaborators->createPending(['fullName' => 'Ada', 'nickname' => '', 'email' => 'ada@example.com'] + $extra);
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString((string) array_key_first($extra), $e->getMessage());
        }

        $this->assertSame([], $this->http->requests);
    }

    public function testUpdateDetailsPutsOnlyTheDetails(): void
    {
        $siren = $this->hostSiren([self::hostJson(200, ['id' => 9])]);

        $siren->collaborators->updateDetails(9, ['nickname' => 'countess', 'trackingId' => 'ADA2']);

        $this->assertSame(['PUT', '/siren/v1/collaborators/9'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame(['nickname' => 'countess', 'trackingId' => 'ADA2'], $this->sentJson());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusedOnUpdate(): array
    {
        $fields = [...Collaborators::BINDING_FIELDS, 'status', 'email'];

        return array_combine($fields, array_map(static fn (string $field): array => [$field], $fields));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedOnUpdate')]
    public function testUpdateDetailsRefusesWhatSirenRefusesFromASite(string $field): void
    {
        $siren = $this->hostSiren([]);

        try {
            $siren->collaborators->updateDetails(9, [$field => null]);
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($field, $e->getMessage());
        }

        $this->assertSame([], $this->http->requests);
    }

    public function testARefusedFieldFromSirenIsAPolicyRefusal(): void
    {
        $siren = $this->hostSiren([self::hostJson(403, ['error' => ['message' => 'A connected site may not send email here.', 'context' => ['param' => 'email']]])]);

        try {
            $siren->collaborators->update(9, ['email' => 'new@example.com']);
            $this->fail('Expected a PolicyException.');
        } catch (PolicyException $e) {
            $this->assertSame('email', $e->getParam());
        }
    }

    public function testCustomCurrencyRetrieveReadsOneCodeThroughTheListFilter(): void
    {
        $currency = ['code' => 'STORE_CREDIT', 'name' => 'Store credit', 'symbol' => '$', 'symbolPosition' => 'before', 'type' => 'internal', 'exchangeRate' => 100];
        $siren = $this->hostSiren([self::hostPage([$currency], 1, 1, 1)]);

        $this->assertSame($currency, $siren->customCurrencies->retrieve('STORE_CREDIT'));
        $this->assertSame(['GET', '/siren/v1/currencies/custom'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertSame(['code' => 'STORE_CREDIT', 'number' => '1'], $this->sentQuery());
    }

    public function testCustomCurrencyRetrieveIsNullForAnUnknownCode(): void
    {
        $siren = $this->hostSiren([self::hostPage([], 0, 1, 1)]);

        $this->assertNull($siren->customCurrencies->retrieve('NOPE'));
    }

    public function testCustomCurrencyRetrieveRefusesAnEmptyCode(): void
    {
        $siren = $this->hostSiren([]);

        $this->expectException(\InvalidArgumentException::class);

        $siren->customCurrencies->retrieve('');
    }
}
