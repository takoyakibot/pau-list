import { addVideo, removeVideo, moveVideo, toggleVideo, maxItemsFrom } from './lib/selection.js';
import { formatPublishedDate } from './lib/format.js';
import { filterVideos } from './lib/filter.js';
import { countChars, isOverLimit } from './lib/text.js';
import { safeSessionStorage } from './lib/safe-storage.js';
import { loadDraft, saveDraft, clearDraft } from './lib/draft.js';
import {
  normalizePrivacy, remainingFrom, creationQuota, shortageText, isCreateDisabled as createDisabled,
  restoreDraftItems, sanitizeUnlisted, mergeUnlisted, upgradePlaceholders, unlistedResultMessage, rowActionLabel, isBlankTitle,
  creationRequestBody, startFailure, markUnavailable, unlistedFailure, withRelogin, publicUnlistedNote,
  TITLE_REQUIRED, DRAFT_RESTORED, TITLE_MAX, DESCRIPTION_MAX,
} from './lib/owner-edit.js';

const MAX_MISSING = '最大本数を読み込めなかったため、動画を選べません。ページを再読み込みしてください';
const UNLISTED_FAILED = 'あなたの限定公開の動画を読み込めませんでした。公開動画の一覧と動画 URL で追加できます';
const NETWORK_FAILED = '通信に失敗しました。時間をおいて再度お試しください';

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

function toItem(x) {
  return {
    id: String(x.id),
    title: String(x.title ?? ''),
    thumbnail_url: typeof x.thumbnail_url === 'string' ? x.thumbnail_url : null,
    outside: x.outside === true,
  };
}

function serverMessage(body, fallback) {
  return (body && typeof body.message === 'string' && body.message) || fallback;
}

const JSON_HEADERS = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

function csrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

// Only a relative path of this page (the start API answers 'creation') is followed.
function relativeRedirect(body) {
  const url = body !== null && typeof body === 'object' ? body.redirect : null;
  return typeof url === 'string' && /^[a-z]+$/.test(url) ? url : '';
}

document.addEventListener('alpine:init', () => {
  window.Alpine.data('ownerEditor', () => ({
    ready: false,
    draftId: '',
    lookupUrl: '',
    unlistedUrl: '',
    creationUrl: '',
    createBusy: false,
    resetLabel: '',
    maxItems: 0,
    remainingItems: 0,
    initial: { items: [], title: '', description: '', privacy: 'private' },
    byId: new Map(),
    videos: [],
    items: [],
    query: '',
    title: '',
    description: '',
    privacy: 'private',
    urlInput: '',
    urlMessage: '',
    urlBusy: false,
    limitMessage: '',
    unlistedBusy: false,
    unlistedLoaded: false,
    unlistedMessage: '',
    relogin: { create: false, unlisted: false },
    createMessage: '',
    restoredMessage: '',
    storage: null,

    init() {
      const editor = readJson(this.$el, 'editor') ?? {};
      this.draftId = typeof editor.draftId === 'string' ? editor.draftId : '';
      this.lookupUrl = typeof editor.lookupUrl === 'string' ? editor.lookupUrl : '';
      this.unlistedUrl = typeof editor.unlistedUrl === 'string' ? editor.unlistedUrl : '';
      this.creationUrl = typeof editor.creationUrl === 'string' && /^[a-z]+$/.test(editor.creationUrl) ? editor.creationUrl : '';
      this.resetLabel = typeof editor.resetLabel === 'string' ? editor.resetLabel : '';
      this.maxItems = maxItemsFrom(editor);
      this.remainingItems = remainingFrom(editor);
      this.videos = Array.isArray(editor.videos) ? editor.videos : [];

      const proposalItems = (Array.isArray(editor.items) ? editor.items : [])
        .filter((x) => x !== null && typeof x === 'object' && typeof x.id === 'string')
        .map(toItem);
      this.initial = {
        items: proposalItems,
        title: typeof editor.title === 'string' ? editor.title : '',
        description: typeof editor.description === 'string' ? editor.description : '',
        privacy: normalizePrivacy(editor.privacy),
      };
      // Proposal items first (they keep their outside flag), channel videos (current values) override.
      this.byId = new Map(proposalItems.map((x) => [x.id, x]));
      for (const v of this.videos) this.byId.set(v.id, { ...toItem(v), outside: false });

      this.storage = safeSessionStorage();
      const draft = loadDraft(this.storage, this.draftId);
      if (draft) {
        this.items = restoreDraftItems(draft.items, this.byId, this.maxItems);
        this.title = draft.title;
        this.description = draft.description;
        this.privacy = draft.privacy;
        this.restoredMessage = DRAFT_RESTORED;
      } else {
        this.applyInitial();
      }

      this.$watch('title', () => this.persist());
      this.$watch('description', () => this.persist());
      this.$watch('privacy', () => { this.privacy = normalizePrivacy(this.privacy); this.persist(); });
      this.ready = true;
    },

    // --- derived ---
    get showInitialList() { return !this.ready; },
    get showLiveList() { return this.ready; },
    get count() { return this.items.length; },
    get isEmpty() { return this.items.length === 0; },
    get hasVideos() { return this.videos.length > 0; },
    get filtered() { return filterVideos(this.videos, this.query); },
    get isFull() { return this.items.length >= this.maxItems; },
    get quota() { return creationQuota(this.count, this.remainingItems); },
    get neededText() { return this.quota.neededText; },
    // The server line is the no-JS state; once running, the live line below replaces it.
    get showServerShortage() { return !this.ready; },
    // Nothing left today: the remaining-today line already gives the reset time; reducing would not help.
    get hasShortage() { return this.ready && this.remainingItems > 0 && this.quota.shortBy > 0; },
    get shortageText() { return this.hasShortage ? shortageText(this.quota.shortBy, this.resetLabel) : ''; },
    get titleCounter() { return `${countChars(this.title)} / ${TITLE_MAX}`; },
    get descriptionCounter() { return `${countChars(this.description)} / ${DESCRIPTION_MAX}`; },
    get titleOver() { return isOverLimit(this.title, TITLE_MAX); },
    get titleEmpty() { return this.ready && isBlankTitle(this.title); },
    get titleRequiredText() { return TITLE_REQUIRED; },
    get hasRestoredMessage() { return this.restoredMessage !== ''; },
    get descriptionOver() { return isOverLimit(this.description, DESCRIPTION_MAX); },
    get titleCounterClass() { return counterClass(this.titleOver); },
    get descriptionCounterClass() { return counterClass(this.descriptionOver); },
    get isCreateDisabled() {
      return this.createBusy || this.creationUrl === '' || createDisabled({
        count: this.count, maxItems: this.maxItems, remainingItems: this.remainingItems, title: this.title, description: this.description,
      });
    },
    get hasLimitMessage() { return this.limitMessage !== ''; },
    get hasUrlMessage() { return this.urlMessage !== ''; },
    get hasUnlistedMessage() { return this.unlistedMessage !== ''; },
    get hasCreateMessage() { return this.createMessage !== ''; },
    get createNeedsRelogin() { return this.relogin.create; },
    get unlistedNeedsRelogin() { return this.relogin.unlisted; },
    get publicUnlistedNote() { return publicUnlistedNote(this.privacy, this.items); },
    get hasPublicUnlistedNote() { return this.publicUnlistedNote !== ''; },
    get canLoadUnlisted() { return this.unlistedUrl !== '' && !this.unlistedLoaded; },
    // Reloading S5 sends an expired owner back to the preview with its login link; the draft survives.
    get reloginHref() { return window.location.pathname; },

    // --- per-row helpers (this.video / this.item from x-for scope) ---
    isSelected() { return this.items.some((x) => x.id === this.video.id); },
    isCheckDisabled() { return this.isFull && !this.isSelected(); },
    publishedLabel() { return formatPublishedDate(this.video.published_at); },
    moveUpLabel() { return rowActionLabel(this.item, 'up'); },
    moveDownLabel() { return rowActionLabel(this.item, 'down'); },
    removeLabel() { return rowActionLabel(this.item, 'remove'); },

    // --- actions ---
    applyInitial() {
      this.items = this.initial.items.map((x) => ({ ...x }));
      this.title = this.initial.title;
      this.description = this.initial.description;
      this.privacy = this.initial.privacy;
    },
    isInitialState() {
      const a = this.items; const b = this.initial.items;
      return a.length === b.length && a.every((x, i) => x.id === b[i].id)
        && this.title === this.initial.title && this.description === this.initial.description
        && this.privacy === this.initial.privacy;
    },
    persist() {
      // A draft equal to the proposal is not kept (also covers the watchers firing after a reset).
      if (this.isInitialState()) {
        clearDraft(this.storage, this.draftId);
        return;
      }
      saveDraft(this.storage, this.draftId, {
        items: this.items, title: this.title, description: this.description, privacy: this.privacy,
      });
    },
    apply(list) {
      this.items = list;
      this.persist();
    },
    limitText() {
      return this.maxItems === 0 ? MAX_MISSING : `選べるのは最大 ${this.maxItems} 本までです`;
    },
    toggle() {
      const r = toggleVideo(this.items, this.byId.get(this.video.id) ?? toItem(this.video), this.maxItems);
      this.limitMessage = r.error === 'limit' ? this.limitText() : '';
      this.apply(r.list);
    },
    remove() {
      this.limitMessage = '';
      this.apply(removeVideo(this.items, this.item.id));
    },
    moveUp() { this.apply(moveVideo(this.items, this.item.id, -1)); },
    moveDown() { this.apply(moveVideo(this.items, this.item.id, 1)); },
    resetToProposal() {
      clearDraft(this.storage, this.draftId);
      this.applyInitial();
      this.limitMessage = '';
      this.restoredMessage = '';
      this.urlMessage = '';
      this.createMessage = '';
    },
    // 「作成」: POST the edited list (JSON) to the start API; on success S6 takes over and the draft is dropped.
    async onCreate() {
      if (this.isCreateDisabled) return;
      this.createBusy = true;
      this.createMessage = '';
      this.relogin = withRelogin(this.relogin, 'create', false);
      try {
        const res = await fetch(this.creationUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { ...JSON_HEADERS, 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
          body: JSON.stringify(creationRequestBody({
            items: this.items, title: this.title, description: this.description, privacy: this.privacy,
          })),
        });
        let body = null;
        try { body = await res.json(); } catch { body = null; }
        const redirect = relativeRedirect(body);
        if ((res.status === 200 || res.status === 201) && redirect !== '') {
          clearDraft(this.storage, this.draftId);
          window.location.assign(redirect);
          return; // stays busy: the page is leaving
        }
        const failure = startFailure(res.status, body);
        this.createMessage = failure.message;
        this.relogin = withRelogin(this.relogin, 'create', failure.relogin);
        this.items = markUnavailable(this.items, failure.unavailableIds);
      } catch {
        this.createMessage = NETWORK_FAILED;
      }
      this.createBusy = false;
    },

    async addByUrl() {
      const url = this.urlInput.trim();
      if (url === '' || this.urlBusy || this.lookupUrl === '') return;
      this.urlBusy = true;
      this.urlMessage = '';
      try {
        const res = await fetch(`${this.lookupUrl}?url=${encodeURIComponent(url)}`, { headers: JSON_HEADERS });
        let body = null;
        try { body = await res.json(); } catch { body = null; }
        if (!res.ok || !body || !body.video || typeof body.video.id !== 'string') {
          this.urlMessage = serverMessage(body, '動画を追加できませんでした。時間をおいて再度お試しください');
          return;
        }
        const v = body.video;
        const item = this.byId.get(v.id) ?? toItem({ ...v, outside: v.outside === true });
        const r = addVideo(this.items, item, this.maxItems);
        if (r.error === 'duplicate') {
          this.urlMessage = 'この動画はすでに選択されています';
        } else if (r.error === 'limit') {
          this.urlMessage = this.limitText();
        } else {
          this.apply(r.list);
          this.urlInput = '';
        }
      } catch {
        this.urlMessage = NETWORK_FAILED;
      } finally {
        this.urlBusy = false;
      }
    },

    async loadUnlisted() {
      if (!this.canLoadUnlisted || this.unlistedBusy) return;
      this.unlistedBusy = true;
      this.unlistedMessage = '';
      this.relogin = withRelogin(this.relogin, 'unlisted', false);
      try {
        const res = await fetch(this.unlistedUrl, { headers: JSON_HEADERS, credentials: 'same-origin' });
        let body = null;
        try { body = await res.json(); } catch { body = null; }
        if (!res.ok) {
          // 401 asks for login; 429 and 503 carry a Japanese message from the server.
          const failure = unlistedFailure(res.status, body, UNLISTED_FAILED);
          this.unlistedMessage = failure.message;
          this.relogin = withRelogin(this.relogin, 'unlisted', failure.relogin);
          return;
        }
        const unlisted = sanitizeUnlisted(body);
        this.videos = mergeUnlisted(this.videos, unlisted);
        for (const v of unlisted) this.byId.set(v.id, { ...toItem(v), outside: false, unlisted: true });
        this.items = upgradePlaceholders(this.items, unlisted);
        this.unlistedLoaded = true;
        this.unlistedMessage = unlistedResultMessage(unlisted.length, body.truncated);
      } catch {
        this.unlistedMessage = NETWORK_FAILED;
      } finally {
        this.unlistedBusy = false;
      }
    },
  }));
});
