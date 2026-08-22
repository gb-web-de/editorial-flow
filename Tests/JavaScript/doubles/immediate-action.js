/*
 * Stands in for @typo3/backend/action-button/immediate-action.js. Core's version
 * wraps a callback so a notification link can run it; the only thing a test
 * needs is to be able to run it.
 */
export default class ImmediateAction {
  constructor(callback) {
    this.callback = callback
  }

  execute() {
    return Promise.resolve(this.callback())
  }
}
