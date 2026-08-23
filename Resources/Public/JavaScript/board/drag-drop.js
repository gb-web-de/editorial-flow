/*
 * Column drag and drop.
 *
 * A drop only ever PROPOSES a move - the server hands core stage columns to
 * TYPO3's DataHandler, which decides. Nothing here is drag-only: the same moves
 * are reachable from the keyboard and from the ticket view.
 */
import Notification from '@typo3/backend/notification.js';

export function registerDragAndDrop(board) {
  let draggedCard = null;

  const clearDropTargetStyles = () => {
    board.board.querySelectorAll('.editorialflow-column').forEach((column) => {
      column.classList.remove('is-drop-target-valid', 'is-drop-target-invalid', 'is-drop-target-foreign');
    });
  };

  /*
   * Three states, not two.
   *
   * Red used to mean everything a card could not be dropped on, and that put
   * one message on three quite different facts: "it is already here", "you are
   * not allowed to move it there", and "this step is not part of your workspace
   * at all". The third is not a refusal - a step another workspace defines was
   * never a target for your card, and painting it like a rejected action reads
   * as a fault the editor could have avoided.
   *
   * A foreign step is recognised by its own state rather than by the drop
   * answer: BoardColumnRegistry gives a column `foreign_stage` exactly when the
   * active workspace has no stage of that name.
   */
  const updateDropTargetStyles = (card) => {
    board.board.querySelectorAll('.editorialflow-column').forEach((column) => {
      const foreign = column.dataset.editorialflowState === 'foreign_stage';
      const valid = !foreign && board.canDropCardIntoColumn(card, column);

      column.classList.toggle('is-drop-target-valid', valid);
      column.classList.toggle('is-drop-target-foreign', foreign);
      column.classList.toggle('is-drop-target-invalid', !valid && !foreign);
    });
  };

  board.board.querySelectorAll('.editorialflow-card').forEach((card) => {
    card.addEventListener('dragstart', (event) => {
      draggedCard = card;
      card.classList.add('is-dragged');
      updateDropTargetStyles(card);

      if (event.dataTransfer !== null) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', card.dataset.editorialflowTask || '');
      }
    });

    card.addEventListener('dragend', () => {
      if (draggedCard !== null) {
        draggedCard.classList.remove('is-dragged');
        draggedCard = null;
      }
      clearDropTargetStyles();
    });
  });

  board.board.querySelectorAll('.editorialflow-column').forEach((column) => {
    column.addEventListener('dragover', (event) => {
      if (draggedCard === null) {
        return;
      }

      event.preventDefault();
      if (event.dataTransfer !== null) {
        event.dataTransfer.dropEffect = board.canDropCardIntoColumn(draggedCard, column) ? 'move' : 'none';
      }
    });

    column.addEventListener('drop', async (event) => {
      event.preventDefault();

      if (draggedCard === null) {
        return;
      }

      const taskUid = event.dataTransfer?.getData('text/plain') || draggedCard.dataset.editorialflowTask || '';
      const valid = board.canDropCardIntoColumn(draggedCard, column);
      clearDropTargetStyles();

      if (!valid) {
        const message = board.getDropRejectionMessage(draggedCard, column);
        Notification.warning('Editorial Flow', message);
        board.announce(message);
        return;
      }

      if (taskUid === '') {
        return;
      }

      await board.handleCardDrop(parseInt(taskUid, 10), column, draggedCard);
    });
  });
}
