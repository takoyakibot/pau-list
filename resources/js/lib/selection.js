// Pure selection helpers. Items: {id, title, thumbnail_url, outside, unavailable?}. Never mutate arguments.
export function addVideo(list, video, max) {
  if (list.some((x) => x.id === video.id)) return { list: [...list], error: 'duplicate' };
  if (list.length >= max) return { list: [...list], error: 'limit' };
  return { list: [...list, video], error: null };
}

export function toggleVideo(list, video, max) {
  if (list.some((x) => x.id === video.id)) return { list: removeVideo(list, video.id), error: null };
  const r = addVideo(list, video, max);
  return { list: r.list, error: r.error };
}

export function removeVideo(list, id) {
  return list.filter((x) => x.id !== id);
}

export function moveVideo(list, id, delta) {
  const from = list.findIndex((x) => x.id === id);
  if (from < 0) return [...list];
  const to = Math.min(list.length - 1, Math.max(0, from + delta));
  const next = [...list];
  const [item] = next.splice(from, 1);
  next.splice(to, 0, item);
  return next;
}

const ID_RE = /^[A-Za-z0-9_-]{11}$/;

function placeholder(id, outside) {
  return { id, title: `動画 ${id}`, thumbnail_url: null, outside, placeholder: true };
}

function validStored(x) {
  return x !== null && typeof x === 'object' && typeof x.id === 'string' && ID_RE.test(x.id);
}

// Stored selections hold only {id, outside}: catalog videos get their current catalog values,
// any other id (URL-added, or no longer in the list) becomes a placeholder. The server re-fetches on save.
export function restoreSelection(stored, catalogById, max) {
  if (!Array.isArray(stored)) return [];
  const seen = new Set();
  const out = [];
  for (const x of stored) {
    if (!validStored(x) || seen.has(x.id)) continue;
    seen.add(x.id);
    const hit = catalogById.get(x.id);
    out.push(hit ? { ...hit, outside: false } : placeholder(x.id, x.outside === true));
    if (out.length >= max) break;
  }
  return out;
}

export function markUnavailable(list, ids) {
  const set = new Set(ids);
  return list.map((x) => (set.has(x.id) ? { ...x, unavailable: true } : x));
}

// After a failed save the server-submitted order wins. IDs not in the catalog are kept as
// placeholders (keeping the stored outside flag) so they are still submitted; the server
// re-fetches real values on save.
export function selectionFromOldIds(oldIds, catalogById, stored) {
  const byId = new Map((Array.isArray(stored) ? stored : [])
    .filter((x) => validStored(x))
    .map((x) => [x.id, x]));
  const seen = new Set();
  const out = [];
  for (const id of Array.isArray(oldIds) ? oldIds : []) {
    if (typeof id !== 'string' || !ID_RE.test(id) || seen.has(id)) continue;
    seen.add(id);
    out.push(catalogById.get(id) ?? placeholder(id, byId.get(id)?.outside === true));
  }
  return out;
}
