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

export const TITLE_REQUIRED = '再生リスト名を入力してください';
export const DRAFT_RESTORED = '前回の編集内容を復元しました（タイトルが表示されない動画は追加し直すと表示されます）';

// String.prototype.trim also removes the full-width space (U+3000).
export function isBlankTitle(title) {
  return String(title ?? '').trim() === '';
}

const ROW_ACTIONS = { up: 'を上へ移動', down: 'を下へ移動', remove: 'を削除' };

// Per-row button names (↑ / ↓ / 削除 alone do not say which video they act on).
export function rowActionLabel(item, action) {
  if (!Object.hasOwn(ROW_ACTIONS, action)) return '';
  const title = item !== null && typeof item === 'object' && typeof item.title === 'string' && item.title !== ''
    ? item.title : 'タイトル不明の動画';
  return `「${title}」${ROW_ACTIONS[action]}`;
}

export function isCreateDisabled({ count, maxItems, remainingItems, title, description }) {
  if (!(count >= 1) || !(maxItems >= 1)) return true;
  if (!creationQuota(count, remainingItems).canCreate) return true;
  const t = String(title ?? '');
  if (isBlankTitle(t) || countChars(t) > TITLE_MAX) return true;
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

export const UNLISTED_SHOWN = 'あなたの限定公開の動画も一覧に表示しています（あなたにだけ表示されます）';
export const UNLISTED_NONE = '限定公開の動画はありませんでした';
// Same text as OwnerEditController::UNLISTED_TRUNCATED (the server keeps at most 300, newest first).
export const UNLISTED_TRUNCATED = '限定公開の動画は新しい順に300本まで表示しています';

export function unlistedResultMessage(count, truncated) {
  if (!(count > 0)) return UNLISTED_NONE;
  return truncated === true ? `${UNLISTED_SHOWN}。${UNLISTED_TRUNCATED}` : UNLISTED_SHOWN;
}

// ---- 「作成」 (POST creation, spec 7.2) ----

export const START_FAILED = '作成を開始できませんでした。時間をおいて再度お試しください';
export const PAGE_EXPIRED = 'ページの有効期限が切れました。ページを再読み込みしてください（編集内容は残っています）';

export function creationRequestBody({ items, title, description, privacy }) {
  return {
    video_ids: (Array.isArray(items) ? items : []).map((x) => x.id),
    title,
    description,
    privacy: normalizePrivacy(privacy),
  };
}

function bodyMessage(body) {
  return body !== null && typeof body === 'object' && typeof body.message === 'string' && body.message !== ''
    ? body.message : null;
}

// A failed start: the server's Japanese message for the statuses that carry one (401 login, 409 quota/busy,
// 422 validation/unavailable videos, 429 limit, 503); 419 (CSRF) and anything else get a fixed text.
export function startFailure(httpStatus, body) {
  const ids = body !== null && typeof body === 'object' && Array.isArray(body.unavailable_ids)
    ? body.unavailable_ids.filter((id) => typeof id === 'string' && ID_RE.test(id)) : [];
  if (httpStatus === 419) return { message: PAGE_EXPIRED, relogin: false, unavailableIds: [] };
  if (httpStatus === 401) return { message: bodyMessage(body) ?? START_FAILED, relogin: true, unavailableIds: [] };
  if ([409, 422, 429, 503].includes(httpStatus)) {
    return { message: bodyMessage(body) ?? START_FAILED, relogin: false, unavailableIds: ids };
  }
  return { message: START_FAILED, relogin: false, unavailableIds: [] };
}

// Rows of videos the final check found deleted or private get `unavailable: true`; every other row loses it.
export function markUnavailable(items, ids) {
  const set = new Set(Array.isArray(ids) ? ids : []);
  return items.map((x) => {
    const { unavailable: _drop, ...rest } = x;
    return set.has(x.id) ? { ...rest, unavailable: true } : rest;
  });
}
