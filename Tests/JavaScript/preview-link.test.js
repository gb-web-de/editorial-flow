import { beforeEach, describe, expect, it, vi } from 'vitest'
import { behaviour, recorded, reset as resetAjax } from './doubles/ajax-request.js'
import { notifications, reset as resetNotifications } from './doubles/notification.js'
import { lastModal, opened, press, reset as resetModal } from './doubles/modal.js'
import { calls as topLevelImports } from './doubles/top-level-module-import.js'
import { openPreviewLink, registerPreviewLinks } from '../../Resources/Public/JavaScript/task/preview-link.js'

/*
 * "Preview link" in the ticket: the Workspaces module's shareable links and QR
 * code, for a task's draft.
 *
 * What this module decides is what an editor sees before passing a link on -
 * that it works without a login, and until when - and which link the QR code
 * carries, per language. The QR image itself is core's <typo3-qrcode>.
 */

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const EXPIRES = Date.UTC(2026, 9, 11, 12, 0) / 1000

const ONE_LINK = {
  success: true,
  links: [{ language: '', url: 'https://example.org/about?ADMCMD_prev=abc' }],
  expires: EXPIRES,
}

const TWO_LANGUAGES = {
  success: true,
  links: [
    { language: 'English', url: 'https://example.org/about?ADMCMD_prev=abc' },
    { language: 'Deutsch', url: 'https://example.org/de/ueber-uns?ADMCMD_prev=abc' },
  ],
  expires: EXPIRES,
}

function qrCode() {
  return lastModal().content.querySelector('typo3-qrcode')
}

describe('openPreviewLink', () => {
  beforeEach(() => {
    resetAjax()
    resetNotifications()
    resetModal()
    topLevelImports.length = 0
    global.TYPO3 = { settings: { ajaxUrls: { editorialflow_task_preview_link: '/preview-link' } } }
  })

  it('asks for the task\'s own subject when no record is named', async () => {
    behaviour.resolved = ONE_LINK

    await openPreviewLink({ task: 12, title: 'About us' })

    expect(recorded.url).toBe('/preview-link')
    expect(recorded.body).toEqual({ task: 12 })
  })

  it('asks for one record of the task when one is named', async () => {
    behaviour.resolved = ONE_LINK

    await openPreviewLink({ task: 12, table: 'tt_content', uid: 10, title: 'Intro' })

    expect(recorded.body).toEqual({ task: 12, table: 'tt_content', uid: 10 })
  })

  it('shows core\'s QR code for the link, with its copy and download controls', async () => {
    behaviour.resolved = ONE_LINK

    await openPreviewLink({ task: 12, title: 'About us' })

    expect(lastModal().title).toBe('previewLink.dialog.title(About us)')
    expect(qrCode().getAttribute('content')).toBe(ONE_LINK.links[0].url)
    expect(qrCode().hasAttribute('show-url')).toBe(true)
    expect(qrCode().hasAttribute('show-download')).toBe(true)
    // jsdom's window is its own top: imported here, not through the top frame.
    expect(topLevelImports).toEqual([])
  })

  it('says the link works without a login, and until when', async () => {
    behaviour.resolved = ONE_LINK

    await openPreviewLink({ task: 12, title: 'About us' })

    expect(lastModal().content.querySelector('p').textContent).toMatch(/^previewLink\.notice\.until\(.+\)$/)
  })

  it('still warns when the expiry is unknown', async () => {
    behaviour.resolved = { ...ONE_LINK, expires: 0 }

    await openPreviewLink({ task: 12, title: 'About us' })

    expect(lastModal().content.querySelector('p').textContent).toBe('previewLink.notice')
  })

  it('offers no language choice for a single link', async () => {
    behaviour.resolved = ONE_LINK

    await openPreviewLink({ task: 12, title: 'About us' })

    expect(lastModal().content.querySelector('select')).toBeNull()
  })

  it('swaps the QR code for the language picked', async () => {
    behaviour.resolved = TWO_LANGUAGES

    await openPreviewLink({ task: 12, title: 'About us' })
    const select = lastModal().content.querySelector('select')
    expect([...select.options].map((option) => option.textContent)).toEqual(['English', 'Deutsch'])
    expect(qrCode().getAttribute('content')).toBe(TWO_LANGUAGES.links[0].url)

    select.value = '1'
    select.dispatchEvent(new Event('change'))

    expect(lastModal().content.querySelectorAll('typo3-qrcode')).toHaveLength(1)
    expect(qrCode().getAttribute('content')).toBe(TWO_LANGUAGES.links[1].url)
  })

  it('opens the link picked', async () => {
    behaviour.resolved = TWO_LANGUAGES
    const open = vi.spyOn(window, 'open').mockImplementation(() => null)

    await openPreviewLink({ task: 12, title: 'About us' })
    const select = lastModal().content.querySelector('select')
    select.value = '1'
    select.dispatchEvent(new Event('change'))
    press('open')

    expect(open).toHaveBeenCalledWith(TWO_LANGUAGES.links[1].url, '_blank', 'noopener')
  })

  it('passes the server\'s refusal on instead of opening a dialog', async () => {
    behaviour.rejectWith = {
      resolve: async () => ({
        success: false,
        code: 'no-workspace-access',
        message: 'This task belongs to workspace "Team B", and you are not a member of it.',
      }),
    }

    await openPreviewLink({ task: 12, title: 'About us' })

    expect(opened).toHaveLength(0)
    expect(notifications).toEqual([expect.objectContaining({
      severity: 'error',
      message: 'This task belongs to workspace "Team B", and you are not a member of it.',
    })])
  })

  it('says so when the server cannot be reached', async () => {
    behaviour.rejectWith = new Error('network down')

    await openPreviewLink({ task: 12, title: 'About us' })

    expect(opened).toHaveLength(0)
    expect(notifications).toEqual([expect.objectContaining({ severity: 'error', message: 'previewLink.error' })])
  })
})

describe('registerPreviewLinks', () => {
  beforeEach(() => {
    resetAjax()
    resetNotifications()
    resetModal()
    document.body.innerHTML = ''
    global.TYPO3 = { settings: { ajaxUrls: { editorialflow_task_preview_link: '/preview-link' } } }
  })

  it('reads the task and the record from the ticket button', async () => {
    behaviour.resolved = ONE_LINK
    document.body.innerHTML = '<button type="button" data-editorialflow-preview-link="12"'
      + ' data-table="tt_content" data-uid="10" data-title="Intro"><span>QR code</span></button>'
    registerPreviewLinks()

    document.querySelector('span').click()
    await flush()

    expect(recorded.body).toEqual({ task: 12, table: 'tt_content', uid: 10 })
    expect(lastModal().title).toBe('previewLink.dialog.title(Intro)')
  })
})
