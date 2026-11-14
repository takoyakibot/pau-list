import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  PRIVACY_VALUES, normalizePrivacy, remainingFrom, creationQuota, shortageText, isCreateDisabled,
  restoreDraftItems, mergeUnlisted, upgradePlaceholders, sanitizeUnlisted, unlistedResultMessage,
  rowActionLabel, isBlankTitle, TITLE_REQUIRED, DRAFT_RESTORED,
} from '../../resources/js/lib/owner-edit.js';

test('creationQuota: within remaining', () => {
  assert.deepEqual(creationQuota(2, 99), { neededText: 'この内容に必要な枠: 再生リスト 1 つ＋動画 2 本', shortBy: 0, canCreate: true });
});
test('creationQuota: shortage and zero', () => {
  assert.equal(creationQuota(5, 3).shortBy, 2);
  assert.equal(creationQuota(5, 3).canCreate, false);
  assert.equal(creationQuota(0, 99).canCreate, false);
});
test('shortageText', () => {
  assert.equal(shortageText(2, '11月15日 17時'), 'あと 2 本減らすか、リセット後に作成してください（次にリセットされるのは 日本時間 11月15日 17時 です）');
  assert.equal(shortageText(0, 'x'), '');
});
test('remainingFrom rejects missing or malformed', () => {
  for (const v of [undefined, null, -1, 1.5, '3']) assert.equal(remainingFrom({ remainingItems: v }), 0);
  assert.equal(remainingFrom({ remainingItems: 7 }), 7);
  assert.equal(remainingFrom({ remainingItems: 0 }), 0);
  assert.equal(remainingFrom(null), 0);
});
test('isCreateDisabled', () => {
  const ok = { count: 2, maxItems: 99, remainingItems: 99, title: 'a', description: '' };
  assert.equal(isCreateDisabled(ok), false);
  assert.equal(isCreateDisabled({ ...ok, title: ' 　' }), true);
  assert.equal(isCreateDisabled({ ...ok, title: 'あ'.repeat(151) }), true);
  assert.equal(isCreateDisabled({ ...ok, title: 'あ'.repeat(150) }), false);
  assert.equal(isCreateDisabled({ ...ok, description: 'x'.repeat(1001) }), true);
  assert.equal(isCreateDisabled({ ...ok, description: 'x'.repeat(1000) }), false);
  assert.equal(isCreateDisabled({ ...ok, remainingItems: 1 }), true);
  assert.equal(isCreateDisabled({ ...ok, maxItems: 0 }), true);
  assert.equal(isCreateDisabled({ ...ok, count: 0 }), true);
});
test('normalizePrivacy defaults to private', () => {
  assert.deepEqual(PRIVACY_VALUES, ['private', 'unlisted', 'public']);
  assert.equal(normalizePrivacy('public'), 'public');
  assert.equal(normalizePrivacy('unlisted'), 'unlisted');
  for (const v of [undefined, '', 'PUBLIC', 'friends']) assert.equal(normalizePrivacy(v), 'private');
});

const A = 'aaaaaaaaaaa'; const B = 'bbbbbbbbbbb'; const C = 'ccccccccccc'; const U = 'uuuuuuuuuuu';
test('restoreDraftItems keeps the outside flag of proposal items and placeholders for unknown ids', () => {
  const byId = new Map([
    [A, { id: A, title: 'A', thumbnail_url: null, outside: true }],
    [B, { id: B, title: 'B', thumbnail_url: null, outside: false }],
  ]);
  const r = restoreDraftItems([{ id: B, outside: true }, { id: A, outside: false }, { id: U, outside: false }, { id: 'bad' }], byId, 99);
  assert.deepEqual(r.map((x) => [x.id, x.outside, x.placeholder === true]), [[B, false, false], [A, true, false], [U, false, true]]);
  assert.equal(restoreDraftItems([{ id: A }, { id: B }], byId, 1).length, 1);
});
test('sanitizeUnlisted keeps only well-formed entries and marks them unlisted', () => {
  const r = sanitizeUnlisted({ videos: [
    { id: U, title: 'U', thumbnail_url: 'https://i.ytimg.com/u', published_at: '2026-01-01T00:00:00Z', unlisted: true },
    { id: 'bad', title: 'x' }, null, { id: C, title: 5 },
  ] });
  assert.deepEqual(r, [{ id: U, title: 'U', thumbnail_url: 'https://i.ytimg.com/u', published_at: '2026-01-01T00:00:00Z', unlisted: true }]);
  assert.deepEqual(sanitizeUnlisted(null), []);
  assert.deepEqual(sanitizeUnlisted({ videos: 'x' }), []);
});
test('mergeUnlisted dedupes by id (unlisted wins) and sorts newest first', () => {
  const videos = [
    { id: A, title: 'A', published_at: '2026-03-01T00:00:00Z' },
    { id: B, title: 'B old public', published_at: '2026-01-01T00:00:00Z' },
    { id: C, title: 'C', published_at: null },
  ];
  const unl = [
    { id: B, title: 'B', published_at: '2026-01-01T00:00:00Z', unlisted: true },
    { id: U, title: 'U', published_at: '2026-02-01T00:00:00Z', unlisted: true },
  ];
  const r = mergeUnlisted(videos, unl);
  assert.deepEqual(r.map((v) => v.id), [A, U, B, C]);
  assert.equal(r.find((v) => v.id === B).unlisted, true);
  assert.equal(videos.length, 3); // not mutated
  assert.equal(mergeUnlisted(r, unl).length, 4); // idempotent
});
test('upgradePlaceholders fills restored unlisted placeholders', () => {
  const items = [{ id: U, title: `動画 ${U}`, thumbnail_url: null, outside: false, placeholder: true }, { id: A, title: 'A', outside: true }];
  const r = upgradePlaceholders(items, [{ id: U, title: 'U', thumbnail_url: 'https://i.ytimg.com/u', published_at: null, unlisted: true }]);
  assert.deepEqual(r[0], { id: U, title: 'U', thumbnail_url: 'https://i.ytimg.com/u', outside: false, unlisted: true });
  assert.deepEqual(r[1], items[1]);
});
test('unlistedResultMessage reports none, shown, and the 300 cap', () => {
  assert.equal(unlistedResultMessage(0, false), '限定公開の動画はありませんでした');
  assert.equal(unlistedResultMessage(3, false), 'あなたの限定公開の動画も一覧に表示しています（あなたにだけ表示されます）');
  assert.equal(unlistedResultMessage(300, true), 'あなたの限定公開の動画も一覧に表示しています（あなたにだけ表示されます）。限定公開の動画は新しい順に300本まで表示しています');
  assert.equal(unlistedResultMessage(300, 'yes'), unlistedResultMessage(300, false));
});
test('rowActionLabel names the video and the action for screen readers', () => {
  assert.equal(rowActionLabel({ title: '猫の動画' }, 'up'), '「猫の動画」を上へ移動');
  assert.equal(rowActionLabel({ title: '猫の動画' }, 'down'), '「猫の動画」を下へ移動');
  assert.equal(rowActionLabel({ title: '猫の動画' }, 'remove'), '「猫の動画」を削除');
  assert.equal(rowActionLabel({ title: '' }, 'remove'), '「タイトル不明の動画」を削除');
  assert.equal(rowActionLabel(null, 'up'), '「タイトル不明の動画」を上へ移動');
  assert.equal(rowActionLabel({ title: 'x' }, 'other'), '');
});
test('isBlankTitle treats ASCII and full-width spaces as empty', () => {
  for (const v of ['', ' ', '　', ' 　\n', undefined, null]) assert.equal(isBlankTitle(v), true, String(v));
  assert.equal(isBlankTitle(' a '), false);
  assert.equal(TITLE_REQUIRED, '再生リスト名を入力してください');
  assert.equal(DRAFT_RESTORED, '前回の編集内容を復元しました（タイトルが表示されない動画は追加し直すと表示されます）');
});
