<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests\Resources;

use Siren\Sdk\Exception\NotFoundException;
use Siren\Sdk\Resources\Credits;
use Siren\Sdk\Tests\TestCase;

/**
 * Releasing a store credit hold early, when a checkout is abandoned or no
 * longer uses the credit it reserved.
 */
final class HoldReleaseTest extends TestCase
{
    public function testReleaseHoldDeletesTheHoldByIdAndReturnsIt(): void
    {
        $hold = ['id' => 3, 'collaboratorId' => 4, 'currency' => 'STORE_CREDIT', 'amount' => 800, 'status' => Credits::HOLD_STATUS_RELEASED, 'reference' => 'hold:woocommerce@property:5:tok'];
        $siren = $this->hostSiren([self::hostJson(200, $hold)]);

        $this->assertSame($hold, $siren->credits->releaseHold(3));
        $this->assertSame(['DELETE', '/siren/v1/credit-holds/3'], [$this->sent()->getMethod(), $this->sentPath()]);
        $this->assertNull($this->sent()->getBody());
        $this->assertSame([], $this->sentQuery());
    }

    /**
     * A hold that already ended, released, expired or settled, comes back as
     * it is, with no error.
     */
    public function testReleasingAHoldThatAlreadyEndedIsNotAnError(): void
    {
        $hold = ['id' => 3, 'status' => Credits::HOLD_STATUS_EXPIRED];
        $siren = $this->hostSiren([self::hostJson(200, $hold)]);

        $this->assertSame(Credits::HOLD_STATUS_EXPIRED, $siren->credits->releaseHold(3)['status']);
    }

    public function testAHoldThatIsNotThisSitesIsNotFound(): void
    {
        $siren = $this->hostSiren([self::hostJson(404, ['error' => ['message' => 'No credit hold has that id.', 'context' => []]])]);

        $this->expectException(NotFoundException::class);

        $siren->credits->releaseHold(3);
    }

    /**
     * Releasing twice changes nothing, so a server error is retried.
     */
    public function testAReleaseIsRetriedAfterAServerError(): void
    {
        $siren = $this->hostSiren([
            self::hostJson(503, ['error' => 'Unavailable.']),
            self::hostJson(200, ['id' => 3, 'status' => Credits::HOLD_STATUS_RELEASED]),
        ]);

        $siren->credits->releaseHold(3);

        $this->assertCount(2, $this->http->requests);
    }

    public function testReleaseHoldRefusesAnIdBelowOneBeforeSending(): void
    {
        $siren = $this->hostSiren([]);

        try {
            $siren->credits->releaseHold(0);
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame([], $this->http->requests);
    }
}
