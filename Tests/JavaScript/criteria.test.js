import { describe, it, expect, beforeEach } from 'vitest'

import { appendAcceptanceCriteria, confirmIncompleteCriteria } from '../../Resources/Public/JavaScript/board/criteria.js'
import { recorded, behaviour, reset as resetAjax } from './doubles/ajax-request.js'
import { notifications, reset as resetNotifications } from './doubles/notification.js'
import { lastModal, press, reset as resetModals } from './doubles/modal.js'

/*
 * Flushes the microtask queue - the change handler awaits the request and then
 * its resolve(), and counting `await Promise.resolve()` calls is exactly the
 * kind of thing that breaks when someone adds one more await.
 */
function flush() {
  return new Promise((resolve) => setTimeout(resolve, 0))
}

/*
 * The acceptance criteria in the "Send to stage" dialog.
 *
 * What matters here is that a tick is only shown as given once the server has
 * it: an editor who sees a checked box has been told their confirmation was
 * recorded, and the acceptance record written at the transition is built from
 * what the server stored, not from what the browser is showing.
 */
describe('the acceptance criteria in the stage dialog', () => {
  let form

  beforeEach(() => {
    resetAjax()
    resetNotifications()
    resetModals()
    document.body.innerHTML = ''
    form = document.createElement('form')
    document.body.appendChild(form)

    global.TYPO3 = {
      settings: { ajaxUrls: { editorialflow_checklist_toggle: '/toggle' } },
    }
  })

  it('adds nothing at all when the stage asks for nothing', () => {
    appendAcceptanceCriteria(form, [], 7)

    expect(form.querySelector('.editorialflow-stage-criteria')).toBeNull()
  })

  it('renders one operable checkbox per criterion, ticked as the server has it', () => {
    appendAcceptanceCriteria(form, [
      { uid: 1, title: 'All links checked', completed: true },
      { uid: 2, title: 'Images have alt text', completed: false },
    ], 7)

    const boxes = form.querySelectorAll('input[type="checkbox"]')
    expect(boxes).toHaveLength(2)
    expect(boxes[0].checked).toBe(true)
    expect(boxes[1].checked).toBe(false)

    // The label is associated with its box, which is what makes the text
    // clickable and what a screen reader reads out for the control.
    const label = form.querySelector(`label[for="${boxes[0].id}"]`)
    expect(label.textContent).toBe('All links checked')
  })

  it('records a tick against the task the dialog is about', async () => {
    appendAcceptanceCriteria(form, [{ uid: 2, title: 'Images have alt text', completed: false }], 7)

    const box = form.querySelector('input[type="checkbox"]')
    box.checked = true
    box.dispatchEvent(new Event('change'))
    await flush()

    expect(recorded.url).toBe('/toggle')
    expect(recorded.body).toEqual({ task: 7, itemUid: 2, completed: true })
  })

  it('puts the box back when the server did not record the tick', async () => {
    behaviour.resolved = { success: false, message: 'Nope.' }
    appendAcceptanceCriteria(form, [{ uid: 2, title: 'Images have alt text', completed: false }], 7)

    const box = form.querySelector('input[type="checkbox"]')
    box.checked = true
    box.dispatchEvent(new Event('change'))
    await flush()

    expect(box.checked).toBe(false)
    expect(notifications.at(-1).message).toBe('Nope.')
  })

  it('puts the box back when the server could not be reached at all', async () => {
    behaviour.rejectWith = new Error('offline')
    appendAcceptanceCriteria(form, [{ uid: 2, title: 'Images have alt text', completed: false }], 7)

    const box = form.querySelector('input[type="checkbox"]')
    box.checked = true
    box.dispatchEvent(new Event('change'))
    await flush()

    expect(box.checked).toBe(false)
    expect(notifications.at(-1).severity).toBe('error')
  })

  it('re-enables the box afterwards, so a mistake can be corrected', async () => {
    appendAcceptanceCriteria(form, [{ uid: 2, title: 'Images have alt text', completed: false }], 7)

    const box = form.querySelector('input[type="checkbox"]')
    box.checked = true
    box.dispatchEvent(new Event('change'))
    await flush()

    expect(box.disabled).toBe(false)
  })
})

/*
 * The confirmation the server asks for when criteria are left open. It never
 * refuses the move - it only makes the editor say that they meant it, which is
 * what gets written into the acceptance record.
 */
describe('confirming a move with unconfirmed criteria', () => {
  beforeEach(() => {
    resetModals()
    document.body.innerHTML = ''
  })

  it('lists exactly what is still open', async () => {
    const decision = confirmIncompleteCriteria({
      message: '2 of 3 acceptance criteria for "Review" are not confirmed.',
      unconfirmed: ['All links checked', 'Images have alt text'],
    })

    const modal = lastModal()
    expect(modal.content.querySelector('p').textContent)
      .toBe('2 of 3 acceptance criteria for "Review" are not confirmed.')
    expect([...modal.content.querySelectorAll('li')].map((entry) => entry.textContent))
      .toEqual(['All links checked', 'Images have alt text'])

    press('back')
    expect(await decision).toBe(false)
  })

  it('answers yes only when the editor presses Send anyway', async () => {
    const decision = confirmIncompleteCriteria({ message: 'One left.', unconfirmed: ['All links checked'] })

    press('send')

    expect(await decision).toBe(true)
  })

  /*
   * Escape and a backdrop click both close the dialog without pressing
   * anything. Left unhandled, the caller would wait on a promise nothing ever
   * settles and the OK button would stay disabled - the dialog would simply
   * stop responding.
   */
  it('answers no when the dialog is dismissed without an answer', async () => {
    const decision = confirmIncompleteCriteria({ message: 'One left.', unconfirmed: ['All links checked'] })

    lastModal().instance.hideModal()

    expect(await decision).toBe(false)
  })
})
