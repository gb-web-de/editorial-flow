<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Controller;

use GbWeb\EditorialFlow\Controller\TaskAjaxController;
use GbWeb\EditorialFlow\Domain\Repository\TaskRepository;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\AbstractLogger;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Who may read a task, and who may do its bookkeeping.
 *
 * Every endpoint here is a backend AJAX route, and backend routes admit any
 * logged-in backend user - with or without the Editorial Flow module, with
 * or without the page the task is about. The route layer checks the session
 * and the request token, never who the task belongs to. So each endpoint has
 * to ask for itself, and before this the ticket, the details, the version
 * comparison, "assign me", ticking a criterion and moving a ticket that has
 * no record yet asked nothing: a task uid was enough.
 *
 * The cast, all of them non-admins - an admin passes every check there is
 * and would prove nothing:
 *
 * - EDITOR owns the Editorial workspace and has page 1 and below mounted.
 * - NEIGHBOUR has the same pages mounted but is no member of Editorial: they
 *   see its tasks on the board, as core would show them the page, but not
 *   the unpublished content behind them.
 * - OUTSIDER has a different part of the tree mounted and belongs to no
 *   workspace: nothing here is theirs to see.
 * - PAGES_ONLY sees and edits pages, but not content elements - the
 *   table-level half of "may read this record".
 */
final class TaskAccessControlTest extends FunctionalTestCase
{
    use BuildsTaskAjaxController;

    private const ADMIN = 1;
    private const EDITOR = 2;
    private const OUTSIDER = 3;
    private const NEIGHBOUR = 4;
    private const PAGES_ONLY = 5;

    private const EDITORIAL = 1;
    private const LEGAL = 2;

    /**
     * @var string[]
     */
    protected array $coreExtensionsToLoad = [
        'typo3/cms-workspaces',
        'typo3/cms-dashboard',
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

        $pool = $this->getConnectionPool();
        // A second site, mounted for the outsider only.
        $pool->getConnectionForTable('pages')
            ->insert('pages', ['uid' => 3, 'pid' => 0, 'title' => 'Other site', 'doktype' => 1]);
        // Mounts decide who sees what, not the page's owner bits.
        $pool->getConnectionForTable('pages')
            ->update('pages', ['perms_everybody' => 31], ['deleted' => 0]);

        $groups = $pool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', $this->group(50, 'Editors', '1', 'pages,tt_content'));
        $groups->insert('be_groups', $this->group(51, 'Outsiders', '3', 'pages,tt_content'));
        $groups->insert('be_groups', $this->group(52, 'Neighbours', '1', 'pages,tt_content'));
        $groups->insert('be_groups', $this->group(53, 'Page editors', '1', 'pages'));

        $users = $pool->getConnectionForTable('be_users');
        $users->update('be_users', ['usergroup' => '50'], ['uid' => self::EDITOR]);
        $users->insert('be_users', ['uid' => self::OUTSIDER, 'pid' => 0, 'username' => 'outsider', 'usergroup' => '51']);
        $users->insert('be_users', ['uid' => self::NEIGHBOUR, 'pid' => 0, 'username' => 'neighbour', 'usergroup' => '52']);
        $users->insert('be_users', ['uid' => self::PAGES_ONLY, 'pid' => 0, 'username' => 'pages-only', 'usergroup' => '53']);

        $workspaces = $pool->getConnectionForTable('sys_workspace');
        $workspaces->update('sys_workspace', ['adminusers' => 'be_groups_50,be_groups_53'], ['uid' => self::EDITORIAL]);
        // Admin only: nobody in the cast is a member.
        $workspaces->insert('sys_workspace', ['uid' => self::LEGAL, 'pid' => 0, 'title' => 'Legal']);
    }

    // -----------------------------------------------------------------
    // Reading: the ticket, its JSON details, the version comparison
    // -----------------------------------------------------------------

    #[Test]
    public function theTicketIsRefusedToSomeoneWhoCannotSeeItsPage(): void
    {
        $taskUid = $this->draftTask(self::EDITORIAL, ['subtitle' => 'Embargoed subtitle']);
        $this->actAs(self::OUTSIDER);

        $response = $this->subject()->ticketAction($this->htmlRequest(['task' => $taskUid]));
        $body = (string)$response->getBody();

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('callout-danger', $body);
        self::assertStringNotContainsString('Embargoed', $body);
        self::assertStringNotContainsString('About us', $body, 'not even the subject is named');
    }

    #[Test]
    public function theTicketStillOpensForAMemberSittingInLive(): void
    {
        $taskUid = $this->draftTask(self::EDITORIAL, ['subtitle' => 'Embargoed subtitle']);
        $this->actAs(self::EDITOR);

        $response = $this->subject()->ticketAction($this->htmlRequest(['task' => $taskUid]));
        $body = (string)$response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('About us', $body);
        self::assertStringContainsString('Embargoed', $body, 'a member sees the draft - that is what the ticket is for');
        self::assertStringNotContainsString('editorialflow-diff-withheld', $body);
    }

    /**
     * The board shows a neighbour this task (as a read-only card): they may
     * see the page, and knowing that somebody is working on it is the point.
     * What the draft says is the workspace's business, the same line core
     * draws when it hides a workspace's versions from non-members.
     */
    #[Test]
    public function aNonMemberSeesTheTicketButNotTheUnpublishedChanges(): void
    {
        $taskUid = $this->draftTask(self::EDITORIAL, ['subtitle' => 'Embargoed subtitle']);
        $this->actAs(self::NEIGHBOUR);

        $response = $this->subject()->ticketAction($this->htmlRequest(['task' => $taskUid]));
        $body = (string)$response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('About us', $body);
        self::assertStringNotContainsString('Embargoed', $body);
        self::assertStringContainsString('editorialflow-diff-withheld', $body, 'and says why the list is empty');
        self::assertStringNotContainsString('30 days', $body, 'not blamed on expired history');
    }

    /**
     * A closed task's archive names the fields that changed, never their
     * values (WorkspaceIntegrationService::getArchivedMemberDiffs()) - there
     * is no draft in it to withhold.
     */
    #[Test]
    public function aClosedTasksArchiveIsNotWithheldFromANonMember(): void
    {
        $taskUid = $this->draftTask(self::EDITORIAL, ['subtitle' => 'Embargoed subtitle']);
        $this->get(TaskRepository::class)->close($taskUid, self::ADMIN);
        $this->actAs(self::NEIGHBOUR);

        $payload = $this->decode($this->subject()->detailsAction($this->htmlRequest(['task' => $taskUid])));

        self::assertTrue($payload['success']);
        self::assertFalse($payload['details']['diffsWithheld']);
        self::assertContains('Subtitle', array_column($payload['details']['diffs'], 'label'));
        self::assertStringNotContainsString('Embargoed', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * The refused ticket is an HTML fragment for the modal, but it is logged
     * like every JSON refusal: the stable code, and who asked.
     */
    #[Test]
    public function aRefusedTicketIsLoggedWithItsCodeAndTheUser(): void
    {
        $taskUid = $this->draftTask(self::EDITORIAL, ['subtitle' => 'Embargoed subtitle']);
        $this->actAs(self::OUTSIDER);
        $logger = new class () extends AbstractLogger {
            /**
             * @var list<array{message: string, context: array<string, mixed>}>
             */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string)$message, 'context' => $context];
            }
        };

        $this->buildTaskAjaxController($logger)->ticketAction($this->htmlRequest(['task' => $taskUid]));

        self::assertCount(1, $logger->records);
        self::assertSame('no-page-show-permission', $logger->records[0]['message']);
        self::assertSame(self::OUTSIDER, $logger->records[0]['context']['beUser']);
        self::assertSame(2, $logger->records[0]['context']['pageUid']);
    }

    #[Test]
    public function theDetailsAreRefusedToSomeoneWhoCannotSeeItsPage(): void
    {
        $taskUid = $this->draftTask(self::EDITORIAL, ['subtitle' => 'Embargoed subtitle']);
        $this->actAs(self::OUTSIDER);

        $response = $this->subject()->detailsAction($this->htmlRequest(['task' => $taskUid]));
        $payload = $this->decode($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertFalse($payload['success']);
        self::assertSame('no-page-show-permission', $payload['code']);
        self::assertArrayNotHasKey('details', $payload);
        self::assertArrayNotHasKey('recipients', $payload);
    }

    #[Test]
    public function theDetailsWithholdTheChangesFromANonMember(): void
    {
        $taskUid = $this->draftTask(self::EDITORIAL, ['subtitle' => 'Embargoed subtitle']);
        $this->actAs(self::NEIGHBOUR);

        $payload = $this->decode($this->subject()->detailsAction($this->htmlRequest(['task' => $taskUid])));

        self::assertTrue($payload['success']);
        self::assertSame([], $payload['details']['diffs']);
        self::assertTrue($payload['details']['diffsWithheld']);
        self::assertStringNotContainsString('Embargoed', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function theDetailsStillAnswerAMember(): void
    {
        $taskUid = $this->draftTask(self::EDITORIAL, ['subtitle' => 'Embargoed subtitle']);
        $this->actAs(self::EDITOR);

        $payload = $this->decode($this->subject()->detailsAction($this->htmlRequest(['task' => $taskUid])));

        self::assertTrue($payload['success']);
        self::assertNotSame([], $payload['details']['diffs']);
        self::assertFalse($payload['details']['diffsWithheld']);
    }

    #[Test]
    public function theVersionComparisonIsRefusedToSomeoneWhoCannotSeeTheRecord(): void
    {
        $this->draftConflict();
        $this->actAs(self::OUTSIDER);

        $response = $this->subject()->conflictDiffAction($this->htmlRequest(['table' => 'pages', 'uid' => 2]));
        $body = (string)$response->getBody();

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('callout-danger', $body);
        self::assertStringNotContainsString('Embargoed', $body);
        self::assertStringNotContainsString('Editorial subtitle', $body);
    }

    #[Test]
    public function theVersionComparisonIsRefusedWithoutReadAccessToTheTable(): void
    {
        $this->asAdminIn(self::EDITORIAL);
        $this->edit('tt_content', 10, ['header' => 'Intro (Editorial)']);
        $this->asAdminIn(self::LEGAL);
        $this->edit('tt_content', 10, ['header' => 'Intro (Embargoed)']);
        $this->actAs(self::PAGES_ONLY);

        $response = $this->subject()->conflictDiffAction($this->htmlRequest(['table' => 'tt_content', 'uid' => 10]));

        self::assertSame(403, $response->getStatusCode());
        self::assertStringNotContainsString('Embargoed', (string)$response->getBody());
    }

    /**
     * The comparison exists for exactly this person: an editor whose record
     * is also being changed in a workspace they are not in. They learn which
     * fields the other side touched, and whether both sides disagree - not
     * what the other side wrote.
     */
    #[Test]
    public function theVersionComparisonShowsOnlyTheWorkspacesTheViewerBelongsTo(): void
    {
        $this->draftConflict();
        $this->actAs(self::EDITOR);

        $response = $this->subject()->conflictDiffAction($this->htmlRequest(['table' => 'pages', 'uid' => 2]));
        $body = (string)$response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Editorial subtitle', $body, 'their own workspace\'s side is shown');
        self::assertStringNotContainsString('Embargoed', $body, 'the other side\'s content is not');
        self::assertStringContainsString('editorialflow-conflict-diff-withheld', $body);
        self::assertStringContainsString('Legal', $body, 'the other workspace is still named, as the conflict badge names it');
        self::assertStringContainsString('editorialflow-conflict-diff-row--conflict', $body, 'and the disagreement is still flagged');
    }

    #[Test]
    public function anAdminStillComparesEveryWorkspace(): void
    {
        $this->draftConflict();
        $this->actAs(self::ADMIN);

        $body = (string)$this->subject()->conflictDiffAction($this->htmlRequest(['table' => 'pages', 'uid' => 2]))->getBody();

        self::assertStringContainsString('Editorial subtitle', $body);
        self::assertStringContainsString('Embargoed', $body);
        self::assertStringNotContainsString('editorialflow-conflict-diff-withheld', $body);
    }

    // -----------------------------------------------------------------
    // "Assign me"
    // -----------------------------------------------------------------

    #[Test]
    public function someoneOutsideTheTasksWorkspaceCannotTakeIt(): void
    {
        $taskUid = $this->draftTask(self::EDITORIAL, ['subtitle' => 'Draft']);
        // An auto-created task starts out with whoever made the first edit.
        $assigneeBefore = (int)$this->taskRow($taskUid)['assignee'];

        foreach ([self::OUTSIDER, self::NEIGHBOUR] as $user) {
            $this->actAs($user);
            $response = $this->subject()->assignMeAction($this->post(['task' => $taskUid]));

            self::assertSame(400, $response->getStatusCode());
            self::assertSame('no-workspace-access', $this->decode($response)['code']);
        }
        self::assertSame($assigneeBefore, (int)$this->taskRow($taskUid)['assignee']);
    }

    #[Test]
    public function aMemberTakesATaskOfTheirWorkspaceFromLive(): void
    {
        $taskUid = $this->draftTask(self::EDITORIAL, ['subtitle' => 'Draft']);
        $this->actAs(self::EDITOR);

        $payload = $this->decode($this->subject()->assignMeAction($this->post(['task' => $taskUid])));

        self::assertTrue($payload['success'], (string)($payload['message'] ?? ''));
        self::assertSame(self::EDITOR, (int)$this->taskRow($taskUid)['assignee']);
        self::assertSame(0, (int)$GLOBALS['BE_USER']->workspace, 'never switched');
    }

    #[Test]
    public function aPlannedTaskIsTakenOnlyBySomeoneWhoMayEditItsRecord(): void
    {
        $taskUid = $this->insertTask(['subject_table' => 'tt_content', 'subject_uid' => 10, 'subject_pid' => 2]);

        $this->actAs(self::OUTSIDER);
        $response = $this->subject()->assignMeAction($this->post(['task' => $taskUid]));
        self::assertSame('no-content-edit-permission', $this->decode($response)['code']);
        self::assertSame(0, (int)$this->taskRow($taskUid)['assignee']);

        $this->actAs(self::EDITOR);
        self::assertTrue($this->decode($this->subject()->assignMeAction($this->post(['task' => $taskUid])))['success']);
        self::assertSame(self::EDITOR, (int)$this->taskRow($taskUid)['assignee']);
    }

    /**
     * A ticket for a page that does not exist yet has no record to ask about.
     * The bar is the one its page will have to clear: creating a page there.
     */
    #[Test]
    public function aTicketForAPlannedPageIsTakenOnlyBySomeoneWhoMayCreateItThere(): void
    {
        $taskUid = $this->insertTask(['subject_table' => 'pages', 'subject_uid' => 0, 'subject_pid' => 1]);

        $this->actAs(self::OUTSIDER);
        $response = $this->subject()->assignMeAction($this->post(['task' => $taskUid]));
        self::assertSame('no-page-new-permission', $this->decode($response)['code']);

        $this->actAs(self::EDITOR);
        self::assertTrue($this->decode($this->subject()->assignMeAction($this->post(['task' => $taskUid])))['success']);
    }

    // -----------------------------------------------------------------
    // Moving a ticket that has no record yet between the planning columns
    // -----------------------------------------------------------------

    #[Test]
    public function aPlannedPageTicketIsNotMovedBySomeoneOutsideItsPart(): void
    {
        $taskUid = $this->insertTask(['subject_table' => 'pages', 'subject_uid' => 0, 'subject_pid' => 1]);
        $this->actAs(self::OUTSIDER);

        $response = $this->subject()->moveStageAction($this->post(['task' => $taskUid, 'state' => 'planned']));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('no-page-new-permission', $this->decode($response)['code']);
        self::assertSame('backlog', $this->taskRow($taskUid)['state']);
    }

    #[Test]
    public function aPlannedPageTicketIsStillMovedByItsEditor(): void
    {
        $taskUid = $this->insertTask(['subject_table' => 'pages', 'subject_uid' => 0, 'subject_pid' => 1]);
        $this->actAs(self::EDITOR);

        $payload = $this->decode($this->subject()->moveStageAction($this->post(['task' => $taskUid, 'state' => 'planned'])));

        self::assertTrue($payload['success'], (string)($payload['message'] ?? ''));
        self::assertSame('planned', $this->taskRow($taskUid)['state']);
    }

    /**
     * A ticket for a record that does not exist yet can be filed by anyone who
     * sees the planning page (TaskWizardProvider::submitCreatePendingRecord()),
     * so seeing it is also the bar for moving it - not more, not less.
     */
    #[Test]
    public function aPlannedRecordTicketFollowsTheBarForFilingIt(): void
    {
        $taskUid = $this->insertTask(['subject_table' => 'tt_content', 'subject_uid' => 0, 'subject_pid' => 2]);

        $this->actAs(self::OUTSIDER);
        $response = $this->subject()->moveStageAction($this->post(['task' => $taskUid, 'state' => 'planned']));
        self::assertSame('no-page-show-permission', $this->decode($response)['code']);
        self::assertSame('backlog', $this->taskRow($taskUid)['state']);

        $this->actAs(self::NEIGHBOUR);
        self::assertTrue($this->decode($this->subject()->moveStageAction($this->post(['task' => $taskUid, 'state' => 'planned'])))['success']);
    }

    // -----------------------------------------------------------------
    // Acceptance criteria
    // -----------------------------------------------------------------

    #[Test]
    public function aNonMemberCannotConfirmACriterion(): void
    {
        $taskUid = $this->draftTask(self::EDITORIAL, ['subtitle' => 'Draft']);
        $itemUid = $this->addCriterion(self::EDITORIAL, 'All links checked');
        $this->actAs(self::NEIGHBOUR);

        $response = $this->subject()->checklistToggleAction($this->post([
            'task' => $taskUid,
            'itemUid' => $itemUid,
            'completed' => true,
        ]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('no-workspace-access', $this->decode($response)['code']);
        self::assertSame([], $this->checklistStateRows($taskUid));
    }

    #[Test]
    public function aCriterionOfAnotherWorkspaceCannotBeConfirmedOnThisTask(): void
    {
        $taskUid = $this->draftTask(self::EDITORIAL, ['subtitle' => 'Draft']);
        $foreignItemUid = $this->addCriterion(self::LEGAL, 'Signed off by legal');
        $this->actAs(self::EDITOR);

        $response = $this->subject()->checklistToggleAction($this->post([
            'task' => $taskUid,
            'itemUid' => $foreignItemUid,
            'completed' => true,
        ]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('foreign-checklist-item', $this->decode($response)['code']);
        self::assertSame([], $this->checklistStateRows($taskUid));
    }

    #[Test]
    public function aMemberConfirmsACriterionOfTheirWorkspace(): void
    {
        $taskUid = $this->draftTask(self::EDITORIAL, ['subtitle' => 'Draft']);
        $itemUid = $this->addCriterion(self::EDITORIAL, 'All links checked');
        $this->actAs(self::EDITOR);

        $payload = $this->decode($this->subject()->checklistToggleAction($this->post([
            'task' => $taskUid,
            'itemUid' => $itemUid,
            'completed' => true,
        ])));

        self::assertTrue($payload['success'], (string)($payload['message'] ?? ''));
        $rows = $this->checklistStateRows($taskUid);
        self::assertCount(1, $rows);
        self::assertSame(1, (int)$rows[0]['completed']);
    }

    /**
     * The workspace a criterion belongs to is read from the criterion, never
     * taken from the request: owning Editorial is no licence to rewrite
     * Legal's policy by saying "Editorial" while naming Legal's item.
     */
    #[Test]
    public function owningOneWorkspaceDoesNotLetYouRemoveAnotherOnesCriterion(): void
    {
        $foreignItemUid = $this->addCriterion(self::LEGAL, 'Signed off by legal');
        $this->actAs(self::EDITOR);

        $response = $this->subject()->checklistRemoveAction($this->post([
            'workspaceUid' => self::EDITORIAL,
            'itemUid' => $foreignItemUid,
        ]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('checklist-not-permitted', $this->decode($response)['code']);
        self::assertSame(0, (int)$this->criterionRow($foreignItemUid)['deleted']);
    }

    #[Test]
    public function anOwnerStillRemovesTheirOwnWorkspacesCriterion(): void
    {
        $itemUid = $this->addCriterion(self::EDITORIAL, 'All links checked');
        $this->actAs(self::EDITOR);

        $payload = $this->decode($this->subject()->checklistRemoveAction($this->post([
            'workspaceUid' => self::EDITORIAL,
            'itemUid' => $itemUid,
        ])));

        self::assertTrue($payload['success'], (string)($payload['message'] ?? ''));
        self::assertSame(1, (int)$this->criterionRow($itemUid)['deleted']);
    }

    #[Test]
    public function removingACriterionThatDoesNotExistSaysSo(): void
    {
        $this->actAs(self::EDITOR);

        $response = $this->subject()->checklistRemoveAction($this->post([
            'workspaceUid' => self::EDITORIAL,
            'itemUid' => 4711,
        ]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('missing-checklist-item', $this->decode($response)['code']);
    }

    // -----------------------------------------------------------------

    /**
     * @return array<string, int|string>
     */
    private function group(int $uid, string $title, string $mounts, string $tables): array
    {
        return [
            'uid' => $uid,
            'pid' => 0,
            'title' => $title,
            'db_mountpoints' => $mounts,
            'tables_modify' => $tables,
            'tables_select' => $tables,
            'pagetypes_select' => '1',
            'explicit_allowdeny' => 'tt_content:CType:text',
            // Live access, so every one of them can sit outside a workspace.
            'workspace_perms' => 1,
        ];
    }

    /**
     * A draft written by the admin inside $workspaceUid - the edit opens the
     * task on its own (TaskAutoCreationDataHandlerHook).
     *
     * @param array<string, string> $fields
     */
    private function draftTask(int $workspaceUid, array $fields): int
    {
        $this->asAdminIn($workspaceUid);
        $this->edit('pages', 2, $fields);

        return $this->openTaskUid($workspaceUid);
    }

    /**
     * The same page changed in both workspaces, to different subtitles.
     */
    private function draftConflict(): void
    {
        $this->asAdminIn(self::EDITORIAL);
        $this->edit('pages', 2, ['subtitle' => 'Editorial subtitle']);
        $this->asAdminIn(self::LEGAL);
        $this->edit('pages', 2, ['subtitle' => 'Embargoed subtitle']);
    }

    private function asAdminIn(int $workspaceUid): void
    {
        $this->actAs(self::ADMIN);
        $GLOBALS['BE_USER']->setWorkspace($workspaceUid);
    }

    /**
     * @param array<string, string> $fields
     */
    private function edit(string $table, int $uid, array $fields): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$table => [$uid => $fields]], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);
    }

    private function actAs(int $userUid): void
    {
        $this->setUpBackendUser($userUid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('en');
    }

    /**
     * @param array<string, int|string> $fields
     */
    private function insertTask(array $fields): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_editorialflow_task');
        $connection->insert('tx_editorialflow_task', $fields + [
            'pid' => 0,
            'title' => 'Planned work',
            'state' => 'backlog',
            'workspace_uid' => 0,
            'closed' => 0,
        ]);

        return (int)$connection->lastInsertId();
    }

    private function addCriterion(int $workspaceUid, string $title): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_editorialflow_stage_checklist_item');
        $connection->insert('tx_editorialflow_stage_checklist_item', [
            'pid' => 0,
            'workspace_uid' => $workspaceUid,
            'stage_uid' => 0,
            'title' => $title,
        ]);

        return (int)$connection->lastInsertId();
    }

    private function openTaskUid(int $workspaceUid): int
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_editorialflow_task');
        $queryBuilder->getRestrictions()->removeAll();

        $taskUid = (int)$queryBuilder->select('uid')->from('tx_editorialflow_task')
            ->where(
                $queryBuilder->expr()->eq('closed', 0),
                $queryBuilder->expr()->eq('workspace_uid', $workspaceUid),
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()->fetchOne();
        self::assertGreaterThan(0, $taskUid, 'the draft opened a task');

        return $taskUid;
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRow(int $taskUid): array
    {
        $row = $this->get(TaskRepository::class)->findByUid($taskUid);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function checklistStateRows(int $taskUid): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_editorialflow_task_checklist_state');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder->select('*')->from('tx_editorialflow_task_checklist_state')
            ->where($queryBuilder->expr()->eq('task', $taskUid))
            ->executeQuery()->fetchAllAssociative();
    }

    /**
     * @return array<string, mixed>
     */
    private function criterionRow(int $itemUid): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_editorialflow_stage_checklist_item');
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder->select('*')->from('tx_editorialflow_stage_checklist_item')
            ->where($queryBuilder->expr()->eq('uid', $itemUid))
            ->executeQuery()->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }

    private function subject(): TaskAjaxController
    {
        return $this->get(TaskAjaxController::class);
    }

    /**
     * @param array<string, int|string> $query
     */
    private function htmlRequest(array $query): ServerRequestInterface
    {
        // The ticket and the comparison render Fluid views with f:translate,
        // which needs the application type the backend middleware would set.
        return (new ServerRequest())
            ->withQueryParams($query)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body): ServerRequestInterface
    {
        return (new ServerRequest())->withParsedBody($body)->withMethod('POST');
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
}
