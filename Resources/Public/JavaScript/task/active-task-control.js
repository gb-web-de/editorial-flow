import DocumentService from '@typo3/core/document-service.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import labels from '~labels/editorial_flow.messages';

class ActiveTaskControl {
  constructor() {
    this.controls = [];
    DocumentService.ready().then(() => this.initialize());
  }

  initialize() {
    this.controls = [...document.querySelectorAll('[data-editorialflow-active-control]')];
    this.registerActions();
    this.reloadControls();

    const eventDocument = window.top?.document || document;
    eventDocument.addEventListener('editorialflow:active-task-changed', () => this.reloadControls());
  }

  registerActions() {
    document.addEventListener('click', async (event) => {
      const target = event.target instanceof Element ? event.target : null;
      if (!target) {
        return;
      }

      const stopButton = target.closest('[data-editorialflow-stop-editing]');
      if (stopButton !== null) {
        event.preventDefault();
        event.stopPropagation();
        await this.setActive('', 0, 0, stopButton);
        return;
      }

      const activateButton = target.closest('[data-editorialflow-set-active]');
      if (activateButton === null) {
        return;
      }
      event.preventDefault();
      event.stopPropagation();
      await this.setActive(
        activateButton.dataset.editorialflowContextTable || '',
        parseInt(activateButton.dataset.editorialflowContextUid || '0', 10),
        parseInt(activateButton.dataset.editorialflowSetActive || '0', 10),
        activateButton,
      );
    });
  }

  async reloadControls() {
    await Promise.all(this.controls.map((control) => this.reloadControl(control)));
  }

  async reloadControl(control) {
    const url = TYPO3.settings?.ajaxUrls?.editorialflow_task_active_context;
    if (!url) {
      return;
    }

    const table = control.dataset.editorialflowContextTable || '';
    const uid = parseInt(control.dataset.editorialflowContextUid || '0', 10);
    try {
      const request = new AjaxRequest(url);
      if (table !== '' && uid > 0) {
        request.withQueryArguments({ table, uid });
      }
      const response = await request.get();
      const result = await response.resolve();
      if (result.success !== true) {
        return;
      }
      this.render(control, result.activeTask || null, Array.isArray(result.tasks) ? result.tasks : []);
    } catch {
      // The docheader remains usable even if Editorial Flow is temporarily unavailable.
    }
  }

  render(control, activeTask, tasks) {
    control.replaceChildren();
    const table = control.dataset.editorialflowContextTable || '';
    const uid = parseInt(control.dataset.editorialflowContextUid || '0', 10);

    if (table !== '' && uid > 0) {
      const select = document.createElement('select');
      select.className = 'form-select form-select-sm editorialflow-active-control-select';
      select.setAttribute('aria-label', labels.get('ve.select.label'));

      const none = document.createElement('option');
      none.value = '0';
      none.textContent = labels.get('active.select.none');
      select.append(none);

      tasks.forEach((task) => {
        const option = document.createElement('option');
        option.value = String(task.uid);
        option.textContent = `${task.title} (${task.stageLabel})`;
        select.append(option);
      });

      if (activeTask && tasks.some((task) => task.uid === activeTask.uid)) {
        select.value = String(activeTask.uid);
      }
      select.addEventListener('change', () => {
        this.setActive(table, uid, parseInt(select.value, 10), select);
      });
      control.append(select);
    }

    if (activeTask) {
      const status = document.createElement('span');
      status.className = 'editorialflow-active-control-status';
      status.style.setProperty('--editorialflow-task-hue', String(activeTask.hue));
      status.title = activeTask.stageLabel;
      status.innerHTML = '<span class="editorialflow-task-dot" aria-hidden="true"></span>';
      const title = document.createElement('span');
      title.textContent = activeTask.title;
      status.append(title);
      control.append(status);

      const stop = document.createElement('button');
      stop.type = 'button';
      stop.className = 'btn btn-sm btn-default';
      stop.dataset.editorialflowStopEditing = '1';
      stop.textContent = labels.get('active.stop');
      control.append(stop);
    } else if (table === '' || uid < 1) {
      const empty = document.createElement('span');
      empty.className = 'editorialflow-active-control-empty';
      empty.textContent = labels.get('active.select.none');
      control.append(empty);
    }
  }

  async setActive(table, uid, taskUid, source) {
    const url = TYPO3.settings?.ajaxUrls?.editorialflow_task_set_active_context;
    if (!url || Number.isNaN(taskUid)) {
      return;
    }

    source.disabled = true;
    try {
      const response = await new AjaxRequest(url).post({ table, uid, taskUid });
      const result = await response.resolve();
      if (result.success !== true) {
        Notification.error('Editorial Flow', result.message || labels.get('ve.error.selectFailed'));
        return;
      }

      Notification.success(
        'Editorial Flow',
        taskUid === 0 ? labels.get('active.notification.stopped') : labels.get('active.notification.changed'),
      );
      const eventDocument = window.top?.document || document;
      eventDocument.dispatchEvent(new eventDocument.defaultView.CustomEvent('editorialflow:active-task-changed', {
        detail: { activeTask: result.activeTask || null },
      }));

      if (result.workspaceSwitched === true) {
        // The task lives in another workspace and the server just switched the
        // user into it. The whole backend has to follow - header indicator,
        // page tree, every open module - not just this frame.
        (window.top || window).location.reload();
      } else if (source.dataset.editorialflowReload === 'true') {
        window.location.reload();
      } else {
        await this.reloadControls();
      }
    } catch (error) {
      Notification.error('Editorial Flow', await this.errorMessage(error));
    } finally {
      source.disabled = false;
    }
  }

  async errorMessage(error) {
    if (error && typeof error.resolve === 'function') {
      try {
        const payload = await error.resolve();
        if (payload?.message) {
          return payload.message;
        }
      } catch {
        // Fall through to the translated generic message.
      }
    }
    return labels.get('ve.error.server');
  }
}

export default new ActiveTaskControl();
