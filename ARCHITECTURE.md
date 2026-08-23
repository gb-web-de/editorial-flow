# Editorial Flow — Architecture

Editorial Flow is a **standalone** TYPO3 v14 extension. It depends on TYPO3 core only
(`typo3/cms-workspaces`), and deliberately **not** on
`web-vision/kanban-workspaces` or `xima/xima-typo3-content-planner`. Those two were
studied as prior art; neither is installed, and none of their tables are read or written.

## The idea in one paragraph

TYPO3 workspaces already contain a complete approval engine: stages, permissions,
notifications, diffs, publishing. What they lack is a **before** and an **after** — there is
no way to say "this page needs work" until somebody actually edits it, and no trace left once
it goes live. Editorial Flow adds a **task** on either side of the version's lifetime, and gets
out of the way in the middle. Editors do not learn a workflow; the workflow follows them.

```
    ┌── Editorial Flow ──┐   ┌────── TYPO3 core workspace stages ──────┐   ┌ Editorial Flow ┐
    │                  │   │                                        │   │              │
    │ Backlog  Planned │   │ In Progress   Review 1..n   Ready       │   │ Done         │
    │                  │   │                                        │   │              │
    └──────────────────┘   └────────────────────────────────────────┘   └──────────────┘
      no version yet         a workspace version exists (t3ver_stage)      published,
                                                                          version gone
```

The middle section is read from `sys_workspace_stage`. An integrator defines review steps
where TYPO3 already expects them (Workspace record → Stages) and the board picks them up —
Editorial Flow has no stage configuration of its own to keep in sync.

This also answers, natively, what
[kanban-workspaces#31](https://github.com/web-vision/kanban-workspaces/issues/31) asked for
(stages *before* and *after* the fixed core ones): "before editing" is Backlog/Planned,
"after ready" is Done, and everything between is already freely definable in core.

## What a task is about

Every **versionable** record can be tracked — if a table has no workspace support it never
produces a version, so there is nothing for the workflow to move. Among those, Editorial Flow
distinguishes two roles:

- A **subject** is page-like: it stands on its own and gets its own card. `pages`, obviously.
  But not only: a *news* record is technically a record while behaving like a page, because it
  has its own detail view. To an editor it *is* a page. Which tables count is configuration
  (`$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['editorial_flow']['subjectTables']`), because only the
  integrator knows their content model.
- Everything else is **page-bound**: a content element belongs to the page it sits on.

**A task on a page pulls in everything on that page.** One card means "this page and its
content", not one card per content element — otherwise editing a page with twelve elements
would bury the board in twelve identical-looking cards.

**But the editor can pull an element out** and give it a task of its own, when one banner or
one accordion really is a separate piece of work.

That split is enforced with a single rule, no flags: **a record belongs to at most one open
task**, as a unique key on the membership table. Detaching moves the membership row to the new
task; re-syncing the page's task then *cannot* reclaim the element, because the slot is taken.
The same key is what stops two editors opening the same page from creating two tasks.

## Content that lives somewhere else

A shortcut element pulls content in from another page. An editor working on page A
changes it — and page B silently changes too. **A board that hides this is a task list;
a board that shows it is a planning tool.** So every member carries two facts:

- `home_pid` — the page the record actually lives on. When it differs from the task's
  subject, the editor is working on foreign content.
- `shared` — other pages reference this record.

Both are surfaced as a warning on the card, and both are advisory: nothing is blocked.
The editor decides whether the change belongs to **their own** task (they are working on
page A, so track it there) or gets **its own** task (it is really a change to page B's
content) — `moveMemberToTask()` and `detachIntoOwnTask()` are the two operations.

`ReferenceInspector` is built on **`sys_refindex`, not on `CType='shortcut'`**. The
reference index already records every kind of reuse — shortcuts, inline relations, links,
file references — so this catches reuse a shortcut check would miss, and it keeps working
when an extension invents its own relation type. The tradeoff: the index is rebuilt by a
scheduler task and can be stale, so a negative answer means "no reuse known", not proof.
That is acceptable precisely because the result is a warning rather than a gate.

## The wizard

"Not straight to a new task on direct editing — a wizard: new task, or add to task."

The constraint that shapes this: the hook runs **server-side inside DataHandler, during a
save**. It cannot open a dialog. And blocking the first keystroke behind a modal would
contradict the whole premise that editors should barely have to think.

So the wizard is **post-save and non-blocking**:

1. The editor edits. A task is created as before and flagged `auto_created = 1` — the
   work is captured no matter what happens next, which is the important part.
2. If that edit opened a brand-new task, a backend `MultiStepWizard` asks only for the
   human details core cannot infer: a required title, plus optional description and
   assignee.
3. If the edit hit a page-bound record whose page already has an open task, the wizard
   instead asks for the routing decision: *add this record to the existing page task* or
   *give it its own task*.
4. Ignoring it is a valid answer. The sensible default already happened on the server.

This keeps the "unplanned work is never lost" guarantee while still giving the editor the
say. `auto_created` is what lets the board distinguish "somebody planned this" from "this
appeared because someone started typing" even after the follow-up wizard refined the task.

## The five moments

**1. Somebody plans work.** A task is created for a subject and lands in Backlog, taking the
page's content along with it. Assigning a `be_user` moves it to Planned. Editors may assign
themselves. No version exists yet, nothing is locked.

**2. Somebody edits.** The editor opens the page and types. TYPO3 auto-creates a workspace
version. `TaskAutoCreationDataHandlerHook` notices and moves the task to In Progress — **or
creates one on the spot if none existed**, so unplanned work is captured too. The editor is
never asked to "open a ticket"; there simply is one afterwards.

The hook routes the edit rather than blindly creating: an existing membership wins (so a
detached element stays with its own task), otherwise the subject is resolved — the record
itself when page-like, else the page it sits on.

**3. The version walks the stages.** Core does this entirely. Dragging a card is translated
into a normal core stage transition, so permissions, recipients and stage comments behave
exactly as they do in the Workspaces module. Editorial Flow mirrors the resulting
`t3ver_stage` onto the task as a read cache for sorting; core stays the source of truth.

**4. It goes live.** `CloseTaskAfterPublishListener` closes the task on
`AfterRecordPublishedEvent` — but only once **nothing the task covers is still pending**, and
only when the publish came from **the task's own workspace**. The event fires per record, and
a task covers a page and all its content, so closing on the first published element would
archive a task with half its content still in review. Core also allows the same live record to
be versioned in several workspaces at once, so a publish out of one of the others says nothing
about whether this task is finished.

**5. Somebody stops.** Not every task ends by going live. A draft gets discarded, the change
turns out to be unnecessary, the record is published from somewhere else, or the work is
simply abandoned — and none of those produce a publish event. So closing is also an explicit
action an editor can take on **any** task, in any state, from its ticket
(`TaskAjaxController::closeTaskAction()`). Offered there rather than on the board card:
three buttons and an assignee in a 310px column pushed the card footer onto three lines, and
finishing a task is not the one-glance decision assigning yourself is. Every card opens its
ticket from the title, and a refusal's own offer opens the dialog directly.

Its precondition chain is almost empty on purpose. Every check that can refuse a close
recreates the trap the action exists to remove: before it, a task whose version had been
discarded could not change stage (`no-pending-versions`), could not return to a planning column
(`cannot-return-versioned-task-to-planning`), could not be dropped into Done (that column takes
no drops by design) and could not be published — three correct refusals adding up to a card
nobody could get rid of.

What happens to versions that are still pending is a separate, deliberate choice, made in the
dialog and never by default: keep them (they stay in the workspace, reachable from the
Workspaces module), hand the records to another task first — which is `attach`, and has to run
*before* the close, since `close()` marks the member rows closed and `moveMemberToTask()` only
moves open ones — or discard them, which is irreversible and says so.

**Refusals name the way out.** A rejection may carry a `TaskActionResolution`: a stable action
identifier plus an editor-facing label, rendered as a link on the notification. This does not
soften the rule that was applied — the stage gate still refuses — it stands beside it. The
four refusals that were dead ends now all offer "close this task instead".

## Where the history lives

An earlier draft of this document claimed the trail breaks when a version is published, and
snapshotted a copy of `sys_history` at publish time to compensate. **That was wrong on both
counts**, and the corrected reasoning is what the code now does:

- **Publishing does not lose anything.** `RecordHistoryStore::publishRecord()` calls
  `migrateWorkspaceHistory()`, which rewrites `sys_history.recuid` from the version uid to the
  live uid. Every entry survives and stays reachable from the live record — including the
  `ACTION_STAGECHANGE` rows that carry each stage comment and its recipients.
- **Nothing deletes those rows at publish time either.** Nothing in core does.
- **What actually loses them is age.** EXT:scheduler registers `sys_history` in the table
  garbage collection task with an `expirePeriod` of **30 days** by default. A task archived
  today has no trail left in a month or two.

So the real question was never "duplicate or not" — it is that `sys_history` is a *volatile
operational log*, while a closed task is an *archive record*. Writing the decision down is
therefore not a second truth; it is the only durable one:

| | Stored where | Why |
|---|---|---|
| **Comments** | own table `tx_editorialflow_comment` | must be queryable (@mentions, "unresolved" filters, dashboards) and concurrently writable. A JSON blob on the task means read-modify-write races and no indexes. |
| **Decisions** (assigned, moved from stage X to Y, with comment) | own table `tx_editorialflow_activity`, append-only, written **when it happens** | these must outlive the 30-day GC. Kept small: who, when, from/to, comment. |
| **Field-level before/after values** | **not copied** — `activity.history_uid` points at the `sys_history` row | bulky, and for the common case (one edit, straight to live) the row is still there. |

One correction found while adding the membership entries: `ActivityLogger::log()` used to
`json_encode()` the payload itself before handing it to the `payload` column. TYPO3's
`Connection::insert()` applies the column's own schema type (`ensureDatabaseValueTypes()`),
so Doctrine's `JsonType` encoded it a **second** time — every reader decoded one layer, got a
string back, and gave up. Which is why a stage change's "from → to" line never appeared in a
ticket, on any platform. The logger now hands over the array; `decodePayload()` peels the extra
layer off rows written before that, because an entry from last year is an archive record and
has to stay readable.

The pointer is the part that makes both halves work, and it is exactly the right granularity:
where the `sys_history` row still exists you get the full field-level detail for free; once the
GC has taken it, the decision itself is still on record. **A dangling `history_uid` means
"detail expired", never an error** — readers must degrade, not fail.

Because decisions are logged as they happen rather than reconstructed at the end, they also
carry the correct user and timestamp. Transitions performed outside the board (in the
Workspaces module) are not observed live; `ActivityLogger::findStageChanges()` reconciles those
from `sys_history` on read.

## Concurrency

Two editors opening the same page at the same time must not produce two tasks. This is
enforced by the **unique key on the membership table**
(`record_table, record_uid, closed, deleted`), not by a read-then-write check: the loser of
the race catches the constraint violation, discards its own task and adopts the winner's.
Check-then-insert would let both pass the check (this exact TOCTOU bug exists in
`kanban-workspaces`' `AssigneeMappingService`, found during the 2026-08-07 review).

`closed` participates in the key so a record can accumulate many closed tasks over its
lifetime while only ever belonging to one open one. That single key does triple duty: it
prevents duplicate tasks, makes detaching permanent, and keeps aggregation idempotent.

## What the two reference extensions do, and what we take from it

Studied to decide scope, not to depend on.

**kanban-workspaces — backend module.** Columns are workspace stages; cards are workspace
records. A card shows: record type icon, title, UID, page name, last-modified date, language
flag, assignee avatars, comment and attachment counts, due date/schedule badges, and an
"integrity" warning line. Drag-and-drop routes a drop through
`sendToSpecificStageWindow/Execute` — i.e. the drop *proposes* a stage transition and core's
send-to-stage form (recipients, comment) confirms it. It has **no dashboard**.

**xima content-planner — status everywhere.** Its reach comes from being present where
editors already are, not from one module: a **context menu item provider** puts status and
assignee on right-click in the page tree and record lists, plus badges in the page tree, and
four **dashboard widgets** (status overview, content status, updates, comments).

**What that means for Editorial Flow.** The card content list above is essentially a
requirements list — we have title and subject, and still owe: type icon, assignee, comment
count, due date, language, and the cross-page warning. The two lessons worth copying are
kanban-workspaces' *drop proposes, core confirms* (never write a stage directly) and xima's
*meet editors where they are* (context menu on the page tree, so a task can be planned without
opening the board at all).

## Investigated and rejected: core's new Wizard framework

TYPO3 v14 introduced `TYPO3\CMS\Backend\Wizard\WizardProviderInterface` (plus
`PageWizardProvider`, `PageWizardStepBuilder`, DTOs `Step`/`Configuration`/
`Finisher`/`SubmissionResult`) - the machinery behind core's new page-creation
wizard. It looked like the more idiomatic base for "+ New task" than the older
`TYPO3.MultiStepWizard` JS API this extension actually uses.

Checked and rejected: every class in that namespace is marked `@internal`. That is
TYPO3's own signal that it is core-only implementation detail with no BC promise,
built specifically for the page-creation dialog - not a general-purpose extension
point despite the registry name suggesting otherwise. Building on it would mean
this extension could break on any v14.3.x point release without a deprecation
notice. `@typo3/backend/multi-step-wizard.js` carries no such marking and is what
core's own established extensions (extension manager, redirects, and others) use
for the same kind of flow - the existing choice was already the right one.

## Core APIs first


Everything TYPO3 already solves is delegated. Verified present in v14.3:

| Need | Core module — no custom code |
|---|---|
| Pick a page for the "+" button (tree, **live search**, depth) | `wizard_element_browser` route + `@typo3/backend/tree/page-browser.js` |
| Multi-step "new task" flow | `@typo3/backend/multi-step-wizard.js` (`addSlide`, `lockNextStep`, `addFinalProcessingSlide`) |
| Select records → run an action ("select to task") | `@typo3/backend/multi-record-selection.js` + `multi-record-selection-action.js` |
| Right-click a page → plan a task | `TYPO3\CMS\Backend\ContextMenu\ItemProviders\AbstractProvider` (the xima pattern) |
| Dialogs, confirmations | `@typo3/backend/modal.js`, `@typo3/backend/severity.js` |
| Feedback | `@typo3/backend/notification.js` |
| Icons | `@typo3/backend/icons.js`, `<core:icon>` |
| Record writes | `@typo3/backend/ajax-data-handler.js` / DataHandler |
| Content drag-and-drop reference | `@typo3/backend/layout-module/drag-drop.js` |
| CSRF + session on write endpoints | backend ajax routes (`Configuration/Backend/AjaxRoutes.php`) |

Custom code is limited to what core has no opinion about: the board's own column-to-column
drag-and-drop, and the extension's ajax endpoints.

## Stage changes go through core

A drop onto a review column **proposes** a move; TYPO3 **decides** it. The board
never writes `t3ver_stage` itself.

Concretely, `executeStageAction` resolves the task's pending versions and hands them
to `DataHandler` as a `version` / `setStage` command. EXT:workspaces'
`version_setStage()` then does what we must not reimplement:

- `workspaceCannotEditOfflineVersion()` and `hasPermissionToUpdate()` on the page,
- **`workspaceCheckStageForCurrent()`** - whether this user may move *out of* the
  current stage at all,
- writes `t3ver_stage`,
- records the transition, its comment and its recipients in `sys_history`,
- queues the stage notification mails.

If `DataHandler::$errorLog` is non-empty, nothing of ours is written: our state must
not drift away from what core actually did. Only afterwards is the task row updated
(a read cache for the board) and the activity entry written (the durable record, since
`sys_history` is garbage-collected after 30 days).

An earlier version of this action simply `UPDATE`d our own table. It skipped every
check, every notification and core's entire history - and looked like it worked.

The distinction that makes this workable: Backlog, Planned and Done are **Content
Flow's own** states, which exist precisely because core has no notion of "not
versioned yet". Those are written directly. Everything between them belongs to core.

## Connecting an external tool: Jira, Trello, whatever else

The question that started this was whether webhooks and reactions could connect
Editorial Flow to a tool the rest of the organisation already uses. They can, and
both directions are built. What each half rests on is worth writing down, because
neither is obvious from the code alone.

**Outgoing.** Three PSR-14 events — `TaskCreatedEvent`, `TaskStageChangedEvent`,
`TaskClosedEvent` — dispatched by `TaskEventPublisher` from the places that
already write the matching activity entry. They exist for their own sake: before
them there was no seam at all for anything to listen on, which is also what
blocked the notification and @mention work listed under *Not implemented*.

Each event has a webhook message beside it in `Classes/Webhook/`. **There is no
listener of ours anywhere.** EXT:webhooks' `WebhookCompilerPass` reads the single
parameter of `createFromEvent()` *by reflection* and wires core's own
`MessageListener` to that event class. That binding is invisible in our source:
rename the parameter type and the webhook silently disconnects, with nothing to
see in a diff. `TaskWebhooksTest` therefore asserts against the registry rather
than against the attribute.

Messages carry a fully resolved `TaskSnapshot`, not a task uid, because core's
rule for a webhook message is that it is a plain object — it may be queued and
handled in a process where a service or a request means nothing any more. So
stage titles, workspace titles and the subject's title are resolved *when the
thing happens*. A Jira automation cannot look up `sys_workspace_stage`.

**Incoming.** `Reaction\TaskReaction` accepts `create`, `comment` and `close`.
Core's `ReactionResolver` authenticates by the reaction's secret and runs it as
the backend user on the `sys_reaction` record; everything after that is treated
as hostile input. Three rules carry it:

- The subject table is checked against `TaskSubjectRegistry`, never taken as
  given, and edit permission is re-derived against the real record — on every
  action, not only on create, because a page can change hands long after the task
  was opened.
- A task is addressed **only** by the external reference it was created with,
  never by uid. A payload that could name a task uid would let whoever holds the
  secret reach every task in the installation.
- "Never existed" and "already closed" get the same answer, because which one it
  is says something about this installation that the caller has no business
  learning.

`create` is idempotent against `(external_system, external_ref)`. Webhooks are
retried when they get no 2xx, and a retry must not put a second card on the
board. Those columns are also what the outgoing messages carry back out, which is
what stops a round trip duplicating a ticket on the other side.

**Both packages are optional.** `#[WebhookMessage]` and `WebhookMessageInterface`
live in `typo3/cms-core`, not in `typo3/cms-webhooks` — so the message types cost
nothing on an installation without it and simply become configurable once it is
there. `ReactionInterface` does not, which is why `TaskReaction` is registered
from `Configuration/Services.php` behind an `interface_exists()` check and
excluded from the `Classes/*` resource in `Services.yaml`. Declaring both as hard
requirements was tried first and had an immediate price: every functional test
instance in this package refused to build with *Package "editorial_flow" depends
on package "reactions" which does not exist*, because a test instance only loads
what its own `coreExtensionsToLoad` names.

**What is not verified:** a real HTTP delivery. Everything up to and including
core's own message factory is covered by tests; the message bus and the sender
behind it are core's code and have not been exercised against a live receiver.

## Errors: named for developers, worded for editors

Every rejection from `TaskAjaxController` carries two things, deliberately kept
apart: a stable, kebab-case `code` (`task-closed`, `no-workspace-version`,
`record-not-in-open-task`, ...) that never gets rephrased once shipped, and a
`message` specific enough for an editor to act on - never a bare "an error
occurred". Both are logged server-side via a PSR-3 `LoggerInterface` (TYPO3 wires
a class-scoped logger into any autowired `LoggerInterface` constructor argument
automatically - `LoggerInterfacePass` - no manual setup) before the client
response goes out, so `var/log` keeps the full context (table, uid, task,
be_user) that the browser deliberately does not see.

`findOpenTaskOrError()` is the concrete case that mattered: several actions used
to fold "task does not exist" and "task exists but is closed" into one "not
found or closed" message and one code. Those are different problems for both
audiences - a missing task is a stale link or typo, a closed task is an archive
record someone is trying to act on - so they now have distinct codes and
messages, tested in `TaskAjaxControllerErrorsTest`.

While auditing this path, `assertMayEdit()` was moved off
`BackendUserAuthentication::recordEditAccessInternals()` (deprecated since v14,
removed in v15, and trips `failOnDeprecation` in the test suite) onto
`checkRecordEditAccess()`. Both are marked `@internal` - there is currently no
public, non-deprecated API for this specific check - but since this extension
targets v14.3.x only, the non-deprecated internal method is the more honest
trade: no deprecation-log noise in production, and it is what core's own
controllers use for the same check today.

## Select to task, split from task


Two operations on the same invariant — *a record belongs to at most one open task*:

- **Select to task** (`editorialflow_task_attach`): the selected records are moved onto a task.
  Already-claimed records are *moved*, not duplicated.
- **Split from task** (`editorialflow_task_detach`): one record is pulled out into a task of its
  own. Confirmed first, and never a side effect of a drag — see below.

Both endpoints re-derive permissions server-side: the workspace comes from the backend user
(never the request), the table must be trackable, and `doesUserHaveAccess()` +
`recordEditAccessInternals()` are checked on the concrete record. A client-supplied workspace
id was a real IDOR in the reviewed `AssignAjaxController`.

`attach` additionally refuses a target task bound to **another workspace** - checked once on
the task, since that is a property of the target rather than of any record handed to it. A
pending version lives in the editor's workspace, so a record moved onto such a task would sit
in a ticket that can only say "switch to that workspace to act on this". `move-targets`, which
feeds the picker, filters by the same rule so nothing is ever offered that the write endpoint
would then refuse. It deliberately does **not** reuse `openTasksForContext()`: that one narrows
to a record's own subject and member tasks, which for a content element is precisely the task
it already sits in.

**Neither operation can lose work**, and that is structural rather than careful: the workspace
version hangs on the *record*, and both endpoints only re-point a membership row. `detach`
additionally copies the old task's state, stage and workspace, so the split-off card appears in
the same column showing the same diffs. Discarding is a different endpoint, a different button,
and red.

Reachable from three surfaces, all of them driven by `task/membership.js` - the ticket's member
list, the Page module's element badge (`ContentElementTaskBadgeListener`), and a menu on the
Visual Editor's task bubble, which also offers "move to the active task" in one click. The
badge and the ticket only carry `data-editorialflow-split` / `data-editorialflow-move` attributes;
the Visual Editor calls the module directly, because its bubbles live inside the rendered
frontend iframe where a delegated listener never sees a click. Both tasks involved get an
activity entry (`member_moved` / `member_split`) - without one on the source, a task's trail
would simply lose a record with no record of where it went.

## UX and accessibility commitments

"Editors should barely have to think" is a design constraint, not a nice-to-have. Concretely,
binding for every board feature:

- **Nothing is drag-only.** Every card move is reachable from the keyboard and from a menu on
  the card. Drag-and-drop is an accelerator, never the only path. (This is the single most
  common Kanban accessibility failure.)
- **The board announces itself.** Moves, assignments and errors go through an ARIA live
  region, so a screen-reader user hears "Moved *About us* to Review" rather than silence.
- **Status is never colour alone** — always icon *and* label. Required for WCAG 1.4.1, and it
  also survives greyscale printing and colour-blind editors.
- **Focus is never lost.** After a move, focus follows the card. Modals trap focus and return
  it to the trigger on close.
- **`prefers-reduced-motion` is respected** for all card transitions.
- **Publishing is not a drop target.** Going live is irreversible, so it is an explicit action
  with a confirmation, never something an editor can do by dropping a card slightly off target.
- Target: **WCAG 2.2 AA**, verified with keyboard-only and screen-reader passes before any
  release.

### Which of those actually hold today

The list above is the commitment. Two of its entries are not met yet, and saying so here is
worth more than letting the claim stand:

- **"Nothing is drag-only" is not true of a column move.** Every *action* on a card is
  keyboard-reachable — working on a task, publishing, assigning, comparing versions, and now
  closing (`Tests/Playwright/close-task.spec.ts` proves that one end to end from the keyboard
  alone). But changing a card's state or stage is still drag-and-drop only: there is no menu on
  the card and no key command for it, so `board.js`'s keyboard surface is selection and nothing
  else. This is the single most common Kanban accessibility failure and it is currently ours.
- **"Focus is never lost" is not true after a mutation.** Every successful action ends in
  `window.location.reload()`, which returns focus to `<body>`. Reaching the stated behaviour
  means the board updating in place instead of reloading, which is a larger change than any one
  action.

The rest hold: the live region is used by every path that changes something, status is always
icon-and-label or word-and-colour rather than colour alone, `prefers-reduced-motion` is
honoured, and publishing and closing are explicit confirmed actions rather than drop targets.

## Carried over from the kanban-workspaces review (2026-08-07)

Baked in from the start rather than fixed later:

- Unique constraints instead of check-then-insert (see Concurrency).
- One query per board, cards grouped in PHP — adding review stages never adds queries. The
  reviewed code had an N+1 in `AssigneeEnrichmentListener` (up to 4 queries × N cards).
- All `QueryBuilder` input parameter-bound; restrictions applied explicitly.
- When the board gains inline JSON config, encode with
  `JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP` — the reviewed code had a
  stored-XSS path via `json_encode()` into an inline `<script>` block.
- Every Ajax endpoint validates the target record and the user's rights on it server-side,
  and never trusts a client-supplied workspace id — the reviewed `AssignAjaxController` did.
- DocHeader configured properly, so the page-tree toggle keeps working
  ([kanban-workspaces#43](https://github.com/web-vision/kanban-workspaces/issues/43)).

## Verified core API assumptions

Checked against core sources rather than assumed — worth re-checking on core updates:

- `AfterRecordPublishedEvent::getRecordId()` is the **live** uid in both publish paths
  (`DataHandlerHook::publishVersion` swap path and `publishNewRecord`).
- `RecordHistoryStore::publishRecord()` → `migrateWorkspaceHistory()` re-points the version's
  `sys_history` rows at the live uid, so the trail survives publishing without help. Note this
  runs *after* `AfterRecordPublishedEvent` is dispatched — an earlier design snapshotted
  history in that listener and was therefore both redundant and racing core.
- `sys_history` is garbage-collected after **30 days** by default
  (EXT:scheduler `TableGarbageCollectionTask`). This, not publishing, is what an archive has
  to survive.
- Core dispatches **no** event for "a workspace version was created". Version creation must
  be observed via a DataHandler hook; `DataHandler::getAutoVersionId()` is public API and is
  how the hook learns which version an update was redirected into.
- `WorkspaceRepository::findByUid()` **throws** `\RuntimeException` when the workspace is
  missing — it does not return `null`.
- `StagesService::STAGE_EDIT_ID` = 0, `STAGE_PUBLISH_ID` = -10,
  `STAGE_PUBLISH_EXECUTE_ID` = -20. The last is a core implementation detail and is never
  shown as a column.

## Dashboard widgets: not a 1:1 copy of xima's four

xima/xima-typo3-content-planner ships four: `ContentStatusWidget`/
`ConfigurableContentStatusWidget` (status counts), `ContentUpdateWidget` (recently
changed records), `ContentCommentWidget` (recent comments). Editorial Flow ships
four too, but not the same four:

- `TaskOverviewWidget` covers the status-count case. xima's version is
  configurable because xima's status list is open-ended and user-defined;
  Editorial Flow's states are a fixed, small set (backlog/planned/in_progress/
  review/ready/done), so a "which statuses to show" control would configure
  nothing meaningful.
- `RecentActivityWidget` covers "recently changed" - and more, since it reads
  the durable activity trail rather than a raw changed-records list.
- `MyTasksWidget` has no xima equivalent; it is Editorial Flow's own "what should
  I work on" view, which follows directly from tasks having assignees.
- `RecentCommentsWidget` is the one genuine gap this comparison found: none of
  the other three surfaced comments as their own feed. Added once the ticket
  view had a real comment form and therefore real comment data worth
  surfacing on the dashboard.

## Status


Audited against the code on 2026-08-07, not written from memory.

**Implemented and covered by tests**

- Data model: subject + members, with the single unique key that prevents duplicate
  tasks, makes detaching permanent and keeps aggregation idempotent.
- State machine, column registry (core stages + Editorial Flow's own columns), subject
  registry (configurable page-like tables), page aggregation, cross-page/reuse
  detection via `sys_refindex`.
- Auto-creation on edit (`TaskAutoCreationDataHandlerHook`) and publish/close
  (`CloseTaskAfterPublishListener`), including "close only when nothing is pending".
- **Stage changes routed through core** (`version` / `setStage` on DataHandler), with
  tests asserting on core's side effects: `t3ver_stage`, the `sys_history` entry with
  its comment, and core refusing a stage change on a live record.
- Ticket view: covered records with per-record icons, cross-page warnings, one merged
  timeline with comments anchored to the action they explain, and core-rendered diffs.
- Board: cards, filters, column drag-and-drop, assign-to-me, keyboard-operable
  selection, live-region announcements.
- "+ New task": core element browser for the page, then core `MultiStepWizard` for
  title / priority / assignment.
- Post-save task wizard (details for new auto-tasks, routing for page-bound records),
  reachable from anywhere in the backend via
  `AfterBackendPageRenderEvent` (the same mechanism EXT:workspaces uses for
  `workspace-state.js`) - not the dead `includeInModules` config key an earlier
  version relied on, which nothing in core reads.
- Four Dashboard widgets on v14's `WidgetRendererInterface`, including a task-title
  join that an earlier version's template referenced but never populated.
- Page module banner via `ModifyPageLayoutContentEvent`.
- Comment form in the ticket view, refused server-side on closed tasks.
- Splitting a record into its own task and moving it onto another one, from the
  ticket, the Page module badge and the Visual Editor bubble - with a dialog for
  the new task's title/description/assignee, a server-built picker for the move,
  the cross-workspace refusal above, and an activity entry on both tasks.
- Ajax endpoints: create, attach, detach, move-stage, execute-stage, assign-me,
  details, ticket, comment, wizard-pending, wizard-submit - each re-deriving
  permissions server-side and logging every rejection with a stable code.
- **Visual Editor integration**: a task select in EXT:visual_editor's toolbar,
  where picking a task is a declaration made *before* editing (`ActiveTaskSession`,
  honoured by `TaskAutoCreationService` on every surface, not just this one),
  Backlog/Planned lifted into Editing and Review/Ready regressed back to it on
  the pick, and a coloured bubble on every content element another open task
  already claims. Three documents are involved and the difference matters: the
  backend chrome loads the module, EXT:visual_editor's own module document holds
  the toolbar, and the rendered frontend page - one nested `iframe` deeper - is
  the only place `ve-content-element` exists. Reaching into that iframe is safe
  because `PageEdit.html` renders it only for a same-origin site. Members are
  matched by *both* their live and their workspace-version uid, because the
  wrapper writes the version uid onto the element while a membership row holds
  the live one.

- **Pending pages are created by TYPO3's own page wizard.** A ticket planned as
  "a new page" carries no subject until it is dropped into Editing; the board
  then opens core's page-creation dialog (`openPageWizardModal()`, the same one
  the page tree uses) prefilled with the planned parent, and core's own
  `PageWizardProvider` creates the page with its position, page type and
  required fields. Editorial Flow neither rebuilds nor wraps that wizard: core's
  provider identifier is fixed in core's JavaScript and it reports success as a
  redirect, handing no uid back. The two halves are joined at the other end
  instead - `PendingPageHandoff` notes which ticket is waiting, and the
  DataHandler hook claims the created page for it (`PendingPageClaimService`),
  which is also what lifts the ticket into Editing. The note expires, and a
  cancelled wizard drops it, so a ticket cannot adopt an unrelated page.

**Not implemented**

- **TCA for `tx_editorialflow_task` / `_task_item` / `_comment` / `_activity`.** Only
  `Configuration/TCA/Overrides` exists. The tables are therefore not editable in the
  backend, and every base column is declared by hand in `ext_tables.sql` because the
  schema analyzer has no TCA to derive them from.
- **Context-menu item provider** for planning a task by right-clicking the page tree.
  Listed under "meet editors where they are" as a lesson taken from the xima
  content-planner - the lesson is recorded, the code is not written.
- **Notification/@mention system.**
- **Automated coverage for most of the JavaScript.** `dom-scope.js`, the wizard's
  submission service and the Visual Editor's marker matching have vitest tests;
  the board modules are still only syntax-checked.

**Verification**

Unlike an earlier version of this document, this is no longer "verified by reading":
`ddev` runs the instance, and the suite (PHPUnit functional + unit, PHPStan level 8,
php-cs-fixer) runs green against it. Several bugs listed in this document were found
only by executing - notably the auto-creation hook silently doing nothing, and a
template that could not be parsed while every test stayed green.
