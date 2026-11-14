import { test } from 'node:test';
import assert from 'node:assert/strict';
import { loadSelection, saveSelection, clearSelection, rememberProposal, listProposals } from '../../resources/js/lib/storage.js';

const mem = () => {
  const m = new Map();
  return {
    getItem: k => m.get(k) ?? null,
    setItem: (k, x) => m.set(k, String(x)),
    removeItem: k => m.delete(k),
    key: i => [...m.keys()][i] ?? null,
    get length() { return m.size; },
    raw: m,
  };
};
const DAY = 24 * 60 * 60 * 1000;
const throwing = { getItem() { throw new Error('x'); }, setItem() { throw new Error('x'); }, removeItem() { throw new Error('x'); } };
const NOW = 1_000_000;

test('throwing storage is safe', () => {
  assert.deepEqual(loadSelection(throwing, 'c', NOW), []);
  assert.equal(saveSelection(throwing, 'c', [{ id: 'aaaaaaaaaaa' }], NOW), false);
  clearSelection(throwing, 'c');
  assert.equal(rememberProposal(throwing, { url: '/p/x', title: 't', channelTitle: 'c', expiresAt: NOW + 1 }, NOW), false);
  assert.deepEqual(listProposals(throwing, NOW), []);
});
test('null storage and broken json', () => {
  assert.deepEqual(loadSelection(null, 'c', NOW), []);
  assert.equal(saveSelection(null, 'c', [], NOW), false);
  const s = mem();
  s.setItem('listmaker:selection:c', '{broken');
  assert.deepEqual(loadSelection(s, 'c', NOW), []);
  s.setItem('listmaker:selection:c', '{"a":1}');
  assert.deepEqual(loadSelection(s, 'c', NOW), []);
});
test('save keeps only ids, the outside flag and savedAt', () => {
  const s = mem();
  const list = [
    { id: 'aaaaaaaaaaa', title: 'secret title', thumbnail_url: 'https://i.ytimg.com/x', outside: true, placeholder: true },
    { id: 'bbbbbbbbbbb', title: 't' },
    { id: 'bad id', title: 't' },
  ];
  assert.equal(saveSelection(s, 'c', list, NOW), true);
  assert.deepEqual(JSON.parse(s.getItem('listmaker:selection:c')), {
    savedAt: NOW,
    items: [{ id: 'aaaaaaaaaaa', outside: true }, { id: 'bbbbbbbbbbb', outside: false }],
  });
  assert.deepEqual(loadSelection(s, 'c', NOW), [{ id: 'aaaaaaaaaaa', outside: true }, { id: 'bbbbbbbbbbb', outside: false }]);
  clearSelection(s, 'c');
  assert.deepEqual(loadSelection(s, 'c', NOW), []);
});
test('selections older than 30 days are deleted on read, for every channel', () => {
  const s = mem();
  saveSelection(s, 'old', [{ id: 'aaaaaaaaaaa' }], NOW - 31 * DAY);
  saveSelection(s, 'edge', [{ id: 'aaaaaaaaaaa' }], NOW - 30 * DAY);
  saveSelection(s, 'new', [{ id: 'bbbbbbbbbbb' }], NOW - 29 * DAY);
  s.setItem('listmaker:selection:legacy', JSON.stringify([{ id: 'ccccccccccc', title: 'old format with titles' }]));
  s.setItem('unrelated', 'keep');

  assert.deepEqual(loadSelection(s, 'new', NOW), [{ id: 'bbbbbbbbbbb', outside: false }]);
  assert.equal(s.getItem('listmaker:selection:old'), null);
  assert.equal(s.getItem('listmaker:selection:edge'), null);
  assert.equal(s.getItem('listmaker:selection:legacy'), null);
  assert.notEqual(s.getItem('listmaker:selection:new'), null);
  assert.equal(s.getItem('unrelated'), 'keep');

  const t = mem();
  saveSelection(t, 'c', [{ id: 'aaaaaaaaaaa' }], NOW - 31 * DAY);
  assert.deepEqual(loadSelection(t, 'c', NOW), []);
  assert.equal(t.raw.size, 0);
});
test('proposals: dedupe, expiry, scheme filter, cap 50', () => {
  const s = mem();
  const e = (u, exp = NOW + 100) => ({ url: u, title: 't', channelTitle: 'c', expiresAt: exp });
  rememberProposal(s, e('/p/a'), NOW);
  rememberProposal(s, e('/p/a'), NOW);
  assert.equal(listProposals(s, NOW).length, 1);
  rememberProposal(s, e('/p/old', NOW - 1), NOW);
  rememberProposal(s, e('javascript:alert(1)//p/'), NOW);
  assert.deepEqual(listProposals(s, NOW).map(x => x.url), ['/p/a']);
  // expired entries are deleted from storage, not just hidden
  s.setItem('listmaker:proposals', JSON.stringify([e('/p/a'), e('/p/gone', NOW)]));
  assert.deepEqual(listProposals(s, NOW).map(x => x.url), ['/p/a']);
  assert.deepEqual(JSON.parse(s.getItem('listmaker:proposals')).map(x => x.url), ['/p/a']);
  s.setItem('listmaker:proposals', JSON.stringify([e('/p/gone', NOW)]));
  assert.deepEqual(listProposals(s, NOW), []);
  assert.equal(s.getItem('listmaker:proposals'), null);
  s.setItem('listmaker:proposals', JSON.stringify([e('javascript:alert(1)')]));
  assert.deepEqual(listProposals(s, NOW), []);
  const t = mem();
  for (let i = 0; i < 51; i++) rememberProposal(t, e(`/p/${i}`), NOW);
  const urls = listProposals(t, NOW).map(x => x.url);
  assert.equal(urls.length, 50);
  assert.equal(urls[0], '/p/50');
  assert.ok(!urls.includes('/p/0'));
});
