import { test } from 'node:test';
import assert from 'node:assert/strict';
import { loadSelection, saveSelection, clearSelection, rememberProposal, listProposals } from '../../resources/js/lib/storage.js';

const mem = () => { const m = new Map(); return { getItem: k => m.get(k) ?? null, setItem: (k, x) => m.set(k, String(x)), removeItem: k => m.delete(k) }; };
const throwing = { getItem() { throw new Error('x'); }, setItem() { throw new Error('x'); }, removeItem() { throw new Error('x'); } };
const NOW = 1_000_000;

test('throwing storage is safe', () => {
  assert.deepEqual(loadSelection(throwing, 'c'), []);
  assert.equal(saveSelection(throwing, 'c', [1]), false);
  clearSelection(throwing, 'c');
  assert.equal(rememberProposal(throwing, { url: '/p/x', title: 't', channelTitle: 'c', expiresAt: NOW + 1 }, NOW), false);
  assert.deepEqual(listProposals(throwing, NOW), []);
});
test('null storage and broken json', () => {
  assert.deepEqual(loadSelection(null, 'c'), []);
  assert.equal(saveSelection(null, 'c', []), false);
  const s = mem();
  s.setItem('listmaker:selection:c', '{broken');
  assert.deepEqual(loadSelection(s, 'c'), []);
  s.setItem('listmaker:selection:c', '{"a":1}');
  assert.deepEqual(loadSelection(s, 'c'), []);
});
test('save/load/clear roundtrip', () => {
  const s = mem();
  assert.equal(saveSelection(s, 'c', [{ id: 'a' }]), true);
  assert.deepEqual(loadSelection(s, 'c'), [{ id: 'a' }]);
  clearSelection(s, 'c');
  assert.deepEqual(loadSelection(s, 'c'), []);
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
  s.setItem('listmaker:proposals', JSON.stringify([e('javascript:alert(1)')]));
  assert.deepEqual(listProposals(s, NOW), []);
  const t = mem();
  for (let i = 0; i < 51; i++) rememberProposal(t, e(`/p/${i}`), NOW);
  const urls = listProposals(t, NOW).map(x => x.url);
  assert.equal(urls.length, 50);
  assert.equal(urls[0], '/p/50');
  assert.ok(!urls.includes('/p/0'));
});
