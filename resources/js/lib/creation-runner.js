// Pure helpers for S6 (creation progress). Texts from the server (`message`) win; these are fallbacks only.

export const TERMINAL = ['completed', 'paused', 'needs_check'];

export const ITEM_LABELS = Object.freeze({
  pending: '待機中', in_flight: '追加中', done: '追加済み', failed: '失敗', skipped: '中止',
});

export const NETWORK_FAILED = '通信に失敗しました。「続きから」で再開できます';
export const PAGE_EXPIRED = 'ページの有効期限が切れました。ページを再読み込みしてください';
// A running creation on a page whose step loop is not running (opened again, not right after 「作成」).
export const IDLE_HINT = 'この画面を開いている間だけ進みます。「続きから」で再開できます';

const RUNNING = ['pending', 'creating_playlist', 'adding'];
const PLAYLIST_URL = /^https:\/\/www\.youtube\.com\/playlist\?list=[A-Za-z0-9_-]+$/;
const MAX_NETWORK_RETRIES = 5;

export function isTerminal(status) {
  return TERMINAL.includes(status);
}

/** The server's next_delay_ms when it is an integer within 0..60000, otherwise 1000. */
export function nextDelay(body) {
  const v = body && typeof body === 'object' ? body.next_delay_ms : undefined;
  return Number.isInteger(v) && v >= 0 && v <= 60000 ? v : 1000;
}

function count(v) {
  return Number.isInteger(v) && v >= 0 ? v : 0;
}

export function processed(p) {
  return count(p?.done) + count(p?.failed) + count(p?.skipped);
}

export function progressText(p) {
  return `${processed(p)} / ${count(p?.total)} 本を処理しました（追加 ${count(p?.done)}・失敗 ${count(p?.failed)}・中止 ${count(p?.skipped)}）`;
}

export function itemLabel(status) {
  return Object.hasOwn(ITEM_LABELS, status) ? ITEM_LABELS[status] : '';
}

/** How the page reacts to an HTTP status of the JSON endpoints. */
export function classify(httpStatus) {
  switch (httpStatus) {
    case 200: return 'ok';
    case 401: return 'relogin';
    case 429: return 'wait';
    case 404: return 'gone';
    case 409: return 'conflict';
    default: return 'error';
  }
}

/** Wait before retrying after the n-th consecutive network failure: 2, 4, 8, 16, 32 s; null = stop. */
export function networkDelay(n) {
  if (!Number.isInteger(n) || n < 1 || n > MAX_NETWORK_RETRIES) return null;
  return 2000 * 2 ** (n - 1);
}

export function buttonsFor(p) {
  const status = p?.status;
  return {
    pause: RUNNING.includes(status),
    resume: status === 'paused',
    abort: typeof status === 'string' && status !== 'completed',
    retry: status === 'completed' && count(p?.failed) >= 1 && typeof p?.playlist_url === 'string' && p.playlist_url !== '',
    recreate: status === 'needs_check',
  };
}

function text(v) {
  return typeof v === 'string' ? v : '';
}

/** The progress JSON reduced to its known keys and types; null when it is not a progress object. */
export function sanitizeProgress(body) {
  if (!body || typeof body !== 'object' || typeof body.status !== 'string') return null;
  const items = Array.isArray(body.items) ? body.items : [];
  const playlistUrl = typeof body.playlist_url === 'string' && PLAYLIST_URL.test(body.playlist_url) ? body.playlist_url : null;
  return {
    status: body.status,
    pause_reason: typeof body.pause_reason === 'string' ? body.pause_reason : null,
    done: count(body.done),
    failed: count(body.failed),
    skipped: count(body.skipped),
    total: count(body.total),
    playlist_url: playlistUrl,
    // True also when the playlist's id was dropped 7 days after the owner's last use (no link then).
    playlist_created: body.playlist_created === true || playlistUrl !== null,
    message: text(body.message),
    remaining_today: text(body.remaining_today),
    items: items
      .filter((i) => i && typeof i === 'object' && Number.isInteger(i.position) && typeof i.status === 'string')
      .map((i) => ({ position: i.position, status: i.status, error: text(i.error) })),
  };
}

/** Item rows for the list: the progress items with their titles (titles[position], rendered by the server). */
export function rowsFor(p, titles) {
  const names = Array.isArray(titles) ? titles : [];
  return (p?.items ?? []).map((i) => ({
    position: i.position,
    number: i.position + 1,
    title: text(names[i.position]),
    status: i.status,
    label: itemLabel(i.status),
    error: text(i.error),
  }));
}
