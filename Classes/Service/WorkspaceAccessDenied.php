<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Service;

/**
 * The user asked to act on a task whose workspace they are not a member of.
 *
 * An exception rather than a false return, because the work inside a scope
 * returns whatever it likes - including false - and a refusal must not be
 * mistaken for an answer.
 */
final class WorkspaceAccessDenied extends \RuntimeException
{
    public function __construct(public readonly int $workspaceUid)
    {
        parent::__construct(sprintf('No access to workspace #%d.', $workspaceUid), 1758700001);
    }
}
