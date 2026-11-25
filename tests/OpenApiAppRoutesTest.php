<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

/**
 * openapi.yaml describes the two app routes this SDK calls: the answer route,
 * `POST /apps/requests/{requestKey}/answer` (AppRequests::answer() and the
 * request handler), and the token route, `POST /oauth/token` with the
 * `client_credentials` grant (Auth\AppTokens). Each is read from Siren's own
 * code on feat/apps (6d2abd936a): saas/Apps/Rest/AnswerAppRequest.php with
 * the codes of lib/Apps/Core/Models/AppAnswerResult.php, and
 * saas/Rest/OAuth/IssueToken.php with saas/Auth/AppTokenGrant.php.
 */
final class OpenApiAppRoutesTest extends TestCase
{
    /** The text of one path's block in openapi.yaml, up to the next path. */
    private static function path(string $path): string
    {
        $spec = (string) file_get_contents(__DIR__ . '/../openapi.yaml');
        $start = strpos($spec, "\n  " . $path . ":\n");
        self::assertNotFalse($start, $path . ' is in openapi.yaml.');
        $end = preg_match('/\n  \/[^\n]*:\n/', $spec, $match, PREG_OFFSET_CAPTURE, $start + 1) === 1 ? $match[0][1] : strpos($spec, "\ncomponents:", $start);

        return substr($spec, $start, ($end ?: strlen($spec)) - $start);
    }

    public function testTheAnswerRouteIsDescribedWithEveryRefusal(): void
    {
        $route = self::path('/apps/requests/{requestKey}/answer');

        $this->assertStringContainsString('operationId: answerAppRequest', $route);
        $this->assertStringContainsString("pattern: '^apr_[0-9a-f]{32}$'", $route);
        foreach (["'200'", "'401'", "'403'", "'404'", "'409'", "'422'"] as $status) {
            $this->assertStringContainsString($status . ':', $route);
        }
        foreach (['duplicate', 'app_request_already_answered', 'app_request_dropped', 'app_request_not_needing_attention', 'app_answer_invalid', 'app_inactive', 'app_answer_forbidden', 'app_request_not_found', '16384'] as $words) {
            $this->assertStringContainsString($words, $route);
        }
    }

    public function testTheTokenRouteIsDescribedWithTheAppGrant(): void
    {
        $route = self::path('/oauth/token');

        $this->assertStringContainsString('operationId: requestAppToken', $route);
        foreach (['client_credentials', 'client_secret', 'browser:{eventKey}', 'expires_in', '1200', '600', 'invalid_client', 'invalid_scope', 'unauthorized_client', "'400'", "'401'", "'429'", "'503'"] as $words) {
            $this->assertStringContainsString($words, $route);
        }
    }

    public function testTheEventRouteTakesAnAppsToken(): void
    {
        $route = self::path('/event/{eventSlug}');

        $this->assertStringContainsString('access_token', $route);
        $this->assertStringContainsString('appToken: []', $route);
    }
}
