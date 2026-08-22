/*
 * Editorial Flow board - entry point.
 *
 * This file only wires things together. Each behaviour lives in its own small
 * module under board/ and task/, so a reader looking for "how does splitting a
 * record work" opens task/membership.js instead of scrolling a 600-line class.
 *
 * Everything TYPO3 core already solves is delegated to core:
 *
 *   Modal, Wizard      @typo3/backend/modal.js, multi-step-wizard.js
 *   Page picking       the element browser route (tree, live search, depth)
 *   Feedback           @typo3/backend/notification.js
 *   Workspace dialog   @typo3/workspaces/workspaces.js
 *
 * Custom code is limited to what core has no opinion about: mapping tasks onto
 * columns, drag-and-drop validation, and calls to this extension's routes.
 */
import DocumentService from '@typo3/core/document-service.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import Workspaces from '@typo3/workspaces/workspaces.js';
import { topLevelModuleImport } from '@typo3/backend/utility/top-level-module-import.js';
import labels from '~labels/editorial_flow.messages';
import workspacesLabels from '~labels/workspaces.messages';

import { registerFilters } from '@gb-web/editorial-flow/board/filters.js';
import { registerDragAndDrop } from '@gb-web/editorial-flow/board/drag-drop.js';
import { registerAssignButtons } from '@gb-web/editorial-flow/task/assign.js';
import { registerTicketButtons } from '@gb-web/editorial-flow/task/ticket.js';
import { registerCreateButton } from '@gb-web/editorial-flow/task/create-wizard.js';
import { registerCommentForm } from '@gb-web/editorial-flow/task/comment.js';
import { registerPublishButtons } from '@gb-web/editorial-flow/task/publish.js';
import { registerMemberActions } from '@gb-web/editorial-flow/task/member-actions.js';
import { registerMembershipActions } from '@gb-web/editorial-flow/task/membership.js';
import { registerConflictDiffButtons } from '@gb-web/editorial-flow/task/conflict-diff.js';
import { registerCloseActions, openCloseDialog } from '@gb-web/editorial-flow/task/close.js';
import { notifyRefusal } from '@gb-web/editorial-flow/task/refusal.js';
import { registerChecklistManagement, registerChecklistManageActions, registerChecklistToggle } from '@gb-web/editorial-flow/board/checklist.js';

/*
 * Core's own "Editing" stage (StagesService::STAGE_EDIT_ID), the one a record
 * sits in as soon as a workspace version exists.
 */
const EDITING_STAGE_UID = 0;

/*
 * A ticket planned with "Neue Seite erstellen" carries no subject yet, which
 * the card spells as `pages:0` (Index.html's data-editorialflow-record).
 */
const PENDING_PAGE_RECORD = 'pages:0';

class EditorialFlowBoard {
  constructor() {
    this.selection = new Set();
    this.workspaceUi = new Workspaces();
    DocumentService.ready().then(() => this.initialize());
  }

  initialize() {
    this.announcer = document.querySelector('.editorialflow-announcer');

    // Registered before the board check on purpose: these actions also appear in
    // the page module banner, where there is no board element at all.
    registerCreateButton(this);
    registerTicketButtons(this);
    registerAssignButtons(this);
    // Delegated from the document: the ticket form arrives with the modal.
    registerCommentForm();
    registerMemberActions();
    // Same reasoning, and the same delegation: the split/move buttons appear in
    // the ticket's member list and on the page module's element badge.
    registerMembershipActions();
    // Same delegation again: "Compare versions" appears on the page module
    // banner, the content-element badge, the board card and inside the
    // ticket modal's member list.
    registerConflictDiffButtons();
    registerChecklistToggle();
    registerChecklistManageActions();
    // Closing has to be reachable wherever a task is: the board card, the
    // ticket modal, and the page module banner.
    registerCloseActions(this);

    this.board = document.querySelector('.editorialflow-board');
    if (this.board === null) {
      return;
    }

    this.registerCardEvents();
    registerDragAndDrop(this);
    registerFilters(this);
    registerPublishButtons(this);
    registerChecklistManagement();
  }

  /**
   * Announce a change to screen readers. Every mutation goes through here - a
   * board that only communicates by moving pixels is unusable without sight.
   */
  announce(message) {
    if (this.announcer instanceof HTMLElement) {
      this.announcer.textContent = message;
    }
  }

  registerCardEvents() {
    this.board.querySelectorAll('.editorialflow-card').forEach((card) => {
      card.addEventListener('click', () => this.toggleSelection(card));
      card.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          this.toggleSelection(card);
        }
      });
    });
  }

  toggleSelection(card) {
    const id = card.dataset.editorialflowRecord;
    if (!id) {
      return;
    }
    const wasSelected = this.selection.has(id);
    if (wasSelected) {
      this.selection.delete(id);
    } else {
      this.selection.add(id);
    }
    card.classList.toggle('is-selected', !wasSelected);
    card.setAttribute('aria-selected', wasSelected ? 'false' : 'true');
    this.announce((wasSelected ? 'Deselected ' : 'Selected ') + (card.dataset.editorialflowTitle || 'task'));
  }

  canDropCardIntoColumn(card, column) {
    if (column.dataset.editorialflowAcceptsDrop === 'false') {
      return false;
    }
    // Gates the DRAGGED card, not the target column: core's own stage
    // permission is about who may act on whatever currently sits in a stage,
    // never about who may move things into one (see WORKSPACE-STAGES.md).
    // EditorialFlowController::buildBoard() stamps this from the card's own
    // current stage_uid.
    if (card.dataset.editorialflowCanAct === 'false') {
      return false;
    }

    const currentState = card.dataset.editorialflowState || '';
    const currentStage = this.parseStageUid(card.dataset.editorialflowStage);
    const currentWorkspaceUid = parseInt(card.dataset.editorialflowWorkspace || '0', 10);
    const targetState = column.dataset.editorialflowState || '';
    const targetStage = this.parseStageUid(column.dataset.editorialflowStage);

    if (targetStage !== null) {
      if (currentWorkspaceUid < 1) {
        // Editing is where any planned task starts. Pending subjects open core's
        // creation wizard; existing subjects become the active edit context.
        return targetStage === EDITING_STAGE_UID;
      }
      return currentStage !== targetStage;
    }

    if (currentWorkspaceUid > 0) {
      return false;
    }

    return currentState !== targetState;
  }

  isPendingPageCard(card) {
    return (card?.dataset.editorialflowRecord || '') === PENDING_PAGE_RECORD;
  }

  getDropRejectionMessage(card, column) {
    if (column.dataset.editorialflowAcceptsDrop === 'false') {
      return 'This column does not accept manual card drops. Going live is an explicit action.';
    }
    if (card.dataset.editorialflowCanAct === 'false') {
      return 'You are not responsible for the stage this task currently sits in, so you cannot move it. '
        + 'An administrator can add you (or your group) as a responsible person for that stage in the workspace settings.';
    }

    const currentState = card.dataset.editorialflowState || '';
    const currentWorkspaceUid = parseInt(card.dataset.editorialflowWorkspace || '0', 10);
    const targetState = column.dataset.editorialflowState || '';
    const targetStage = this.parseStageUid(column.dataset.editorialflowStage);

    if (targetStage !== null && currentWorkspaceUid < 1) {
      return 'This planned task has to enter Editing before it can move to a review stage.';
    }
    if (targetStage === null && currentWorkspaceUid > 0) {
      return 'This task already has a workspace version, so it cannot be moved back to a planning column.';
    }
    if (targetStage === null && currentState === targetState) {
      return 'This task is already in that column.';
    }
    if (targetStage !== null && this.parseStageUid(card.dataset.editorialflowStage) === targetStage) {
      return 'This task is already in that review column.';
    }

    return 'This task cannot be dropped there.';
  }

  async handleCardDrop(taskUid, column, card = null) {
    const targetState = column.dataset.editorialflowState || 'backlog';
    const targetStageUid = this.parseStageUid(column.dataset.editorialflowStage);
    const columnTitle = column.querySelector('.editorialflow-column-title')?.textContent?.trim() || targetState;
    const cardTitle = card?.dataset.editorialflowTitle || 'Task';

    if (targetStageUid !== null) {
      // A ticket that has no page yet cannot change a stage - there is nothing
      // versioned to move. Its drop into Editing means "create the page now",
      // and the server answers that with the page wizard rather than a
      // transition (TaskAjaxController::requestPageWizard()).
      const currentWorkspaceUid = parseInt(card?.dataset.editorialflowWorkspace || '0', 10);
      if (this.isPendingPageCard(card) || (currentWorkspaceUid < 1 && targetStageUid === EDITING_STAGE_UID)) {
        await this.moveTaskToColumn(taskUid, targetState, targetStageUid, columnTitle, cardTitle);
        return;
      }

      // Asked before the dialog opens, not just at submit time: a task whose
      // subject has never been touched inside this workspace has nothing to
      // send to a review stage, and the dialog would only ever come back with
      // executeStageAction()'s `no-pending-versions` rejection. Refusing here
      // matches every other drop rule in getDropRejectionMessage() instead of
      // opening a dialog that cannot succeed.
      if (await this.hasNothingPendingForStageTransition(taskUid)) {
        const message = `${cardTitle} has nothing pending in this workspace yet, so it cannot be sent to a review stage.`;
        Notification.warning('Editorial Flow', message);
        this.announce(message);
        return;
      }

      await this.openStageTransitionModal(
        taskUid,
        targetStageUid,
        columnTitle,
        cardTitle,
        card?.dataset.editorialflowActive === 'true' && targetStageUid !== EDITING_STAGE_UID,
      );
      return;
    }

    await this.moveTaskToColumn(taskUid, targetState, 0, columnTitle, cardTitle);
  }

  /*
   * True only when the server confirms there is nothing pending - any check
   * failure (missing route, network error, task already gone) falls back to
   * false so the existing dialog-and-submit path still runs and reports its
   * own, more specific error, rather than silently swallowing the drop here.
   */
  async hasNothingPendingForStageTransition(taskUid) {
    const url = TYPO3.settings.ajaxUrls.editorialflow_task_check_stage_transition;
    if (!url) {
      return false;
    }
    try {
      const result = await this.postJson(url, { task: taskUid });
      return result.success === true && result.hasPending === false;
    } catch {
      return false;
    }
  }

  async moveTaskToColumn(taskUid, targetState, targetStageUid, columnTitle, cardTitle) {
    const url = TYPO3.settings.ajaxUrls.editorialflow_task_move_stage;
    if (!url) {
      Notification.error('Editorial Flow', 'Board move is not configured.');
      return;
    }

    try {
      const result = await this.postJson(url, {
        task: taskUid,
        state: targetState,
        stageUid: targetStageUid,
      });
      if (result.success !== true) {
        // Through notifyRefusal, so a refusal that names a way out - a task
        // with nothing pending can still be closed - arrives as an offer rather
        // than as a dead end.
        notifyRefusal(result, 'Could not move the task.', {
          board: this,
          openCloseDialog,
          taskTitle: cardTitle,
        });
        return;
      }

      // The move is not finished server-side: this ticket has no page yet, and
      // TYPO3's own wizard is where one gets created. The ticket reaches
      // Editing when the page does (PendingPageClaimService).
      if (result.requiresPageWizard === true) {
        await this.openPageWizard(result, cardTitle);
        return;
      }

      if (result.requiresRecordTarget === true) {
        await this.openRecordTargetModal(result, cardTitle);
        return;
      }

      if (result.startedEditing === true && typeof result.redirectUrl === 'string' && result.redirectUrl !== '') {
        window.location.href = result.redirectUrl;
        return;
      }

      this.announce(`Moved ${cardTitle} to ${columnTitle}.`);
      Notification.success('Editorial Flow', `${cardTitle} moved to ${columnTitle}.`);
      window.location.reload();
    } catch (error) {
      Notification.error('Editorial Flow', await this.extractErrorMessage(error, 'Could not move the task.'));
    }
  }

  /*
   * TYPO3's own page-creation dialog, the same one the page tree opens - not a
   * Editorial Flow rebuild of it. Core owns the position step, the page-type step
   * and whatever fields that type requires, and its provider creates the page;
   * this extension only says where to start and then waits for the DataHandler
   * hook to link the result to the ticket.
   *
   * The import goes to the top frame for the same reason the stage dialog's
   * does: the modal is built in the parent's realm, so <typo3-backend-page-
   * wizard> has to be defined there, not in this iframe.
   */
  async openPageWizard(result, cardTitle) {
    try {
      await topLevelModuleImport('@typo3/backend/page-wizard/page-wizard.js');
      const { openPageWizardModal } = await import('@typo3/backend/page-wizard/helper/wizard-helper.js');

      // Core auto-advances from Step 1 (Position) to Step 2 (Type) when
      // configuration.positionData is present. Editorial Flow must *start* at
      // Step 1 and must not preselect the position.
      await openPageWizardModal({});
      this.announce(`Creating the page for ${cardTitle}.`);
      this.dropPageWizardClaimWhenClosed();
    } catch (error) {
      Notification.error('Editorial Flow', 'Could not open TYPO3\'s page wizard.');
      await this.cancelPageWizard();
    }
  }

  async openRecordTargetModal(result, cardTitle) {
    try {
      const targetUrl = TYPO3.settings.ajaxUrls.editorialflow_task_record_creation_targets;
      const startUrl = TYPO3.settings.ajaxUrls.editorialflow_task_start_record_creation;
      if (!targetUrl || !startUrl) {
        Notification.error('Editorial Flow', labels.get('recordTarget.error.unavailable'));
        return;
      }

      const targets = await this.postJson(targetUrl, { task: result.taskUid });
      if (targets.success !== true) {
        Notification.error('Editorial Flow', targets.message || labels.get('recordTarget.error.load'));
        return;
      }
      if (!Array.isArray(targets.pages) || targets.pages.length === 0) {
        Notification.warning('Editorial Flow', labels.get('recordTarget.empty'));
        return;
      }

      const content = document.createElement('div');
      const description = document.createElement('p');
      description.textContent = labels.get('recordTarget.description', [targets.recordTypeLabel || targets.recordTable]);
      const label = document.createElement('label');
      label.className = 'form-label';
      label.htmlFor = 'editorialflow-record-target-page';
      label.textContent = labels.get('recordTarget.page');
      const select = document.createElement('select');
      select.id = 'editorialflow-record-target-page';
      select.className = 'form-select';
      targets.pages.forEach((page) => {
        const option = document.createElement('option');
        option.value = String(page.uid);
        option.textContent = page.path || page.title;
        select.append(option);
      });
      content.append(description, label, select);

      Modal.advanced({
        type: Modal.types.default,
        title: labels.get('recordTarget.title', [cardTitle]),
        content,
        severity: SeverityEnum.notice,
        buttons: [
          {
            text: labels.get('entry.cancel'),
            btnClass: 'btn-default',
            name: 'cancel',
            trigger: (event, modal) => modal.hideModal(),
          },
          {
            text: labels.get('recordTarget.start'),
            btnClass: 'btn-primary',
            name: 'start',
            trigger: async (event, modal) => {
              event.target.disabled = true;
              try {
                const started = await this.postJson(startUrl, {
                  task: result.taskUid,
                  page: parseInt(select.value, 10),
                });
                if (started.success !== true || !started.redirectUrl) {
                  Notification.error('Editorial Flow', started.message || labels.get('recordTarget.error.start'));
                  return;
                }
                modal.hideModal();
                window.location.href = started.redirectUrl;
              } catch (error) {
                Notification.error('Editorial Flow', await this.extractErrorMessage(error, labels.get('recordTarget.error.start')));
              } finally {
                event.target.disabled = false;
              }
            },
          },
        ],
      });
    } catch (error) {
      Notification.error('Editorial Flow', await this.extractErrorMessage(error, labels.get('recordTarget.error.load')));
    }
  }

  /*
   * Core's wizard redirects to the new page on success, so a modal that merely
   * closes almost always means "cancelled". Telling the server so keeps the
   * ticket from adopting whatever page the editor creates next; if a page WAS
   * created, the claim is already spent and this call changes nothing.
   */
  dropPageWizardClaimWhenClosed() {
    const modal = top.document.querySelector('typo3-backend-modal');
    if (!modal) {
      return;
    }
    modal.addEventListener('typo3-modal-hidden', () => this.cancelPageWizard(), { once: true });
  }

  async cancelPageWizard() {
    const url = TYPO3.settings.ajaxUrls.editorialflow_task_cancel_page_wizard;
    if (!url) {
      return;
    }
    try {
      await this.postJson(url, {});
    } catch (error) {
      // The claim expires on its own - see PendingPageHandoff.
    }
  }

  async openStageTransitionModal(taskUid, targetStageUid, columnTitle, cardTitle, askToDeactivate = false) {
    try {
      const response = await this.workspaceUi.sendRemoteRequest(
        this.workspaceUi.generateRemotePayloadBody('sendToSpecificStageWindow', [targetStageUid]),
        '.editorialflow-board',
      );
      const payload = await response.resolve();

      const stageDialogData = payload?.[0]?.result;
      if (!stageDialogData || stageDialogData.success === false) {
        Notification.error('Editorial Flow', 'TYPO3 refused to open the workspace stage dialog.');
        return;
      }

      const form = this.buildStageTransitionForm(stageDialogData, askToDeactivate);
      const modal = Modal.advanced({
        type: Modal.types.default,
        title: workspacesLabels.get('actionSendToStage'),
        content: form,
        severity: SeverityEnum.info,
        staticBackdrop: true,
        buttons: [
          {
            text: workspacesLabels.get('cancel'),
            active: true,
            btnClass: 'btn-default',
            name: 'cancel',
            trigger: (event, currentModal) => currentModal.hideModal(),
          },
          {
            text: workspacesLabels.get('ok'),
            btnClass: 'btn-primary',
            name: 'ok',
            trigger: async (event, currentModal) => {
              if (currentModal.dataset.editorialflowSubmitting === '1') {
                return;
              }

              const currentForm = currentModal.querySelector('form');
              if (currentForm === null || currentForm.tagName !== 'FORM') {
                Notification.error('Editorial Flow', 'The workspace stage dialog could not be rendered.');
                return;
              }

              currentModal.dataset.editorialflowSubmitting = '1';
              event.target.disabled = true;

              try {
                const dialogValues = this.readStageTransitionForm(currentForm);
                const result = await this.postJson(TYPO3.settings.ajaxUrls.editorialflow_task_execute_stage, {
                  task: taskUid,
                  stageUid: targetStageUid,
                  comment: dialogValues.comment,
                  recipients: dialogValues.recipients,
                  additional: dialogValues.additional,
                  deactivateActiveTask: dialogValues.deactivateActiveTask,
                });
                if (result.success !== true) {
                  notifyRefusal(result, 'Could not move the task to that stage.', {
                    board: this,
                    openCloseDialog,
                    taskTitle: cardTitle,
                  });
                  return;
                }

                currentModal.hideModal();
                this.announce(`Moved ${cardTitle} to ${columnTitle}.`);
                Notification.success('Editorial Flow', `${cardTitle} moved to ${columnTitle}.`);
                // Soft warning, never a block: core already decided the move itself
                // is allowed, this only flags that the stage being left had unchecked
                // review items.
                if (result.incompleteChecklistItems > 0) {
                  Notification.warning(
                    'Editorial Flow',
                    `${result.incompleteChecklistItems} checklist item(s) were left unchecked in the previous stage.`,
                  );
                }
                window.location.reload();
              } catch (error) {
                // Every rejection this endpoint sends is about the task's own
                // state (closed, no workspace version, nothing pending, core
                // itself refusing the move) - resubmitting the same form can
                // never turn one into a success, so the dialog closes here
                // too instead of sitting open for a retry that only stacks
                // the same error toast again.
                currentModal.hideModal();
                Notification.error(
                  'Editorial Flow',
                  await this.extractErrorMessage(error, 'Could not move the task to that stage.'),
                );
              } finally {
                delete currentModal.dataset.editorialflowSubmitting;
                event.target.disabled = false;
              }
            },
          },
        ],
      });

      modal.addEventListener('typo3-modal-shown', () => {
        const comment = modal.querySelector('#comments');
        if (comment && typeof comment.focus === 'function') {
          comment.focus();
        }
      }, { once: true });
    } catch (error) {
      Notification.error(
        'Editorial Flow',
        await this.extractErrorMessage(error, 'Could not open the TYPO3 workspace dialog.'),
      );
    }
  }

  openTicket(taskUid, title) {
    const url = TYPO3.settings.ajaxUrls.editorialflow_task_ticket;
    if (!url) {
      Notification.error('Editorial Flow', 'Ticket view is not available.');
      return;
    }

    this.announce('Opened task ' + title);
    Modal.advanced({
      type: Modal.types.ajax,
      title,
      content: url + '&task=' + encodeURIComponent(taskUid),
      size: Modal.sizes.large,
      severity: SeverityEnum.notice,
    });
  }

  async postJson(url, payload) {
    const response = await new AjaxRequest(url).post(payload, {
      headers: {
        'Content-Type': 'application/json; charset=utf-8',
      },
    });

    return response.resolve();
  }

  async extractErrorMessage(error, fallback) {
    if (error && typeof error.resolve === 'function') {
      try {
        const payload = await error.resolve();
        if (payload?.message) {
          return payload.message;
        }
      } catch {
        // Ignore follow-up parse errors and keep the fallback below.
      }
    }

    return fallback;
  }

  readStageTransitionForm(form) {
    const formData = new FormData(form);
    const recipients = formData
      .getAll('recipients')
      .map((value) => parseInt(String(value), 10))
      .filter((value) => Number.isInteger(value) && value > 0);

    return {
      comment: String(formData.get('comments') || '').trim(),
      additional: String(formData.get('additional') || '').trim(),
      recipients,
      deactivateActiveTask: formData.get('deactivateActiveTask') === '1',
    };
  }

  appendActiveTaskChoice(form) {
    const section = form.ownerDocument.createElement('fieldset');
    section.className = 'editorialflow-stage-active-choice';

    const wrapper = form.ownerDocument.createElement('div');
    wrapper.className = 'form-check';
    const checkbox = form.ownerDocument.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.className = 'form-check-input';
    checkbox.id = 'editorialflow-deactivate-active-task';
    checkbox.name = 'deactivateActiveTask';
    checkbox.value = '1';
    checkbox.checked = true;
    const label = form.ownerDocument.createElement('label');
    label.className = 'form-check-label';
    label.htmlFor = checkbox.id;
    label.textContent = labels.get('stage.deactivate.label');
    wrapper.append(checkbox, label);

    const hint = form.ownerDocument.createElement('div');
    hint.className = 'form-text';
    hint.textContent = labels.get('stage.deactivate.hint');
    section.append(wrapper, hint);
    form.append(section);
  }

  buildStageTransitionForm(stageDialogData, askToDeactivate) {
    const wrapper = document.createElement('div');

    const form = document.createElement('form');
    form.className = 'editorialflow-stage-dialog';
    wrapper.append(form);

    if (Array.isArray(stageDialogData.sendMailTo) && stageDialogData.sendMailTo.length > 0) {
      const details = document.createElement('details');
      details.className = 'editorialflow-stage-notify';

      const summary = document.createElement('summary');
      summary.className = 'editorialflow-stage-notify-summary';
      details.append(summary);

      const recipientSection = document.createElement('div');
      recipientSection.className = 'editorialflow-stage-notify-body';

      const controls = document.createElement('div');
      controls.className = 'form-group';

      const selectAll = document.createElement('button');
      selectAll.type = 'button';
      selectAll.className = 'btn btn-default btn-xs';
      selectAll.textContent = workspacesLabels.get('window.sendToNextStageWindow.selectAll');

      const uncheckAll = document.createElement('button');
      uncheckAll.type = 'button';
      uncheckAll.className = 'btn btn-default btn-xs';
      uncheckAll.textContent = workspacesLabels.get('window.sendToNextStageWindow.deselectAll');

      controls.append(selectAll, document.createTextNode(' '), uncheckAll);

      const list = document.createElement('div');
      list.className = 'form-group';

      stageDialogData.sendMailTo.forEach((recipient) => {
        const row = document.createElement('div');
        row.className = 'form-check';

        const input = document.createElement('input');
        input.type = 'checkbox';
        input.className = 'form-check-input';
        input.name = 'recipients';
        input.value = String(recipient.value);
        input.id = String(recipient.name);
        input.checked = recipient.checked === true;
        input.disabled = recipient.disabled === true;

        const label = document.createElement('label');
        label.className = 'form-check-label';
        label.htmlFor = input.id;
        label.textContent = String(recipient.label || recipient.value);

        row.append(input, label);
        list.append(row);
      });

      const updateSummary = () => {
        const enabled = Array.from(list.querySelectorAll('input[name="recipients"]'))
          .filter((input) => !input.disabled);
        const checked = enabled.filter((input) => input.checked);
        summary.textContent = `${workspacesLabels.get('window.sendToNextStageWindow.itemsWillBeSentTo')} (${checked.length}/${enabled.length})`;
      };

      const toggleAll = (checked) => {
        list.querySelectorAll('input[name="recipients"]').forEach((input) => {
          if (input.disabled) {
            return;
          }
          input.checked = checked;
        });
        updateSummary();
      };

      selectAll.addEventListener('click', () => toggleAll(true));
      uncheckAll.addEventListener('click', () => toggleAll(false));
      list.addEventListener('change', updateSummary);
      updateSummary();

      recipientSection.append(controls, list);

      if (stageDialogData.additional !== undefined) {
        const additionalGroup = document.createElement('div');
        additionalGroup.className = 'form-group';

        const additionalLabel = document.createElement('label');
        additionalLabel.className = 'form-label';
        additionalLabel.htmlFor = 'additional';
        additionalLabel.textContent = workspacesLabels.get('window.sendToNextStageWindow.additionalRecipients');

        const additional = document.createElement('textarea');
        additional.className = 'form-control';
        additional.name = 'additional';
        additional.id = 'additional';
        additional.value = String(stageDialogData.additional?.value || '');

        const additionalHint = document.createElement('div');
        additionalHint.className = 'form-text';
        additionalHint.textContent = workspacesLabels.get('window.sendToNextStageWindow.additionalRecipients.hint');

        additionalGroup.append(additionalLabel, additional, additionalHint);
        recipientSection.append(additionalGroup);
      }

      details.append(recipientSection);
      form.append(details);
    } else if (stageDialogData.additional !== undefined) {
      const additionalGroup = document.createElement('div');
      additionalGroup.className = 'form-group';

      const additionalLabel = document.createElement('label');
      additionalLabel.className = 'form-label';
      additionalLabel.htmlFor = 'additional';
      additionalLabel.textContent = workspacesLabels.get('window.sendToNextStageWindow.additionalRecipients');

      const additional = document.createElement('textarea');
      additional.className = 'form-control';
      additional.name = 'additional';
      additional.id = 'additional';
      additional.value = String(stageDialogData.additional?.value || '');

      const additionalHint = document.createElement('div');
      additionalHint.className = 'form-text';
      additionalHint.textContent = workspacesLabels.get('window.sendToNextStageWindow.additionalRecipients.hint');

      additionalGroup.append(additionalLabel, additional, additionalHint);
      form.append(additionalGroup);
    }

    const commentGroup = document.createElement('div');
    commentGroup.className = 'form-group';

    const commentLabel = document.createElement('label');
    commentLabel.className = 'form-label';
    commentLabel.htmlFor = 'comments';
    commentLabel.textContent = workspacesLabels.get('window.sendToNextStageWindow.comments');

    const comment = document.createElement('textarea');
    comment.className = 'form-control';
    comment.name = 'comments';
    comment.id = 'comments';
    comment.value = String(stageDialogData.comments?.value || '');

    commentGroup.append(commentLabel, comment);
    form.append(commentGroup);

    if (askToDeactivate) {
      this.appendActiveTaskChoice(form);
    }

    return wrapper;
  }

  parseStageUid(rawValue) {
    if (rawValue === undefined || rawValue === null || rawValue === '') {
      return null;
    }

    const value = parseInt(rawValue, 10);
    return Number.isNaN(value) ? null : value;
  }
}

export default new EditorialFlowBoard();
