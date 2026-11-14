// All functions swallow storage errors (private mode, quota, null storage).
const selKey = (channelId) => `listmaker:selection:${channelId}`;
const PROPOSALS = 'listmaker:proposals';
const MAX_PROPOSALS = 50;

function readJson(storage, key) {
  try {
    if (!storage) return null;
    const raw = storage.getItem(key);
    return raw === null || raw === undefined ? null : JSON.parse(raw);
  } catch {
    return null;
  }
}

function write(storage, key, value) {
  try {
    if (!storage) return false;
    storage.setItem(key, JSON.stringify(value));
    return true;
  } catch {
    return false;
  }
}

const SEL_PREFIX = 'listmaker:selection:';
const SELECTION_TTL_MS = 30 * 24 * 60 * 60 * 1000;
const ID_RE = /^[A-Za-z0-9_-]{11}$/;

function remove(storage, key) {
  try {
    if (storage) storage.removeItem(key);
  } catch {
    // ignore
  }
}

// A stored selection: {savedAt, items: [{id, outside}]}. No titles/thumbnails (API data) are kept.
function liveSelection(v, now) {
  return v !== null && typeof v === 'object' && !Array.isArray(v)
    && typeof v.savedAt === 'number' && now - v.savedAt < SELECTION_TTL_MS
    && Array.isArray(v.items);
}

// Deletes every channel's selection that is older than 30 days, broken, or in an older format.
function purgeSelections(storage, now) {
  try {
    if (!storage || typeof storage.key !== 'function') return;
    const keys = [];
    for (let i = 0; i < storage.length; i++) {
      const k = storage.key(i);
      if (typeof k === 'string' && k.startsWith(SEL_PREFIX)) keys.push(k);
    }
    for (const k of keys) {
      if (!liveSelection(readJson(storage, k), now)) remove(storage, k);
    }
  } catch {
    // ignore
  }
}

export function loadSelection(storage, channelId, now) {
  purgeSelections(storage, now);
  const v = readJson(storage, selKey(channelId));
  if (!liveSelection(v, now)) {
    if (v !== null) remove(storage, selKey(channelId));
    return [];
  }
  return v.items
    .filter((x) => x !== null && typeof x === 'object' && typeof x.id === 'string' && ID_RE.test(x.id))
    .map((x) => ({ id: x.id, outside: x.outside === true }));
}

export function saveSelection(storage, channelId, list, now) {
  const items = (Array.isArray(list) ? list : [])
    .filter((x) => x !== null && typeof x === 'object' && typeof x.id === 'string' && ID_RE.test(x.id))
    .map((x) => ({ id: x.id, outside: x.outside === true }));
  return write(storage, selKey(channelId), { savedAt: now, items });
}

export function clearSelection(storage, channelId) {
  remove(storage, selKey(channelId));
}

function safeProposalUrl(url) {
  if (typeof url !== 'string' || !url.includes('/p/')) return false;
  if (url.startsWith('/p/')) return true;
  try {
    const u = new URL(url);
    return (u.protocol === 'https:' || u.protocol === 'http:') && u.pathname.startsWith('/p/');
  } catch {
    return false;
  }
}

function validEntry(e, now) {
  return e !== null && typeof e === 'object'
    && safeProposalUrl(e.url)
    && typeof e.expiresAt === 'number' && e.expiresAt > now;
}

// Expired or invalid entries are deleted from storage, not just hidden.
export function listProposals(storage, now) {
  const v = readJson(storage, PROPOSALS);
  if (v === null) return [];
  const all = Array.isArray(v) ? v : [];
  const live = all.filter((e) => validEntry(e, now));
  if (live.length !== all.length || !Array.isArray(v)) {
    if (live.length === 0) remove(storage, PROPOSALS);
    else write(storage, PROPOSALS, live);
  }
  return live;
}

export function rememberProposal(storage, entry, now) {
  const rest = listProposals(storage, now).filter((e) => e.url !== entry.url);
  return write(storage, PROPOSALS, [entry, ...rest].slice(0, MAX_PROPOSALS));
}
