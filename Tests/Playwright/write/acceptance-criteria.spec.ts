import { test, expect, type Frame, type Page } from '@playwright/test'
import { dragCardIntoColumn, openBoard } from '../fixtures/board'

/*
 * The acceptance criteria, asked where they are meant to be asked: in the
 * dialog that sends a task on to the next stage.
 *
 * Data-dependent on purpose. This needs a card that has something pending in a
 * workspace - which is what an editor produces by editing a page, and what
 * `editorialflow:democontent` cannot create on its own. Rather than fabricating
 * that state through the UI in every test, the spec looks for a card it can
 * send onward and skips when the seeded board has none. A skip here means "this
 * installation has no work in progress", not "the feature is broken", and the
 * write path itself is covered by StageAcceptanceCriteriaTest either way.
 */
const topModal = (page: Page) => page.locator('typo3-backend-modal').last().getByRole('dialog')

/**
 * A review column this board will actually accept a drop into. `acceptsDrop` is
 * the board's own answer, so a spec that respects it cannot fail on a column
 * that was never a target in the first place.
 */
function reviewColumn(board: Frame) {
  return board
    .locator('[data-editorialflow-state="review"][data-editorialflow-accepts-drop="true"]')
    .first()
}

/** A card sitting in Editing, which is the stage whose criteria get asked. */
async function findSendableCard(board: Frame) {
  // By state, not by column key: the Editing column's key is derived from the
  // stage uid (`stage-0`), while the state is what the board itself reasons in.
  const card = board
    .locator('[data-editorialflow-state="in_progress"] .editorialflow-card')
    .first()

  return (await card.count()) === 0 ? null : card
}

test.describe('sending a task on to a review stage', () => {
  test('asks for the stage criteria before it lets the card go', async ({ page }) => {
    const board = await openBoard(page)
    const card = await findSendableCard(board)
    test.skip(card === null, 'no task in progress on the seeded board')

    const title = await card!.locator('.editorialflow-card-title').first().innerText()
    const target = reviewColumn(board)
    test.skip((await target.count()) === 0, 'this board has no review column that accepts a drop')

    await dragCardIntoColumn(board, card!, target)

    const dialog = topModal(page)
    await expect(dialog).toBeVisible({ timeout: 15000 })

    const criteria = dialog.locator('.editorialflow-stage-criteria')
    test.skip((await criteria.count()) === 0, `the stage "${title}" is leaving asks for no criteria`)

    // A fieldset with a legend and native checkboxes: the grouping a screen
    // reader announces and the keyboard operation both come from the markup,
    // which is the whole reason there is no key handling of ours to assert.
    await expect(criteria.locator('legend')).toBeVisible()
    await expect(criteria.locator('input[type="checkbox"]').first()).toBeVisible()
  })

  test('records a tick straight away, even if the dialog is then cancelled', async ({ page }) => {
    const board = await openBoard(page)
    const card = await findSendableCard(board)
    test.skip(card === null, 'no task in progress on the seeded board')

    const target = reviewColumn(board)
    test.skip((await target.count()) === 0, 'this board has no review column that accepts a drop')

    await dragCardIntoColumn(board, card!, target)
    const dialog = topModal(page)
    await expect(dialog).toBeVisible({ timeout: 15000 })

    const box = dialog.locator('.editorialflow-stage-criteria input[type="checkbox"]').first()
    test.skip((await box.count()) === 0, 'the stage being left asks for no criteria')

    // Pinned by uid, because the second half of this test has to reopen the
    // dialog for the SAME task - "the first card in Editing" is not stable
    // across a board reload, and comparing one task's criteria against
    // another's is how this first failed.
    const taskUid = await card!.getAttribute('data-editorialflow-task')

    const wasChecked = await box.isChecked()
    await box.setChecked(!wasChecked)
    // The change goes to the server on the spot, not with the transition - so
    // the box settling in its new state is the request having been accepted.
    await expect(box).toBeChecked({ checked: !wasChecked })

    await dialog.getByRole('button', { name: /^cancel$/i }).click()

    // Reopened: a confirmation is a fact about the review, and cancelling the
    // dialog is not withdrawing it.
    const reopened = await openBoard(page)
    const again = reopened.locator(`.editorialflow-card[data-editorialflow-task="${taskUid}"]`)
    test.skip((await again.count()) === 0, 'the card left Editing while the dialog was open')
    await dragCardIntoColumn(reopened, again, reviewColumn(reopened))

    const secondDialog = topModal(page)
    await expect(secondDialog).toBeVisible({ timeout: 15000 })
    await expect(secondDialog.locator('.editorialflow-stage-criteria input[type="checkbox"]').first())
      .toBeChecked({ checked: !wasChecked })

    // Put it back, so a rerun starts where this one did.
    await secondDialog.locator('.editorialflow-stage-criteria input[type="checkbox"]').first().setChecked(wasChecked)
    await secondDialog.getByRole('button', { name: /^cancel$/i }).click()
  })
})
