import { beforeEach, describe, expect, it, vi } from 'vitest'
import { behaviour, requests, reset as resetAjax } from './doubles/ajax-request.js'
import { notifications, reset as resetNotifications } from './doubles/notification.js'
import { lastModal, press, reset as resetModal } from './doubles/modal.js'
import { openCloseDialog } from '../../Resources/Public/JavaScript/task/close.js'

/*
 * Closing a task, and what the editor is told before they do.
 *
 * The dialog is the only place an editor learns what is at stake: which records
 * still hold unpublished changes and which fields those are. It also owns an
 * ordering rule the server cannot enforce - handing records to another task has
 * to happen BEFORE the close, because close() marks the member rows closed and
 * moveMemberToTask() only ever moves open ones.
 */

/*
 * Click handlers are async and nothing awaits them, so the assertion has to run
 * after the microtask queue drains. A fixed number of `await Promise.resolve()`
 * is fragile the moment a promise chain grows a link - see publish.test.js.
 */
const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const PREVIEW_WITH_PENDING = {
  success: true,
  task: { uid: 12, title: 'About us', workspaceUid: 1, workspaceTitle: 'Editorial' },
  canDiscard: true,
  discardBlockedReason: '',
  pending: [
    {
      table: 'tt_content',
      uid: 10,
      versionUid: 42,
      title: 'Intro text',
      changes: ['Header', 'Text'],
      changeCount: 2,
    },
  ],
  handoverTargets: [{ uid: 8, title: 'Campaign', state: 'in_progress', stageLabel: 'Editing' }],
}

function setup() {
  resetAjax()
  resetNotifications()
  resetModal()
  global.TYPO3 = {
    settings: {
      ajaxUrls: {
        editorialflow_task_close: '/close',
        editorialflow_task_close_preview: '/close-preview',
        editorialflow_task_attach: '/attach',
      },
    },
  }
}

function chooseMode(value) {
  const form = lastModal().content
  const radio = form.querySelector(`input[value="${value}"]`)
  radio.checked = true
  return radio
}

describe('openCloseDialog', () => {
  beforeEach(() => {
    setup()
    // window.location.reload is called on success and jsdom has no navigation.
    delete window.location
    window.location = { reload: vi.fn() }
  })

  it('asks the server what is pending before showing anything', async () => {
    behaviour.queue = [PREVIEW_WITH_PENDING]

    await openCloseDialog(12, 'About us')

    expect(requests[0].url).toBe('/close-preview')
    expect(requests[0].queryArguments).toEqual({ task: 12 })
  })

  it('names each pending record and the fields that changed', async () => {
    behaviour.queue = [PREVIEW_WITH_PENDING]

    await openCloseDialog(12, 'About us')

    const text = lastModal().content.textContent
    expect(text).toContain('Intro text')
    expect(text).toContain('Header')
    expect(text).toContain('Text')
  })

  it('says how many further changes there are rather than listing forty', async () => {
    behaviour.queue = [{
      ...PREVIEW_WITH_PENDING,
      pending: [{ ...PREVIEW_WITH_PENDING.pending[0], changes: ['Header'], changeCount: 12 }],
    }]

    await openCloseDialog(12, 'About us')

    expect(lastModal().content.textContent).toContain('close.dialog.moreChanges(11)')
  })

  it('sends nothing until the editor confirms', async () => {
    behaviour.queue = [PREVIEW_WITH_PENDING]

    await openCloseDialog(12, 'About us')

    expect(requests).toHaveLength(1)
    expect(requests.every((request) => request.url !== '/close')).toBe(true)
  })

  it('cancelling closes nothing', async () => {
    behaviour.queue = [PREVIEW_WITH_PENDING]
    await openCloseDialog(12, 'About us')

    press('cancel')
    await flush()

    expect(requests.every((request) => request.url !== '/close')).toBe(true)
  })

  it('defaults to keeping the pending changes', async () => {
    behaviour.queue = [PREVIEW_WITH_PENDING, { success: true, closed: true, mode: 'keep', discarded: 0 }]
    await openCloseDialog(12, 'About us')

    // Nothing is selected by the test - this is what the dialog opened with.
    press('close')
    await flush()

    const close = requests.find((request) => request.url === '/close')
    expect(close.body).toEqual({ task: 12, mode: 'keep' })
  })

  it('sends discard only when the editor picked it', async () => {
    behaviour.queue = [PREVIEW_WITH_PENDING, { success: true, closed: true, mode: 'discard', discarded: 1 }]
    await openCloseDialog(12, 'About us')

    chooseMode('discard')
    press('close')
    await flush()

    const close = requests.find((request) => request.url === '/close')
    expect(close.body).toEqual({ task: 12, mode: 'discard' })
  })

  it('greys out discarding when the server said it is not possible from here', async () => {
    behaviour.queue = [{
      ...PREVIEW_WITH_PENDING,
      canDiscard: false,
      discardBlockedReason: 'Switch to workspace "Editorial" to discard these changes.',
    }]

    await openCloseDialog(12, 'About us')

    const form = lastModal().content
    expect(form.querySelector('input[value="discard"]').disabled).toBe(true)
    expect(form.textContent).toContain('Switch to workspace "Editorial"')
  })

  it('hands every pending record over in one request, and only then closes', async () => {
    behaviour.queue = [
      PREVIEW_WITH_PENDING,
      { success: true, moved: [{ table: 'tt_content', uid: 10 }], refused: [] },
      { success: true, closed: true, mode: 'keep', discarded: 0 },
    ]
    await openCloseDialog(12, 'About us')

    chooseMode('handover')
    press('close')
    await flush()

    const attach = requests.filter((request) => request.url === '/attach')
    expect(attach).toHaveLength(1)
    expect(attach[0].body).toEqual({ task: 8, records: [{ table: 'tt_content', uid: 10 }] })

    // The order is the point: close() marks the member rows closed, and
    // moveMemberToTask() only moves open ones.
    const urls = requests.map((request) => request.url)
    expect(urls.indexOf('/attach')).toBeLessThan(urls.indexOf('/close'))
  })

  it('does not close the task when the handover was refused', async () => {
    behaviour.queue = [
      PREVIEW_WITH_PENDING,
      { success: true, moved: [], refused: [{ table: 'tt_content', uid: 10, message: 'That task is in another workspace.' }] },
    ]
    await openCloseDialog(12, 'About us')

    chooseMode('handover')
    press('close')
    await flush()

    expect(requests.every((request) => request.url !== '/close')).toBe(true)
    expect(notifications[0].message).toBe('That task is in another workspace.')
  })

  it('disables handover when there is nowhere to hand records to', async () => {
    behaviour.queue = [{ ...PREVIEW_WITH_PENDING, handoverTargets: [] }]

    await openCloseDialog(12, 'About us')

    expect(lastModal().content.querySelector('input[value="handover"]').disabled).toBe(true)
  })

  it('shows the server\'s own refusal, not a generic error', async () => {
    behaviour.queue = [
      PREVIEW_WITH_PENDING,
      {
        throw: {
          resolve: async () => ({
            success: false,
            code: 'close-requires-task-workspace',
            message: 'Switch to workspace "Editorial" to discard these changes, or close the task and leave them pending.',
          }),
        },
      },
    ]
    await openCloseDialog(12, 'About us')

    chooseMode('discard')
    press('close')
    await flush()

    expect(notifications[0].message).toContain('Switch to workspace "Editorial"')
  })

  it('reports a transport failure as one instead of pretending the task closed', async () => {
    behaviour.queue = [PREVIEW_WITH_PENDING, { throw: new Error('network down') }]
    await openCloseDialog(12, 'About us')

    press('close')
    await flush()

    expect(notifications[0].severity).toBe('error')
    expect(notifications[0].message).toBe('close.error.server')
    expect(window.location.reload).not.toHaveBeenCalled()
  })

  it('collapses to a plain confirmation when nothing is pending', async () => {
    behaviour.queue = [{
      success: true,
      task: { uid: 12, title: 'About us', workspaceUid: 0, workspaceTitle: '' },
      canDiscard: false,
      discardBlockedReason: '',
      pending: [],
      handoverTargets: [],
    }]

    await openCloseDialog(12, 'About us')

    const form = lastModal().content
    expect(form.querySelectorAll('input[type="radio"]')).toHaveLength(0)
    expect(form.textContent).toContain('close.dialog.nothingPending')
  })

  it('announces the close, since a notification reaches no screen reader', async () => {
    behaviour.queue = [PREVIEW_WITH_PENDING, { success: true, closed: true, mode: 'keep', discarded: 0 }]
    const board = { announce: vi.fn() }
    await openCloseDialog(12, 'About us', { board })

    press('close')
    await flush()

    expect(board.announce).toHaveBeenCalledWith('close.success(About us)')
  })
})
