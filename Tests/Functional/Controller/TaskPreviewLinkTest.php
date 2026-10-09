<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * "Preview link" in the ticket: the shareable links and QR code the
 * Workspaces module offers, for a task's draft.
 *
 * The guarantees that matter are the ones a wrong link would break silently:
 * the link shows the TASK's workspace although the member sits in another
 * one (core compiles the keyword from the current workspace), and nobody
 * outside that workspace gets one - a link that needs no login hands the
 * draft to whoever holds it, so it must not be easier to get than the diff
 * the ticket withholds from them.
 *
 * Same coach setup as CrossWorkspaceActionsTest: not an admin, owner of two
 * team workspaces, drafting in one while sitting in the other.
 */
final class TaskPreviewLinkTest extends FunctionalTestCase
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
        // Preview links are frontend URLs: without a site there is nothing to
        // link to at all.
        $this->get(SiteWriter::class)->createNewBasicSite('main', 1, 'http://localhost/');

        $this->getConnectionPool()->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 50,
            'title' => 'Coaches',
            'db_mountpoints' => '1',
            'tables_modify' => 'pages,tt_content',
            'tables_select' => 'pages,tt_content',
            'pagetypes_select' => '1',
            'explicit_allowdeny' => 'tt_content:CType:text',
            'workspace_perms' => 1,
        ]);
        $this->getConnectionPool()->getConnectionForTable('be_users')
            ->update('be_users', ['usergroup' => '50'], ['uid' => self::COACH]);
        $this->getConnectionPool()->getConnectionForTable('pages')
            ->update('pages', ['perms_everybody' => 31], ['deleted' => 0]);

        $workspaces = $this->getConnectionPool()->getConnectionForTable('sys_workspace');
        $workspaces->update('sys_workspace', ['title' => 'Team A', 'adminusers' => 'be_groups_50'], ['uid' => self::TEAM_A]);
        $workspaces->insert('sys_workspace', ['uid' => self::TEAM_B, 'title' => 'Team B', 'adminusers' => 'be_groups_50']);

        $this->setUpBackendUser(self::COACH);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    #[Test]
    public function aMemberSittingInAnotherWorkspaceGetsALinkToTheTasksDraft(): void
    {
        $taskUid = $this->draftInWorkspace(self::TEAM_B);
        $this->sitIn(self::TEAM_A);

        $payload = $this->decode($this->buildTaskAjaxController()->previewLinkAction($this->post(['task' => $taskUid])));

        self::assertTrue($payload['success'], (string)($payload['message'] ?? ''));
        self::assertCount(1, $payload['links'], 'one link per language the page exists in');
        self::assertSame('English', $payload['links'][0]['language']);
        $preview = $this->previewRowOf($payload['links'][0]['url']);
        self::assertSame(self::TEAM_B, json_decode($preview['config'], true)['fullWorkspace'], 'the task\'s draft, not the one the coach sits in');
        self::assertSame((int)$preview['endtime'], $payload['expires']);
        self::assertGreaterThan(time(), $payload['expires']);
        self::assertSame(self::TEAM_A, (int)$GLOBALS['BE_USER']->workspace, 'never switched');
    }

    /**
     * The QR code on a covered record: one link, to the page the record is
     * shown on.
     */
    #[Test]
    public function aRecordTheTaskCoversGetsALinkOfItsOwn(): void
    {
        $taskUid = $this->draftInWorkspace(self::TEAM_B);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['tt_content' => [10 => ['header' => 'Intro text (draft)']]], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);
        $this->sitIn(0);

        $payload = $this->decode($this->buildTaskAjaxController()->previewLinkAction($this->post([
            'task' => $taskUid,
            'table' => 'tt_content',
            'uid' => 10,
        ])));

        self::assertTrue($payload['success'], (string)($payload['message'] ?? ''));
        self::assertCount(1, $payload['links']);
        self::assertSame('', $payload['links'][0]['language']);
        $preview = $this->previewRowOf($payload['links'][0]['url']);
        self::assertSame(self::TEAM_B, json_decode($preview['config'], true)['fullWorkspace']);
    }

    /**
     * A site configured without a host ("/camino/") gets its links from core
     * without one too - and a QR code of "/camino/about-us?ADMCMD_prev=..."
     * leads a phone nowhere. They are completed with the host the backend was
     * reached on.
     */
    #[Test]
    public function aSiteWithoutAHostGetsLinksOnTheHostTheBackendWasReachedOn(): void
    {
        $this->get(SiteWriter::class)->delete('main');
        $this->get(SiteWriter::class)->createNewBasicSite('main', 1, '/camino/');
        $taskUid = $this->draftInWorkspace(self::TEAM_B);
        $request = $this->post(['task' => $taskUid])->withAttribute(
            'normalizedParams',
            NormalizedParams::createFromServerParams(['HTTP_HOST' => 'editorial.example', 'HTTPS' => 'on']),
        );

        $payload = $this->decode($this->buildTaskAjaxController()->previewLinkAction($request));

        self::assertTrue($payload['success'], (string)($payload['message'] ?? ''));
        self::assertStringStartsWith('https://editorial.example/camino/', $payload['links'][0]['url']);
    }

    #[Test]
    public function someoneOutsideTheTasksWorkspaceGetsNoLink(): void
    {
        $taskUid = $this->draftInWorkspace(self::TEAM_B);
        $this->getConnectionPool()->getConnectionForTable('sys_workspace')
            ->update('sys_workspace', ['adminusers' => ''], ['uid' => self::TEAM_B]);
        $this->setUpBackendUser(self::COACH);
        $this->sitIn(self::TEAM_A);
        $previewsBefore = $this->previewRowCount();

        $response = $this->buildTaskAjaxController()->previewLinkAction($this->post(['task' => $taskUid]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('no-workspace-access', $this->decode($response)['code']);
        self::assertSame($previewsBefore, $this->previewRowCount(), 'no keyword was handed out');
    }

    /**
     * The task uid is what membership is checked against - a record the task
     * does not cover must not borrow it.
     */
    #[Test]
    public function aRecordTheTaskDoesNotCoverIsRefused(): void
    {
        $taskUid = $this->draftInWorkspace(self::TEAM_B);

        $response = $this->buildTaskAjaxController()->previewLinkAction($this->post([
            'task' => $taskUid,
            'table' => 'pages',
            'uid' => 1,
        ]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('record-not-in-task', $this->decode($response)['code']);
    }

    #[Test]
    public function theTicketOffersThePreviewLinkToMembersOfTheTasksWorkspace(): void
    {
        $taskUid = $this->draftInWorkspace(self::TEAM_B);
        $this->sitIn(self::TEAM_A);

        $body = (string)$this->buildTaskAjaxController()->ticketAction($this->ticketRequest($taskUid))->getBody();

        // The header's, for the task's subject...
        self::assertStringContainsString('data-editorialflow-preview-link="' . $taskUid . '" data-title="', $body);
        // ...and the QR code on the one covered record with a draft, the page.
        self::assertSame(2, substr_count($body, 'data-editorialflow-preview-link="' . $taskUid . '"'));
    }

    #[Test]
    public function theTicketOffersNoPreviewLinkToSomeoneOutsideTheWorkspace(): void
    {
        $taskUid = $this->draftInWorkspace(self::TEAM_B);
        $this->getConnectionPool()->getConnectionForTable('sys_workspace')
            ->update('sys_workspace', ['adminusers' => ''], ['uid' => self::TEAM_B]);
        $this->setUpBackendUser(self::COACH);
        $this->sitIn(self::TEAM_A);

        $response = $this->buildTaskAjaxController()->ticketAction($this->ticketRequest($taskUid));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringNotContainsString('data-editorialflow-preview-link', (string)$response->getBody());
    }

    private function draftInWorkspace(int $workspaceUid): int
    {
        $this->sitIn($workspaceUid);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['pages' => [2 => ['title' => 'About us (draft)']]], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);

        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_editorialflow_task');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder->select('uid')->from('tx_editorialflow_task')
            ->where($queryBuilder->expr()->eq('closed', 0))
            ->executeQuery()->fetchOne();
    }

    private function sitIn(int $workspaceUid): void
    {
        $GLOBALS['BE_USER']->setWorkspace($workspaceUid);
        self::assertSame($workspaceUid, (int)$GLOBALS['BE_USER']->workspace);
    }

    /**
     * @return array<string, mixed>
     */
    private function previewRowOf(string $url): array
    {
        self::assertStringStartsWith('http://localhost/', $url);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        self::assertNotEmpty($query['ADMCMD_prev'] ?? '', 'a link that works without a login: ' . $url);

        $row = $this->getConnectionPool()->getConnectionForTable('sys_preview')
            ->select(['*'], 'sys_preview', ['keyword' => $query['ADMCMD_prev']])
            ->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }

    private function previewRowCount(): int
    {
        return $this->getConnectionPool()->getConnectionForTable('sys_preview')->count('*', 'sys_preview', []);
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
