# TYPO3 Extension `Editorial Flow`

An editorial task board for TYPO3 v14. It puts a **backlog in front of TYPO3 workspaces** and
an **archive behind them**, and lets the workspace do the approval work in between.

Depends on TYPO3 core only — no third-party extensions.

```
 Backlog   Planned  │  In Progress   Review…   Ready  │  Done
 ─────────────────  │  ─────────────────────────────  │  ────────
    Editorial Flow    │   TYPO3 core workspace stages   │  Editorial Flow
```

**Tasks open themselves.** An editor who just opens a page and starts typing gets a workspace
version from TYPO3 and a task from Editorial Flow, without asking for either. When the version
goes live, the task closes with its history frozen into it.

See [ARCHITECTURE.md](ARCHITECTURE.md) for the data model, the history-storage decision and the
accessibility commitments.

## Requirements

- [DDEV](https://ddev.com/) >= 1.24
- Docker

## Getting started

```bash
ddev start
```

[`.ddev/scripts/post-start.sh`](.ddev/scripts/post-start.sh) then runs `composer install`,
installs TYPO3 v14 (once — it is guarded, not re-run on every start), sets up the extensions,
and creates demo content. It aborts loudly on failure rather than leaving a half-built
instance behind.

- Frontend: https://editorial-flow.ddev.site/
- Backend: https://editorial-flow.ddev.site/typo3/ (`admin` / `Password.1`)
- Module: **Web → Editorial Flow**

**To see the workflow:** switch into the *Editorial* workspace (created by the demo step, with
two review stages), open one of the demo pages, and change something. A card appears on the
board on its own — that is the point of the extension. Then drag it to *Review*: the dialog asks
you to confirm that stage's acceptance criteria before it lets the card go.

### About demo content

`typo3/theme-camino` ships a complete demo site in `Initialisation/data.xml` — pages *Camino*,
*FAQs*, *Packing List*, *Camino Route Comparison*, an imprint/privacy footer and images. TYPO3
imports it during `typo3 setup` when `TYPO3_SETUP_DISTRIBUTION=theme_camino` is set (see
[.ddev/config.yaml](.ddev/config.yaml)); this requires `typo3/cms-impexp`, and it is mutually
exclusive with `--create-site`/`TYPO3_SETUP_CREATE_SITE` — the distribution creates the site
configuration itself.

What no distribution can provide is **workspaces with custom review stages**, the permission
spread between real editorial roles, and the acceptance criteria a stage asks for. Without those
the board shows only the two fixed core stages, and there is nothing for it to merge.
`editorial_flow` adds all three:

```bash
ddev editorialflow-demo
```

It verifies the Camino content arrived and creates three workspaces, nine backend users and the
criteria on the review stages. Re-running is safe — existing data is kept, and recreating (which
deletes the workspaces and any versions inside them) only happens after you confirm, or with
`--force`.

| Workspace | Stages | What it shows |
|---|---|---|
| **Editorial** | Review, Approval | The full chain, with acceptance criteria on both stages |
| **Marketing** | **Review**, Legal | Its first stage is *also* called "Review" — the board merges stages by title, so both workspaces share one column |
| **Quickfix** | *none* | Editing straight to "Ready to publish", the path without a review process |

All nine users have the password `Password.1`:

| User | Role |
|---|---|
| `editor` | Editorial + Quickfix member, responsible for Editorial's *Review* |
| `reviewer` | Editorial member, responsible for *Approval* — cannot publish |
| `approver` | Owner of Editorial and Quickfix: the only role that may publish |
| `editor2` | Marketing member, responsible for Marketing's *Review* |
| `both` | Reviews in **both** Editorial and Marketing, and sees both sides of a conflict |
| `legal` | Marketing member, responsible for *Legal* only |
| `marketing` | Owner of Marketing, responsible for no stage of it |
| `stagelead` | Responsible for **both** Editorial stages at once |
| `observer` | Has the module and page access, but no workspace at all |

The spread is core's own model, not this extension's: being responsible for a stage means you
may act on whatever currently *sits* in it and send it anywhere from there — it says nothing
about who may move things *into* it. Only a workspace owner may publish. See
[WORKSPACE-STAGES.md](WORKSPACE-STAGES.md) for the reading of core that took several wrong turns
before it was right.

> During `ddev start` the same command runs non-interactively. DDEV hooks have no TTY, so it
> can only report and keep, never ask — use `ddev editorialflow-demo` when you want the prompt.

`typo3/cms-styleguide` is also installed if you want bulk TCA test records on top (backend
module *System → Styleguide*).

## Manual setup

```bash
ddev composer install
ddev exec .Build/bin/typo3 setup --no-interaction --force --server-type=other
ddev exec .Build/bin/typo3 extension:setup
ddev editorialflow-demo
```

(The `setup` call picks up database, admin user and `TYPO3_SETUP_DISTRIBUTION` from the
environment defined in [.ddev/config.yaml](.ddev/config.yaml).)

## Development

```bash
ddev composer cs:check      # PHP-CS-Fixer, dry-run
ddev composer cs:fix
ddev composer phpstan       # level 8
ddev composer test:unit
ddev composer test:functional
npm run test:js             # vitest, the extension's own ES modules
npm run test:e2e            # Playwright, against a served backend
ddev editorialflow-e2e      # the same, wrapped in a database snapshot
```

The functional suite runs on SQLite in CI and needs no database service:
`typo3DatabaseDriver=pdo_sqlite composer test:functional`.

Playwright needs a running site and a backend login, both read from the
environment — there is no fixed default, because the DDEV project name is not
unique across worktrees:

```bash
npm ci && npx playwright install --with-deps chromium
EDITORIALFLOW_BASE_URL=$(ddev describe -j | jq -r .raw.primary_url) npm run test:e2e
```

### The specs that write, and the ones that do not

Two Playwright projects, deliberately apart:

- **`chromium`** renders and navigates and changes nothing. A run that only wants
  to know whether the board still comes up leaves no trace on the installation it
  was pointed at.
- **`chromium-write`** (`Tests/Playwright/write/`) plans tasks, moves stages and
  confirms acceptance criteria — the journeys that cannot be checked below the
  UI. Everything it creates carries a per-run id in its title, and a `teardown`
  project closes those cards afterwards whether the run passed or failed.

Closing is as far as a browser can go: this extension has no delete, on purpose,
so a dev instance still collects archived cards. `ddev editorialflow-e2e` takes a
database snapshot first and restores it afterwards — including on a failed run or
a Ctrl-C — which is the version to use when you would rather not think about it.

The PHPUnit functional suite never touches the dev database at all: the testing
framework creates and drops a `db_ft…` database per test run.

CI serves the same installation without DDEV: `typo3 setup` on SQLite,
`extension:setup` for the Camino content, `editorialflow:democontent` for the
workspace, then PHP's built-in server via
[`Build/playwright/router.php`](Build/playwright/router.php).

## Status

Early. Data model, state machine, auto-creation and publish/close are implemented; the board
renders read-only. TCA, Ajax write endpoints and the interactive board UI are still open — see
the end of [ARCHITECTURE.md](ARCHITECTURE.md).

## Compatibility

| Branch | TYPO3 | PHP |
|--------|-------|-----|
| main   | v14   | 8.2 – 8.5 |
