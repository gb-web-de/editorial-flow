import { test, expect } from '@playwright/test'
import { openBoard } from '../fixtures/board'

/*
 * The installation guide as a browser journey: an installation that went
 * through `editorialflow:setup` and nothing else, and an editor who was
 * created by `editorialflow:setup --editor=...` in the same run.
 *
 * What is checked is what an editor would notice first if setup had got it
 * wrong - the module missing from their menu, an empty board, or a board
 * without the stages setup was asked for. None of it can be seen as admin,
 * which is why this logs in as the editor and does not reuse the admin state.
 *
 * Skipped unless CI (or you) point it at such an installation:
 *
 *   EDITORIALFLOW_SETUP_EDITOR=redakteur
 *   EDITORIALFLOW_SETUP_PASSWORD=...           (default Password.1)
 *   EDITORIALFLOW_SETUP_STAGES=Review,Approval (default)
 *   EDITORIALFLOW_SETUP_WORKSPACE=Redaktion    (optional: the workspace expected)
 *   EDITORIALFLOW_TEST_PAGE_ID=1
 */
const editor = process.env.EDITORIALFLOW_SETUP_EDITOR
const password = process.env.EDITORIALFLOW_SETUP_PASSWORD ?? 'Password.1'
const workspace = process.env.EDITORIALFLOW_SETUP_WORKSPACE
const stages = (process.env.EDITORIALFLOW_SETUP_STAGES ?? 'Review,Approval').split(',').map((stage) => stage.trim()).filter(Boolean)

test.describe('an editor on an installation set up with editorialflow:setup', () => {
  test.skip(!editor, 'EDITORIALFLOW_SETUP_EDITOR is not set - see Documentation/Installation')
  test.use({ storageState: { cookies: [], origins: [] } })

  test.beforeEach(async ({ page }) => {
    await page.goto('/typo3/')
    await page.getByLabel(/username/i).fill(editor ?? '')
    await page.getByLabel(/password/i).fill(password)
    await page.getByRole('button', { name: /^login$/i }).click()
    await expect(page.locator('#typo3-module-menu, .modulemenu')).toBeVisible({ timeout: 15000 })
  })

  test('finds the board in the module menu', async ({ page }) => {
    await expect(page.locator('[data-modulemenu-identifier="web_editorialflow"]')).toBeVisible()
  })

  test('sees the board with the stages setup created', async ({ page }) => {
    const board = await openBoard(page)

    for (const column of ['backlog', 'planned', 'done']) {
      await expect(board.locator(`[data-editorialflow-column="${column}"]`)).toBeVisible()
    }
    const titles = board.locator('.editorialflow-column-title')
    for (const stage of stages) {
      await expect(titles.filter({ hasText: stage }).first()).toBeVisible()
    }
  })

  /*
   * The setting that makes the board look broken without any error: an
   * editor who may edit Live never gets a workspace version, so nothing they
   * change becomes a task. Setup creates groups without it and the guide
   * runs `editorialflow:check --fix-live-editing` after creating users -
   * if either step regressed, the editor would land in Live here.
   */
  test('works in the workspace, not in Live', async ({ page }) => {
    // The backend's own record of where the user is - the same object the
    // workspace indicator in the top bar renders from.
    await page.waitForFunction(() => (window as any).TYPO3?.WorkspaceState !== undefined)
    const current = await page.evaluate(async () => (window as any).TYPO3.WorkspaceState.getCurrentWorkspace())

    expect(current.id, 'the editor landed in Live').toBeGreaterThan(0)
    if (workspace) {
      expect(current.title).toBe(workspace)
    }
  })
})
