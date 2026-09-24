/*
 * Which stage a board column stands for - per card.
 *
 * A board shows cards from every workspace the user belongs to, and each card
 * moves within ITS OWN workspace's stage chain (the server runs the move in
 * that workspace, see TaskWorkspaceScope). Custom stages have a different uid
 * in every workspace, so a column carries a map workspace uid => stage uid
 * (BoardColumnRegistry's stageMapJson) instead of the single uid it used to
 * carry - which only ever fit the active workspace and made every other
 * workspace's card undroppable.
 *
 * Pure functions over dataset values, kept out of board.js so they can be
 * tested without a TYPO3 backend around them.
 */

export const EDITING_STAGE_UID = 0;

export function parseStageUid(rawValue) {
  if (rawValue === undefined || rawValue === null || rawValue === '') {
    return null;
  }
  const value = parseInt(rawValue, 10);
  return Number.isNaN(value) ? null : value;
}

export function stageMapOf(column) {
  try {
    const map = JSON.parse(column?.dataset.editorialflowStageMap || '{}');
    return map && typeof map === 'object' && !Array.isArray(map) ? map : {};
  } catch {
    return {};
  }
}

/*
 * Whether the column is a workspace stage at all, as opposed to one of
 * Editorial Flow's own columns (Backlog, Planned, Done). Read off the map,
 * not the scalar stage uid: that one is empty whenever the ACTIVE workspace
 * lacks the step, which says nothing about the card being dragged.
 */
export function isStageColumn(column) {
  return Object.keys(stageMapOf(column)).length > 0;
}

/*
 * The stage uid this column stands for in the card's own workspace, or null
 * when that workspace has no such step. A planned card has no workspace yet;
 * the only stage it can enter is Editing, which is 0 in every workspace.
 */
export function columnStageFor(card, column) {
  const map = stageMapOf(column);
  if (Object.keys(map).length === 0) {
    return parseStageUid(column?.dataset.editorialflowStage);
  }
  const workspaceUid = parseInt(card?.dataset.editorialflowWorkspace || '0', 10);
  if (workspaceUid < 1) {
    return Object.values(map).map(Number).includes(EDITING_STAGE_UID) ? EDITING_STAGE_UID : null;
  }
  return Object.prototype.hasOwnProperty.call(map, String(workspaceUid)) ? Number(map[workspaceUid]) : null;
}
