<?php

declare(strict_types=1);

/**
 * A group of its own in the "Add widget" dialog.
 *
 * The four widgets were registered under core's `general` group, which is where
 * "About TYPO3" lives - so an editor looking for editorial numbers had to read
 * past unrelated entries, and the group counter said 5 for a group that is
 * really one core widget plus somebody else's extension. Widgets that belong to
 * one feature belong in one group; that is what the group list is for.
 */
return [
    'editorialFlow' => [
        'title' => 'LLL:EXT:editorial_flow/Resources/Private/Language/locallang.xlf:widget.group.editorialFlow',
    ],
];
