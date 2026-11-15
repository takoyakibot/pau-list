import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  hasFilter, unselectedFiltered, selectFilteredCount, canSelectFiltered, selectFilteredLabel, selectFiltered, selectFilteredMessage,
} from '../../resources/js/lib/select-filtered.js';

const v = (id, extra = {}) => ({ id, title: `t${id}`, thumbnail_url: null, outside: false, ...extra });

test('hasFilter needs a non-blank query (full-width space counts as blank)', () => {
  for (const q of ['', '   ', '　', null, undefined]) assert.equal(hasFilter(q), false, String(q));
  assert.equal(hasFilter('コラボ'), true);
  assert.equal(hasFilter(' a '), true);
});

test('enabled only with a filter, a filtered video not yet selected and room below the cap', () => {
  const filtered = [v('a'), v('b')];
  assert.equal(canSelectFiltered('', filtered, [], 99), false);
  assert.equal(canSelectFiltered('  ', filtered, [], 99), false);
  assert.equal(canSelectFiltered('x', [], [], 99), false);
  assert.equal(canSelectFiltered('x', filtered, [v('a'), v('b')], 99), false);
  assert.equal(canSelectFiltered('x', filtered, [v('a')], 99), true);
  // At the cap (like the per-row checkboxes) or with an unknown cap: disabled.
  assert.equal(canSelectFiltered('x', filtered, [v('z'), v('y')], 2), false);
  assert.equal(canSelectFiltered('x', filtered, [v('z')], 2), true);
  assert.equal(canSelectFiltered('x', filtered, [], 0), false);
  assert.equal(selectFilteredCount('x', filtered, [v('a')]), 1);
  assert.equal(selectFilteredCount('', filtered, []), 0);
});

test('an already selected placeholder counts as selected', () => {
  assert.equal(canSelectFiltered('x', [v('a')], [v('a', { placeholder: true, title: '動画 a' })], 99), false);
});

test('label shows the count only when there is something to add', () => {
  assert.equal(selectFilteredLabel(3), '絞り込み結果をすべて選択（3 本）');
  assert.equal(selectFilteredLabel(1), '絞り込み結果をすべて選択（1 本）');
  assert.equal(selectFilteredLabel(0), '絞り込み結果をすべて選択');
});

test('unselectedFiltered keeps the displayed order and drops duplicates and malformed entries', () => {
  const r = unselectedFiltered([v('c'), null, v('a'), { title: 'x' }, v('c'), v('b')], [v('b')]);
  assert.deepEqual(r.map((x) => x.id), ['c', 'a']);
});

test('selectFiltered appends in the filtered order after the current selection without touching it', () => {
  const selected = [v('z', { outside: true }), v('b', { placeholder: true })];
  const before = JSON.stringify(selected);
  const r = selectFiltered(selected, [v('c'), v('b'), v('a')], 99);
  assert.deepEqual(r.list.map((x) => x.id), ['z', 'b', 'c', 'a']);
  assert.equal(r.list[0].outside, true);
  assert.equal(r.list[1].placeholder, true);
  assert.deepEqual([r.wanted, r.added], [2, 2]);
  assert.equal(JSON.stringify(selected), before);
  assert.equal(selectFilteredMessage(r.wanted, r.added, 99), '2 本を選択しました');
  assert.equal(selectFilteredMessage(0, 0, 99), '');
});

test('selectFiltered uses resolve for the added items (flags of the screen)', () => {
  const byId = new Map([['a', v('a', { unlisted: true })]]);
  const r = selectFiltered([], [v('a'), v('b')], 99, (x) => byId.get(x.id) ?? { ...x, outside: false });
  assert.equal(r.list[0].unlisted, true);
  assert.equal(r.list[1].unlisted, undefined);
});

test('selectFiltered stops at the cap and reports how many fit', () => {
  const selected = Array.from({ length: 97 }, (_, i) => v(`s${i}`));
  const r = selectFiltered(selected, [v('s0'), v('a'), v('b'), v('c'), v('d')], 99);
  assert.equal(r.list.length, 99);
  assert.deepEqual(r.list.slice(97).map((x) => x.id), ['a', 'b']);
  assert.deepEqual([r.wanted, r.added], [4, 2]);
  assert.equal(selectFilteredMessage(r.wanted, r.added, 99), '上限 99 本のため、4 本中 2 本を選択しました');
});

test('selectFiltered at the cap adds nothing', () => {
  const selected = [v('a'), v('b')];
  const r = selectFiltered(selected, [v('c')], 2);
  assert.deepEqual(r.list.map((x) => x.id), ['a', 'b']);
  assert.notEqual(r.list, selected);
  assert.equal(selectFilteredMessage(r.wanted, r.added, 2), '上限 2 本のため、1 本中 0 本を選択しました');
});
