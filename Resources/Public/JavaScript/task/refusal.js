/*
 * Rejections that name a way out of themselves.
 *
 * Several of the server's refusals are correct and still leave an editor with
 * nowhere to go - a task with nothing pending cannot change stage, cannot go
 * back to a planning column and cannot be published. Each answer is right, and
 * together they are a dead end.
 *
 * So a rejection may carry a `resolution` (see TaskActionResolution): a stable
 * `action` identifier, an editor-facing `label`, and whatever the client needs
 * to carry it out. This turns it into a notification with a link.
 *
 * Its own module rather than a method on the board, because board.js is 800
 * lines of wiring and untested, the offer has to be reachable from the ticket
 * and the page module too, and fifteen lines are worth testing on their own.
 */
import Notification from '@typo3/backend/notification.js';
import ImmediateAction from '@typo3/backend/action-button/immediate-action.js';
import labels from '~labels/editorial_flow.messages';

const NOTIFICATION_TITLE = 'Editorial Flow';

/**
 * Report a refusal, with its way out attached where the server offered one.
 *
 * Deliberately a warning, not an error: with an offer present this is a fork in
 * the road, not a failure. And deliberately sticky - a five-second offer is not
 * an offer, so anything carrying an action stays until the editor dismisses it.
 *
 * @param {object|null} result   the decoded server answer
 * @param {string} fallback      what to say when the answer carried no message
 * @param {object} [options]     `board` for the live region, `openCloseDialog`
 *                               to avoid a circular import from close.js
 */
export function notifyRefusal(result, fallback, options = {}) {
  const message = result?.message || fallback;
  const resolution = result?.resolution ?? null;
  const actions = [];

  if (resolution?.action === 'close-task' && typeof options.openCloseDialog === 'function') {
    actions.push({
      label: resolution.label || labels.get('close.trigger'),
      action: new ImmediateAction(() => options.openCloseDialog(
        parseInt(resolution.taskUid ?? 0, 10),
        options.taskTitle ?? '',
      )),
    });
  }

  if (actions.length > 0) {
    Notification.warning(NOTIFICATION_TITLE, message, 0, actions);
  } else {
    Notification.error(NOTIFICATION_TITLE, message);
  }

  // The notification is not wired to the live region, so a screen reader user
  // would otherwise learn nothing at all about a refused action.
  options.board?.announce(message);
}
