import { test, expect } from '@playwright/test'
import { openBoard } from './fixtures/board'

/*
 * Closing a task, keyboard only.
 *
 * ARCHITECTURE.md commits to WCAG 2.2 AA and to "nothing is drag-only", and
 * closing is the newest irreversible action on the board - so at least one
 * machine-checked keyboard route to it has to exist, rather than a claim that
 * the markup ought to allow one.
 *
 * The dialog is deliberately built from native controls: a fieldset of radios,
 * so arrow keys work without any JavaScript of ours, and core's Modal owns the
 * focus trap. That is what this asserts - not our own key handling, but that we
 * did not need any.
 *
 * Read-only up to the confirmation. The spec cancels rather than closing, for
 * the same reason visual-editor-task-select.spec.ts stays write-free: a run
 * must not litter the installation it is pointed at. The write path itself is
 * covered by TaskCloseActionTest and close.test.js.
 */
test.describe('closing a task from the keyboard', () => {
  test('reaches the close button and opens its dialog without a mouse', async ({ page }) => {
    const board = await openBoard(page)

    const closeButton = board.locator('[data-editorialflow-close]').first()
    if ((await closeButton.count()) === 0) {
      test.skip(true, 'no open task on the seeded board to close')
    }

    // focus(), then Enter: this asserts the control is keyboard-operable and
    // Enter-activated, without asserting a tab ORDER that any later card change
    // would break for no real reason.
    await closeButton.focus()
    await expect(closeButton).toBeFocused()
    await page.keyboard.press('Enter')

    // The modal renders into the backend chrome, not into the board iframe.
    const dialog = page.locator('typo3-backend-modal').getByRole('dialog')
    await expect(dialog).toBeVisible()
  })

  test('lets the keyboard reach every choice, including the destructive one', async ({ page }) => {
    const board = await openBoard(page)

    const closeButton = board.locator('[data-editorialflow-close]').first()
    if ((await closeButton.count()) === 0) {
      test.skip(true, 'no open task on the seeded board to close')
    }

    await closeButton.focus()
    await page.keyboard.press('Enter')

    const dialog = page.locator('typo3-backend-modal').getByRole('dialog')
    await expect(dialog).toBeVisible()

    const radios = dialog.locator('input[type="radio"]')
    if ((await radios.count()) === 0) {
      // Nothing pending: the dialog collapses to a plain confirmation, which is
      // the intended degraded form rather than a failure.
      await expect(dialog).toContainText(/nothing pending|Nothing is pending|pending/i)
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

  test('closes the dialog again on Escape, changing nothing', async ({ page }) => {
    const board = await openBoard(page)

    const closeButton = board.locator('[data-editorialflow-close]').first()
    if ((await closeButton.count()) === 0) {
      test.skip(true, 'no open task on the seeded board to close')
    }

    await closeButton.focus()
    await page.keyboard.press('Enter')

    const dialog = page.locator('typo3-backend-modal').getByRole('dialog')
    await expect(dialog).toBeVisible()

    await page.keyboard.press('Escape')

    await expect(dialog).toBeHidden()
    await expect(closeButton).toBeVisible()
  })
})
