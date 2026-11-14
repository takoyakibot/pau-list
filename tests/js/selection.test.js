import { test } from 'node:test';
import assert from 'node:assert/strict';
import { toggleVideo, addVideo, removeVideo, moveVideo, restoreSelection, markUnavailable, selectionFromOldIds } from '../../resources/js/lib/selection.js';

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
test('restore refreshes catalog items, turns other ids into placeholders, drops junk, caps at max', () => {
  const catalog = new Map([['aaaaaaaaaaa', v('aaaaaaaaaaa', { title: 'new' })]]);
  const stored = [{ id: 'aaaaaaaaaaa', outside: false }, { id: 'xxxxxxxxxxx', outside: true }, { id: 1 }, null, { id: 'bad id' },
    { id: 'aaaaaaaaaaa' }, { id: 'yyyyyyyyyyy' }];
  const list = restoreSelection(stored, catalog, 2);
  assert.deepEqual(list, [
    { ...v('aaaaaaaaaaa', { title: 'new' }), outside: false },
    { id: 'xxxxxxxxxxx', title: '動画 xxxxxxxxxxx', thumbnail_url: null, outside: true, placeholder: true },
  ]);
});
test('restore never uses stored titles or thumbnails (storage keeps ids only)', () => {
  const stored = [{ id: 'bbbbbbbbbbb', title: 'old title', thumbnail_url: 'https://i.ytimg.com/vi/b/mqdefault.jpg', outside: true }];
  assert.deepEqual(restoreSelection(stored, new Map(), 10),
    [{ id: 'bbbbbbbbbbb', title: '動画 bbbbbbbbbbb', thumbnail_url: null, outside: true, placeholder: true }]);
});
test('markUnavailable flags ids', () => { assert.equal(markUnavailable([v('a')], ['a'])[0].unavailable, true); });
test('selectionFromOldIds keeps server order and fills unknown ids with placeholders', () => {
  const catalog = new Map([['aaaaaaaaaaa', v('aaaaaaaaaaa', { title: 'cat' })]]);
  const stored = [{ id: 'bbbbbbbbbbb', outside: true }];
  const list = selectionFromOldIds(['ccccccccccc', 'aaaaaaaaaaa', 'bbbbbbbbbbb', 'bad', 'aaaaaaaaaaa'], catalog, stored);
  assert.deepEqual(list.map(x => [x.id, x.title, x.placeholder === true, x.outside]), [
    ['ccccccccccc', '動画 ccccccccccc', true, false],
    ['aaaaaaaaaaa', 'cat', false, false],
    ['bbbbbbbbbbb', '動画 bbbbbbbbbbb', true, true],
  ]);
  // placeholders survive restoreSelection
  assert.equal(restoreSelection(list, catalog, 99)[0].placeholder, true);
  // broken storage value
  assert.deepEqual(selectionFromOldIds(['aaaaaaaaaaa'], catalog, null).map(x => x.id), ['aaaaaaaaaaa']);
});
