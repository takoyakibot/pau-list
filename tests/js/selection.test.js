import { test } from 'node:test';
import assert from 'node:assert/strict';
import { toggleVideo, addVideo, removeVideo, moveVideo, restoreSelection, markUnavailable } from '../../resources/js/lib/selection.js';

const v = (id, extra = {}) => ({ id, title: `t${id}`, thumbnail_url: null, outside: false, ...extra });

test('toggle adds until max then refuses', () => {
  let r = toggleVideo([v('a')], v('b'), 2);
  assert.deepEqual(r.list.map(x => x.id), ['a', 'b']);
  r = toggleVideo(r.list, v('c'), 2);
  assert.equal(r.error, 'limit');
  assert.equal(r.list.length, 2);
  assert.deepEqual(toggleVideo(r.list, v('a'), 2).list.map(x => x.id), ['b']);
});
test('addVideo reports duplicate and limit', () => {
  assert.equal(addVideo([v('a')], v('a'), 99).error, 'duplicate');
  assert.equal(addVideo([v('a')], v('b'), 1).error, 'limit');
  assert.equal(addVideo([v('a')], v('b'), 2).error, null);
});
test('removeVideo does not mutate', () => {
  const l = [v('a'), v('b')];
  assert.deepEqual(removeVideo(l, 'a').map(x => x.id), ['b']);
  assert.equal(l.length, 2);
});
test('move clamps at edges', () => {
  assert.deepEqual(moveVideo([v('a'), v('b'), v('c')], 'c', -1).map(x => x.id), ['a', 'c', 'b']);
  assert.deepEqual(moveVideo([v('a'), v('b')], 'a', -1).map(x => x.id), ['a', 'b']);
  assert.deepEqual(moveVideo([v('a'), v('b')], 'b', 1).map(x => x.id), ['a', 'b']);
});
test('restore refreshes catalog items, keeps url-added, drops junk, caps at max', () => {
  const catalog = new Map([['a', v('a', { title: 'new' })]]);
  const stored = [v('a', { title: 'old' }), v('x', { outside: true }), { id: 1 }, null, v('a'), v('y')];
  const list = restoreSelection(stored, catalog, 2);
  assert.deepEqual(list.map(x => [x.id, x.title]), [['a', 'new'], ['x', 'tx']]);
});
test('markUnavailable flags ids', () => { assert.equal(markUnavailable([v('a')], ['a'])[0].unavailable, true); });
