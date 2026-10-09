<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\UnableToLinkToPageException;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Versioning\VersionState;
use TYPO3\CMS\Workspaces\Preview\PreviewUriBuilder;

/**
 * Shareable preview links for what a task covers - the ones the Workspaces
 * module hands out behind "Generate page preview links" and behind the QR
 * code on each of its rows.
 *
 * Core's links, not a second kind: each carries an ADMCMD_prev keyword, a
 * sys_preview row that lets whoever holds the link see the draft without a
 * backend login until it expires (the workspace's preview link lifetime, 48
 * hours unless configured). The workspace a link shows is the one the user
 * sits in when core compiles that keyword - so every call here has to run
 * inside the task's workspace (TaskWorkspaceScope), or it shares the wrong
 * draft, or none.
 */
final readonly class PreviewLinkBuilder
{
    public function __construct(
        private PreviewUriBuilder $previewUriBuilder,
        private TcaSchemaFactory $tcaSchemaFactory,
        private ConnectionPool $connectionPool,
    ) {
    }

    /**
     * A page in its default language gets one link per language it exists
     * in, the way the module's page preview links do. Anything else - a
     * content element, a news record, a page translation - gets one link to
     * the page it is shown on, in its own language, the way the module's QR
     * code does.
     *
     * Empty when there is nothing to link to: no site for the page, or a
     * draft that deletes the record (core offers no link for those either).
     *
     * @param string $requestHost scheme and host the backend was reached on,
     *                            for a site whose base names no host
     * @return list<array{language: string, url: string}>
     */
    public function build(BackendUserAuthentication $user, string $table, int $liveUid, string $requestHost = ''): array
    {
        $live = BackendUtility::getRecord($table, $liveUid);
        if ($live === null) {
            return [];
        }
        // A record born in this workspace is found as its own version.
        $version = BackendUtility::getWorkspaceVersionOfRecord((int)$user->workspace, $table, $liveUid) ?: $live;
        if (VersionState::tryFrom((int)($version['t3ver_state'] ?? 0)) === VersionState::DELETE_PLACEHOLDER) {
            return [];
        }

        $languageId = $this->languageOf($table, $version);
        if ($table === 'pages' && $languageId === 0) {
            try {
                $urls = $this->previewUriBuilder->buildUrisForAllLanguagesOfPage($liveUid);
            } catch (UnableToLinkToPageException) {
                return [];
            }
            $links = [];
            foreach ($urls as $language => $url) {
                $links[] = ['language' => (string)$language, 'url' => $this->absolute((string)$url, $requestHost)];
            }
            return $links;
        }

        // "Uid of the version(!) record" - with both rows handed over, core
        // takes the page and the parameters from the draft, not from live.
        $url = $this->previewUriBuilder->buildUriForElementWithToken($table, (int)$version['uid'], $languageId, $live, $version);

        return $url === '' ? [] : [['language' => '', 'url' => $this->absolute($url, $requestHost)]];
    }

    /**
     * When a link stops working, as a timestamp - 0 when it carries no
     * keyword this installation knows.
     *
     * Read back from the sys_preview row the link points at rather than
     * worked out again from core's rule (workspace setting, then user
     * TSconfig, then 48 hours): a second copy of that rule could only ever
     * tell an editor the wrong time.
     */
    public function expiryOf(string $url): int
    {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $keyword = $query['ADMCMD_prev'] ?? '';
        if (!is_string($keyword) || $keyword === '') {
            return 0;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_preview');

        return (int)$queryBuilder
            ->select('endtime')
            ->from('sys_preview')
            ->where($queryBuilder->expr()->eq('keyword', $queryBuilder->createNamedParameter($keyword)))
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * A site whose base names no host ("/camino/") answers on whichever host
     * it is asked on, and core hands its links out the same way - fine for a
     * click inside this backend, useless in a QR code: a phone has nothing to
     * resolve "/camino/..." against. The host the editor reached the backend
     * on is the one the site answers them on, too.
     */
    private function absolute(string $url, string $requestHost): string
    {
        if ($requestHost === '' || (new Uri($url))->getHost() !== '') {
            return $url;
        }

        return rtrim($requestHost, '/') . '/' . ltrim($url, '/');
    }

    /**
     * @param array<string, mixed> $record
     */
    private function languageOf(string $table, array $record): int
    {
        $schema = $this->tcaSchemaFactory->get($table);
        if (!$schema->isLanguageAware()) {
            return 0;
        }
        $languageField = $schema->getCapability(TcaSchemaCapability::Language)->getLanguageField()->getName();

        // -1 ("all languages") is shown in the default language.
        return max(0, (int)($record[$languageField] ?? 0));
    }
}
