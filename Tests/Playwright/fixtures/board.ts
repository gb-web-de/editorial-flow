import { type Frame, type Locator, type Page, expect } from '@playwright/test'

/*
 * The board is a backend module, so it renders inside the backend's content
 * iframe (name `list_frame`) while anything TYPO3.Modal opens - the whole task
 * wizard - lives in the outer chrome document. Every spec here needs both, and
 * mixing them up is why a locator silently matches nothing.
 */

export const boardPageId = process.env.EDITORIALFLOW_TEST_PAGE_ID ?? '1'

export async function openBoard(page: Page, pageId: string = boardPageId): Promise<Frame> {
  await page.goto(`/typo3/module/web/editorialflow?id=${pageId}`)

  /*
   * page.frame() is a synchronous look at the frames attached at that instant,
   * and the backend chrome builds its content iframe after its own document
   * has loaded - so asking straight after goto() returns null often enough to
   * fail a random spec per run. Wait for the element, then take its frame.
   */
  const iframe = await page.waitForSelector('iframe[name="list_frame"]', { state: 'attached' })
  const frame = await iframe.contentFrame()
  if (frame === null) {
    throw new Error('The backend content iframe (list_frame) never appeared.')
  }

  await expect(frame.locator('[data-editorialflow-column]').first()).toBeVisible()

  return frame
}

/*
 * EXT:visual_editor's module, one document deeper than the board: its own
 * toolbar and language columns render inside the backend content iframe, and
 * the rendered frontend page sits in another iframe inside that. The task
 * select lives in the first, its markers in the second - see
 * visual-editor-task-select.js's docblock for why that split exists.
 *
 * frameLocator rather than the frame handle openBoard() takes: nothing here
 * needs a Frame object, and chaining is what reaches the inner document.
 */
export function openVisualEditor(page: Page, pageId: string = boardPageId) {
  return {
    goto: async () => {
      await page.goto(`/typo3/module/web/edit?id=${pageId}`)
      const moduleFrame = page.frameLocator('iframe#typo3-contentIframe')
      // The select is inserted by wizard.js from the chrome document once the
      // module document has loaded, so waiting on the module's own toolbar
      // first would still race the insertion.
      await expect(moduleFrame.locator('.editorialflow-ve-task-select select')).toBeVisible({ timeout: 20000 })

      return moduleFrame
    },
  }
}

export function visualEditorContentFrame(page: Page) {
  return page.frameLocator('iframe#typo3-contentIframe').frameLocator('iframe.visual-editor-iframe')
}

/*
 * The <typo3-backend-modal> host itself never becomes visible - it wraps the
 * <dialog> that does - so the dialog is what a spec waits on and queries.
 */
export function taskWizardModal(page: Page) {
  return page.locator('typo3-backend-modal').getByRole('dialog')
}

export async function openTaskWizard(page: Page, frame: Frame) {
  const trigger = frame.locator('[data-editorialflow-action="create-task"]').first()
  const modal = taskWizardModal(page)

  /*
   * The button is in the markup before board.js has bound its handler, so a
   * click can land on a dead element and be lost silently. There is no
   * readiness flag to wait for, hence retrying the click until the modal is
   * actually up - re-clicking is safe precisely because a click that did
   * nothing is what put us back here.
   */
  await expect(async () => {
    if (!(await modal.isVisible())) {
      await trigger.click()
    }
    await expect(modal).toBeVisible({ timeout: 2000 })
  }).toPass({ timeout: 20000 })

  return modal
}

/*
 * Moves a card into a column the way the board's own drag-and-drop does.
 *
 * Playwright's dragTo() drives the mouse, and the mouse alone does not start an
 * HTML5 drag: the board listens for `dragstart`/`dragover`/`drop` with a
 * DataTransfer (board/drag-drop.js), and no synthetic mouse gesture produces
 * those. So the events are dispatched inside the page, with a real DataTransfer
 * carrying the same payload the board puts on it.
 *
 * This is the one place in this suite that reaches past the user's own input
 * devices, and it is worth saying why it has to: there is no keyboard route to
 * moving a card between columns. Enter and Space select a card, nothing more.
 * That is a genuine gap against ARCHITECTURE.md's "nothing is drag-only"
 * commitment - when it is closed, this helper should be replaced by whatever
 * keys close it.
 */
export async function dragCardIntoColumn(frame: Frame, card: Locator, column: Locator): Promise<void> {
  const taskUid = (await card.getAttribute('data-editorialflow-task')) ?? ''
  const columnKey = (await column.getAttribute('data-editorialflow-column')) ?? ''

  await frame.evaluate(
    ({ task, key }) => {
      const source = document.querySelector(`.editorialflow-card[data-editorialflow-task="${task}"]`)
      const target = document.querySelector(`[data-editorialflow-column="${key}"]`)
      if (source === null || target === null) {
        throw new Error(`Card ${task} or column ${key} is not on this board.`)
      }

      const dataTransfer = new DataTransfer()
      dataTransfer.setData('text/plain', task)

      source.dispatchEvent(new DragEvent('dragstart', { bubbles: true, dataTransfer }))
      target.dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer }))
      target.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer }))
      source.dispatchEvent(new DragEvent('dragend', { bubbles: true, dataTransfer }))
    },
    { task: taskUid, key: columnKey },
  )
}
