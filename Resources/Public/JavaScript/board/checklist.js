/*
 * Manage a stage's review checklist - owner-gated, so the gear button only
 * exists in the DOM for columns the server already decided the current editor
 * may configure (BoardColumnRegistry::canManageChecklist).
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import { topDocument } from '@gb-web/editorial-flow/dom-scope.js';

export function registerChecklistManagement() {
  // The gear button lives on the board itself (this script's own document), not
  // inside a modal - no top-document delegation needed here.
  document.addEventListener('click', async (event) => {
    const button = event.target.closest('.editorialflow-column-checklist-manage');
    if (button === null) {
      return;
    }
    const column = button.closest('.editorialflow-column');
    if (column === null) {
      return;
    }
    openManageModal(column);
  });
}

/*
 * Checking an item off in the ticket - delegated from the TOP document, same
 * reasoning as task/comment.js: the ticket's checklist arrives with a modal that
 * TYPO3.Modal renders into the top-level backend document, not this script's own
 * (see dom-scope.js).
 */
export function registerChecklistToggle() {
  topDocument().addEventListener('change', async (event) => {
    const checkbox = event.target.closest('[data-editorialflow-checklist-toggle]');
    if (checkbox === null) {
      return;
    }

    const taskUid = parseInt(checkbox.dataset.editorialflowChecklistToggle, 10);
    const itemUid = parseInt(checkbox.dataset.itemUid, 10);
    const completed = checkbox.checked;
    const previous = !completed;
    checkbox.disabled = true;

    try {
      // JSON, explicitly - same reason as board/criteria.js: form-encoded, a
      // `false` reaches the server as the string "false".
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.editorialflow_checklist_toggle)
        .post(
          { task: taskUid, itemUid, completed },
          { headers: { 'Content-Type': 'application/json; charset=utf-8' } },
        );
      const result = await response.resolve();
      if (result.success !== true) {
        Notification.error('Editorial Flow', result.message || 'Could not update the checklist.');
        checkbox.checked = previous;
        return;
      }
      checkbox.closest('.editorialflow-checklist-item')?.classList.toggle('is-completed', completed);
    } catch (error) {
      Notification.error('Editorial Flow', 'Could not reach the server.');
      checkbox.checked = previous;
    } finally {
      checkbox.disabled = false;
    }
  });
}

/*
 * Adding/removing items in the manage modal - delegated from the TOP document,
 * same reasoning as registerChecklistToggle() above. openManageModal() builds the
 * form and remove buttons before handing them to Modal.advanced(), and that hand-off
 * does not preserve listeners bound beforehand (Modal renders `content` through a
 * Lit property, which does not keep the original node's listeners intact) - a
 * listener attached directly on those elements, as this code used to do, silently
 * never fires. Binding here instead, and reading every value the handler needs back
 * out of the DOM (dataset / sibling lookups), sidesteps that entirely.
 */
export function registerChecklistManageActions() {
  topDocument().addEventListener('submit', async (event) => {
    const form = event.target.closest('.editorialflow-checklist-manage-add');
    if (form === null) {
      return;
    }
    event.preventDefault();

    const workspaceUid = parseInt(form.dataset.workspaceUid, 10);
    const stageUid = parseInt(form.dataset.stageUid, 10);
    const input = form.querySelector('input');
    const title = input.value.trim();
    if (title === '') {
      return;
    }

    const submit = form.querySelector('button[type="submit"]');
    submit.disabled = true;

    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.editorialflow_checklist_add)
        .post({ workspaceUid, stageUid, title });
      const result = await response.resolve();
      if (result.success !== true) {
        Notification.error('Editorial Flow', result.message || 'Could not add that checklist item.');
        return;
      }
      const list = form.closest('.editorialflow-checklist-manage').querySelector('.editorialflow-checklist-manage-list');
      list.querySelector('.editorialflow-empty')?.remove();
      list.appendChild(buildManageItemRow(workspaceUid, result.item));
      input.value = '';
    } catch (error) {
      Notification.error('Editorial Flow', 'Could not reach the server.');
    } finally {
      submit.disabled = false;
    }
  });

  topDocument().addEventListener('click', async (event) => {
    const removeButton = event.target.closest('.editorialflow-checklist-manage-remove');
    if (removeButton === null) {
      return;
    }

    const workspaceUid = parseInt(removeButton.dataset.workspaceUid, 10);
    const itemUid = parseInt(removeButton.dataset.itemUid, 10);
    removeButton.disabled = true;

    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.editorialflow_checklist_remove)
        .post({ workspaceUid, itemUid });
      const result = await response.resolve();
      if (result.success !== true) {
        Notification.error('Editorial Flow', result.message || 'Could not remove that checklist item.');
        removeButton.disabled = false;
        return;
      }
      const list = removeButton.closest('.editorialflow-checklist-manage-list');
      removeButton.closest('li').remove();
      if (list.children.length === 0) {
        list.appendChild(buildEmptyRow());
      }
    } catch (error) {
      Notification.error('Editorial Flow', 'Could not reach the server.');
      removeButton.disabled = false;
    }
  });
}

function buildEmptyRow() {
  const empty = document.createElement('li');
  empty.className = 'editorialflow-empty';
  empty.textContent = 'No checklist items yet.';
  return empty;
}

function buildManageItemRow(workspaceUid, item) {
  const row = document.createElement('li');
  const title = document.createElement('span');
  title.textContent = item.title;
  row.appendChild(title);

  const removeButton = document.createElement('button');
  removeButton.type = 'button';
  removeButton.className = 'btn btn-xs btn-default editorialflow-checklist-manage-remove';
  removeButton.dataset.workspaceUid = String(workspaceUid);
  removeButton.dataset.itemUid = String(item.uid);
  removeButton.textContent = 'Remove';
  row.appendChild(removeButton);

  return row;
}

function openManageModal(column) {
  const workspaceUid = parseInt(column.dataset.editorialflowWorkspace || '0', 10);
  const stageUid = parseInt(column.dataset.editorialflowStage || '0', 10);
  let items = [];
  try {
    items = JSON.parse(column.dataset.editorialflowChecklistItems || '[]');
  } catch {
    items = [];
  }

  const content = document.createElement('div');
  content.className = 'editorialflow-checklist-manage';

  const list = document.createElement('ul');
  list.className = 'editorialflow-checklist-manage-list';
  if (items.length === 0) {
    list.appendChild(buildEmptyRow());
  } else {
    items.forEach((item) => list.appendChild(buildManageItemRow(workspaceUid, item)));
  }
  content.appendChild(list);

  // No listeners bound here - registerChecklistManageActions() handles submit/click
  // via top-document delegation, since Modal.advanced() does not preserve listeners
  // bound to `content` before the hand-off (see that function's comment).
  const form = document.createElement('form');
  form.className = 'editorialflow-checklist-manage-add';
  form.dataset.workspaceUid = String(workspaceUid);
  form.dataset.stageUid = String(stageUid);
  const input = document.createElement('input');
  input.type = 'text';
  input.className = 'form-control';
  input.placeholder = 'New checklist item';
  const submit = document.createElement('button');
  submit.type = 'submit';
  submit.className = 'btn btn-primary btn-sm';
  submit.textContent = 'Add';
  form.appendChild(input);
  form.appendChild(submit);
  content.appendChild(form);

  Modal.advanced({
    title: 'Manage review checklist',
    content,
    severity: SeverityEnum.notice,
    buttons: [
      {
        text: 'Done',
        btnClass: 'btn-default',
        name: 'close',
        trigger: (event, modal) => modal.hideModal(),
      },
    ],
    callback: () => {
      // Reload once the modal closes so the board's own copy of each column's
      // checklist (used by the incomplete-items warning) is fresh.
      window.location.reload();
    },
  });
}
