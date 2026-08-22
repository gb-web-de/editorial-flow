/*
 * Stands in for @typo3/backend/modal.js. Keeps the last dialog's content node
 * and buttons reachable, so a test can look at the markup an editor would see
 * and press a button the way they would.
 *
 * confirm()'s default OK/Cancel buttons carry no `trigger` in real TYPO3
 * core - clicking one only dispatches 'confirm.button.ok'/
 * 'confirm.button.cancel' on the modal, which is why modal-confirm.js exists
 * (see its own comment). This double mirrors that: the instance is a real
 * EventTarget, and press() dispatches those events for a button that has no
 * `trigger` of its own, instead of calling one that was never there.
 */
export const opened = []

export function reset() {
  opened.length = 0
}

export function lastModal() {
  return opened[opened.length - 1] ?? null
}

export function press(name) {
  const modal = lastModal()
  const button = modal?.buttons.find((candidate) => candidate.name === name)
  if (button === undefined) {
    throw new Error('No button named "' + name + '" in the current modal')
  }

  if (typeof button.trigger === 'function') {
    // A real button as the event target, because core passes the click event
    // from the button the editor pressed and handlers disable it against double
    // submission - `new Event('click')` has a null target and blows up there.
    const element = document.createElement('button')
    element.name = name
    element.textContent = button.text ?? name
    const event = new Event('click')
    Object.defineProperty(event, 'target', { value: element })

    return button.trigger(event, modal.instance)
  }
  modal.instance.dispatchEvent(new Event('confirm.button.' + name))
}

/*
 * A real element, not a bare EventTarget: core's modal IS one, and callers use
 * it as one - reading `dataset` to guard against double submission and calling
 * `querySelector` to read their own form back out. The content node is appended
 * so those queries find what the caller put there.
 */
function buildInstance(content) {
  const instance = document.createElement('div')
  instance.classList.add('typo3-modal-double')
  if (content instanceof Node) {
    instance.appendChild(content)
  }
  instance.hidden = false
  instance.hideModal = () => {
    instance.hidden = true
    instance.dispatchEvent(new Event('typo3-modal-hidden'))
  }
  return instance
}

export default {
  /*
   * Copied verbatim from core's own enums (cms-backend Modal): callers pass
   * Modal.types.default and Modal.sizes.large, and a double without them fails
   * with "cannot read properties of undefined" rather than anything readable.
   */
  types: { default: 'default', template: 'template', ajax: 'ajax', iframe: 'iframe' },
  sizes: { small: 'small', default: 'default', medium: 'medium', large: 'large', full: 'full' },

  advanced(configuration) {
    const instance = buildInstance(configuration.content)
    opened.push({ ...configuration, instance })

    return instance
  },
  confirm(title, content, severity, buttons = []) {
    const resolvedButtons = buttons.length > 0 ? buttons : [
      { text: 'Cancel', active: true, btnClass: 'btn-default', name: 'cancel' },
      { text: 'OK', btnClass: 'btn-' + severity, name: 'ok' },
    ]
    return this.advanced({ title, content, severity, buttons: resolvedButtons })
  },
}
