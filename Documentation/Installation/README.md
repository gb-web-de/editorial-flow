# Installing Editorial Flow

*[Deutsche Fassung](README.de.md)*

Three steps, whatever your installation looks like:

1. **Install the extension**
2. **Run one setup command** for your situation
3. **Check the result**

Every command shows its plan first. Add `--dry-run` to see the plan and change nothing.
Running a command again is safe: it only adds what is missing.

> In a DDEV project, put `ddev exec` in front of every command (`ddev exec vendor/bin/typo3 …`).
> In this repository's own dev setup the binary is `.Build/bin/typo3`.

## 1. Install the extension

```bash
composer require gb-web/editorial-flow
vendor/bin/typo3 extension:setup
```

Requires TYPO3 v14.3 with `typo3/cms-workspaces` (installed automatically as a dependency).

## 2. Choose your situation

| Your installation | Command |
|---|---|
| **Fresh / empty database** – nothing there yet | `typo3 editorialflow:setup fresh` |
| **One editorial group**, name of your choice | `typo3 editorialflow:setup single-group --group="Online desk"` |
| **Several groups, each with its own workspace** | `typo3 editorialflow:setup group-per-workspace --group="News,Sport,Culture"` |
| **Large existing group tree** that must stay as it is | `typo3 editorialflow:setup existing-groups --attach-to-editors --dry-run` |
| Not sure | `typo3 editorialflow:setup` – asks you, step by step |
| Just want to try it out | `typo3 editorialflow:democontent` – demo workspaces and nine demo users |

Common options for all of them:

| Option | Meaning | Default |
|---|---|---|
| `--stages="Review,Approval"` | Review stages in order, or `none` | `Review,Approval` |
| `--owner=chief` | Users who own the workspace – **only owners may publish** | only administrators |
| `--owner-group="Editors-in-chief"` | Same for a whole group (created if missing) | – |
| `--editor=jane` | Create an editor right away (`jane:Sport` for a specific group) | – |
| `--editor-password=…` | Password for new editors, or `EDITORIALFLOW_EDITOR_PASSWORD` | – |
| `--dry-run` | Show the plan, change nothing | – |

### Fresh installation / empty database

```bash
EDITORIALFLOW_EDITOR_PASSWORD='S3cure!Pass' \
  vendor/bin/typo3 editorialflow:setup fresh --owner=admin --editor=jane
```

Creates, if missing:

- a root page *Home* and a site *main* (`--site-base=https://example.org/` for a real domain) –
  only if there are no pages at all, an existing page tree is never touched
- the group **Editors** with the board, Page, List and Media modules, mounted on the root
  page, allowed to edit pages and content – **in workspaces only**, not in Live
- the workspace **Editors** with the stages *Review → Approval*; the group is member and
  responsible for both stages; `admin` owns it and may publish
- the editor **jane**, who starts in that workspace on her first login

Call the group something else with `--group="Online desk"`.

### One editorial group with a name of your choice

```bash
vendor/bin/typo3 editorialflow:setup single-group --group="Online desk" --owner=chief
```

- **Group exists:** only the board module is added. Its other modules, tables, mounts and
  page permissions stay exactly as they are.
- **Group is new:** created like in *fresh*. It gets the pages nobody owns yet; pages that
  already belong to another group are left alone (the command says how many).
- Workspace: named after the group unless you pass `--workspace="Drafts"`. An existing
  workspace keeps all its members and owners; the group is added.

### Several groups, each with its own workspace

```bash
vendor/bin/typo3 editorialflow:setup group-per-workspace --group="News,Sport,Culture" --owner-group="Editors-in-chief"
```

Every group gets a workspace of the same name. Members of *Sport* only see and edit in
*Sport*. The owner group (created if missing) owns all three workspaces and may publish
everywhere.

A page can belong to only one group, so new groups share their page and editing rights
through one extra group, **Editorial Flow Editors (shared)**. It has no board and no
workspace of its own; *News*, *Sport* and *Culture* include it and add only the board.
Groups that already exist just get the board module.

Editors for a specific group: `--editor=jane:Sport --editor=tom:News`.

### Large existing group tree

Your groups, permissions and page owners took years to build – this scenario does not touch
them. It only adds one small group, **Editorial Flow Access**, which has nothing but the board
module, and hangs it into your groups as a subgroup.

```bash
# 1. look first
vendor/bin/typo3 editorialflow:setup existing-groups --attach-to-editors --dry-run
# 2. apply
vendor/bin/typo3 editorialflow:setup existing-groups --attach-to-editors
```

- `--attach-to-editors` picks every group that has the Page module itself.
- Or name the groups: `--attach-to="Editorial,Online desk"` (titles or uids; use uids when
  several groups share a title). Everyone in those groups gets the board. TYPO3 passes rights
  from a subgroup *up* to the groups that include it, not down – a user who sits only in a
  subgroup of *Editorial* needs that subgroup named as well.
- Workspaces stay as they are. To put the whole tree into one workspace, add
  `--workspace="Editorial" --owner=chief`.

## 3. Check the result

```bash
vendor/bin/typo3 editorialflow:check
```

```
  ✓ 7 page(s).
  ✓ Workspace "Online desk" (uid 1): stages Review > Approval.
  ✓ 1 group(s) can open the board: Online desk.

 [OK] The board is ready to use.
```

It exits with an error code when something is broken, so a deployment can run it. What it
finds, and how to fix it:

| Finding | Meaning | Fix |
|---|---|---|
| *There is no workspace* | No task ever opens on its own | run a setup scenario |
| *stage counter says 0* | Nobody but owners can move a card past *Editing* | `editorialflow:check --fix` |
| *N editor(s) may edit Live* | What they change in Live never becomes a task | `editorialflow:check --fix-live-editing` |
| *Group … is not a member of any workspace* | It sees the board, but cannot work on it | `editorialflow:setup single-group --group="…"` |
| *… has no owner* | Only administrators can publish | re-run setup with `--owner=…` |

### Adding editors later

Either let setup do it (recommended – the editor starts in the workspace, not in Live):

```bash
EDITORIALFLOW_EDITOR_PASSWORD='…' vendor/bin/typo3 editorialflow:setup single-group --group="Online desk" --editor=tom
```

or create the user in the backend or with `typo3 backend:user:create --groups=<uid>`, and then:

```bash
vendor/bin/typo3 editorialflow:check --fix-live-editing
```

The backend user form ticks *Edit Live* by default – that one tick keeps every change the
editor makes off the board.

## What setup never does

- remove a module, table, mount or subgroup from a group
- take over a page that already belongs to another group
- remove a member or owner from a workspace
- change the password or settings of an existing user
- delete anything

To undo, delete the group, workspace or user it created in the backend (*Administration →
Backend users*, *Workspaces*).

## Automated deployments

All scenarios run without questions when given a scenario name and `--no-interaction`:

```bash
vendor/bin/typo3 extension:setup
vendor/bin/typo3 editorialflow:setup single-group --group="Online desk" --owner=chief --no-interaction
vendor/bin/typo3 editorialflow:check
```
