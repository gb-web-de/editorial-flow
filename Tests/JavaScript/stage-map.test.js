import { describe, expect, it } from 'vitest';
import {
  columnStageFor,
  isStageColumn,
  stageMapOf,
} from '@gb-web/editorial-flow/board/stage-map.js';

/*
 * A board shows cards from every workspace the user belongs to. A drop has
 * to resolve the column to the stage of the DRAGGED card's workspace - the
 * column's single stage uid only ever fit the workspace selected in the
 * header, which is why every other workspace's card was undroppable.
 */

const column = (stageMap, stage = '') => {
  const element = document.createElement('section');
  element.dataset.editorialflowStageMap = JSON.stringify(stageMap);
  element.dataset.editorialflowStage = stage;
  return element;
};

const card = (workspaceUid) => {
  const element = document.createElement('li');
  element.dataset.editorialflowWorkspace = String(workspaceUid);
  return element;
};

describe('columnStageFor', () => {
  it('resolves a custom stage to the uid of the card\'s own workspace', () => {
    const legalReview = column({ 4: 100, 5: 101 }, '100');

    expect(columnStageFor(card(4), legalReview)).toBe(100);
    expect(columnStageFor(card(5), legalReview)).toBe(101);
  });

  it('finds the stage even when the active workspace does not have it', () => {
    // The scalar stage is empty: the workspace in the header lacks the step.
    expect(columnStageFor(card(5), column({ 5: 101 }, ''))).toBe(101);
  });

  it('answers null for a step the card\'s workspace does not have', () => {
    expect(columnStageFor(card(9), column({ 4: 100 }))).toBeNull();
  });

  it('lets a planned card (no workspace yet) enter Editing only', () => {
    expect(columnStageFor(card(0), column({ 4: 0, 5: 0 }))).toBe(0);
    expect(columnStageFor(card(0), column({ 4: -10 }))).toBeNull();
  });

  it('falls back to the scalar stage for columns without a map', () => {
    expect(columnStageFor(card(4), column({}, ''))).toBeNull();
    expect(columnStageFor(card(4), column({}, '-10'))).toBe(-10);
  });
});

describe('isStageColumn', () => {
  it('tells workspace stages from Editorial Flow\'s own columns', () => {
    expect(isStageColumn(column({ 4: 0 }))).toBe(true);
    expect(isStageColumn(column({}))).toBe(false);
  });
});

describe('stageMapOf', () => {
  it('survives a broken or missing attribute', () => {
    const broken = document.createElement('section');
    broken.dataset.editorialflowStageMap = '{not json';

    expect(stageMapOf(broken)).toEqual({});
    expect(stageMapOf(document.createElement('section'))).toEqual({});
  });
});
