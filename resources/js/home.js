import { listProposals } from './lib/storage.js';
import { safeStorage } from './lib/safe-storage.js';

function sameOriginHref(url) {
  try {
    const u = new URL(url, window.location.origin);
    return u.origin === window.location.origin && u.pathname.startsWith('/p/') ? u.pathname : null;
  } catch {
    return null;
  }
}

function formatDate(ms) {
  const d = new Date(ms);
  return Number.isNaN(d.getTime())
    ? ''
    : new Intl.DateTimeFormat('ja-JP', { timeZone: 'Asia/Tokyo', month: 'numeric', day: 'numeric' }).format(d);
}

function setup() {
  const root = document.getElementById('my-proposals');
  const list = root?.querySelector('[data-list]');
  if (!root || !list) return;

  const entries = listProposals(safeStorage(), Date.now())
    .map((e) => ({ ...e, href: sameOriginHref(e.url) }))
    .filter((e) => e.href !== null);
  if (entries.length === 0) {
    root.hidden = true;
    return;
  }

  for (const e of entries) {
    const li = document.createElement('li');
    const a = document.createElement('a');
    a.href = e.href;
    a.className = 'underline';
    a.textContent = typeof e.title === 'string' && e.title !== '' ? e.title : '（無題）';
    li.appendChild(a);
    const meta = document.createElement('span');
    meta.className = 'ml-2 text-xs text-ink-muted';
    const channel = typeof e.channelTitle === 'string' ? e.channelTitle : '';
    meta.textContent = `${channel}（${formatDate(e.expiresAt)}までに削除されます）`;
    li.appendChild(meta);
    list.appendChild(li);
  }
  root.hidden = false;
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', setup);
} else {
  setup();
}
