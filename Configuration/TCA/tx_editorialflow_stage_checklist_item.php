<?php

declare(strict_types=1);

/**
 * TCA for a stage's acceptance criteria.
 *
 * This is the one table of this extension that has TCA, and the reason is a
 * single one: the criteria a stage asks for are workspace policy, so they belong
 * on the `sys_workspace_stage` record an integrator already edits - and an
 * inline relation needs the child table to be known to FormEngine. Everything
 * else this extension owns stays TCA-free on purpose (see ext_tables.sql).
 *
 * Mirrors core's own sys_workspace_stage ctrl: `adminOnly`, `rootLevel`, and
 * `hideTable`, because a criterion is never edited on its own - it is edited
 * inside the stage it belongs to, or through the board's own manage dialog
 * (GbWeb\EditorialFlow\Controller\TaskAjaxController::checklistAddAction(),
 * which is open to a workspace owner rather than to an admin).
 *
 * `delete` is declared, which switches DeletedRestriction from the silent no-op
 * it is for every other table here to a real constraint. The explicit
 * `deleted = 0` conditions in TaskChecklistRepository stay regardless: they say
 * what the query means without the reader having to know which of this
 * extension's tables has TCA today.
 */
return [
    'ctrl' => [
        'title' => 'LLL:EXT:editorial_flow/Resources/Private/Language/locallang.xlf:criteria.table',
        'label' => 'title',
        'sortby' => 'sorting',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'adminOnly' => true,
        'rootLevel' => 1,
        'hideTable' => true,
        'typeicon_classes' => [
            'default' => 'mimetypes-x-sys_workspace',
        ],
    ],
    'columns' => [
        'title' => [
            'label' => 'LLL:EXT:editorial_flow/Resources/Private/Language/locallang.xlf:criteria.item.title',
            'description' => 'LLL:EXT:editorial_flow/Resources/Private/Language/locallang.xlf:criteria.item.title.description',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'max' => 255,
                'required' => true,
                'eval' => 'trim',
            ],
        ],
        // Written by FormEngine as the inline parent pointer, and by
        // TaskChecklistRepository::addItem() for the board's own manage dialog.
        'stage_uid' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        // Only load-bearing for the three fixed core stages (0, -10, -20), which
        // have no sys_workspace_stage record to hang from and are therefore
        // reachable through the board dialog alone - see
        // TaskChecklistRepository::findItemsForStage().
        'workspace_uid' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'title',
        ],
    ],
];
