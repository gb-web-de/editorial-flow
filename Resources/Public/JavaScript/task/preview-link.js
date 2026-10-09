/*
 * "Preview link": the Workspaces module's shareable preview links and QR code,
 * for a task - its subject, in every language a page exists in - or for one
 * record the task covers.
 *
 * Asked for on click, never rendered with the ticket: every link is a
 * sys_preview row that opens the draft to whoever holds it, and a ticket is
 * opened far more often than a draft is shared (see
 * TaskAjaxController::previewLinkAction()).
 *
 * The QR code is core's own <typo3-qrcode>, the element behind the Workspaces
 * module's and the page module's QR codes, with its copy and download controls.
 * It has to be defined in the top frame, where the modal renders (see
 * dom-scope.js): defined in this iframe's registry only, the element in the
 * modal would never upgrade - which is how core's own qrcode-modal-button
 * loads it, too.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import { topLevelModuleImport } from '@typo3/backend/utility/top-level-module-import.js';
import labels from '~labels/editorial_flow.messages';
import { topDocument } from '@gb-web/editorial-flow/dom-scope.js';

const NOTIFICATION_TITLE = 'Editorial Flow';

/**
 * Delegated from the top document only: both buttons live in the ticket, which
 * core's Modal renders there (same reasoning as member-actions.js).
 */
export function registerPreviewLinks() {
  topDocument().addEventListener('click', (event) => {
    const button = event.target.closest('[data-editorialflow-preview-link]');
    if (button === null) {
      return;
    }
    event.preventDefault();
    openPreviewLink({
      task: parseInt(button.dataset.editorialflowPreviewLink || '0', 10),
      table: button.dataset.table || '',
      uid: parseInt(button.dataset.uid || '0', 10),
      title: button.dataset.title || '',
    });
  });
}

/**
 * @param {{task: number, table?: string, uid?: number, title?: string}} target
 *   no table means the task's own subject
 */
export async function openPreviewLink({ task, table = '', uid = 0, title = '' }) {
  const payload = table === '' ? { task } : { task, table, uid };
  const result = await request(payload);
  if (result === null) {
    return;
  }
  if (result.success !== true || !Array.isArray(result.links) || result.links.length === 0) {
    Notification.error(NOTIFICATION_TITLE, result.message || labels.get('previewLink.error'));
    return;
  }

  try {
    await defineQrCodeElement();
  } catch {
    Notification.error(NOTIFICATION_TITLE, labels.get('previewLink.error'));
    return;
  }

  const content = buildContent(topDocument(), result);
  Modal.advanced({
    type: Modal.types.default,
    title: title !== '' ? labels.get('previewLink.dialog.title', [title]) : labels.get('previewLink.trigger'),
    content,
    size: Modal.sizes.small,
    severity: SeverityEnum.notice,
    buttons: [
      {
        text: labels.get('previewLink.open'),
        btnClass: 'btn-default',
        name: 'open',
        trigger: () => window.open(content.dataset.url, '_blank', 'noopener'),
      },
      {
        text: labels.get('previewLink.close'),
        btnClass: 'btn-primary',
        name: 'close',
        trigger: (event, modal) => modal.hideModal(),
      },
    ],
  });
}

/**
 * The notice first: a link that works without a login is not something to pass
 * on without knowing so. Then one QR code - for the language picked, where the
 * page exists in more than one.
 */
function buildContent(doc, result) {
  const wrapper = doc.createElement('div');
  wrapper.className = 'editorialflow-preview-link';

  const notice = doc.createElement('p');
  notice.className = 'text-variant';
  notice.textContent = result.expires > 0
    ? labels.get('previewLink.notice.until', [formatExpiry(doc, result.expires)])
    : labels.get('previewLink.notice');
  wrapper.append(notice);

  const qrHolder = doc.createElement('div');
  qrHolder.className = 'text-center';
  // Replaced, not updated: <typo3-qrcode> fetches its image once, when it is
  // connected, and ignores a later change of `content`.
  const show = (url) => {
    wrapper.dataset.url = url;
    const qrCode = doc.createElement('typo3-qrcode');
    qrCode.className = 'text-start';
    qrCode.setAttribute('content', url);
    qrCode.setAttribute('size', 'large');
    qrCode.setAttribute('show-url', '');
    qrCode.setAttribute('show-download', '');
    qrHolder.replaceChildren(qrCode);
  };

  if (result.links.length > 1) {
    const group = doc.createElement('div');
    group.className = 'form-group';
    const label = doc.createElement('label');
    label.className = 'form-label';
    label.htmlFor = 'editorialflow-preview-link-language';
    label.textContent = labels.get('previewLink.language');
    const select = doc.createElement('select');
    select.id = 'editorialflow-preview-link-language';
    select.className = 'form-select';
    result.links.forEach((link, index) => {
      const option = doc.createElement('option');
      option.value = String(index);
      option.textContent = link.language;
      select.append(option);
    });
    select.addEventListener('change', () => show(result.links[parseInt(select.value, 10)].url));
    group.append(label, select);
    wrapper.append(group);
  }

  wrapper.append(qrHolder);
  show(result.links[0].url);

  return wrapper;
}

function formatExpiry(doc, timestamp) {
  return new Date(timestamp * 1000).toLocaleString(doc.documentElement.lang || undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
  });
}

async function defineQrCodeElement() {
  if (window.top !== window) {
    await topLevelModuleImport('@typo3/backend/element/qrcode-element.js');
  } else {
    await import('@typo3/backend/element/qrcode-element.js');
  }
}

/*
 * A refusal arrives as a 400 whose body still carries the server's code and
 * message - read it rather than reporting a transport failure (as close.js).
 */
async function request(payload) {
  try {
    const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.editorialflow_task_preview_link).post(payload);
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
    Notification.error(NOTIFICATION_TITLE, labels.get('previewLink.error'));
    return null;
  }
}
