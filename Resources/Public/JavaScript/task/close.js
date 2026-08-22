/*
 * Finishing a task.
 *
 * Closing is the one action that has to work on every task, in every state -
 * see TaskAjaxController::closeTaskAction(). A task whose versions were
 * discarded has nothing left to publish and no column that will accept it, and
 * before this existed it stayed on the board forever.
 *
 * The dialog asks the server first (close-preview) rather than guessing from
 * the card, because what is at stake is not on the card: which records still
 * carry unpublished changes, which fields those are, and whether discarding is
 * even possible from the workspace the editor is currently in.
 *
 * Two layers, same reason membership.js has two: registerCloseActions()
 * delegates from the document for markup that only carries data attributes,
 * while openCloseDialog() is called directly - by refusal.js, when a refused
 * action offers closing as the way out.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import { topDocument } from '@gb-web/editorial-flow/dom-scope.js';
import labels from '~labels/editorial_flow.messages';

const NOTIFICATION_TITLE = 'Editorial Flow';

/**
 * Delegated from both documents: the board runs in the content iframe, the
 * ticket renders into the backend chrome, and a listener on one never sees a
 * click in the other.
 */
export function registerCloseActions(board) {
  const handler = (event) => {
    const trigger = event.target.closest('[data-editorialflow-close]');
    if (trigger === null) {
      return;
    }
    event.preventDefault();
    event.stopPropagation();
    openCloseDialog(
      parseInt(trigger.dataset.editorialflowClose || '0', 10),
      trigger.dataset.taskTitle || '',
      { board },
    );
  };

  document.addEventListener('click', handler);
  const top = topDocument();
  if (top !== document) {
    top.addEventListener('click', handler);
  }
}

export async function openCloseDialog(taskUid, taskTitle, options = {}) {
  if (!taskUid) {
    return;
  }

  const preview = await request(() => new AjaxRequest(
    TYPO3.settings.ajaxUrls.editorialflow_task_close_preview,
  ).withQueryArguments({ task: taskUid }).get(), 'close.error.preview');
  if (preview === null) {
    return;
  }
  if (preview.success !== true) {
    Notification.error(NOTIFICATION_TITLE, preview.message || labels.get('close.error.preview'));
    return;
  }

  const title = taskTitle || preview.task?.title || '';
  const doc = topDocument();
  const form = buildCloseForm(doc, preview, title);

  Modal.advanced({
    type: Modal.types.default,
    title: labels.get('close.dialog.title', [title]),
    content: form,
    // notice, not warning: with nothing pending this is an ordinary finish, and
    // the destructive choice carries its own warning styling inside the form.
    severity: preview.pending.length > 0 ? SeverityEnum.warning : SeverityEnum.notice,
    staticBackdrop: true,
    buttons: [
      {
        text: labels.get('close.dialog.cancel'),
        active: true,
        btnClass: 'btn-default',
        name: 'cancel',
        trigger: (event, modal) => modal.hideModal(),
      },
      {
        text: labels.get('close.dialog.confirm'),
        btnClass: 'btn-primary',
        name: 'close',
        trigger: async (event, modal) => {
          // Double submission would attach the records twice and then close a
          // task that is already closed - same guard board.js uses on the stage
          // dialog.
          if (modal.dataset.editorialflowSubmitting === '1') {
            return;
          }
          modal.dataset.editorialflowSubmitting = '1';
          event.target.disabled = true;

          try {
            await submitClose(modal, taskUid, title, preview, options);
          } finally {
            delete modal.dataset.editorialflowSubmitting;
            event.target.disabled = false;
          }
        },
      },
    ],
  });
}

/**
 * The handover runs BEFORE the close, and that order is not cosmetic: close()
 * marks the member rows closed, and moveMemberToTask() only ever moves open
 * ones - so handing over afterwards would silently update nothing.
 *
 * Each half reports its own failure. If the handover is refused the task stays
 * open, which is the honest outcome: the editor asked for the records to go
 * somewhere, and they did not.
 */
async function submitClose(modal, taskUid, title, preview, options) {
  const choice = modal.querySelector('input[name="editorialflow-close-mode"]:checked')?.value ?? 'keep';

  if (choice === 'handover') {
    const targetUid = parseInt(modal.querySelector('[name="editorialflow-close-target"]')?.value || '0', 10);
    if (!targetUid) {
      Notification.error(NOTIFICATION_TITLE, labels.get('close.dialog.handoverNone'));
      return;
    }
    const handed = await request(() => new AjaxRequest(
      TYPO3.settings.ajaxUrls.editorialflow_task_attach,
    ).post({
      task: targetUid,
      records: preview.pending.map((record) => ({ table: record.table, uid: record.uid })),
    }), 'close.error.server');
    if (handed === null) {
      return;
    }
    // attachAction() reports per record, so an empty `refused` list is the only
    // proof every one of them actually moved.
    const refused = Array.isArray(handed.refused) ? handed.refused : [];
    if (handed.success !== true || refused.length > 0) {
      Notification.error(NOTIFICATION_TITLE, refused[0]?.message || handed.message || labels.get('close.error.failed'));
      return;
    }
  }

  const result = await request(() => new AjaxRequest(
    TYPO3.settings.ajaxUrls.editorialflow_task_close,
  ).post({ task: taskUid, mode: choice === 'discard' ? 'discard' : 'keep' }), 'close.error.server');
  if (result === null) {
    return;
  }
  if (result.success !== true) {
    Notification.error(NOTIFICATION_TITLE, result.message || labels.get('close.error.failed'));
    return;
  }

  modal.hideModal();

  const message = result.discarded > 0
    ? labels.get('close.success.discarded', [title, result.discarded])
    : labels.get('close.success', [title]);
  options.board?.announce(message);
  Notification.success(NOTIFICATION_TITLE, message);
  window.location.reload();
}

/**
 * A radio group, not three buttons.
 *
 * Same reasoning openMoveDialog() records: picking is separate from confirming,
 * so a mis-click cannot act on its own - which matters more here, because one
 * of the three choices destroys unpublished work.
 *
 * With nothing pending the whole group collapses to a single sentence. One code
 * path, degraded, rather than a second dialog to keep in step.
 */
function buildCloseForm(doc, preview, title) {
  const form = doc.createElement('form');
  form.className = 'editorialflow-close-form';

  if (preview.pending.length === 0) {
    const done = doc.createElement('p');
    done.textContent = labels.get('close.dialog.nothingPending');
    form.appendChild(done);
    return form;
  }

  const intro = doc.createElement('p');
  intro.textContent = labels.get('close.dialog.pendingIntro', [
    String(preview.pending.length),
    preview.task?.workspaceTitle ?? '',
  ]);
  form.appendChild(intro);

  const list = doc.createElement('ul');
  list.className = 'editorialflow-close-pending';
  preview.pending.forEach((record) => {
    const item = doc.createElement('li');
    const name = doc.createElement('strong');
    name.textContent = record.title;
    item.appendChild(name);

    // The changed fields by name. "This task has unpublished changes" is not
    // something an editor can weigh a discard against.
    const changes = record.changes.slice();
    if (record.changeCount > changes.length) {
      changes.push(labels.get('close.dialog.moreChanges', [String(record.changeCount - changes.length)]));
    }
    if (changes.length > 0) {
      const detail = doc.createElement('span');
      detail.className = 'editorialflow-close-changes';
      detail.textContent = ' — ' + changes.join(', ');
      item.appendChild(detail);
    }
    list.appendChild(item);
  });
  form.appendChild(list);

  const fieldset = doc.createElement('fieldset');
  fieldset.className = 'editorialflow-close-choices';
  const legend = doc.createElement('legend');
  legend.textContent = labels.get('close.dialog.legend');
  fieldset.appendChild(legend);

  fieldset.appendChild(choice(doc, 'keep', labels.get('close.dialog.keep'), {
    checked: true,
    hint: labels.get('close.dialog.keepHint'),
  }));

  const targets = preview.handoverTargets ?? [];
  const handover = choice(doc, 'handover', labels.get('close.dialog.handover'), {
    disabled: targets.length === 0,
    hint: targets.length === 0 ? labels.get('close.dialog.handoverNone') : '',
  });
  if (targets.length > 0) {
    const select = doc.createElement('select');
    select.className = 'form-select form-select-sm';
    select.name = 'editorialflow-close-target';
    select.setAttribute('aria-label', labels.get('close.dialog.handover'));
    targets.forEach((target) => {
      const option = doc.createElement('option');
      option.value = String(target.uid);
      option.textContent = target.stageLabel ? target.title + ' (' + target.stageLabel + ')' : target.title;
      select.appendChild(option);
    });
    handover.appendChild(select);
  }
  fieldset.appendChild(handover);

  fieldset.appendChild(choice(doc, 'discard', labels.get('close.dialog.discard'), {
    disabled: preview.canDiscard !== true,
    danger: true,
    // Never colour alone: the warning is words, and the radio is described by
    // it so a screen reader reaches it without hunting.
    hint: preview.canDiscard === true
      ? labels.get('close.dialog.discardWarning')
      : preview.discardBlockedReason,
  }));

  form.appendChild(fieldset);

  return form;
}

/**
 * One radio with its explanation, wired together for assistive technology.
 */
function choice(doc, value, labelText, options = {}) {
  const wrapper = doc.createElement('div');
  wrapper.className = 'form-check editorialflow-close-choice';
  if (options.danger) {
    wrapper.classList.add('editorialflow-close-choice--danger');
  }

  const id = 'editorialflow-close-' + value;
  const input = doc.createElement('input');
  input.type = 'radio';
  input.className = 'form-check-input';
  input.name = 'editorialflow-close-mode';
  input.id = id;
  input.value = value;
  input.checked = options.checked === true;
  input.disabled = options.disabled === true;

  const label = doc.createElement('label');
  label.className = 'form-check-label';
  label.htmlFor = id;
  label.textContent = labelText;

  wrapper.appendChild(input);
  wrapper.appendChild(label);

  if (options.hint) {
    const hint = doc.createElement('p');
    hint.className = 'editorialflow-close-hint';
    hint.id = id + '-hint';
    hint.textContent = options.hint;
    input.setAttribute('aria-describedby', hint.id);
    wrapper.appendChild(hint);
  }

  return wrapper;
}

/**
 * One request, with the server's own rejection kept intact.
 *
 * Same pattern as membership.js and publish.js: AjaxRequest throws on any
 * non-2xx, and every rejection this controller raises is a 400 carrying the
 * message an editor is meant to read. A bare catch would replace "switch to
 * workspace Editorial to discard these changes" with a generic transport error.
 */
async function request(send, fallbackLabel) {
  try {
    const response = await send();
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
    Notification.error(NOTIFICATION_TITLE, labels.get(fallbackLabel));
    return null;
  }
}
