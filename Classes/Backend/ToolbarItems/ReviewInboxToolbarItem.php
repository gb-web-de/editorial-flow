<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Backend\ToolbarItems;

use GbWeb\EditorialFlow\Service\ReviewInbox;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Toolbar\RequestAwareToolbarItemInterface;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;

/**
 * "Approvals" in the backend's top bar: everything waiting for the current
 * user, from every workspace they belong to, with Preview and Publish right
 * in the dropdown.
 *
 * The top bar because it is the one place that is there in every module - a
 * coach reading a post in the page module, the list module or their own
 * team module sees the count and can approve from where they are. No workspace
 * switch: the actions run in each task's own workspace (TaskWorkspaceScope),
 * behind the same gates as the board's buttons.
 *
 * Only shown to users who belong to at least one workspace; for everyone else
 * there is never anything to approve.
 */
final class ReviewInboxToolbarItem implements ToolbarItemInterface, RequestAwareToolbarItemInterface
{
    private ServerRequestInterface $request;

    public function __construct(
        private readonly ReviewInbox $reviewInbox,
        private readonly WorkspaceService $workspaceService,
        private readonly BackendViewFactory $backendViewFactory,
    ) {
    }

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function checkAccess(): bool
    {
        return $this->workspaceUids() !== [];
    }

    public function getItem(): string
    {
        return $this->backendViewFactory
            ->create($this->request, ['typo3/cms-backend', 'gb-web/editorial-flow'])
            ->render('ToolbarItems/ReviewInboxToolbarItem');
    }

    public function hasDropDown(): bool
    {
        return true;
    }

    public function getDropDown(): string
    {
        return $this->renderDropDown($this->request);
    }

    /**
     * Also the body of the refresh endpoint, so the list rendered at page load
     * and the one fetched after a publish cannot differ.
     */
    public function renderDropDown(ServerRequestInterface $request): string
    {
        $entries = $this->reviewInbox->forUser($this->getBackendUser(), $this->workspaceUids());

        return $this->backendViewFactory
            ->create($request, ['typo3/cms-backend', 'gb-web/editorial-flow'])
            ->assignMultiple([
                'entries' => $entries,
                'count' => count($entries),
            ])
            ->render('ToolbarItems/ReviewInboxDropDown');
    }

    /**
     * @return array<string, string>
     */
    public function getAdditionalAttributes(): array
    {
        return [];
    }

    public function getIndex(): int
    {
        // Left of the core items (bookmarks 20, clear cache 25, system info 30):
        // this one is about the user's work, not about the system.
        return 15;
    }

    /**
     * @return list<int>
     */
    private function workspaceUids(): array
    {
        return array_values(array_filter(
            array_map('intval', array_keys($this->workspaceService->getAvailableWorkspaces())),
            static fn (int $uid): bool => $uid > 0,
        ));
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
