import { test, expect, type Frame, type Locator, type Page } from '@playwright/test'
import { openBoard } from '../fixtures/board'
import { runId } from '../../../playwright.config'

/*
 * Closes whatever this run created, pass or fail (Playwright's `teardown`
 * project hook).
 *
 * Closing, not deleting: there is no delete in this extension, deliberately -
 * a finished task is an archive record. So an instance a write suite has been
 * pointed at accumulates closed cards, which is honest rather than tidy. The
 * reset is `ddev editorialflow-demo --force`, and `ddev editorialflow-e2e` runs
 * the whole thing against a snapshot it restores afterwards.
 *
 * It goes through the same route an editor uses rather than a fixture: cleanup
 * that takes a shortcut around the UI is cleanup that can leave the UI's own
 * state behind.
 *
 * Nothing here asserts. The specs have already passed or failed by this point,
 * and a card that would not close means the cleanup hit something - a slow
 * reload, a card that moved - not that the feature is broken. What is left is
 * reported on the console instead of turning a green run red.
 */

/**
 * This run's cards that are still open.
 *
 * A closed task does not leave the board - it moves to Done, title and all. So
 * "a card carrying the run id" is not "a card left to close": matched without
 * this filter, the loop closed both cards, came round again, opened a Done
 * card's ticket (which rightly offers no Close button), stopped there, and
 * reported both finished cards as left behind.
 */
const openCardsOfThisRun = (board: Frame) =>
  board.locator('.editorialflow-card:not([data-editorialflow-state="done"])', { hasText: runId })

test('closes everything this run put on the board', async ({ page }) => {
  // Well past the 30s default: each close is a modal, a request and a board
  // reload, and this loops over everything the run created.
  test.setTimeout(180_000)

  for (let attempt = 0; attempt < 20; attempt++) {
    // The frame is re-acquired on every pass, not held across the loop: closing
    // a task reloads the board, and a Frame handle taken before that reload is
    // detached afterwards - every locator built from it silently matches nothing.
    const board = await openBoard(page)
    const card = openCardsOfThisRun(board).first()
    if ((await card.count()) === 0) {
      break
    }

    const title = (await card.getAttribute('data-editorialflow-title')) ?? ''
    try {
      await closeCard(page, card)
    } catch (error) {
      // This test passes whatever happens here, so retain-on-failure throws its
      // trace away - the screenshot is what is left to look at afterwards.
      const screenshot = test.info().outputPath('teardown-stopped-early.png')
      await page.screenshot({ path: screenshot, fullPage: true }).catch(() => undefined)
      console.warn(
        `Teardown stopped early at "${title}": ${(error as Error).message}\nScreenshot: ${screenshot}`,
      )
      break
    }
  }

  const board = await openBoard(page)
  const left = await openCardsOfThisRun(board).count()
  if (left > 0) {
    console.warn(
      `Teardown could not close ${left} card(s) titled "${runId} …" - close them by hand, ` +
        'or reseed with "ddev editorialflow-demo --force".',
    )
  }
})

async function closeCard(page: Page, card: Locator): Promise<void> {
  await card.locator('.editorialflow-card-title').first().click()

  const ticket = page.locator('typo3-backend-modal').last().getByRole('dialog')
  await expect(ticket).toBeVisible({ timeout: 15000 })

  // By data attribute, not by accessible name: core's modal has a dismiss
  // control of its own called "Close", and matching on the word closed the
  // ticket instead of opening the confirmation - which is why the first version
  // of this teardown reported success and cleaned up nothing.
  const closeButton = ticket.locator('[data-editorialflow-close]')
  await expect(closeButton).toHaveCount(1)
  await closeButton.click()

  const confirmation = page.locator('typo3-backend-modal').last().getByRole('dialog')
  await expect(confirmation).toBeVisible({ timeout: 15000 })

  // Waiting on the request rather than on the modal going away: a successful
  // close reloads the board underneath, and that reload races every assertion
  // about the dialog's own visibility. The server having answered is the fact
  // that matters, and navigating away before it does would abort it.
  const closed = page.waitForResponse(
    (response) => response.url().includes('/editorialflow/task/close') && response.status() === 200,
    { timeout: 20000 },
  )
  await confirmation.getByRole('button', { name: /^close task$/i }).click()
  await closed
}
