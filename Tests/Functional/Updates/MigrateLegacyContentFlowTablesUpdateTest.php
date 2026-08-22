<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Updates;

use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Table;
use GbWeb\EditorialFlow\Updates\MigrateLegacyContentFlowTablesUpdate;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers the upgrade wizard that copies rows out of the old tx_contentflow_*
 * tables (from the extension's previous life as content_flow) into their
 * tx_editorialflow_* successors.
 *
 * The extension's own ext_tables.sql only declares the new table names, so a
 * production site coming from content_flow is simulated here by creating the
 * old tables by hand - via a schema clone of the real tx_editorialflow_*
 * tables the testing framework already built, so this stays portable between
 * the SQLite CI backend and MySQL/MariaDB in production.
 */
final class MigrateLegacyContentFlowTablesUpdateTest extends FunctionalTestCase
{
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

    private function subject(): MigrateLegacyContentFlowTablesUpdate
    {
        return GeneralUtility::makeInstance(MigrateLegacyContentFlowTablesUpdate::class);
    }

    /**
     * The primary key has to be carried over explicitly.
     *
     * Table::__construct()'s third argument is the index list, and leaving it
     * out drops the primary key along with everything else - which leaves `uid`
     * as an auto-increment column belonging to no key at all. SQLite hides that:
     * its platform emits `PRIMARY KEY AUTOINCREMENT` inline for any
     * auto-increment column, so CI (which runs pdo_sqlite) stayed green while
     * MySQL/MariaDB rejected the same clone with "there can be only one auto
     * column and it must be defined as a key".
     *
     * Only the primary key, not $reference->getIndexes() wholesale: index names
     * are global to the database in SQLite, so copying board_scope/subject/
     * assignee verbatim onto a second table collides there. The primary key is
     * emitted inline on both platforms, and these legacy tables hold one or two
     * rows - they need no secondary index.
     *
     * Dropped first for the same reason: the testing framework only rebuilds
     * the schema it knows about, and these tables are not in it. On SQLite each
     * test gets its own database file so a leftover cannot be observed, but on
     * MySQL/MariaDB the tests of one class share a database and the second one
     * to run would hit "table already exists".
     */
    private function createLegacyTable(string $oldTable, string $newTable): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable($newTable);
        $schemaManager = $connection->createSchemaManager();
        if ($schemaManager->tablesExist([$oldTable])) {
            $schemaManager->dropTable($oldTable);
        }
        $reference = $schemaManager->introspectTable($newTable);
        $primaryKey = array_values(array_filter(
            $reference->getIndexes(),
            static fn (Index $index): bool => $index->isPrimary(),
        ));
        $legacyTable = new Table($oldTable, $reference->getColumns(), $primaryKey);
        $schemaManager->createTable($legacyTable);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function insertLegacyRow(string $oldTable, array $fields): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable($oldTable);
        $connection->insert($oldTable, $fields);

        return (int)$connection->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function selectAll(string $table): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        return $queryBuilder->select('*')->from($table)->executeQuery()->fetchAllAssociative();
    }

    #[Test]
    public function updateIsNotNecessaryWithoutLegacyTables(): void
    {
        self::assertFalse($this->subject()->updateNecessary());
    }

    #[Test]
    public function updateIsNotNecessaryWithAnEmptyLegacyTable(): void
    {
        $this->createLegacyTable('tx_contentflow_task', 'tx_editorialflow_task');

        self::assertFalse($this->subject()->updateNecessary());
    }

    #[Test]
    public function updateIsNecessaryWhenALegacyTableHasRows(): void
    {
        $this->createLegacyTable('tx_contentflow_task', 'tx_editorialflow_task');
        $this->insertLegacyRow('tx_contentflow_task', [
            'pid' => 0,
            'title' => 'Legacy task',
            'subject_table' => 'pages',
            'subject_uid' => 1,
        ]);

        self::assertTrue($this->subject()->updateNecessary());
    }

    #[Test]
    public function copiesRowsPreservingUidsAndLeavesTheOldTableInPlace(): void
    {
        $this->createLegacyTable('tx_contentflow_task', 'tx_editorialflow_task');
        $this->createLegacyTable('tx_contentflow_task_item', 'tx_editorialflow_task_item');

        $taskUid = $this->insertLegacyRow('tx_contentflow_task', [
            'pid' => 0,
            'title' => 'Legacy task',
            'subject_table' => 'pages',
            'subject_uid' => 1,
            'state' => 'in_progress',
        ]);
        $this->insertLegacyRow('tx_contentflow_task_item', [
            'pid' => 0,
            'task' => $taskUid,
            'record_table' => 'pages',
            'record_uid' => 1,
            'origin' => 'subject',
        ]);

        self::assertTrue($this->subject()->executeUpdate());

        $migratedTasks = $this->selectAll('tx_editorialflow_task');
        self::assertCount(1, $migratedTasks);
        self::assertSame($taskUid, (int)$migratedTasks[0]['uid'], 'uid must be preserved so cross-references keep working');
        self::assertSame('Legacy task', $migratedTasks[0]['title']);

        $migratedItems = $this->selectAll('tx_editorialflow_task_item');
        self::assertCount(1, $migratedItems);
        self::assertSame($taskUid, (int)$migratedItems[0]['task']);

        // Old data is left in place, not moved.
        self::assertCount(1, $this->selectAll('tx_contentflow_task'));
        self::assertCount(1, $this->selectAll('tx_contentflow_task_item'));

        self::assertFalse($this->subject()->updateNecessary(), 'a second run should see nothing left to migrate');
    }

    #[Test]
    public function isIdempotentWhenRunTwice(): void
    {
        $this->createLegacyTable('tx_contentflow_task', 'tx_editorialflow_task');
        $this->insertLegacyRow('tx_contentflow_task', [
            'pid' => 0,
            'title' => 'Legacy task',
            'subject_table' => 'pages',
            'subject_uid' => 1,
        ]);

        self::assertTrue($this->subject()->executeUpdate());
        self::assertTrue($this->subject()->executeUpdate());

        self::assertCount(1, $this->selectAll('tx_editorialflow_task'), 'a second run must not duplicate already-migrated rows');
    }
}
