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

export function loadSelection(storage, channelId) {
  const v = readJson(storage, selKey(channelId));
  return Array.isArray(v) ? v : [];
}

export function saveSelection(storage, channelId, list) {
  return write(storage, selKey(channelId), list);
}

export function clearSelection(storage, channelId) {
  try {
    if (storage) storage.removeItem(selKey(channelId));
  } catch {
    // ignore
  }
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

export function listProposals(storage, now) {
  const v = readJson(storage, PROPOSALS);
  return Array.isArray(v) ? v.filter((e) => validEntry(e, now)) : [];
}

export function rememberProposal(storage, entry, now) {
  const rest = listProposals(storage, now).filter((e) => e.url !== entry.url);
  return write(storage, PROPOSALS, [entry, ...rest].slice(0, MAX_PROPOSALS));
}
