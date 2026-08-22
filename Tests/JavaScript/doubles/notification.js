/*
 * Stands in for @typo3/backend/notification.js. Records what an editor would
 * have been told, so a test can assert on the message rather than on a mock
 * having been called.
 *
 * duration and actions are recorded too: a refusal that offers a way out must
 * carry that offer AND stay on screen (duration 0), and both are things only
 * this record can prove.
 */
export const notifications = []

export function reset() {
  notifications.length = 0
}

export default {
  success(title, message, duration, actions) {
    notifications.push({ severity: 'success', title, message, duration, actions: actions ?? [] })
  },
  error(title, message, duration, actions) {
    notifications.push({ severity: 'error', title, message, duration, actions: actions ?? [] })
  },
  warning(title, message, duration, actions) {
    notifications.push({ severity: 'warning', title, message, duration, actions: actions ?? [] })
  },
}
