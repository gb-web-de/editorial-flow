<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Controller;

use GbWeb\EditorialFlow\Controller\TaskAjaxController;
use GbWeb\EditorialFlow\Domain\Repository\TaskRepository;
use GbWeb\EditorialFlow\Service\ReviewInbox;
use GbWeb\EditorialFlow\Service\TaskMemberSynchronizer;
use GbWeb\EditorialFlow\Service\TaskPublishGate;
use GbWeb\EditorialFlow\Service\TaskWorkspaceScope;
use GbWeb\EditorialFlow\Service\WorkspaceAccessDenied;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Authorization\WorkspacePublishGate;
use TYPO3\CMS\Workspaces\Service\StagesService;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * A coach who looks after two teams - owner of two team workspaces - acts on
 * both teams' work from one board, without ever switching workspace.
 *
 * Before, every card from a workspace other than the selected one was
 * read-only, and the endpoints refused with "switch to workspace X first":
 * core ties setStage, discard and the stage permission check to the user's
 * CURRENT workspace. TaskWorkspaceScope runs each of those in the task's own
 * workspace instead - for members only.
 *
 * Deliberately not an admin: admins bypass workspaceCheckStageForCurrent()
 * and most of the rest, and would prove nothing about the rules a coach runs
 * into. The setup mirrors EXT:handball's: a manager group owns the team
 * workspace, the workspace requires the publish stage before going live.
 */
final class CrossWorkspaceActionsTest extends FunctionalTestCase
{
    use BuildsTaskAjaxController;

    private const COACH = 2;
    private const TEAM_A = 1;
    private const TEAM_B = 2;

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

        $this->getConnectionPool()->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 50,
            'title' => 'Coaches',
            'db_mountpoints' => '1',
            'tables_modify' => 'pages,tt_content',
            'tables_select' => 'pages,tt_content',
            'pagetypes_select' => '1',
            'explicit_allowdeny' => 'tt_content:CType:text',
            // Live access - a coach reads the site like anyone else, and
            // "publish from Live" is one of the things proved below.
            'workspace_perms' => 1,
        ]);
        $this->getConnectionPool()->getConnectionForTable('be_users')
            ->update('be_users', ['usergroup' => '50'], ['uid' => self::COACH]);
        $this->getConnectionPool()->getConnectionForTable('pages')
            ->update('pages', ['perms_everybody' => 31], ['deleted' => 0]);

        $workspaces = $this->getConnectionPool()->getConnectionForTable('sys_workspace');
        $workspaces->update('sys_workspace', [
            'title' => 'Team A',
            'adminusers' => 'be_groups_50',
            'publish_access' => WorkspaceService::PUBLISH_ACCESS_ONLY_IN_PUBLISH_STAGE,
        ], ['uid' => self::TEAM_A]);
        $workspaces->insert('sys_workspace', [
            'uid' => self::TEAM_B,
            'title' => 'Team B',
            'adminusers' => 'be_groups_50',
            'publish_access' => WorkspaceService::PUBLISH_ACCESS_ONLY_IN_PUBLISH_STAGE,
        ]);

        $this->setUpBackendUser(self::COACH);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    #[Test]
    public function aCoachMovesTheOtherTeamsPostToReadyToPublishWithoutSwitching(): void
    {
        $taskUid = $this->draftInWorkspace(self::TEAM_B, 'About us (Team B draft)');
        $this->sitIn(self::TEAM_A);

        $payload = $this->decode($this->subject()->executeStageAction($this->post([
            'task' => $taskUid,
            'stageUid' => StagesService::STAGE_PUBLISH_ID,
        ])));

        self::assertTrue($payload['success'], (string)($payload['message'] ?? ''));
        self::assertSame(StagesService::STAGE_PUBLISH_ID, $this->stageOfVersion(self::TEAM_B));
        self::assertSame(self::TEAM_A, (int)$GLOBALS['BE_USER']->workspace, 'the coach was never switched');
    }

    #[Test]
    public function aCoachSittingOnLivePublishesATeamPostInOneClick(): void
    {
        $taskUid = $this->draftInWorkspace(self::TEAM_B, 'About us (published by the coach)');
        $this->sitIn(0);

        $payload = $this->decode($this->subject()->publishTaskAction($this->post(['task' => $taskUid])));

        self::assertTrue($payload['success'], (string)($payload['message'] ?? ''));
        self::assertTrue($payload['closed'], 'nothing is left pending, so the task is done');
        self::assertSame('About us (published by the coach)', $this->liveTitleOfPage(2));
        self::assertSame(0, (int)$GLOBALS['BE_USER']->workspace);
    }

    /**
     * The one-click path for a post that did not exist before - the case the
     * C1 coach actually ran into: a new blog page written in the team
     * workspace, published by the coach from Live.
     */
    #[Test]
    public function aNewPostWrittenInATeamWorkspaceIsPublishedFromLive(): void
    {
        $this->sitIn(self::TEAM_B);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([
            // sys_language_uid set explicitly, as EXT:handball's BlogPostCreator
            // does: a non-admin creating in a workspace is refused otherwise.
            'pages' => ['NEWpage' => ['pid' => 1, 'title' => 'C1 wins at home', 'sys_language_uid' => 0]],
            'tt_content' => ['NEWce' => ['pid' => 'NEWpage', 'header' => 'Report', 'CType' => 'text', 'sys_language_uid' => 0]],
        ], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);
        $pageUid = (int)$dataHandler->substNEWwithIDs['NEWpage'];
        $taskUid = $this->openTaskUid();
        $this->sitIn(0);

        $payload = $this->decode($this->subject()->publishTaskAction($this->post(['task' => $taskUid])));

        self::assertTrue($payload['success'], (string)($payload['message'] ?? ''));
        $page = $this->pageRow($pageUid);
        self::assertSame(0, (int)$page['t3ver_wsid'], 'the page is live now');
        self::assertSame('C1 wins at home', $page['title']);
    }

    #[Test]
    public function someoneWhoIsNoMemberOfTheTasksWorkspaceIsRefused(): void
    {
        $taskUid = $this->draftInWorkspace(self::TEAM_B, 'About us (Team B draft)');
        $this->getConnectionPool()->getConnectionForTable('sys_workspace')
            ->update('sys_workspace', ['adminusers' => ''], ['uid' => self::TEAM_B]);
        $this->setUpBackendUser(self::COACH);
        $this->sitIn(self::TEAM_A);

        $response = $this->subject()->executeStageAction($this->post([
            'task' => $taskUid,
            'stageUid' => StagesService::STAGE_PUBLISH_ID,
        ]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('no-workspace-access', $this->decode($response)['code']);
        self::assertSame(StagesService::STAGE_EDIT_ID, $this->stageOfVersion(self::TEAM_B), 'nothing moved');
    }

    /**
     * Core builds the send-to-stage dialog for the CURRENT workspace only. A
     * custom stage that exists in Team B alone is unknown to it while the
     * coach sits in Team A - the board has to get the dialog from us.
     */
    #[Test]
    public function theStageDialogIsBuiltForTheTasksOwnWorkspace(): void
    {
        $this->getConnectionPool()->getConnectionForTable('sys_workspace_stage')->insert('sys_workspace_stage', [
            'uid' => 100,
            'pid' => 0,
            'parentid' => self::TEAM_B,
            'title' => 'Match check',
            'default_mailcomment' => 'Please check the score.',
        ]);
        $this->getConnectionPool()->getConnectionForTable('sys_workspace')
            ->update('sys_workspace', ['custom_stages' => 1], ['uid' => self::TEAM_B]);
        $taskUid = $this->draftInWorkspace(self::TEAM_B, 'About us (Team B draft)');
        $this->sitIn(self::TEAM_A);

        $payload = $this->decode($this->subject()->checkStageTransitionEligibilityAction($this->post([
            'task' => $taskUid,
            'stageUid' => 100,
        ])));

        self::assertTrue($payload['hasPending']);
        self::assertSame('Please check the score.', $payload['dialog']['comments']['value'] ?? null);
    }

    /**
     * Editing is the one thing that really does happen in the current
     * workspace - so "Work on this task" switches, instead of refusing.
     */
    #[Test]
    public function workingOnATaskOfAnotherWorkspaceSwitchesIntoIt(): void
    {
        $taskUid = $this->draftInWorkspace(self::TEAM_B, 'About us (Team B draft)');
        $this->sitIn(self::TEAM_A);

        $payload = $this->decode($this->subject()->setActiveTaskForContextAction($this->post([
            'table' => 'pages',
            'uid' => 2,
            'taskUid' => $taskUid,
        ])));

        self::assertTrue($payload['success'], (string)($payload['message'] ?? ''));
        self::assertTrue($payload['workspaceSwitched']);
        self::assertSame(self::TEAM_B, (int)$GLOBALS['BE_USER']->workspace);
    }

    /**
     * Found on the handball site, not in a fixture: core narrows a user's page
     * mounts to the CURRENT workspace's mounts at login. A coach logged in
     * while sitting in Team A (mounted on page 2) has no mount for Team B's
     * page 3 - so acting on Team B's post from there was refused as "no edit
     * permission", although it is their own team. The scope computes the
     * mounts for the task's workspace too.
     */
    #[Test]
    public function aCoachActsOnATeamPageOutsideTheCurrentWorkspacesMounts(): void
    {
        $taskUid = $this->taskOnTeamBsPageLoggedInToTeamA();

        $payload = $this->decode($this->subject()->executeStageAction($this->post([
            'task' => $taskUid,
            'stageUid' => StagesService::STAGE_PUBLISH_ID,
        ])));

        self::assertTrue($payload['success'], (string)($payload['message'] ?? ''));
        self::assertSame([2], array_map('intval', $GLOBALS['BE_USER']->getWebmounts()), 'the mounts are restored');
    }

    /**
     * Core hands a version's history only to a reader sitting in the
     * version's workspace (RecordHistory::findEventsForRecord()). Read from
     * Team A, the ticket of Team B's post said "No field changes recorded" -
     * to the coach who is about to approve it.
     */
    #[Test]
    public function aCoachReadsTheOtherTeamsChangesInTheTicketWithoutSwitching(): void
    {
        $taskUid = $this->draftInWorkspace(self::TEAM_B, 'About us (Team B draft)');
        $this->sitIn(self::TEAM_A);

        $response = $this->subject()->ticketAction($this->ticketRequest($taskUid));
        $body = (string)$response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('editorialflow-diff-body', $body);
        self::assertStringNotContainsString('No field changes recorded', $body);
        self::assertSame(self::TEAM_A, (int)$GLOBALS['BE_USER']->workspace, 'never switched');
    }

    /**
     * The same mounts trap as aCoachActsOnATeamPageOutsideTheCurrentWorkspacesMounts(),
     * for reading: sitting in Team A, Team B's page is outside the coach's
     * mounts - but the ticket is theirs to read as a member of Team B.
     */
    #[Test]
    public function aCoachOpensTheTicketOfATeamPageOutsideTheCurrentWorkspacesMounts(): void
    {
        $taskUid = $this->taskOnTeamBsPageLoggedInToTeamA();

        $response = $this->subject()->ticketAction($this->ticketRequest($taskUid));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame([2], array_map('intval', $GLOBALS['BE_USER']->getWebmounts()), 'the mounts are restored');
    }

    /**
     * The ticket offers what the endpoints do: Preview and Discard run in the
     * task's workspace (previewMemberAction(), discardMemberAction()), and so
     * does commenting. It still told the coach "Switch to that workspace to
     * act on this", gated on the workspace selected in the header.
     */
    #[Test]
    public function theTicketOffersPreviewDiscardAndCommentToACoachSittingInTheOtherTeam(): void
    {
        $taskUid = $this->draftInWorkspace(self::TEAM_B, 'About us (Team B draft)');
        $this->sitIn(self::TEAM_A);

        $body = (string)$this->subject()->ticketAction($this->ticketRequest($taskUid))->getBody();

        self::assertStringContainsString('editorialflow-member-preview', $body);
        self::assertStringContainsString('editorialflow-member-discard', $body);
        self::assertStringContainsString('data-editorialflow-comment-form', $body);
        self::assertStringNotContainsString('members of this task\'s workspace', $body);
    }

    #[Test]
    public function theTicketOffersNoneOfItToSomeoneWhoIsNoMemberOfTheTasksWorkspace(): void
    {
        $taskUid = $this->draftInWorkspace(self::TEAM_B, 'About us (Team B draft)');
        $this->getConnectionPool()->getConnectionForTable('sys_workspace')
            ->update('sys_workspace', ['adminusers' => ''], ['uid' => self::TEAM_B]);
        $this->setUpBackendUser(self::COACH);
        $this->sitIn(self::TEAM_A);

        $response = $this->subject()->ticketAction($this->ticketRequest($taskUid));
        $body = (string)$response->getBody();

        self::assertSame(200, $response->getStatusCode(), 'the page is in their tree, so the ticket is theirs to read');
        self::assertStringNotContainsString('editorialflow-member-preview', $body);
        self::assertStringNotContainsString('editorialflow-member-discard', $body);
        self::assertStringNotContainsString('data-editorialflow-comment-form', $body);
        self::assertStringContainsString('Only members of this task\'s workspace can act on this', $body);
        self::assertStringContainsString('Only members of this task\'s workspace can comment on it', $body);
    }

    /**
     * Commenting needs edit permission on the subject, which depends on the
     * page mounts - asked with Team A's, the coach's own Team B page was out
     * of reach, and the comment form the ticket offers was refused.
     */
    #[Test]
    public function aCoachCommentsOnATeamPageOutsideTheCurrentWorkspacesMounts(): void
    {
        $taskUid = $this->taskOnTeamBsPageLoggedInToTeamA();

        $payload = $this->decode($this->subject()->commentAction($this->post([
            'task' => $taskUid,
            'content' => 'Score checked, good to go.',
        ])));

        self::assertTrue($payload['success'], (string)($payload['message'] ?? ''));
        self::assertSame(1, $this->commentCount($taskUid));
        self::assertSame([2], array_map('intval', $GLOBALS['BE_USER']->getWebmounts()), 'the mounts are restored');
    }

    #[Test]
    public function someoneWhoIsNoMemberOfTheTasksWorkspaceCannotCommentOnIt(): void
    {
        $taskUid = $this->draftInWorkspace(self::TEAM_B, 'About us (Team B draft)');
        $this->getConnectionPool()->getConnectionForTable('sys_workspace')
            ->update('sys_workspace', ['adminusers' => ''], ['uid' => self::TEAM_B]);
        $this->setUpBackendUser(self::COACH);
        $this->sitIn(self::TEAM_A);

        $response = $this->subject()->commentAction($this->post(['task' => $taskUid, 'content' => 'Looks fine to me.']));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('no-workspace-access', $this->decode($response)['code']);
        self::assertSame(0, $this->commentCount($taskUid));
    }

    #[Test]
    public function theScopeRestoresTheWorkspaceEvenWhenTheWorkThrows(): void
    {
        $this->sitIn(self::TEAM_A);
        $context = $this->get(Context::class);
        $aspectBefore = $context->getAspect('workspace');
        $scope = new TaskWorkspaceScope($context);

        $seen = $scope->run($GLOBALS['BE_USER'], self::TEAM_B, fn (): array => [
            (int)$GLOBALS['BE_USER']->workspace,
            (int)$this->get(Context::class)->getPropertyFromAspect('workspace', 'id'),
        ]);
        self::assertSame([self::TEAM_B, self::TEAM_B], $seen);

        try {
            $scope->run($GLOBALS['BE_USER'], self::TEAM_B, static function (): never {
                throw new \LogicException('boom', 1758700100);
            });
        } catch (\LogicException) {
        }

        self::assertSame(self::TEAM_A, (int)$GLOBALS['BE_USER']->workspace);
        self::assertSame($aspectBefore, $context->getAspect('workspace'));
    }

    #[Test]
    public function theScopeNeverEntersAWorkspaceTheUserIsNoMemberOf(): void
    {
        $this->getConnectionPool()->getConnectionForTable('sys_workspace')
            ->update('sys_workspace', ['adminusers' => ''], ['uid' => self::TEAM_B]);
        $this->setUpBackendUser(self::COACH);
        $scope = new TaskWorkspaceScope($this->get(Context::class));

        $this->expectException(WorkspaceAccessDenied::class);
        $scope->run($GLOBALS['BE_USER'], self::TEAM_B, static fn (): bool => true);
    }

    /**
     * The top-bar Approvals list: the coach sits in Team A and sees Team B's
     * post waiting for them, with a Publish button - the same gate as the
     * board's.
     */
    #[Test]
    public function theApprovalsListShowsWorkFromEveryWorkspaceOfTheCoach(): void
    {
        $taskUid = $this->draftInWorkspace(self::TEAM_B, 'About us (Team B draft)');
        $this->sitIn(self::TEAM_A);

        $entries = $this->reviewInbox()->forUser($GLOBALS['BE_USER'], [self::TEAM_A, self::TEAM_B]);

        self::assertCount(1, $entries);
        self::assertSame($taskUid, $entries[0]['uid']);
        self::assertSame('Team B', $entries[0]['workspaceTitle']);
        self::assertTrue($entries[0]['canPublish']);
    }

    #[Test]
    public function theApprovalsListIsEmptyOnceThePostIsLive(): void
    {
        $taskUid = $this->draftInWorkspace(self::TEAM_B, 'About us (live now)');
        $this->sitIn(0);
        $this->subject()->publishTaskAction($this->post(['task' => $taskUid]));

        self::assertSame([], $this->reviewInbox()->forUser($GLOBALS['BE_USER'], [self::TEAM_A, self::TEAM_B]));
    }

    /**
     * A plain member (a player writing the post) has nothing to approve:
     * they may not publish, and Editing is not a review stage.
     */
    #[Test]
    public function aPlainMemberHasNothingToApprove(): void
    {
        $this->draftInWorkspace(self::TEAM_B, 'About us (Team B draft)');
        $this->getConnectionPool()->getConnectionForTable('sys_workspace')
            ->update('sys_workspace', ['adminusers' => '', 'members' => 'be_groups_50'], ['uid' => self::TEAM_B]);
        $this->setUpBackendUser(self::COACH);

        self::assertSame([], $this->reviewInbox()->forUser($GLOBALS['BE_USER'], [self::TEAM_B]));
    }

    #[Test]
    public function someoneOutsideTheWorkspaceSeesNothingOfIt(): void
    {
        $this->draftInWorkspace(self::TEAM_B, 'About us (Team B draft)');
        $this->getConnectionPool()->getConnectionForTable('sys_workspace')
            ->update('sys_workspace', ['adminusers' => ''], ['uid' => self::TEAM_B]);
        $this->setUpBackendUser(self::COACH);

        self::assertSame([], $this->reviewInbox()->forUser($GLOBALS['BE_USER'], [self::TEAM_B]));
    }

    private function draftInWorkspace(int $workspaceUid, string $title): int
    {
        $this->sitIn($workspaceUid);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['pages' => [2 => ['title' => $title]]], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);

        return $this->openTaskUid();
    }

    /**
     * A coach mounted on both team pages (2 for Team A, 3 for Team B) drafts
     * on Team B's page from inside Team B, then logs in again sitting in
     * Team A - so core computes their mounts for Team A only, without page 3.
     *
     * @return int the task of the Team B draft
     */
    private function taskOnTeamBsPageLoggedInToTeamA(): int
    {
        $this->getConnectionPool()->getConnectionForTable('pages')
            ->insert('pages', ['uid' => 3, 'pid' => 1, 'title' => 'Team B', 'doktype' => 1, 'perms_everybody' => 31]);
        $this->getConnectionPool()->getConnectionForTable('be_groups')
            ->update('be_groups', ['db_mountpoints' => '2,3'], ['uid' => 50]);
        $workspaces = $this->getConnectionPool()->getConnectionForTable('sys_workspace');
        $workspaces->update('sys_workspace', ['db_mountpoints' => '2'], ['uid' => self::TEAM_A]);
        $workspaces->update('sys_workspace', ['db_mountpoints' => '3'], ['uid' => self::TEAM_B]);

        $this->setUpBackendUser(self::COACH);
        $this->sitIn(self::TEAM_B);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['pages' => [3 => ['title' => 'Team B (draft)']]], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);
        $taskUid = $this->openTaskUid();

        $this->getConnectionPool()->getConnectionForTable('be_users')
            ->update('be_users', ['workspace_id' => self::TEAM_A], ['uid' => self::COACH]);
        $this->setUpBackendUser(self::COACH);
        self::assertSame(self::TEAM_A, (int)$GLOBALS['BE_USER']->workspace);
        self::assertSame([2], array_map('intval', $GLOBALS['BE_USER']->getWebmounts()));

        return $taskUid;
    }

    private function sitIn(int $workspaceUid): void
    {
        $GLOBALS['BE_USER']->setWorkspace($workspaceUid);
        self::assertSame($workspaceUid, (int)$GLOBALS['BE_USER']->workspace);
    }

    private function openTaskUid(): int
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_editorialflow_task');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder->select('uid')->from('tx_editorialflow_task')
            ->where($queryBuilder->expr()->eq('closed', 0))
            ->executeQuery()->fetchOne();
    }

    private function stageOfVersion(int $workspaceUid): int
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder->select('t3ver_stage')->from('pages')
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', 2),
                $queryBuilder->expr()->eq('t3ver_wsid', $workspaceUid),
            )
            ->executeQuery()->fetchOne();
    }

    private function commentCount(int $taskUid): int
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_editorialflow_comment');

        return (int)$queryBuilder->count('uid')->from('tx_editorialflow_comment')
            ->where($queryBuilder->expr()->eq('task', $queryBuilder->createNamedParameter($taskUid, Connection::PARAM_INT)))
            ->executeQuery()->fetchOne();
    }

    private function liveTitleOfPage(int $uid): string
    {
        return (string)$this->pageRow($uid)['title'];
    }

    /**
     * @return array<string, mixed>
     */
    private function pageRow(int $uid): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder->select('*')->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $uid))
            ->executeQuery()->fetchAssociative() ?: [];
    }

    private function reviewInbox(): ReviewInbox
    {
        $context = $this->get(Context::class);

        return new ReviewInbox(
            $this->get(TaskRepository::class),
            $this->get(TaskMemberSynchronizer::class),
            new TaskPublishGate($this->get(WorkspacePublishGate::class), new TaskWorkspaceScope($context)),
            new TaskWorkspaceScope($context),
            $this->get(StagesService::class),
        );
    }

    private function subject(): TaskAjaxController
    {
        return $this->buildTaskAjaxController();
    }

    private function ticketRequest(int $taskUid): ServerRequestInterface
    {
        // The ticket renders f:translate, which needs the application type the
        // backend middleware would set.
        return (new ServerRequest())
            ->withQueryParams(['task' => $taskUid])
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
        return json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
