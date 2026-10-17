<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use Siren\Sdk\Auth\CallbackAccessTokenSource;
use Siren\Sdk\Exception\ErrorCode;
use Siren\Sdk\Exception\HoldConflictException;
use Siren\Sdk\Exception\HoldLimitException;
use Siren\Sdk\Exception\MissingScopeException;
use Siren\Sdk\Exception\PermissionException;
use Siren\Sdk\Exception\PolicyException;
use Siren\Sdk\Exception\RateLimitException;
use Siren\Sdk\Exception\SiteDisconnectedException;

/**
 * Siren Cloud names each refusal of a connected site with a stable
 * `error.code`. The SDK decides on the code first, so Cloud can reword a
 * message without changing which exception a host catches. A reply with no
 * code, from a Cloud deployment older than the codes, is still read by its
 * wording.
 */
final class ErrorCodesTest extends TestCase
{
    /**
     * The codes are a contract with Siren Cloud. Changing one breaks every
     * host that compares against it, so this pins each value.
     */
    public function testTheCodesAreStable(): void
    {
        $this->assertSame([
            'SITE_DISCONNECTED' => 'site_disconnected',
            'MISSING_SCOPE' => 'missing_scope',
            'FIELD_NOT_ALLOWED' => 'field_not_allowed',
            'PENDING_STATUS_REQUIRED' => 'pending_status_required',
            'USER_TYPE_NOT_ALLOWED' => 'user_type_not_allowed',
            'PROGRAM_NOT_ON_SITE' => 'program_not_on_site',
            'NOT_PERMITTED' => 'not_permitted',
            'CODE_HELD' => 'code_held',
            'MAPPING_EXISTS' => 'mapping_exists',
            'HOLD_NO_CREDIT' => 'hold_no_credit',
            'HOLD_TOKEN_IN_USE' => 'hold_token_in_use',
            'HOLD_LIMIT' => 'hold_limit',
        ], array_filter((new \ReflectionClass(ErrorCode::class))->getConstants(), 'is_string'));
    }

    public function testEveryPolicyCodeIsAKnownCode(): void
    {
        foreach (ErrorCode::POLICY as $code) {
            $this->assertTrue(ErrorCode::isKnown($code), $code);
        }

        $this->assertFalse(ErrorCode::isKnown('entitlement_required'));
        $this->assertFalse(ErrorCode::isKnown(null));
    }

    public function testAMissingScopeCodeIsAMissingScopeExceptionWhateverTheMessage(): void
    {
        $siren = $this->hostSiren([self::coded(403, 'The site needs to reconnect.', ErrorCode::MISSING_SCOPE, ['scope' => 'connect:records:write'])]);

        try {
            $siren->mappings->create(4, '12', 'wordpress_user');
            $this->fail('Expected a MissingScopeException.');
        } catch (MissingScopeException $e) {
            $this->assertSame(ErrorCode::MISSING_SCOPE, $e->getErrorCode());
            $this->assertSame('connect:records:write', $e->getRequiredScope(), 'The scope comes from error.context.scope.');
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function policyCodes(): array
    {
        return array_combine(ErrorCode::POLICY, array_map(fn (string $code): array => [$code], ErrorCode::POLICY));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('policyCodes')]
    public function testEveryPolicyCodeIsAPolicyExceptionWhateverTheMessage(string $code): void
    {
        $siren = $this->hostSiren([self::coded(403, 'Reworded refusal.', $code)]);

        try {
            $siren->collaborators->list();
            $this->fail('Expected a PolicyException.');
        } catch (PolicyException $e) {
            $this->assertSame($code, $e->getErrorCode());
        }
    }

    public function testACodeOutranksAMessageThatReadsLikeAnotherRefusal(): void
    {
        $siren = $this->hostSiren([self::coded(403, 'This token is not authorized for connect:records:read.', ErrorCode::NOT_PERMITTED)]);

        try {
            $siren->collaborators->list();
            $this->fail('Expected a PolicyException.');
        } catch (PermissionException $e) {
            $this->assertSame(PolicyException::class, $e::class, 'The code says policy, so the scope wording is ignored.');
        }
    }

    public function testAnUnknownCodeFallsBackToTheWording(): void
    {
        $siren = $this->hostSiren([self::coded(403, 'This token is not authorized for connect:records:read.', 'something_newer')]);

        $this->expectException(MissingScopeException::class);

        $siren->collaborators->list();
    }

    public function testAnUnknown403CodeWithUnfamiliarWordingStaysAPlainPermissionException(): void
    {
        $siren = $this->hostSiren([self::coded(403, 'Upgrade required.', 'entitlement_required')]);

        try {
            $siren->collaborators->list();
            $this->fail('Expected a PermissionException.');
        } catch (PermissionException $e) {
            $this->assertSame(PermissionException::class, $e::class);
        }
    }

    public function testASiteDisconnectedCodeIsASiteDisconnectedExceptionWhateverTheMessage(): void
    {
        $siren = $this->hostSiren(
            [
                self::coded(401, 'Reconnect this site.', ErrorCode::SITE_DISCONNECTED),
                self::coded(401, 'Reconnect this site.', ErrorCode::SITE_DISCONNECTED),
            ],
            ['accessToken' => new CallbackAccessTokenSource(
                static fn (): string => 'old.jwt.token',
                static fn (string $rejected): string => 'new.jwt.token',
            )],
        );

        $this->expectException(SiteDisconnectedException::class);

        $siren->collaborators->list();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function holdConflicts(): array
    {
        return [
            'no credit, reworded' => [ErrorCode::HOLD_NO_CREDIT, 'Nothing to reserve.', HoldConflictException::REASON_NO_CREDIT],
            'token in use, reworded' => [ErrorCode::HOLD_TOKEN_IN_USE, 'Pick another session.', HoldConflictException::REASON_TOKEN_IN_USE],
            'code outranks the no-credit wording' => [ErrorCode::HOLD_TOKEN_IN_USE, 'This collaborator has no credit available in that currency.', HoldConflictException::REASON_TOKEN_IN_USE],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('holdConflicts')]
    public function testAHoldConflictsReasonComesFromItsCode(string $code, string $message, string $reason): void
    {
        $siren = $this->hostSiren([self::coded(409, $message, $code)]);

        try {
            $siren->credits->placeHold(4, 'STORE_CREDIT', 1000, 'tok', 'woocommerce');
            $this->fail('Expected a HoldConflictException.');
        } catch (HoldConflictException $e) {
            $this->assertSame($reason, $e->getReason());
        }
    }

    public function testAHoldConflictWithNoCodeIsStillReadByItsWording(): void
    {
        $siren = $this->hostSiren([self::hostJson(409, ['error' => 'Another checkout already holds credit under that token.'])]);

        try {
            $siren->credits->placeHold(4, 'STORE_CREDIT', 1000, 'tok', 'woocommerce');
            $this->fail('Expected a HoldConflictException.');
        } catch (HoldConflictException $e) {
            $this->assertSame(HoldConflictException::REASON_TOKEN_IN_USE, $e->getReason());
        }
    }

    public function testTheHoldLimitCodeIsAHoldLimitException(): void
    {
        $siren = $this->hostSiren([self::coded(429, 'Too many carts.', ErrorCode::HOLD_LIMIT)]);

        try {
            $siren->credits->placeHold(4, 'STORE_CREDIT', 1000, 'tok', 'woocommerce');
            $this->fail('Expected a HoldLimitException.');
        } catch (HoldLimitException $e) {
            $this->assertSame(ErrorCode::HOLD_LIMIT, $e->getErrorCode());
        }
    }

    public function testAKnownCodeOtherThanTheHoldLimitIsNotTakenForIt(): void
    {
        $siren = $this->hostSiren([self::coded(429, 'Slow down.', ErrorCode::NOT_PERMITTED)]);

        try {
            $siren->credits->placeHold(4, 'STORE_CREDIT', 1000, 'tok', 'woocommerce');
            $this->fail('Expected a RateLimitException.');
        } catch (RateLimitException $e) {
            $this->assertSame(RateLimitException::class, $e::class);
        }
    }

    /**
     * Cloud's coded error body: `{"error": {"message", "code", "context"}}`.
     *
     * @param array<string, mixed> $context
     */
    private static function coded(int $status, string $message, string $code, array $context = []): \Siren\Sdk\Http\HttpResponse
    {
        return self::hostJson($status, ['error' => ['message' => $message, 'code' => $code, 'context' => $context]]);
    }
}
