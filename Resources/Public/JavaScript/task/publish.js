/*
 * Publishing - the action behind the "Done" column's confirmation.
 *
 * Deliberately not a drop target (ARCHITECTURE.md: going live is irreversible,
 * so it is an explicit action, never something an editor can trigger by
 * dropping a card slightly off target). This is that explicit action.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import { confirmModal } from '@gb-web/editorial-flow/modal-confirm.js';
import { notifyRefusal } from '@gb-web/editorial-flow/task/refusal.js';
import { openCloseDialog } from '@gb-web/editorial-flow/task/close.js';

export function registerPublishButtons(board) {
  // No permission check here any more. The view renders a Publish button only
  // for cards TaskPublishGate cleared, so every button that exists is one this
  // user may press - there is no "disabled publish" state left to represent.
  // The endpoint asks the same gate again regardless: this is a rendering
  // decision, never the access control.
  document.querySelectorAll('.editorialflow-action-publish').forEach((btn) => {
    btn.addEventListener('click', async (event) => {
      event.stopPropagation();
      const taskUid = parseInt(btn.dataset.taskUid || '0', 10);
      const taskTitle = btn.dataset.taskTitle || 'this task';
      if (!taskUid) {
        return;
      }

      const confirmed = await confirmModal(
        'Publish "' + taskTitle + '"',
        'This makes every pending change in this task live immediately. This cannot be undone. Continue?',
        SeverityEnum.warning,
      );
      if (!confirmed) {
        return;
      }

      const result = await postPublish(taskUid);
      if (result === null) {
        // Transport failure - already reported by postPublish().
        return;
      }
      if (result.success !== true) {
        // A publish refused with `no-pending-versions` is the classic dead end:
        // nothing to publish, no column that accepts the card, and until closing
        // existed nothing to suggest. notifyRefusal renders the server's offer.
        notifyRefusal(result, 'Could not publish that task.', {
          board,
          openCloseDialog,
          taskTitle,
        });
        return;
      }
      board?.announce(taskTitle + ' published.');
      Notification.success('Editorial Flow', taskTitle + ' published.' + (result.closed ? ' Task closed.' : ''));
      window.location.reload();
    });
  });
}

/**
 * One request, with the server's own rejection kept intact.
 *
 * AjaxRequest THROWS on any non-2xx answer - and every rejection
 * TaskAjaxController::publishTaskAction() raises is a 400 carrying the code
 * and message an editor is meant to read (see TaskAjaxController::error()).
 * A bare `catch` would replace e.g. "This record is still pending review in
 * ..." with a generic "Server error while publishing.", which is both wrong
 * and unactionable. What is thrown is an AjaxResponse, so the body is still
 * there to be resolved - same pattern as membership.js's request().
 *
 * @returns {object|null} the decoded answer, or null once a genuine transport
 *          failure has been reported - the caller only handles answers it got.
 */
async function postPublish(taskUid) {
  try {
    const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.editorialflow_task_publish)
      .post({ task: taskUid });
    return await response.resolve();
  } catch (error) {
    if (typeof error?.resolve === 'function') {
      try {
        const body = await error.resolve();
        if (body !== null && typeof body === 'object') {
          return body;
        }
      } catch {
        // Not a JSON body after all - fall through to the transport message.
      }
    }
    Notification.error('Editorial Flow', 'Server error while publishing.');
    return null;
  }
}
