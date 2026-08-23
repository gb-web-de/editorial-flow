<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

/**
 * Acceptance criteria, edited where the stage itself is edited.
 *
 * A stage's criteria are a property of the stage ("did we check the links before
 * this leaves Review?"), not of any one task - so they are configured on the
 * `sys_workspace_stage` record, which an integrator reaches through the
 * workspace's own `custom_stages` inline field. Same store as the board's manage
 * dialog writes into: one table, two ways in, no second source of truth.
 *
 * Placed right after `responsible_persons`, because those two answer the same
 * question about a stage from either side - who decides, and what they decide on.
 */
$GLOBALS['TCA']['sys_workspace_stage']['columns']['tx_editorialflow_criteria'] = [
    'label' => 'LLL:EXT:editorial_flow/Resources/Private/Language/locallang.xlf:criteria.field',
    'description' => 'LLL:EXT:editorial_flow/Resources/Private/Language/locallang.xlf:criteria.field.description',
    'config' => [
        'type' => 'inline',
        'foreign_table' => 'tx_editorialflow_stage_checklist_item',
        'foreign_field' => 'stage_uid',
        'foreign_sortby' => 'sorting',
        'appearance' => [
            'useSortable' => true,
            'expandSingle' => true,
            'newRecordLinkTitle' => 'LLL:EXT:editorial_flow/Resources/Private/Language/locallang.xlf:criteria.new',
        ],
    ],
];

ExtensionManagementUtility::addToAllTCAtypes(
    'sys_workspace_stage',
    'tx_editorialflow_criteria',
    '',
    'after:responsible_persons',
);
