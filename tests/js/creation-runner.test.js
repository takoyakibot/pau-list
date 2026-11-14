import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  TERMINAL, isTerminal, nextDelay, processed, progressText, ITEM_LABELS, itemLabel, classify, networkDelay, buttonsFor,
  sanitizeProgress, rowsFor, NETWORK_FAILED, PAGE_EXPIRED,
} from '../../resources/js/lib/creation-runner.js';

test('isTerminal', () => {
  assert.deepEqual(TERMINAL, ['completed', 'paused', 'needs_check']);
  for (const s of TERMINAL) assert.equal(isTerminal(s), true);
  for (const s of ['pending', 'creating_playlist', 'adding', undefined, 'x']) assert.equal(isTerminal(s), false);
});

test('nextDelay clamps and defaults', () => {
  assert.equal(nextDelay({ next_delay_ms: 4000 }), 4000);
  assert.equal(nextDelay({ next_delay_ms: 0 }), 0);
  assert.equal(nextDelay({ next_delay_ms: 60000 }), 60000);
  assert.equal(nextDelay({ next_delay_ms: -1 }), 1000);
  assert.equal(nextDelay({ next_delay_ms: 600000 }), 1000);
  assert.equal(nextDelay({ next_delay_ms: 1.5 }), 1000);
  assert.equal(nextDelay({ next_delay_ms: '4000' }), 1000);
  assert.equal(nextDelay({}), 1000);
  assert.equal(nextDelay(null), 1000);
});

test('processed and progressText', () => {
  assert.equal(processed({ done: 2, failed: 1, skipped: 3 }), 6);
  assert.equal(processed({}), 0);
  assert.equal(progressText({ done: 2, failed: 1, skipped: 0, total: 5 }), '3 / 5 本を処理しました（追加 2・失敗 1・中止 0）');
  assert.equal(progressText({ done: 1, failed: 0, skipped: 0, total: 3 }), '1 / 3 本を処理しました（追加 1・失敗 0・中止 0）');
});

test('itemLabel', () => {
  assert.deepEqual(ITEM_LABELS, { pending: '待機中', in_flight: '追加中', done: '追加済み', failed: '失敗', skipped: '中止' });
  assert.equal(itemLabel('done'), '追加済み');
  assert.equal(itemLabel('in_flight'), '追加中');
  assert.equal(itemLabel('constructor'), '');
  assert.equal(itemLabel(undefined), '');
});

test('classify', () => {
  assert.equal(classify(200), 'ok');
  assert.equal(classify(401), 'relogin');
  assert.equal(classify(429), 'wait');
  assert.equal(classify(404), 'gone');
  assert.equal(classify(409), 'conflict');
  assert.equal(classify(500), 'error');
  assert.equal(classify(419), 'error');
  assert.equal(classify(201), 'error');
});

test('networkDelay stops after 5', () => {
  assert.deepEqual([1, 2, 3, 4, 5, 6].map(networkDelay), [2000, 4000, 8000, 16000, 32000, null]);
  assert.equal(networkDelay(0), null);
});

test('buttonsFor', () => {
  assert.deepEqual(buttonsFor({ status: 'completed', failed: 1, playlist_url: 'https://www.youtube.com/playlist?list=PLx' }),
    { pause: false, resume: false, abort: false, retry: true, recreate: false });
  assert.deepEqual(buttonsFor({ status: 'completed', failed: 1, playlist_url: null }),
    { pause: false, resume: false, abort: false, retry: false, recreate: false });
  assert.deepEqual(buttonsFor({ status: 'completed', failed: 0, playlist_url: 'u' }),
    { pause: false, resume: false, abort: false, retry: false, recreate: false });
  for (const s of ['pending', 'creating_playlist', 'adding']) {
    assert.deepEqual(buttonsFor({ status: s, failed: 0, playlist_url: null }),
      { pause: true, resume: false, abort: true, retry: false, recreate: false });
  }
  assert.deepEqual(buttonsFor({ status: 'paused' }), { pause: false, resume: true, abort: true, retry: false, recreate: false });
  assert.deepEqual(buttonsFor({ status: 'needs_check' }), { pause: false, resume: false, abort: true, retry: false, recreate: true });
});

test('sanitizeProgress keeps only the known shape', () => {
  const p = sanitizeProgress({
    status: 'adding', pause_reason: null, done: 1, failed: 0, skipped: 0, total: 2, playlist_url: 'https://www.youtube.com/playlist?list=PLx',
    message: null, remaining_today: 'r', items: [{ position: 0, status: 'done', error: null }, { position: 1, status: 'pending', error: 'e' }, null],
    next_delay_ms: 1000, extra: 'x',
  });
  assert.deepEqual(p, {
    status: 'adding', pause_reason: null, done: 1, failed: 0, skipped: 0, total: 2, playlist_url: 'https://www.youtube.com/playlist?list=PLx',
    message: '', remaining_today: 'r', items: [{ position: 0, status: 'done', error: '' }, { position: 1, status: 'pending', error: 'e' }],
  });
  assert.equal(sanitizeProgress(null), null);
  assert.equal(sanitizeProgress({ status: 3 }), null);
  // Only YouTube playlist URLs are used as links.
  assert.equal(sanitizeProgress({ status: 'completed', playlist_url: 'javascript:alert(1)' }).playlist_url, null);
});

test('rowsFor merges titles by position', () => {
  const rows = rowsFor({ items: [{ position: 0, status: 'done', error: '' }, { position: 1, status: 'failed', error: 'x' }] }, ['A', 'B']);
  assert.deepEqual(rows, [
    { position: 0, number: 1, title: 'A', status: 'done', label: '追加済み', error: '' },
    { position: 1, number: 2, title: 'B', status: 'failed', label: '失敗', error: 'x' },
  ]);
  assert.deepEqual(rowsFor({ items: [{ position: 5, status: 'pending', error: '' }] }, []), [
    { position: 5, number: 6, title: '', status: 'pending', label: '待機中', error: '' },
  ]);
});

test('fixed texts', () => {
  assert.equal(NETWORK_FAILED, '通信に失敗しました。「続きから」で再開できます');
  assert.equal(PAGE_EXPIRED, 'ページの有効期限が切れました。ページを再読み込みしてください');
});
