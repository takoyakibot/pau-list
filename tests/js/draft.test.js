import { test } from 'node:test';
import assert from 'node:assert/strict';
import { loadDraft, saveDraft, clearDraft, clearAllDrafts } from '../../resources/js/lib/draft.js';

const mem = () => {
  const m = new Map();
  return {
    getItem: (k) => m.get(k) ?? null,
    setItem: (k, x) => m.set(k, String(x)),
    removeItem: (k) => m.delete(k),
    key: (i) => [...m.keys()][i] ?? null,
    get length() { return m.size; },
    raw: m,
  };
};
const throwing = {
  getItem() { throw new Error('x'); }, setItem() { throw new Error('x'); }, removeItem() { throw new Error('x'); },
  key() { throw new Error('x'); }, get length() { throw new Error('x'); },
};
const A = 'aaaaaaaaaaa'; const B = 'bbbbbbbbbbb';

test('save and load round trip keeps only ids and outside', () => {
  const s = mem();
  const state = {
    items: [{ id: A, title: 'secret title', thumbnail_url: 'https://i.ytimg.com/x', outside: true, unlisted: true }, { id: B, title: 't' }],
    title: '名前', description: '説明', privacy: 'unlisted',
  };
  assert.equal(saveDraft(s, 'abc', state), true);
  const raw = s.raw.get('listmaker:edit:abc');
  assert.ok(!raw.includes('secret title'));
  assert.ok(!raw.includes('i.ytimg.com'));
  assert.ok(!raw.includes('unlisted":true'));
  assert.deepEqual(loadDraft(s, 'abc'), {
    items: [{ id: A, outside: true }, { id: B, outside: false }], title: '名前', description: '説明', privacy: 'unlisted',
  });
});
test('broken json or wrong shape returns null', () => {
  const s = mem();
  for (const raw of ['{', '[]', '{"items":"x"}', 'null', '1', '{"items":[],"title":1,"description":"","privacy":"private"}']) {
    s.setItem('listmaker:edit:k', raw);
    assert.equal(loadDraft(s, 'k'), null, raw);
  }
  assert.equal(loadDraft(s, 'missing'), null);
});
test('load drops malformed items and leaves an invalid privacy unchosen', () => {
  const s = mem();
  s.setItem('listmaker:edit:k', JSON.stringify({ items: [{ id: A }, { id: 'bad' }, null, 'x'], title: 't', description: 'd', chosenPrivacy: 'friends' }));
  assert.deepEqual(loadDraft(s, 'k'), { items: [{ id: A, outside: false }], title: 't', description: 'd', privacy: '' });
});
test('an unchosen privacy round-trips as unchosen', () => {
  const s = mem();
  saveDraft(s, 'k', { items: [], title: 't', description: '', privacy: '' });
  assert.equal(loadDraft(s, 'k').privacy, '');
});
test('a draft from before D46 (old `privacy` field, always defaulted) never pre-selects', () => {
  const s = mem();
  s.setItem('listmaker:edit:k', JSON.stringify({ items: [{ id: A }], title: 't', description: 'd', privacy: 'private' }));
  assert.equal(loadDraft(s, 'k').privacy, '');
});
test('throwing or null storage never throws', () => {
  for (const st of [throwing, null, undefined]) {
    assert.equal(loadDraft(st, 'k'), null);
    assert.equal(saveDraft(st, 'k', { items: [], title: '', description: '', privacy: 'private' }), false);
    clearDraft(st, 'k');
    clearAllDrafts(st);
  }
});
test('empty draftId is never used', () => {
  const s = mem();
  assert.equal(saveDraft(s, '', { items: [], title: '', description: '', privacy: 'private' }), false);
  assert.equal(s.length, 0);
  assert.equal(loadDraft(s, ''), null);
});
test('drafts are per draftId', () => {
  const s = mem();
  saveDraft(s, 'aaa', { items: [{ id: A }], title: 'a', description: '', privacy: 'private' });
  saveDraft(s, 'bbb', { items: [{ id: B }], title: 'b', description: '', privacy: 'public' });
  assert.equal(loadDraft(s, 'aaa').title, 'a');
  assert.deepEqual(loadDraft(s, 'bbb').items, [{ id: B, outside: false }]);
  clearDraft(s, 'aaa');
  assert.equal(loadDraft(s, 'aaa'), null);
  assert.equal(loadDraft(s, 'bbb').title, 'b');
});
test('clearAllDrafts removes every edit draft and nothing else', () => {
  const s = mem();
  saveDraft(s, 'aaa', { items: [], title: 'a', description: '', privacy: 'private' });
  saveDraft(s, 'bbb', { items: [], title: 'b', description: '', privacy: 'private' });
  s.setItem('listmaker:selection:c', '{}');
  clearAllDrafts(s);
  assert.deepEqual([...s.raw.keys()], ['listmaker:selection:c']);
});
