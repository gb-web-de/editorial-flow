/*
 * The top-bar "Approvals" item: what waits for the current user, from every
 * workspace they belong to, with Preview and Publish right there.
 *
 * Lives in the outer backend frame, so it is available in every module. The
 * list itself is rendered server-side (ReviewInboxToolbarItem); this module
 * only refreshes it, keeps the badge in step and wires the buttons to the
 * same endpoints the board uses - which ask the same gates again.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import Viewport from '@typo3/backend/viewport.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import labels from '~labels/editorial_flow.messages';
import { confirmModal } from '@gb-web/editorial-flow/modal-confirm.js';
import { postPublish } from '@gb-web/editorial-flow/task/publish.js';

// Core derives a toolbar item's element id from its class name.
const ROOT = '#gbweb-editorialflow-backend-toolbaritems-reviewinboxtoolbaritem';
const REFRESH_INTERVAL_MS = 120 * 1000;

const format = (template, value) => String(template).replace('%1$s', value);

class ReviewInbox {
  constructor() {
    this.timer = null;
    document.addEventListener('click', (event) => this.handleClick(event));
    Viewport.Topbar.Toolbar.registerEvent(() => {
      this.updateBadge();
      this.schedule();
    });
    // A publish from the board changes what waits here as well.
    document.addEventListener('editorialflow:task-published', () => this.refresh());
  }

  menu() {
    return document.querySelector(`${ROOT} .dropdown-menu`);
  }

  schedule() {
    if (this.timer !== null) {
      clearTimeout(this.timer);
    }
    this.timer = setTimeout(() => this.refresh(), REFRESH_INTERVAL_MS);
  }

  async refresh() {
    const url = TYPO3.settings?.ajaxUrls?.editorialflow_review_inbox_render;
    const menu = this.menu();
    if (!url || menu === null) {
      return;
    }
    try {
      const response = await new AjaxRequest(url).get();
      menu.innerHTML = await response.resolve();
      this.updateBadge();
    } catch {
      // The list keeps its last state; the next refresh tries again.
    } finally {
      this.schedule();
    }
  }

  updateBadge() {
    const badge = document.querySelector(`${ROOT} [data-editorialflow-inbox-badge]`);
    const data = document.querySelector(`${ROOT} [data-editorialflow-inbox-data]`);
    if (badge === null) {
      return;
    }
    const count = parseInt(data?.dataset.editorialflowInboxCount || '0', 10);
    badge.textContent = String(count);
    badge.classList.toggle('hidden', count < 1);
  }

  handleClick(event) {
    const target = event.target instanceof Element ? event.target.closest(`${ROOT} button`) : null;
    if (target === null) {
      return;
    }
    if (target.dataset.editorialflowInboxPreview !== undefined) {
      event.preventDefault();
      this.preview(target.dataset.table || '', parseInt(target.dataset.uid || '0', 10));
    } else if (target.dataset.editorialflowInboxPublish !== undefined) {
      event.preventDefault();
      this.publish(parseInt(target.dataset.editorialflowInboxPublish, 10), target.dataset.title || '', target);
    } else if (target.dataset.editorialflowInboxBoard !== undefined) {
      event.preventDefault();
      TYPO3.ModuleMenu.App.showModule('web_editorialflow', 'id=' + parseInt(target.dataset.editorialflowInboxBoard, 10));
    }
  }

  async preview(table, uid) {
    // Opened before the request so the browser treats it as a user action,
    // not a pop-up, and pointed at the preview once the link exists.
    const previewWindow = window.open('', '_blank');
    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.editorialflow_task_preview_member)
        .post({ table, uid });
      const result = await response.resolve();
      if (result.success === true && result.url) {
        previewWindow.location.href = result.url;
        return;
      }
    } catch {
      // Reported below.
    }
    previewWindow?.close();
    Notification.warning(labels.get('inbox.title'), labels.get('inbox.previewError'));
  }

  async publish(taskUid, title, button) {
    const confirmed = await confirmModal(
      format(labels.get('inbox.confirm.title'), title),
      labels.get('inbox.confirm.text'),
      SeverityEnum.warning,
    );
    if (!confirmed) {
      return;
    }

    button.disabled = true;
    const result = await postPublish(taskUid);
    button.disabled = false;
    if (result === null) {
      return;
    }
    if (result.success !== true) {
      Notification.error(labels.get('inbox.error'), result.message || '');
      return;
    }

    Notification.success(labels.get('inbox.title'), format(labels.get('inbox.published'), title));
    await this.refresh();
    // An open board shows the card that just went live - reload it so it
    // does not offer to publish it a second time.
    if (String(Viewport.ContentContainer.getUrl?.() || '').includes('editorialflow')) {
      Viewport.ContentContainer.refresh();
    }
  }
}

export default new ReviewInbox();
