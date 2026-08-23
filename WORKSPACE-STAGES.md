# TYPO3 Workspace Stages — what this session actually learned

Written down because the first read of core's stage/publish permission model was
**wrong**, the wrong version was half-built into this extension, and it cost real
debugging time to find out why a task couldn't move past its first review stage.
The corrected model, the concrete bugs it caused here, and how to set up demo
users that actually exercise it - so nobody re-derives this the hard way.

## The permission check everyone gets backwards the first time

`TYPO3\CMS\Workspaces\Hook\DataHandlerHook::version_setStage()` is the real gate
behind every stage transition (`setStage` cmdmap action). It reads the record's
**current** `t3ver_stage` and calls:

```php
$currentStage = (int)$record['t3ver_stage'];
if (!$dataHandler->BE_USER->workspaceCheckStageForCurrent($currentStage)) {
    // refused
}
```

Not the *target* stage. The intuitive reading - "responsible_persons on a stage
lists who may move things **into** it" - is wrong. The real rule:

> Being responsible for stage N means you may act on whatever **currently sits**
> in stage N, and send it **anywhere** from there - including skipping stages
> ahead. It says nothing about who may move things *into* N.

Concretely, with two custom stages (Review=1, Approval=2) and a member
responsible only for Review:

- They **can** move a task sitting in Review straight to Approval, or even to
  "Ready to publish" - skipping Approval entirely - because they're allowed to
  act on Review, and the target is not checked at all.
- They **cannot** touch a task once it has actually reached Approval, because
  they are not responsible for Approval - regardless of how it got there.

One exception, unconditional for every member regardless of `responsible_persons`
(`BackendUserAuthentication::workspaceCheckStageForCurrent()`):

```php
} elseif (
    $accessType === 'reviewer' && $stage <= 1
    || $accessType === 'member' && $stage <= 0
) {
    return true;
}
```

Any workspace member may always move a task **out of** the default Editing stage
(0), no matter what `responsible_persons` says anywhere. That part genuinely is
not role-specific.

`-10` ("Ready to publish") and `-20` ("Publish") are a separate carve-out in the
same method: nobody but the workspace **owner** (or admin) may act on a record
once it is *at* one of those two stages. This is unrelated to `responsible_persons`
- an owner is required, full stop.

**Where this bit us**: `BoardColumnRegistry` and the board's drag-and-drop
coloring were first built checking `isAllowed` against the **drop target**
column. Fixed to check the **card's own current stage** instead
(`EditorialFlowController::buildBoard()` builds a `stageUid → isAllowed` map from
the columns and stamps `canAct` onto each task; `board.js` gates
`canDropCardIntoColumn()` off `card.dataset.editorialflowCanAct`, not the column).

## `custom_stages` is a maintained counter, not a boolean

`sys_workspace.custom_stages` looks like a flag but is TCA type `inline`
(`foreign_table: sys_workspace_stage`) - core auto-maintains it as the count of
attached stage records whenever a stage is submitted as part of the workspace's
own IRRE relation. `workspaceCheckStageForCurrent()` gates the entire
`responsible_persons` mechanism behind `$workspaceRec['custom_stages'] > 0`:

```php
if ($workspaceRec['custom_stages'] > 0 && $stage !== 0 && $stage !== -10) {
    // only here does responsible_persons get consulted at all
}
```

**Where this bit us**: `editorialflow:democontent` originally created stage records
with a *second*, separate `DataHandler` call setting `parentid` directly - never
going through the workspace's own `custom_stages` field, so core never ran its
counter-maintenance step. The column silently stayed `0` even with two real
stage records attached. Result: **every** non-owner member was permanently stuck
at stage 0, no matter what `responsible_persons` said - not because permissions
were misconfigured, but because the gate that reads them never activated.

Fix: after creating (or finding) the stage records, submit their uids as the
value of the parent's own `custom_stages` field:

```php
$dataHandler->start([
    'sys_workspace' => [
        $workspaceUid => ['custom_stages' => implode(',', $stageUids)],
    ],
], []);
$dataHandler->process_datamap();
```

Done on every `editorialflow:democontent` run now (`syncCustomStagesCounter()`),
not just workspace creation - it has to re-run even when the workspace already
existed and only the demo users are being (re)ensured.

## Publishing: the cmdmap direction that's easy to get backwards too

`DataHandlerHook`'s publish check (`version_swap`-equivalent) validates:

```php
if (!(((int)$swapVersion['t3ver_oid'] > 0 && (int)$curVersion['t3ver_oid'] === 0)
    && (int)$swapVersion['t3ver_oid'] === (int)$id)) {
    // 'In offline record, either t3ver_oid was not set or the t3ver_oid
    //  didn't match the id of the online version as it must'
}
```

where `$id` is the **array key** of the cmdmap entry and `$swapWith` is looked up
separately. Decoded: `$curVersion` (found via the key) must be the **live**
record (`t3ver_oid === 0`), and `$swapVersion` (found via `swapWith`) must be the
**offline version**, whose own `t3ver_oid` must point back at `$id`. So:

```php
$cmd[$table][$liveUid]['version'] = [
    'action' => 'publish',
    'swapWith' => $versionUid,
];
```

**Key = live uid, `swapWith` = version uid.** This is the *opposite* order from
`setStage`/`discard`, which are both keyed by the **version** uid
(`$cmd[$table][$versionUid]['version'] = ['action' => 'setStage', ...]` /
`$cmd[$table][$versionUid]['discard'] = true`). It is also the opposite of what
core's own `WorkspacesAjaxController::publishSingleRecord(string $table, int
$t3ver_oid, int $orig_uid)` parameter *names* suggest at a glance - the first
param is misleadingly called `$t3ver_oid` despite being used as the live-side key.

**Where this bit us**: `TaskAjaxController::publishTaskAction()`'s first version
had this backwards (version uid as key, live uid as `swapWith`), and failed with
exactly the error message quoted above on every attempt. Verified by reading
`DataHandlerHook` directly rather than guessing from the error text a second
time - `Classes/Controller/TaskAjaxController.php`'s `publishTaskAction()` now
has a comment pointing at the exact core method for the next person who touches it.

## Publishing was never wired up in the first place

Separate from the above: there was no `publishTaskAction` (or any publish
button) anywhere in the extension before this session, despite
`BoardColumnRegistry`'s own docblock and `ARCHITECTURE.md` both stating "Editors
publish from the card, with a confirmation" as settled design. This is why a
task could reach "Ready to publish" and then simply go nowhere - not a
permissions bug, a missing feature. Implemented in `publishTaskAction()`, gated
by `WorkspacePublishGate::isGranted()` (the same gate core's own
`WorkspacesAjaxController` uses - owner/admin only, exactly like the `-10`/`-20`
carve-out above), and wired to a "Publish" button on any card with a workspace
version.

## `DeletedRestriction` is a silent no-op for tables without TCA

`TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction::buildExpression()`:

```php
if (!$this->tcaSchemaFactory->has($tableName)) {
    continue; // no restriction added for this table at all
}
```

Editorial Flow's own tables (`tx_editorialflow_task`, `tx_editorialflow_task_item`,
`tx_editorialflow_comment`, `tx_editorialflow_activity`) deliberately have **no
TCA** (see `ext_tables.sql`'s header comment). So `->getRestrictions()->add(new
DeletedRestriction())`, used throughout the existing repositories, has silently
never filtered `deleted = 1` rows for any of them. Confirmed directly: inserted a
checklist item, soft-deleted it, and `findItemsForStage()` (using
`DeletedRestriction`) still returned it; switching to an explicit
`deleted = 0` condition in the `WHERE` clause fixed it immediately.

**One table left that list.** `tx_editorialflow_stage_checklist_item` now *does*
have TCA (`Configuration/TCA/`), because a stage's acceptance criteria are edited
as an inline relation on the `sys_workspace_stage` record and FormEngine cannot
render a relation to a table it does not know. For that one table
`DeletedRestriction` is real. The explicit `deleted = 0` condition stayed anyway:
a query should say what it means without the reader first having to work out
which of six tables is the exception this month. Covered by
`Tests/Functional/Configuration/StageCriteriaTcaTest`, whose delete test goes
through a real DataHandler `delete` command rather than an `UPDATE`.

Fixed in `TaskChecklistRepository` (the newest repository). **Not yet fixed** in
`TaskRepository`, `CommentRepository`, or `ActivityLogger` - flagged as a
separate follow-up rather than folded into this change, since it touches
read paths all over the extension. Anyone picking that up: replace
`DeletedRestriction` with an explicit `$queryBuilder->expr()->eq('deleted', ...)`
in every affected query, the same way `TaskChecklistRepository::findItemsForStage()`
already does.

## Demo users: what it actually takes to get a non-admin be_user working at all

Getting `editor`/`reviewer`/`approver` (see `editorialflow:democontent`) to the
point where they could even attempt an edit took four separate, independently
silent failure modes, verified one at a time against a real backend session
(not just read from source):

1. **`db_mountpoints` on the `be_group`.**
   `BackendUserAuthentication::calcPerms()` calls `isInWebMount()` *before* ever
   consulting `perms_groupid`/`perms_group` - with no mount, every page access
   check returns "nothing" regardless of what the page's own permission columns
   say. Mounted at the site's root page(s) (`pid = 0`).
2. **`perms_groupid`/`perms_group` on the pages themselves.** The Camino demo
   fixture ships with `perms_everybody = 0` and no group set on any page -
   without explicitly granting the demo group access, *no* be_group has any
   page access at all. Applied to every existing page; TYPO3 does not cascade
   page permissions to subpages on its own.
3. **`tables_select`/`tables_modify` on the `be_group`.** Standard TCA-level
   record permission, needed for `pages`/`tt_content`/etc - not needed for
   Editorial Flow's own tables, since those have no TCA and are never touched
   through FormEngine/Recordlist permission checks.
4. **`custom_stages`** - see above; without it, permission setup 1-3 gets a
   non-owner user as far as the Editing stage and no further, which looks
   identical to a page-permission problem but isn't one.

None of this is Editorial Flow-specific - it's the ordinary TYPO3 backend-user
permission model, encountered because the extension previously only had one
real, non-admin-equivalent user to test with (`_cli_`/`admin`, both
`admin = 1`, which bypasses essentially all of it).

## Verifying any of this without a browser session

`Bootstrap::initializeBackendAuthentication()` alone gives a CLI user with **no
real session** - fine for calls that only read, but anything that eventually
calls `BackendUserAuthentication::setAndSaveSessionData()` (e.g. the post-save
wizard's pending-payload storage) throws `Call to a member function set() on
null`. To simulate a *specific*, non-admin backend user from a CLI script for
real permission testing, the minimal working recipe (mirrors
`TYPO3\TestingFramework\Core\Functional\FunctionalTestCase::setUpBackendUser()`,
without depending on PHPUnit):

```php
$row = BackendUtility::getRecord('be_users', $uid);
$user = GeneralUtility::makeInstance(BackendUserAuthentication::class);
$session = $user->createUserSession($row);

$serverParams = ['HTTP_HOST' => '...', 'REQUEST_URI' => '/typo3/', 'REMOTE_ADDR' => '127.0.0.1', 'SCRIPT_NAME' => '/index.php'];
$request = (new ServerRequest('https://.../typo3/', 'GET', 'php://input', [], $serverParams))
    ->withAttribute('applicationType', ApplicationType::BACKEND)
    ->withAttribute('normalizedParams', NormalizedParams::createFromRequest(
        new ServerRequest('https://.../typo3/', 'GET', 'php://input', [], $serverParams),
    ))
    ->withCookieParams(['be_typo_user' => $session->getJwt()]);

$user->start($request);
$user->backendCheckLogin();
$user->setWorkspace($workspaceUid);
GeneralUtility::makeInstance(Context::class)
    ->setAspect('backend.user', GeneralUtility::makeInstance(UserAspect::class, $user));
$GLOBALS['BE_USER'] = $user;
```

Every piece here is load-bearing - dropped `normalizedParams`, for instance,
crashes with `Call to a member function getRemoteAddress() on null` deep in
`AbstractUserAuthentication::start()`. This is how the whole
editor→reviewer→approver stage-skip permission spread got verified end to end
in this session (each transition attempted through the real
`TaskAjaxController::executeStageAction()`/`publishTaskAction()`, not
re-implemented permission math), including the two correctly-refused cases
(reviewer acting on a task still in Review, editor acting on one already in
Approval) and the two correctly-allowed ones either side of them.
