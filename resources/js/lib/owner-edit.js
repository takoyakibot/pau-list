// Pure helpers for the owner's edit screen (S5). Never mutate arguments.
// Quota values come only from the server (data-editor); no quota formula lives in JS.
import { countChars } from './text.js';
import { restoreSelection } from './selection.js';

export const PRIVACY_VALUES = ['private', 'unlisted', 'public'];

export const TITLE_MAX = 150;
export const DESCRIPTION_MAX = 1000;

const ID_RE = /^[A-Za-z0-9_-]{11}$/;

// No default (D46): anything but the three values means "not chosen yet" (''), and the owner must pick one.
export function normalizePrivacy(v) {
  return PRIVACY_VALUES.includes(v) ? v : '';
}

export function isPrivacyChosen(v) {
  return PRIVACY_VALUES.includes(v);
}

export const PRIVACY_REQUIRED = '公開範囲を選んでください';

// 「作成」 is not sent until a privacy is chosen: the message to show next to the radios, or '' to go ahead.
export function createBlockedMessage(privacy) {
  return isPrivacyChosen(privacy) ? '' : PRIVACY_REQUIRED;
}

// The server's 422 message for privacy (errors.privacy[0]), shown next to the radios; '' when there is none.
export function privacyErrorFrom(body) {
  const list = body !== null && typeof body === 'object' && body.errors !== null && typeof body.errors === 'object'
    ? body.errors.privacy : undefined;
  return Array.isArray(list) && typeof list[0] === 'string' ? list[0] : '';
}

// A failed start split between the message under 「作成」 and the one next to the radios: when privacy is the only
// failed field, its message is shown once (next to the radios) instead of twice.
export function failureMessages(failureMessage, body) {
  const privacyMessage = privacyErrorFrom(body);
  const errors = body !== null && typeof body === 'object' && body.errors !== null && typeof body.errors === 'object' ? body.errors : {};
  const onlyPrivacy = privacyMessage !== '' && Object.keys(errors).every((k) => k === 'privacy');
  return { createMessage: onlyPrivacy ? '' : failureMessage, privacyMessage };
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

// A failed load of the owner's unlisted videos: 401 asks for login; 429/503 carry a server message.
export function unlistedFailure(httpStatus, body, fallback) {
  return { message: bodyMessage(body) ?? fallback, relogin: httpStatus === 401 };
}

// One 「もう一度ログインする」 link per action that failed with 401 (create / unlisted / url), never two for one failure.
export function withRelogin(flags, action, needed) {
  if (action !== 'create' && action !== 'unlisted' && action !== 'url') return flags;
  return { ...flags, [action]: needed === true };
}

// A video found by URL on S5 (owner lookup). The owner's own unlisted video is marked like the loaded list's
// entries (outside: false, unlisted: true); anything else keeps the server's outside flag. Null when malformed.
export function lookupItem(body) {
  const v = body !== null && typeof body === 'object' ? body.video : null;
  if (v === null || typeof v !== 'object' || typeof v.id !== 'string' || !ID_RE.test(v.id)) return null;
  const item = {
    id: v.id,
    title: typeof v.title === 'string' ? v.title : '',
    thumbnail_url: typeof v.thumbnail_url === 'string' ? v.thumbnail_url : null,
    outside: v.unlisted === true ? false : v.outside === true,
  };
  return v.unlisted === true ? { ...item, unlisted: true } : item;
}

// A public playlist shows its videos to everyone who opens it, and the shared proposal page links to it.
export const PUBLIC_UNLISTED_NOTE = '公開の再生リストに限定公開の動画が含まれています。再生リストを見た人（共有された提案ページの再生リストへのリンクから開いた人を含む）は、これらの動画も見られます';

export function publicUnlistedNote(privacy, items) {
  return privacy === 'public' && Array.isArray(items) && items.some((x) => x !== null && typeof x === 'object' && x.unlisted === true)
    ? PUBLIC_UNLISTED_NOTE : '';
}

// Rows of videos the final check found deleted or private get `unavailable: true`; every other row loses it.
export function markUnavailable(items, ids) {
  const set = new Set(Array.isArray(ids) ? ids : []);
  return items.map((x) => {
    const { unavailable: _drop, ...rest } = x;
    return set.has(x.id) ? { ...rest, unavailable: true } : rest;
  });
}
