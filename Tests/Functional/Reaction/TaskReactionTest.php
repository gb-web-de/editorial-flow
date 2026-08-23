<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Reaction;

use GbWeb\EditorialFlow\Domain\Repository\TaskRepository;
use GbWeb\EditorialFlow\Reaction\TaskReaction;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Reactions\Model\ReactionInstruction;
use TYPO3\CMS\Reactions\ReactionRegistry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The publicly reachable end of the Jira/Trello connection.
 *
 * Core's ReactionResolver authenticates the request by the reaction's secret and
 * runs it as the backend user on the sys_reaction record - so from here on
 * everything in the payload is hostile input, and most of what is asserted below
 * is a refusal. The two rules worth stating outright:
 *
 * - A task is addressed only by the external reference it was created with. An
 *   external system that could name a task uid could reach every task in the
 *   installation, including the ones it has nothing to do with.
 * - "Never existed" and "already closed" get the same answer on purpose. Which
 *   one it is says something about this installation that the caller has no
 *   business learning.
 */
final class TaskReactionTest extends FunctionalTestCase
{
    /**
     * @var string[]
     */
    protected array $coreExtensionsToLoad = [
        'typo3/cms-workspaces',
        'typo3/cms-dashboard',
        'typo3/cms-reactions',
    ];

    /**
     * @var string[]
     */
    protected array $testExtensionsToLoad = [
        'gb-web/editorial-flow',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
    }

    #[Test]
    public function itIsOfferedInTheReactionsModule(): void
    {
        // Registered from Configuration/Services.php, guarded by
        // interface_exists() - so this asserts the conditional registration
        // really fires, not just that the class compiles.
        self::assertInstanceOf(
            TaskReaction::class,
            $this->get(ReactionRegistry::class)->getReactionByType('editorial-flow-task'),
        );
    }

    #[Test]
    public function itCreatesATaskForAJiraIssue(): void
    {
        $response = $this->react([
            'action' => 'create',
            'externalSystem' => 'jira',
            'externalRef' => 'EDIT-42',
            'externalUrl' => 'https://example.atlassian.net/browse/EDIT-42',
            'subject' => ['table' => 'pages', 'uid' => 2],
            'title' => 'Rewrite the About us page',
            'description' => 'Tone of voice pass.',
        ]);

        self::assertSame(201, $response->getStatusCode());
        $payload = $this->decode($response);
        self::assertTrue($payload['success']);
        self::assertTrue($payload['created']);

        $task = $this->get(TaskRepository::class)->findByUid((int)$payload['task']);
        self::assertSame('Rewrite the About us page', $task['title']);
        self::assertSame('jira', $task['external_system']);
        self::assertSame('EDIT-42', $task['external_ref']);
        self::assertSame('https://example.atlassian.net/browse/EDIT-42', $task['external_url']);
    }

    #[Test]
    public function aRedeliveredCreateDoesNotOpenASecondTask(): void
    {
        $first = $this->decode($this->react($this->createPayload()));
        $second = $this->react($this->createPayload());

        // A webhook that gets no 2xx sends the same thing again. Doing that must
        // not put a second card on the board.
        self::assertSame(200, $second->getStatusCode());
        self::assertFalse($this->decode($second)['created']);
        self::assertSame($first['task'], $this->decode($second)['task']);
    }

    #[Test]
    public function aCommentArrivesMarkedWithWhereItCameFrom(): void
    {
        $taskUid = (int)$this->decode($this->react($this->createPayload()))['task'];

        $response = $this->react([
            'action' => 'comment',
            'externalSystem' => 'jira',
            'externalRef' => 'EDIT-42',
            'content' => 'Legal signed off.',
        ]);

        self::assertSame(200, $response->getStatusCode());
        // Prefixed, because an unmarked comment under the reaction's user reads
        // as something a colleague wrote - which is exactly what it is not.
        self::assertSame('[jira EDIT-42] Legal signed off.', $this->lastCommentFor($taskUid));
    }

    #[Test]
    public function closingFromOutsideClosesTheTask(): void
    {
        $taskUid = (int)$this->decode($this->react($this->createPayload()))['task'];

        $response = $this->react([
            'action' => 'close',
            'externalSystem' => 'jira',
            'externalRef' => 'EDIT-42',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->get(TaskRepository::class)->findOpenBySubject('pages', 2));
    }

    #[Test]
    public function aClosedTaskIsNoLongerAddressable(): void
    {
        $this->react($this->createPayload());
        $this->react(['action' => 'close', 'externalSystem' => 'jira', 'externalRef' => 'EDIT-42']);

        $response = $this->react([
            'action' => 'comment',
            'externalSystem' => 'jira',
            'externalRef' => 'EDIT-42',
            'content' => 'Anything.',
        ]);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('task-not-found', $this->decode($response)['code']);
    }

    #[Test]
    public function anUnknownReferenceGetsTheSameAnswerAsAClosedOne(): void
    {
        $response = $this->react([
            'action' => 'comment',
            'externalSystem' => 'jira',
            'externalRef' => 'NEVER-EXISTED',
            'content' => 'Anything.',
        ]);

        self::assertSame('task-not-found', $this->decode($response)['code']);
    }

    #[Test]
    public function aTableThatIsNotTrackableIsRefused(): void
    {
        $response = $this->react([
            'action' => 'create',
            'externalSystem' => 'jira',
            'externalRef' => 'EDIT-9',
            // Never taken as given: the same rule every ajax endpoint here
            // applies, because the caller is outside the installation.
            'subject' => ['table' => 'be_users', 'uid' => 1],
            'title' => 'Should not happen',
        ]);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('table-not-trackable', $this->decode($response)['code']);
    }

    #[Test]
    public function aRecordThatDoesNotExistIsRefused(): void
    {
        $response = $this->react([
            'action' => 'create',
            'externalSystem' => 'jira',
            'externalRef' => 'EDIT-9',
            'subject' => ['table' => 'pages', 'uid' => 99999],
            'title' => 'Should not happen',
        ]);

        self::assertSame('record-not-found', $this->decode($response)['code']);
    }

    #[Test]
    public function anUnknownActionIsRefusedWithoutTouchingAnything(): void
    {
        $response = $this->react(['action' => 'delete-everything']);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('unknown-action', $this->decode($response)['code']);
    }

    #[Test]
    public function aCreateWithoutAnExternalReferenceIsRefused(): void
    {
        // Without one there is nothing to recognise the task by later, so the
        // next delivery would create a duplicate - and a task nobody can address
        // is not an integration.
        $response = $this->react([
            'action' => 'create',
            'subject' => ['table' => 'pages', 'uid' => 2],
            'title' => 'No reference',
        ]);

        self::assertSame('missing-external-reference', $this->decode($response)['code']);
    }

    #[Test]
    public function anExternalUrlThatIsNotHttpIsDropped(): void
    {
        $payload = $this->createPayload();
        $payload['externalUrl'] = 'javascript:alert(1)';

        $taskUid = (int)$this->decode($this->react($payload))['task'];

        // The url is rendered as a link in the ticket, so a scheme nobody asked
        // for does not get to travel that far.
        self::assertSame('', $this->get(TaskRepository::class)->findByUid($taskUid)['external_url']);
    }

    /**
     * @return array<string, mixed>
     */
    private function createPayload(): array
    {
        return [
            'action' => 'create',
            'externalSystem' => 'jira',
            'externalRef' => 'EDIT-42',
            'externalUrl' => 'https://example.atlassian.net/browse/EDIT-42',
            'subject' => ['table' => 'pages', 'uid' => 2],
            'title' => 'Rewrite the About us page',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function react(array $payload): ResponseInterface
    {
        return $this->get(TaskReaction::class)->react(
            new ServerRequest('https://example.com/typo3/reaction/abc', 'POST'),
            $payload,
            new ReactionInstruction([
                'uid' => 1,
                'identifier' => 'abc',
                'reaction_type' => 'editorial-flow-task',
            ]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string)$response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function lastCommentFor(int $taskUid): string
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_editorialflow_comment');
        $queryBuilder->getRestrictions()->removeAll();

        return (string)$queryBuilder
            ->select('content')
            ->from('tx_editorialflow_comment')
            ->where($queryBuilder->expr()->eq('task', $queryBuilder->createNamedParameter($taskUid)))
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
    }
}
