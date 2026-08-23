#
# Editorial Flow owns six tables.
# Five of them have no TCA definition, so all base columns (uid, pid, tstamp,
# crdate, deleted) are explicitly declared in this SQL schema. The same absence
# means DeletedRestriction is a silent no-op for those five - every repository
# query filters `deleted` explicitly instead, see
# GbWeb\EditorialFlow\Domain\Repository\TaskChecklistRepository::findItemsForStage()
# for the reasoning.
#
# The one exception is tx_editorialflow_stage_checklist_item: it has TCA
# (Configuration/TCA/), because a stage's acceptance criteria are edited as an
# inline relation on the sys_workspace_stage record, and FormEngine cannot
# render a relation to a table it does not know. For that table DeletedRestriction
# is real. The explicit conditions stay anyway - a query should say what it means
# without the reader having to remember which table is the exception.
#

#
# One editorial work item.
#
# A task is about a SUBJECT - the page-like thing it represents. That is a page,
# but not only: any versionable record that behaves like a page gets its own task.
# The motivating case is a news record, which is technically a record but has its
# own detail page and reads as a page to an editor. Which tables count as subjects
# is configurable, see GbWeb\EditorialFlow\Service\TaskSubjectRegistry.
#
# Everything else attaches to the subject's task as a member (see task_item):
# editing a content element on a page belongs to that page's task, it does not
# open a card of its own.
#
CREATE TABLE tx_editorialflow_task (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,

    title varchar(255) DEFAULT '' NOT NULL,
    description text,

    # The page-like record this task represents.
    subject_table varchar(255) DEFAULT '' NOT NULL,
    subject_uid int(11) unsigned DEFAULT '0' NOT NULL,
    # Denormalised so the board can scope by page tree without joining the target
    # table, and so the trail survives the subject record being deleted.
    subject_pid int(11) unsigned DEFAULT '0' NOT NULL,

    # Lifecycle. `state` is the GbWeb\EditorialFlow\Domain\Model\TaskState value.
    # `stage_uid` is only meaningful while state->hasVersion() is true and then
    # mirrors the version's t3ver_stage - core stays the source of truth, this is
    # a read cache so the board can sort columns without touching every version.
    state varchar(20) DEFAULT 'backlog' NOT NULL,
    stage_uid int(11) DEFAULT '0' NOT NULL,

    # Workspace the versions live in. 0 while the task has no version yet.
    workspace_uid int(11) unsigned DEFAULT '0' NOT NULL,

    # 0 = deliberately unassigned, so an editor can pick the task up themselves.
    assignee int(11) unsigned DEFAULT '0' NOT NULL,
    # 0 = not planned yet. Setting one is what moves a task from Backlog into
    # Planned (see TaskAjaxController::createAction()) - a start date is the
    # editorial commitment "this starts being worked on", so the board should
    # already show it as planned rather than waiting for someone to notice and
    # drag the card themselves.
    start_date int(11) unsigned DEFAULT '0' NOT NULL,
    due_date int(11) unsigned DEFAULT '0' NOT NULL,

    # Backlog ordering and priority - planning needs both: sorting is the manual
    # drag order within a column, priority is the editorial urgency label.
    sorting int(11) unsigned DEFAULT '0' NOT NULL,
    priority tinyint(4) unsigned DEFAULT '2' NOT NULL,

    # 1 when the task opened itself because someone just started editing, rather
    # than being planned. The board marks these, and the post-save wizard lets an
    # editor refine its details or route a page-bound record elsewhere later.
    auto_created tinyint(1) unsigned DEFAULT '0' NOT NULL,

    closed tinyint(1) unsigned DEFAULT '0' NOT NULL,
    closed_at int(11) unsigned DEFAULT '0' NOT NULL,
    closed_by int(11) unsigned DEFAULT '0' NOT NULL,

    comments int(11) unsigned DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY board_scope (subject_pid, closed, state),
    KEY subject (subject_table, subject_uid, closed),
    KEY assignee (assignee, closed)
);

#
# Which records belong to a task.
#
# A task on a page pulls in the content elements and records sitting on it, so one
# card covers "this page and everything on it" instead of flooding the board with a
# card per content element.
#
# An editor can pull an element out and give it its own task. That works without a
# "detached" flag, because of the unique key below: a record belongs to at most one
# OPEN task. Detaching moves the membership row to the new task, and re-syncing the
# page's task simply cannot reclaim the record, because the slot is taken.
#
# `closed` is denormalised from the task so the unique key only constrains open
# tasks - a record may of course appear in many closed ones over its lifetime.
#
CREATE TABLE tx_editorialflow_task_item (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,

    task int(11) unsigned DEFAULT '0' NOT NULL,

    record_table varchar(255) DEFAULT '' NOT NULL,
    record_uid int(11) unsigned DEFAULT '0' NOT NULL,

    # subject = the record the task is named after
    # auto    = pulled in because it sits on the subject page
    # manual  = attached or detached by an editor
    origin varchar(10) DEFAULT 'auto' NOT NULL,

    # The page this record actually lives on. Differs from the task's subject when
    # an editor changed content that belongs elsewhere - typically reached through
    # a shortcut element. The board warns about those: touching them changes other
    # pages too, which is precisely what a planning tool has to make visible.
    home_pid int(11) unsigned DEFAULT '0' NOT NULL,
    # 1 when other pages reference this record (shortcut or any other reuse).
    # Derived from sys_refindex at attach time, see ReferenceInspector.
    shared tinyint(1) unsigned DEFAULT '0' NOT NULL,

    closed tinyint(1) unsigned DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY one_open_task_per_record (record_table, record_uid, closed, deleted),
    KEY task (task, closed)
);

#
# Threaded comments on a task. A real table, not a JSON blob on the task:
# comments must be queryable (@mentions, dashboards, "unresolved" filters) and
# concurrently writable without read-modify-write races.
#
CREATE TABLE tx_editorialflow_comment (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,

    task int(11) unsigned DEFAULT '0' NOT NULL,
    parent int(11) unsigned DEFAULT '0' NOT NULL,

    # What this comment is about. A comment is rarely free-floating chatter:
    # most of the time it explains a change ("sent back because ..."), so it is
    # anchored to the activity entry it belongs to. 0 = a standalone remark.
    activity int(11) unsigned DEFAULT '0' NOT NULL,
    # Optional pointer at core's sys_history row, when the comment refers to a
    # concrete field change. Dangling = detail expired, not an error.
    history_uid int(11) unsigned DEFAULT '0' NOT NULL,

    be_user int(11) unsigned DEFAULT '0' NOT NULL,
    content text,
    resolved tinyint(1) unsigned DEFAULT '0' NOT NULL,

    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY task (task, parent),
    KEY activity (activity)
);

#
# Append-only editorial record: what was decided, by whom, when.
#
# This is the DURABLE store. sys_history is not: EXT:scheduler garbage-collects it
# after 30 days by default, so an archived task would otherwise lose its trail.
# Core does keep the trail across publishing (it migrates sys_history.recuid from
# the version uid to the live uid), so the problem is age, not publishing.
#
# Field-level before/after values are NOT copied. `history_uid` points at core's
# sys_history row holding that detail, for as long as it exists - a dangling
# pointer means "detail expired", not an error.
# See ARCHITECTURE.md, "Where the history lives".
#
CREATE TABLE tx_editorialflow_activity (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,

    task int(11) unsigned DEFAULT '0' NOT NULL,
    event varchar(40) DEFAULT '' NOT NULL,
    be_user int(11) unsigned DEFAULT '0' NOT NULL,
    # sys_history row with the full detail, 0 if core wrote none.
    history_uid int(11) unsigned DEFAULT '0' NOT NULL,
    # JSON. The essentials that must outlive sys_history: from/to stage, comment.
    payload json DEFAULT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY task (task, crdate)
);

#
# A stage's own review checklist definition - one policy, reused by every task
# that passes through this stage in this workspace. Not per-task: "did we check
# links" is a property of the Review stage itself, not of any one task.
#
# stage_uid follows the same convention tx_editorialflow_task.stage_uid does:
# a real sys_workspace_stage uid, or one of core's fixed stage ids (0, -10, -20).
# That split is also why workspace_uid is still here: for a real stage the uid is
# globally unique and identifies the workspace on its own (this is what the inline
# relation from sys_workspace_stage writes), while the three fixed ids repeat in
# every workspace and only the pair tells them apart.
#
CREATE TABLE tx_editorialflow_stage_checklist_item (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,

    workspace_uid int(11) unsigned DEFAULT '0' NOT NULL,
    stage_uid int(11) DEFAULT '0' NOT NULL,
    title varchar(255) DEFAULT '' NOT NULL,
    sorting int(11) unsigned DEFAULT '0' NOT NULL,

    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY stage (workspace_uid, stage_uid, deleted)
);

#
# One task's completion of one stage checklist item. The relationship - has this
# task checked this item off - has its own row, independent of both sides: the
# item definition can be edited or removed without rewriting every task's state,
# and a task's state is never lost by a definition change.
#
# No `deleted` column: nothing soft-deletes a state row directly. Toggling
# `completed` back to 0 is how a check is undone; when the item itself is
# removed (stage_checklist_item.deleted = 1), its state rows simply stop being
# reachable through findItemsForStage()'s join and are never read again.
#
CREATE TABLE tx_editorialflow_task_checklist_state (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,

    task int(11) unsigned DEFAULT '0' NOT NULL,
    checklist_item int(11) unsigned DEFAULT '0' NOT NULL,
    completed tinyint(1) unsigned DEFAULT '0' NOT NULL,
    completed_by int(11) unsigned DEFAULT '0' NOT NULL,
    completed_at int(11) unsigned DEFAULT '0' NOT NULL,

    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    # Enforces "one state row per task per item" at the database level - the
    # insert-then-catch upsert in TaskChecklistRepository::setCompletion() relies
    # on this constraint existing to detect "a row is already there".
    UNIQUE KEY task_item (task, checklist_item)
);

#
# The inline counter for the stage's acceptance criteria.
#
# Declared because the column is this extension's, not core's: core's own
# sys_workspace.custom_stages is the same kind of field and is maintained the
# same way - DataHandler writes the child count back onto the parent whenever the
# relation is submitted through FormEngine. Nothing reads this value here (the
# criteria are read by their own stage_uid, see TaskChecklistRepository), but a
# `type: inline` field without a column of its own is not a valid relation.
#
CREATE TABLE sys_workspace_stage (
    tx_editorialflow_criteria int(11) unsigned DEFAULT '0' NOT NULL
);
