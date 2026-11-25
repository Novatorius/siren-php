<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use ReflectionClass;
use Siren\Sdk\FeatureKind;

/**
 * The payout method kind's question and answer types, held to Siren's own
 * shape: AppPayoutQuestion::build() and validate() in
 * lib/Apps/Service/Payouts/AppPayoutQuestion.php on Siren's feat/apps
 * (6d2abd936a), and the payoutMethod request in
 * tests/Fixtures/apps-feature-contracts/requests.json.
 *
 * @phpstan-import-type PayoutMethodQuestion from FeatureKind
 * @phpstan-import-type PayoutMethodAnswer from FeatureKind
 */
final class AppPayoutMethodTest extends TestCase
{
    private static function source(): string
    {
        return (string) file_get_contents((string) (new ReflectionClass(FeatureKind::class))->getFileName());
    }

    /** @return array<string, mixed> Siren's own payoutMethod question, from the contracts fixture. */
    private static function sirenQuestion(): array
    {
        $contracts = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/apps-feature-contracts/requests.json'), true, 512, JSON_THROW_ON_ERROR);

        return $contracts['kinds']['payoutMethod']['request']['data'];
    }

    /** @return PayoutMethodQuestion */
    private static function question(): array
    {
        return [
            'payout' => ['id' => 88, 'fulfillmentId' => 31, 'attempt' => 1],
            'amount' => ['value' => 2500, 'units' => 'USD', 'currency' => ['id' => 'USD', 'symbol' => '$', 'position' => 'before', 'name' => 'US Dollar', 'scale' => 2, 'decimalPlaces' => 2, 'type' => 'monetary']],
            'collaborator' => ['id' => 42, 'name' => 'Ana Ruiz', 'email' => null],
        ];
    }

    /** @return list<PayoutMethodAnswer> */
    private static function answers(): array
    {
        return [
            ['outcome' => 'paid', 'reference' => 'acme-ledger-5521'],
            ['outcome' => 'failed', 'reason' => 'The account is closed.'],
        ];
    }

    public function testTheKindIsAnAsk(): void
    {
        $this->assertSame('payoutMethod', FeatureKind::PAYOUT_METHOD);
        $this->assertContains(FeatureKind::PAYOUT_METHOD, FeatureKind::ASKS);
    }

    public function testTheFeatureKindTypesThePayoutQuestionAsSirenBuildsIt(): void
    {
        $source = self::source();

        $this->assertStringContainsString('@phpstan-type PayoutMethodQuestion array{', $source);
        $this->assertStringContainsString('payout: array{id: int, fulfillmentId: int, attempt: int},', $source);
        $this->assertStringContainsString('amount: array{value: int, units: string, currency: AppCurrency},', $source);
        $this->assertStringContainsString('collaborator: array{id: int, name: string, email: string|null},', $source);

        // The typed question has Siren's keys, in Siren's order, at every level.
        $siren = self::sirenQuestion();
        $typed = self::question();
        $this->assertSame(array_keys($siren), array_keys($typed));
        foreach (['payout', 'amount', 'collaborator'] as $part) {
            $this->assertSame(array_keys($siren[$part]), array_keys($typed[$part]), $part);
        }
    }

    public function testTheFeatureKindTypesAPaidAndAFailedAnswer(): void
    {
        $source = self::source();

        $this->assertStringContainsString("@phpstan-type PayoutMethodPaidAnswer array{outcome: 'paid', reference: non-empty-string}", $source);
        $this->assertStringContainsString("@phpstan-type PayoutMethodFailedAnswer array{outcome: 'failed', reason: non-empty-string}", $source);
        $this->assertStringContainsString('@phpstan-type PayoutMethodAnswer PayoutMethodPaidAnswer|PayoutMethodFailedAnswer', $source);
        $this->assertSame(['outcome', 'reference'], array_keys(self::answers()[0]));
        $this->assertSame(['outcome', 'reason'], array_keys(self::answers()[1]));
    }

    public function testTheKindsDocblockNamesTheRealQuestionNotTheOldOne(): void
    {
        $source = self::source();

        $this->assertStringNotContainsString("'payoutId' =>", $source);
        $this->assertStringContainsString('The question is a `PayoutMethodQuestion`', $source);
        $this->assertStringContainsString('the answer a `PayoutMethodAnswer`', $source);
    }
}
