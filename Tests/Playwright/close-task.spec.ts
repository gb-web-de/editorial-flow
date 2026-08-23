import { test, expect, type Page } from '@playwright/test'
import { openBoard } from './fixtures/board'

/*
 * Closing a task, keyboard only.
 *
 * ARCHITECTURE.md commits to WCAG 2.2 AA and to "nothing is drag-only", and
 * closing is the newest irreversible action - so at least one machine-checked
 * keyboard route to it has to exist, rather than a claim that the markup ought
 * to allow one.
 *
 * The route is two modals deep: the card title opens the ticket, and the
 * ticket's Close button opens the confirmation on top of it. Closing is offered
 * in the ticket only, never on the card - three buttons and an assignee in a
 * 310px column pushed the card footer onto three lines, and finishing a task is
 * not the one-glance decision assigning yourself is.
 *
 * The confirmation dialog is deliberately built from native controls: a
 * fieldset of radios, so arrow keys work without any JavaScript of ours, and
 * core's Modal owns the focus trap. That is what this asserts - not our own key
 * handling, but that we did not need any.
 *
 * Read-only up to the confirmation. The spec cancels rather than closing, for
 * the same reason visual-editor-task-select.spec.ts stays write-free: a run
 * must not litter the installation it is pointed at. The write path itself is
 * covered by TaskCloseActionTest and close.test.js.
 */

/** Core stacks modals, so the confirmation is the last one, not the only one. */
const topModal = (page: Page) => page.locator('typo3-backend-modal').last().getByRole('dialog')

/**
 * Card title -> ticket -> Close, without a mouse. Returns false when the seeded
 * board has no open task to work with.
 */
async function openCloseDialogFromKeyboard(page: Page): Promise<boolean> {
  const board = await openBoard(page)

  const cardTitle = board.locator('.editorialflow-card-title').first()
  if ((await cardTitle.count()) === 0) {
    return false
  }

  // focus() then Enter throughout: this asserts the controls are
  // keyboard-operable and Enter-activated, without asserting a tab ORDER that
  // any later card or ticket change would break for no real reason.
  await cardTitle.focus()
  await expect(cardTitle).toBeFocused()
  await page.keyboard.press('Enter')

  const ticket = topModal(page)
  await expect(ticket).toBeVisible()

  const closeButton = ticket.locator('[data-editorialflow-close]')
  await expect(closeButton).toHaveCount(1)
  await closeButton.focus()
  await page.keyboard.press('Enter')

  return true
}

test.describe('closing a task from the keyboard', () => {
  test('reaches the close dialog through the ticket without a mouse', async ({ page }) => {
    test.skip(!(await openCloseDialogFromKeyboard(page)), 'no open task on the seeded board')

    // Two modals now: the ticket underneath, the confirmation on top.
    await expect(page.locator('typo3-backend-modal')).toHaveCount(2)
    await expect(topModal(page)).toContainText(/close/i)
  })

  test('lets the keyboard reach every choice, including the destructive one', async ({ page }) => {
    test.skip(!(await openCloseDialogFromKeyboard(page)), 'no open task on the seeded board')

    const dialog = topModal(page)
    const radios = dialog.locator('input[type="radio"]')
    if ((await radios.count()) === 0) {
      // Nothing pending: the dialog collapses to a plain confirmation, which is
      // the intended degraded form rather than a failure.
      await expect(dialog).toContainText(/nothing pending/i)
      return
    }

    // Keeping the changes is the default, so a confirmation pressed straight
    // through can never be the destructive one.
    await expect(radios.first()).toBeChecked()

    await radios.first().focus()
    await page.keyboard.press('ArrowDown')
    await expect(radios.first()).not.toBeChecked()

    // Never colour alone: the discard option carries the word, and its warning
    // is wired to it for a screen reader rather than sitting loose beside it.
    const discard = dialog.locator('input[value="discard"]')
    await expect(discard).toHaveCount(1)
    const describedBy = await discard.getAttribute('aria-describedby')
    expect(describedBy).toBeTruthy()
    await expect(dialog.locator(`#${describedBy}`)).not.toBeEmpty()
  })

  test('closes the confirmation again on Escape, changing nothing', async ({ page }) => {
    test.skip(!(await openCloseDialogFromKeyboard(page)), 'no open task on the seeded board')

    await expect(page.locator('typo3-backend-modal')).toHaveCount(2)

    await page.keyboard.press('Escape')

    // The confirmation goes, the ticket underneath stays - Escape backs out of
    // one step, it does not abandon the whole thing.
    await expect(page.locator('typo3-backend-modal')).toHaveCount(1)
  })
})

test.describe('the board card', () => {
  test('does not offer closing, only the ticket does', async ({ page }) => {
    const board = await openBoard(page)

    await expect(board.locator('[data-editorialflow-close]')).toHaveCount(0)
  })
})
