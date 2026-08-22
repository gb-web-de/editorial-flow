import { beforeEach, describe, expect, it, vi } from 'vitest'
import Notification, { notifications, reset as resetNotifications } from './doubles/notification.js'
import { notifyRefusal } from '../../Resources/Public/JavaScript/task/refusal.js'

/*
 * A refusal that names a way out has to actually offer it.
 *
 * Several of the server's rejections are correct and still leave an editor
 * stuck - a task with nothing pending cannot change stage, return to planning
 * or be published. The server attaches a `resolution` to those; these tests
 * pin that it reaches the editor as something clickable, and that a rejection
 * without one does not grow a phantom action.
 */
describe('notifyRefusal', () => {
  beforeEach(() => {
    resetNotifications()
  })

  it('turns a resolution into a notification action that opens the close dialog', async () => {
    const openCloseDialog = vi.fn()

    notifyRefusal(
      {
        success: false,
        code: 'no-pending-versions',
        message: 'There is nothing pending on this task to publish.',
        resolution: { action: 'close-task', label: 'Close this task instead', taskUid: 12 },
      },
      'fallback',
      { openCloseDialog },
    )

    expect(notifications).toHaveLength(1)
    expect(notifications[0].message).toBe('There is nothing pending on this task to publish.')
    expect(notifications[0].actions).toHaveLength(1)
    expect(notifications[0].actions[0].label).toBe('Close this task instead')

    await notifications[0].actions[0].action.execute()
    expect(openCloseDialog).toHaveBeenCalledWith(12, '')
  })

  it('keeps an offer on screen instead of letting it time out', () => {
    notifyRefusal(
      { message: 'nope', resolution: { action: 'close-task', label: 'Close', taskUid: 3 } },
      'fallback',
      { openCloseDialog: () => {} },
    )

    // A five-second offer is not an offer.
    expect(notifications[0].duration).toBe(0)
    expect(notifications[0].severity).toBe('warning')
  })

  it('reports a refusal with no way out as a plain error', () => {
    notifyRefusal({ success: false, code: 'task-closed', message: 'This task is closed.' }, 'fallback')

    expect(notifications[0].severity).toBe('error')
    expect(notifications[0].actions).toHaveLength(0)
  })

  it('falls back to the caller message when the server sent none', () => {
    notifyRefusal(null, 'Could not move the task.')

    expect(notifications[0].message).toBe('Could not move the task.')
  })

  it('announces the refusal, because notifications reach no screen reader', () => {
    const board = { announce: vi.fn() }

    notifyRefusal({ message: 'Nothing pending.' }, 'fallback', { board })

    expect(board.announce).toHaveBeenCalledWith('Nothing pending.')
  })

  it('ignores a resolution it has no handler for rather than offering a dead link', () => {
    notifyRefusal(
      { message: 'nope', resolution: { action: 'switch-workspace', label: 'Switch', workspaceUid: 2 } },
      'fallback',
      { openCloseDialog: () => {} },
    )

    expect(notifications[0].actions).toHaveLength(0)
    expect(notifications[0].severity).toBe('error')
  })
})
