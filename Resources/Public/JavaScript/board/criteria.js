/*
 * A stage's acceptance criteria, asked at the moment they are about: in the
 * dialog that sends the task on to the next stage.
 *
 * Its own module rather than two more methods on the board class, for the
 * reason board.js's own header gives - that file wires things together, each
 * behaviour lives in a small module beside this one. It also keeps these two
 * testable without standing in a whole backend: nothing here reaches for the
 * board, only for a form node and the routes.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import labels from '~labels/editorial_flow.messages';

const NOTIFICATION_TITLE = 'Editorial Flow';

/*
 * Append the criteria of the stage being LEFT, as native checkboxes.
 *
 * Each box writes straight to the server on change rather than travelling with
 * the transition. Two reasons: a confirmation is a fact about the review, so it
 * survives a cancelled dialog; and it is recorded with the moment and the person
 * who gave it, rather than backdated to whoever eventually pressed OK.
 *
 * A fieldset with a legend and plain checkboxes - the grouping a screen reader
 * announces and the keyboard operation both come from the markup, so there is no
 * key handling of ours to get wrong.
 */
export function appendAcceptanceCriteria(form, criteria, taskUid) {
  if (!Array.isArray(criteria) || criteria.length === 0) {
    return;
  }

  const document = form.ownerDocument;
  const section = document.createElement('fieldset');
  section.className = 'editorialflow-stage-criteria';

  const legend = document.createElement('legend');
  legend.className = 'form-label';
  legend.textContent = labels.get('criteria.dialog.heading');
  section.append(legend);

  for (const item of criteria) {
    section.append(buildCriterion(document, item, taskUid));
  }

  const hint = document.createElement('div');
  hint.className = 'form-text';
  hint.textContent = labels.get('criteria.dialog.hint');
  section.append(hint);

  form.append(section);
}

function buildCriterion(document, item, taskUid) {
  const wrapper = document.createElement('div');
  wrapper.className = 'form-check';

  const checkbox = document.createElement('input');
  checkbox.type = 'checkbox';
  checkbox.className = 'form-check-input';
  checkbox.id = `editorialflow-criterion-${item.uid}`;
  checkbox.checked = item.completed === true;
  checkbox.dataset.editorialflowCriterion = String(item.uid);

  const label = document.createElement('label');
  label.className = 'form-check-label';
  label.htmlFor = checkbox.id;
  label.textContent = item.title;

  checkbox.addEventListener('change', async () => {
    const confirmed = checkbox.checked;
    checkbox.disabled = true;
    try {
      // JSON, explicitly. Left to AjaxRequest's default the body is form-encoded
      // and `false` arrives at the server as the string "false" - see
      // TaskAjaxController::checklistToggleAction() for what that cost.
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.editorialflow_checklist_toggle)
        .post(
          { task: taskUid, itemUid: item.uid, completed: confirmed },
          { headers: { 'Content-Type': 'application/json; charset=utf-8' } },
        );
      const result = await response.resolve();
      if (result.success !== true) {
        throw new Error(result.message);
      }
    } catch (error) {
      // Put the box back where the server still has it: a confirmation that was
      // not recorded must not look like one that was.
      checkbox.checked = !confirmed;
      Notification.error(NOTIFICATION_TITLE, error?.message || labels.get('criteria.dialog.error'));
    } finally {
      checkbox.disabled = false;
    }
  });

  wrapper.append(checkbox, label);

  return wrapper;
}

/*
 * "These are still open - send anyway?", raised only because the server asked
 * (executeStageAction()'s `checklist-incomplete` answer).
 *
 * Resolves true when the editor confirms. A modal rather than a toast because
 * the confirmation is what turns an unanswered criterion into an acknowledged
 * one, and that answer is written into the task's history either way - a
 * notification can be missed, this cannot.
 */
export function confirmIncompleteCriteria(result) {
  const unconfirmed = Array.isArray(result.unconfirmed) ? result.unconfirmed : [];

  return new Promise((resolve) => {
    const content = document.createElement('div');

    const message = document.createElement('p');
    message.textContent = result.message || labels.get('criteria.confirm.title');
    content.append(message);

    if (unconfirmed.length > 0) {
      const list = document.createElement('ul');
      list.className = 'editorialflow-criteria-unconfirmed';
      for (const title of unconfirmed) {
        const entry = document.createElement('li');
        entry.textContent = title;
        list.append(entry);
      }
      content.append(list);
    }

    let confirmed = false;
    const modal = Modal.advanced({
      type: Modal.types.default,
      title: labels.get('criteria.confirm.title'),
      content,
      severity: SeverityEnum.warning,
      staticBackdrop: true,
      buttons: [
        {
          text: labels.get('criteria.confirm.back'),
          active: true,
          btnClass: 'btn-default',
          name: 'back',
          trigger: (event, currentModal) => currentModal.hideModal(),
        },
        {
          text: labels.get('criteria.confirm.send'),
          btnClass: 'btn-warning',
          name: 'send',
          trigger: (event, currentModal) => {
            confirmed = true;
            currentModal.hideModal();
          },
        },
      ],
    });

    // Resolved on hide rather than inside each trigger, so closing with Escape
    // or the backdrop counts as "no" instead of leaving the caller waiting
    // forever on a promise nothing will settle.
    modal.addEventListener('typo3-modal-hidden', () => resolve(confirmed), { once: true });
  });
}
