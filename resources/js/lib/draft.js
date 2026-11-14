// The owner's edit draft (S5), in sessionStorage only: key listmaker:edit:${draftId}.
// Stored shape: {items: [{id, outside}], title, description, privacy}. No titles or thumbnails
// (API data). Every function swallows storage errors.
import { normalizePrivacy } from './owner-edit.js';

const PREFIX = 'listmaker:edit:';
const ID_RE = /^[A-Za-z0-9_-]{11}$/;

const keyFor = (draftId) => `${PREFIX}${draftId}`;
const usableId = (draftId) => typeof draftId === 'string' && draftId !== '';

function cleanItems(list) {
  return list
    .filter((x) => x !== null && typeof x === 'object' && typeof x.id === 'string' && ID_RE.test(x.id))
    .map((x) => ({ id: x.id, outside: x.outside === true }));
}

export function loadDraft(storage, draftId) {
  try {
    if (!storage || !usableId(draftId)) return null;
    const raw = storage.getItem(keyFor(draftId));
    if (raw === null || raw === undefined) return null;
    const v = JSON.parse(raw);
    if (v === null || typeof v !== 'object' || Array.isArray(v) || !Array.isArray(v.items)
      || typeof v.title !== 'string' || typeof v.description !== 'string') {
      return null;
    }
    return { items: cleanItems(v.items), title: v.title, description: v.description, privacy: normalizePrivacy(v.privacy) };
  } catch {
    return null;
  }
}

export function saveDraft(storage, draftId, state) {
  try {
    if (!storage || !usableId(draftId) || state === null || typeof state !== 'object') return false;
    const value = {
      items: cleanItems(Array.isArray(state.items) ? state.items : []),
      title: String(state.title ?? ''),
      description: String(state.description ?? ''),
      privacy: normalizePrivacy(state.privacy),
    };
    storage.setItem(keyFor(draftId), JSON.stringify(value));
    return true;
  } catch {
    return false;
  }
}

export function clearDraft(storage, draftId) {
  try {
    if (storage && usableId(draftId)) storage.removeItem(keyFor(draftId));
  } catch {
    // ignore
  }
}

// On logout: drafts may hold the owner's unlisted video ids.
export function clearAllDrafts(storage) {
  try {
    if (!storage || typeof storage.key !== 'function') return;
    const keys = [];
    for (let i = 0; i < storage.length; i++) {
      const k = storage.key(i);
      if (typeof k === 'string' && k.startsWith(PREFIX)) keys.push(k);
    }
    for (const k of keys) storage.removeItem(k);
  } catch {
    // ignore
  }
}
