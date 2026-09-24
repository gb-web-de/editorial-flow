<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Controller;

use GbWeb\EditorialFlow\Backend\ToolbarItems\ReviewInboxToolbarItem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\HtmlResponse;

/**
 * Re-renders the Approvals dropdown after something changed - a publish from
 * the dropdown itself, or the periodic refresh while the backend stays open.
 * Same markup as at page load: it is the toolbar item that renders it.
 */
final readonly class ReviewInboxController
{
    public function __construct(
        private ReviewInboxToolbarItem $toolbarItem,
    ) {
    }

    public function renderAction(ServerRequestInterface $request): ResponseInterface
    {
        return new HtmlResponse($this->toolbarItem->renderDropDown($request));
    }
}
