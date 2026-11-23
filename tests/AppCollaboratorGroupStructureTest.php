<?php

declare(strict_types=1);

namespace Siren\Sdk\Tests;

use ReflectionClass;
use Siren\Sdk\AppFeatureKind;
use Siren\Sdk\AppItemKind;
use Siren\Sdk\AppRequest;

/**
 * Slice C16-7: the collaborator group structure kind, and a chain and a tree
 * answer, which the request handler and the SDK's own checks send unchanged.
 * The question is the slice's example from the distributor side, in the
 * envelope of Siren's own distribution resolver request
 * (tests/Fixtures/apps-feature-contracts/requests.json), signed now with the
 * secret of tests/Fixtures/apps-exchange.
 *
 * @phpstan-import-type AppCollaboratorGroupStructureProgramQuestion from AppFeatureKind
 */
final class AppCollaboratorGroupStructureTest extends TestCase
{
    private const REQUEST_ID = 'apr_b79943ff8ca353ce5cf5312bb3fa66c7';

    private const CHAIN = ['outcome' => 'structure', 'shape' => 'chain', 'order' => [63, 57, 42]];

    private const TREE = ['outcome' => 'structure', 'shape' => 'tree', 'parents' => ['42' => 57, '57' => 63, '63' => null]];

    /** @return array<string, mixed> */
    private static function question(): array
    {
        return [
            'group' => ['id' => 4, 'name' => 'Northeast team'],
            'members' => [
                ['collaboratorId' => 42, 'name' => 'Riley Rep', 'metadata' => ['rank' => 3]],
                ['collaboratorId' => 57, 'name' => 'Sam Lead', 'metadata' => ['rank' => 2]],
                ['collaboratorId' => 63, 'name' => 'Jo Manager', 'metadata' => ['rank' => 1]],
            ],
            'for' => ['type' => 'distributor', 'id' => 2],
            'triggeringCollaborator' => ['id' => 42, 'name' => 'Riley Rep'],
            'cause' => ['id' => '9f2c4e1a7b3d5c60', 'type' => null, 'causeId' => null],
            'distributor' => ['id' => 2, 'name' => 'Partner leaderboard'],
            'distribution' => ['id' => 21, 'periodEnd' => '2026-12-01T00:00:00+00:00'],
            'metricType' => 'referredSiteVisit',
            'distributorMetricTypeId' => 55,
            'opportunityId' => 615,
            'points' => null,
            'sale' => null,
        ];
    }

    /** @return AppCollaboratorGroupStructureProgramQuestion */
    private static function programQuestion(): array
    {
        return [
            'group' => ['id' => 4, 'name' => 'Northeast team'],
            'members' => self::question()['members'],
            'for' => ['type' => 'program', 'id' => 3],
            'triggeringCollaborator' => ['id' => 42, 'name' => 'Riley Rep'],
            'cause' => ['id' => '9f2c4e1a7b3d5c60', 'event' => 'referredSiteVisit', 'occurredAt' => '2026-11-17T12:20:00+00:00'],
            'program' => ['id' => 3, 'name' => 'Partner program'],
            'engagementType' => 'referredSiteVisit',
            'programEngagementTypeId' => 55,
            'args' => ['pointsAtLayer1' => '10'],
            'opportunity' => ['id' => 615, 'customerId' => null],
        ];
    }

    private static function secret(): string
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/apps-exchange/signed-request.json'), true, 512, JSON_THROW_ON_ERROR);

        return $fixture['secret'];
    }

    /** Siren's request envelope with this kind's item and the slice's question, as JSON. */
    /** @param array<string, mixed>|null $question */
    private static function body(string $key, ?array $question = null): string
    {
        $contracts = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/apps-feature-contracts/requests.json'), true, 512, JSON_THROW_ON_ERROR);
        $request = $contracts['kinds']['distributionResolver']['request'];
        $request['item'] = ['kind' => 'collaboratorGroupStructure', 'key' => $key, 'id' => 'app:11'];
        $request['data'] = $question ?? self::question();

        return json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, string> Headers as WebhookSignature::headers() sets them. */
    private static function signed(string $body): array
    {
        $t = time();

        return [
            'Content-Type' => 'application/json',
            'X-Siren-Event' => 'app.request',
            'X-Siren-Timestamp' => (string) $t,
            'X-Siren-Signature-V2' => 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, self::secret()),
        ];
    }

    public function testTheKindIsNamedInBothListsAsAnAsk(): void
    {
        $this->assertSame('collaboratorGroupStructure', AppItemKind::COLLABORATOR_GROUP_STRUCTURE);
        $this->assertSame(AppItemKind::COLLABORATOR_GROUP_STRUCTURE, AppFeatureKind::COLLABORATOR_GROUP_STRUCTURE);
        $this->assertContains('collaboratorGroupStructure', (new ReflectionClass(AppItemKind::class))->getConstants());
        $this->assertContains('collaboratorGroupStructure', AppFeatureKind::ASKS);
    }

    public function testTheFeatureKindTypesAProgramQuestionBesideTheDistributorOne(): void
    {
        $source = (string) file_get_contents((string) (new ReflectionClass(AppFeatureKind::class))->getFileName());

        $this->assertStringContainsString('@phpstan-type AppCollaboratorGroupStructureDistributorQuestion', $source);
        $this->assertStringContainsString('@phpstan-type AppCollaboratorGroupStructureProgramQuestion', $source);
        $this->assertSame(
            ['group', 'members', 'for', 'triggeringCollaborator', 'cause', 'program', 'engagementType', 'programEngagementTypeId', 'args', 'opportunity'],
            array_keys(self::programQuestion()),
        );
    }

    public function testTheHandlerPostsAChainAndATreeAsTheFunctionsReturnThem(): void
    {
        $errors = [];
        $siren = $this->mockSiren([
            self::jsonResponse(200, ['status' => 'accepted']),
            self::jsonResponse(200, ['status' => 'accepted']),
        ]);
        $handler = $siren->apps->requestHandler(self::secret(), ['onError' => function (\Throwable $error) use (&$errors): void {
            $errors[] = $error;
        }]);
        $handler->on('collaboratorGroupStructure', 'by-rank', static function (AppRequest $request): array {
            $members = $request->data['members'];
            usort($members, static fn(array $a, array $b): int => $a['metadata']['rank'] <=> $b['metadata']['rank']);

            return ['outcome' => 'structure', 'shape' => 'chain', 'order' => array_column($members, 'collaboratorId')];
        });
        $handler->on('collaboratorGroupStructure', 'by-lead', static function (AppRequest $request): array {
            $members = $request->data['members'];
            $parents = [];
            foreach ($members as $index => $member) {
                $parents[(string) $member['collaboratorId']] = $members[$index + 1]['collaboratorId'] ?? null;
            }

            return ['outcome' => 'structure', 'shape' => 'tree', 'parents' => $parents];
        });

        $sent = [];
        foreach (['by-rank' => null, 'by-lead' => self::programQuestion()] as $key => $question) {
            $body = self::body($key, $question);
            $reply = $handler->answer('POST', $body, self::signed($body));
            $this->assertSame(200, $reply->status, $reply->body);
            $this->assertNotNull($reply->after);
            ($reply->after)();
            $this->assertSame('/siren/v1/apps/requests/' . json_decode($body, true)['id'] . '/answer', $this->lastRequest()->getUri()->getPath());
            $sent[] = $this->lastRequestJson();
        }

        $this->assertSame([], $errors);
        $this->assertSame([self::CHAIN, self::TREE], $sent);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function structureAnswers(): array
    {
        return [
            'a chain, top first' => [self::CHAIN],
            'a tree, by parent' => [self::TREE],
        ];
    }

    /**
     * @dataProvider structureAnswers
     *
     * @param array<string, mixed> $answer
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('structureAnswers')]
    public function testAnAnswerOfEachShapeIsSentAsItIs(array $answer): void
    {
        $siren = $this->mockSiren([self::jsonResponse(200, ['status' => 'accepted', 'duplicate' => false])]);

        $receipt = $siren->apps->requests->answer(self::REQUEST_ID, $answer);

        $this->assertSame('accepted', $receipt->status);
        $this->assertSame('/siren/v1/apps/requests/' . self::REQUEST_ID . '/answer', $this->lastRequest()->getUri()->getPath());
        $this->assertSame($answer, $this->lastRequestJson());
    }
}
