import { toggleVideo, addVideo, removeVideo, moveVideo, restoreSelection, markUnavailable, selectionFromOldIds } from './lib/selection.js';
import { filterVideos } from './lib/filter.js';
import { loadSelection, saveSelection, clearSelection } from './lib/storage.js';
import { countChars, isOverLimit } from './lib/text.js';
import { safeStorage } from './lib/safe-storage.js';

const LIMITS = { title: 150, description: 1000, comment: 500, proposerName: 30 };

function readJson(el, name) {
  try {
    return JSON.parse(el.dataset[name] ?? '');
  } catch {
    return null;
  }
}

function counterClass(over) {
  return over ? 'font-semibold text-red-700' : 'text-gray-600';
}

function formatDate(iso) {
  return typeof iso === 'string' && iso.length >= 10 ? iso.slice(0, 10).replaceAll('-', '/') : '';
}

document.addEventListener('alpine:init', () => {
  window.Alpine.data('proposer', () => ({
    channelId: '',
    lookupUrl: '',
    maxItems: 99,
    videos: [],
    catalogById: new Map(),
    query: '',
    selected: [],
    urlInput: '',
    urlMessage: '',
    urlBusy: false,
    limitMessage: '',
    title: '',
    description: '',
    comment: '',
    proposerName: '',
    submitting: false,
    storage: null,

    init() {
      const catalog = readJson(this.$el, 'catalog') ?? {};
      const old = readJson(this.$el, 'old') ?? {};
      this.channelId = String(catalog.channelId ?? '');
      this.lookupUrl = String(catalog.lookupUrl ?? '');
      this.maxItems = Number(catalog.maxItems) || 99;
      this.videos = Array.isArray(catalog.videos) ? catalog.videos : [];
      this.catalogById = new Map(this.videos.map((v) => [v.id, { id: v.id, title: v.title, thumbnail_url: v.thumbnail_url, outside: false }]));
      this.title = old.title ?? '';
      this.description = old.description ?? '';
      this.comment = old.comment ?? '';
      this.proposerName = old.proposerName ?? '';
      this.storage = safeStorage();

      let stored = loadSelection(this.storage, this.channelId);
      if (Array.isArray(old.videoIds) && old.videoIds.length > 0) {
        // After a failed save, the server-submitted order wins (unknown IDs become placeholders).
        stored = selectionFromOldIds(old.videoIds, this.catalogById, stored);
      }
      const restored = restoreSelection(stored, this.catalogById, this.maxItems);
      this.selected = markUnavailable(restored, Array.isArray(catalog.unavailableIds) ? catalog.unavailableIds : []);
      this.persist();

      // A page restored from the back/forward cache keeps the old disabled state.
      window.addEventListener('pageshow', () => { this.submitting = false; });
    },

    // --- derived ---
    get filtered() { return filterVideos(this.videos, this.query); },
    get shownCount() { return this.filtered.length; },
    get selectedCount() { return this.selected.length; },
    get isFull() { return this.selected.length >= this.maxItems; },
    get titleCount() { return countChars(this.title); },
    get descriptionCount() { return countChars(this.description); },
    get commentCount() { return countChars(this.comment); },
    get proposerNameCount() { return countChars(this.proposerName); },
    get titleCounter() { return `${this.titleCount} / ${LIMITS.title}`; },
    get descriptionCounter() { return `${this.descriptionCount} / ${LIMITS.description}`; },
    get commentCounter() { return `${this.commentCount} / ${LIMITS.comment}`; },
    get proposerNameCounter() { return `${this.proposerNameCount} / ${LIMITS.proposerName}`; },
    get titleOver() { return isOverLimit(this.title, LIMITS.title); },
    get descriptionOver() { return isOverLimit(this.description, LIMITS.description); },
    get commentOver() { return isOverLimit(this.comment, LIMITS.comment); },
    get proposerNameOver() { return isOverLimit(this.proposerName, LIMITS.proposerName); },
    get anyOver() { return this.titleOver || this.descriptionOver || this.commentOver || this.proposerNameOver; },
    get titleCounterClass() { return counterClass(this.titleOver); },
    get descriptionCounterClass() { return counterClass(this.descriptionOver); },
    get commentCounterClass() { return counterClass(this.commentOver); },
    get proposerNameCounterClass() { return counterClass(this.proposerNameOver); },
    get isSubmitDisabled() { return this.submitting || this.anyOver; },
    get hasLimitMessage() { return this.limitMessage !== ''; },
    get hasUrlMessage() { return this.urlMessage !== ''; },
    get hasSelection() { return this.selected.length > 0; },
    get hasPlaceholders() { return this.selected.some((x) => x.placeholder === true); },

    // --- per-row helpers (use this.video / this.item from x-for scope) ---
    isSelected() { return this.selected.some((x) => x.id === this.video.id); },
    isCheckDisabled() { return this.isFull && !this.isSelected(); },
    publishedLabel() { return formatDate(this.video.published_at); },

    // --- actions ---
    persist() {
      saveSelection(this.storage, this.channelId, this.selected);
    },
    apply(list) {
      this.selected = list;
      this.persist();
    },
    limitText() { return `選べるのは最大 ${this.maxItems} 本までです`; },
    toggle() {
      const r = toggleVideo(this.selected, this.catalogById.get(this.video.id) ?? this.video, this.maxItems);
      this.limitMessage = r.error === 'limit' ? this.limitText() : '';
      this.apply(r.list);
    },
    clearAll() {
      this.limitMessage = '';
      clearSelection(this.storage, this.channelId);
      this.selected = [];
    },
    remove() {
      this.limitMessage = '';
      this.apply(removeVideo(this.selected, this.item.id));
    },
    moveUp() { this.apply(moveVideo(this.selected, this.item.id, -1)); },
    moveDown() { this.apply(moveVideo(this.selected, this.item.id, 1)); },

    async addByUrl() {
      const url = this.urlInput.trim();
      if (url === '' || this.urlBusy) return;
      this.urlBusy = true;
      this.urlMessage = '';
      try {
        const res = await fetch(`${this.lookupUrl}?url=${encodeURIComponent(url)}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        let body = null;
        try { body = await res.json(); } catch { body = null; }
        if (!res.ok || !body || !body.video) {
          this.urlMessage = (body && typeof body.message === 'string' && body.message) || '動画を追加できませんでした。時間をおいて再度お試しください';
          return;
        }
        const v = body.video;
        const fromCatalog = this.catalogById.get(v.id);
        const item = fromCatalog ?? {
          id: String(v.id),
          title: String(v.title ?? ''),
          thumbnail_url: typeof v.thumbnail_url === 'string' ? v.thumbnail_url : null,
          outside: v.outside === true,
        };
        const r = addVideo(this.selected, item, this.maxItems);
        if (r.error === 'duplicate') {
          this.urlMessage = 'この動画はすでに選択されています';
        } else if (r.error === 'limit') {
          this.urlMessage = this.limitText();
        } else {
          this.apply(r.list);
          this.urlInput = '';
        }
      } catch {
        this.urlMessage = '通信に失敗しました。時間をおいて再度お試しください';
      } finally {
        this.urlBusy = false;
      }
    },

    onSubmit(event) {
      if (this.anyOver) {
        event.preventDefault();
        return;
      }
      this.submitting = true;
    },
  }));
});
