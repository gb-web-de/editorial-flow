import { test, expect, type Frame, type Page } from '@playwright/test'
import { openBoard } from './fixtures/board'

/*
 * "Preview link" in the ticket: the Workspaces module's shareable link and QR
 * code, for a task's draft.
 *
 * Worth a browser on top of TaskPreviewLinkTest and preview-link.test.js
 * because the part most likely to break is neither PHP nor this module's own
 * logic: the QR code is core's <typo3-qrcode>, which has to be defined in the
 * TOP frame while the button that asks for it sits in the board's iframe
 * script. Defined in the wrong registry, the dialog opens and stays empty -
 * nothing a unit test can see.
 *
 * Read-only as far as content goes. Asking for a link writes one sys_preview
 * row, as merely opening the Workspaces module does; it expires on its own
 * and `cleanup:previewlinks` removes it.
 */

const topModal = (page: Page) => page.locator('typo3-backend-modal').last().getByRole('dialog')

/**
 * Opens tickets of cards with a draft until one offers the preview link.
 * False when the seeded board has none.
 */
async function openTicketWithPreviewLink(page: Page, board: Frame): Promise<boolean> {
  const titles = board.locator(
    ['in_progress', 'review', 'ready']
      .map((state) => `.editorialflow-card[data-editorialflow-state="${state}"] .editorialflow-card-title`)
      .join(', '),
  )
  const count = await titles.count()
  for (let index = 0; index < count; index++) {
    await titles.nth(index).click()
    const ticket = topModal(page)
    await expect(ticket.locator('.editorialflow-ticket')).toBeVisible()
    if ((await ticket.locator('.editorialflow-ticket-actions [data-editorialflow-preview-link]').count()) > 0) {
      return true
    }
    await page.keyboard.press('Escape')
    await expect(page.locator('typo3-backend-modal')).toHaveCount(0)
  }

  return false
}

test.describe('sharing a task\'s draft', () => {
  test('shows the QR code and the link, and says it needs no login', async ({ page }) => {
    const board = await openBoard(page)
    test.skip(!(await openTicketWithPreviewLink(page, board)), 'no task with a draft on the seeded board')

    await topModal(page).locator('.editorialflow-ticket-actions [data-editorialflow-preview-link]').click()

    // The dialog on top of the ticket.
    await expect(page.locator('typo3-backend-modal')).toHaveCount(2)
    const dialog = topModal(page)
    await expect(dialog).toContainText(/without logging in/i)

    // Upgraded by core, in the top frame: it renders its image and its own
    // URL field - an undefined element would render neither.
    const qrCode = dialog.locator('typo3-qrcode')
    await expect(qrCode.locator('.preview svg, .preview img').first()).toBeVisible({ timeout: 15000 })
    await expect(qrCode.locator('input[readonly]')).toHaveValue(/[?&]ADMCMD_prev=[0-9a-f]{32}/)

    await page.screenshot({ path: 'test-results/preview-link-dialog.png' })
  })
})
