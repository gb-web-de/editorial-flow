import { test, expect } from '@playwright/test'
import { openBoard, openTaskWizard } from '../fixtures/board'
import { runId } from '../../../playwright.config'

/*
 * The first spec in this suite that actually writes.
 *
 * Everything it creates carries the run id in its title, which is what the
 * teardown project finds afterwards - so a local instance ends up with closed
 * cards rather than an ever-growing backlog. Closing is as far as a browser can
 * go: there is no delete, by design, and a closed task is an archive record
 * either way.
 */
test.describe('planning a task through the wizard', () => {
  test('puts a card on the board that was not there before', async ({ page }) => {
    const title = `${runId} plan a page`
    const board = await openBoard(page)

    await expect(board.getByText(title)).toHaveCount(0)

    const modal = await openTaskWizard(page, board)
    await modal.getByRole('button', { name: /create a new page/i }).click()

    const wizard = page.locator('editorialflow-task-wizard')
    await wizard.locator('input[type="text"]').first().fill(title)
    await wizard.getByRole('button', { name: /next/i }).click()
    await wizard.getByRole('button', { name: /save/i }).click()

    // The board reloads itself after a successful save, so the card is what to
    // wait on rather than the modal going away - the latter happens either way.
    const reopened = await openBoard(page)
    await expect(reopened.getByText(title).first()).toBeVisible({ timeout: 15000 })
  })

  test('a ticket planned as a new page waits in the backlog without a subject', async ({ page }) => {
    const title = `${runId} still without a page`
    const board = await openBoard(page)
    const modal = await openTaskWizard(page, board)
    await modal.getByRole('button', { name: /create a new page/i }).click()

    const wizard = page.locator('editorialflow-task-wizard')
    await wizard.locator('input[type="text"]').first().fill(title)
    await wizard.getByRole('button', { name: /next/i }).click()
    await wizard.getByRole('button', { name: /save/i }).click()

    const reopened = await openBoard(page)
    const card = reopened.locator('.editorialflow-card', { hasText: title }).first()
    await expect(card).toBeVisible({ timeout: 15000 })

    // No page exists yet: the card carries the pending-page marker rather than a
    // real subject, and the page itself is only created by core's own wizard
    // when someone drags this into Editing (see PendingPageHandoff).
    await expect(card).toHaveAttribute('data-editorialflow-record', 'pages:0')

    // Backlog, not Planned: nothing set a start date.
    await expect(
      reopened.locator('[data-editorialflow-column="backlog"]').locator('.editorialflow-card', { hasText: title }),
    ).toHaveCount(1)
  })
})
