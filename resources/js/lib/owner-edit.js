// Pure helpers for the owner's edit screen (S5). Never mutate arguments.
// Quota values come only from the server (data-editor); no quota formula lives in JS.
import { countChars } from './text.js';
import { restoreSelection } from './selection.js';

export const PRIVACY_VALUES = ['private', 'unlisted', 'public'];

export const TITLE_MAX = 150;
export const DESCRIPTION_MAX = 1000;

const ID_RE = /^[A-Za-z0-9_-]{11}$/;

export function normalizePrivacy(v) {
  return PRIVACY_VALUES.includes(v) ? v : 'private';
}

// Missing or malformed means 0: nothing can be created.
export function remainingFrom(editor) {
  const n = editor !== null && typeof editor === 'object' ? editor.remainingItems : undefined;
  return Number.isInteger(n) && n >= 0 ? n : 0;
}

export function creationQuota(count, remainingItems) {
  const shortBy = Math.max(0, count - remainingItems);
  return {
    neededText: `この内容に必要な枠: 再生リスト 1 つ＋動画 ${count} 本`,
    shortBy,
    canCreate: count >= 1 && shortBy === 0,
  };
}

export function shortageText(shortBy, resetLabel) {
  if (!(shortBy >= 1)) return '';
  return `あと ${shortBy} 本減らすか、リセット後に作成してください（次にリセットされるのは 日本時間 ${resetLabel} です）`;
}

export function isCreateDisabled({ count, maxItems, remainingItems, title, description }) {
  if (!(count >= 1) || !(maxItems >= 1)) return true;
  if (!creationQuota(count, remainingItems).canCreate) return true;
  const t = String(title ?? '');
  if (t.trim() === '' || countChars(t) > TITLE_MAX) return true;
  return countChars(description) > DESCRIPTION_MAX;
}

// restoreSelection treats every hit as a channel video (outside: false). In S5 the lookup map also
// holds the proposal's own items, which keep their outside flag.
export function restoreDraftItems(draftItems, byId, max) {
  return restoreSelection(draftItems, byId, max)
    .map((x) => (byId.has(x.id) ? { ...x, outside: byId.get(x.id).outside === true } : x));
}

// The owner's unlisted uploads from the JSON endpoint: only well-formed entries, always marked unlisted.
export function sanitizeUnlisted(body) {
  const list = body !== null && typeof body === 'object' && Array.isArray(body.videos) ? body.videos : [];
  return list
    .filter((v) => v !== null && typeof v === 'object' && typeof v.id === 'string' && ID_RE.test(v.id) && typeof v.title === 'string')
    .map((v) => ({
      id: v.id,
      title: v.title,
      thumbnail_url: typeof v.thumbnail_url === 'string' ? v.thumbnail_url : null,
      published_at: typeof v.published_at === 'string' ? v.published_at : null,
      unlisted: true,
    }));
}

// Unlisted entries replace a public entry with the same id; newest first, undated last (stable).
export function mergeUnlisted(videos, unlisted) {
  const ids = new Set(unlisted.map((v) => v.id));
  const merged = [...videos.filter((v) => !ids.has(v.id)), ...unlisted];
  return merged
    .map((v, i) => [v, i])
    .sort(([a, i], [b, j]) => {
      const ka = typeof a.published_at === 'string' ? a.published_at : '';
      const kb = typeof b.published_at === 'string' ? b.published_at : '';
      if (ka !== kb) {
        if (ka === '') return 1;
        if (kb === '') return -1;
        return ka < kb ? 1 : -1;
      }
      return i - j;
    })
    .map(([v]) => v);
}

// A draft keeps only ids, so restored unlisted items are placeholders until the list is loaded.
export function upgradePlaceholders(items, unlisted) {
  const byId = new Map(unlisted.map((v) => [v.id, v]));
  return items.map((x) => {
    const v = x.placeholder === true ? byId.get(x.id) : undefined;
    return v ? { id: v.id, title: v.title, thumbnail_url: v.thumbnail_url, outside: false, unlisted: true } : x;
  });
}
