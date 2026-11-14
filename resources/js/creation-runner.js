// S6: runs the creation one step at a time (spec 7.1) and offers pause / resume / stop / retry / recreate.
// Server texts (`message`) are shown as they are; lib/creation-runner.js only has fallbacks.
import {
  isTerminal, nextDelay, processed, progressText, classify, networkDelay, buttonsFor, sanitizeProgress, rowsFor,
  NETWORK_FAILED, PAGE_EXPIRED,
} from './lib/creation-runner.js';

const ACTION_FAILED = '操作できませんでした。時間をおいて再度お試しください';
const GONE = '作成の記録が見つかりません。ページを再読み込みしてください';
const REDIRECT_DELAY_MS = 3000;
const WAIT_MS = 1000;

function readJson(el, name) {
  try {
    return JSON.parse(el.dataset[name] ?? '');
  } catch {
    return null;
  }
}

function serverMessage(body, fallback) {
  return (body && typeof body.message === 'string' && body.message) || fallback;
}

function csrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

/** Only relative paths of this page (e.g. 'edit', 'creation/step') are followed or fetched. */
function relative(url) {
  return typeof url === 'string' && /^[a-z]+(\/[a-z]+)?$/.test(url) ? url : '';
}

async function post(url) {
  try {
    const res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrfToken(),
      },
    });
    let body = null;
    try { body = await res.json(); } catch { body = null; }
    return { status: res.status, body };
  } catch {
    return { network: true };
  }
}

document.addEventListener('alpine:init', () => {
  window.Alpine.data('creationRunner', () => ({
    ready: false,
    progress: null,
    titles: [],
    urls: {},
    running: false,
    busy: false,
    timer: null,
    failures: 0,
    localMessage: '',
    actionMessage: '',
    needsRelogin: false,

    init() {
      const data = readJson(this.$el, 'runner') ?? {};
      this.progress = sanitizeProgress(data.progress) ?? sanitizeProgress({ status: 'pending' });
      this.titles = Array.isArray(data.titles) ? data.titles.map((t) => (typeof t === 'string' ? t : '')) : [];
      const urls = data.urls && typeof data.urls === 'object' ? data.urls : {};
      for (const k of ['step', 'pause', 'resume', 'abort', 'retry', 'recreate']) this.urls[k] = relative(urls[k]);
      this.ready = true;
      if (data.autostart === true && !isTerminal(this.progress.status)) this.startLoop();
    },

    // --- derived ---
    get showInitialList() { return !this.ready; },
    get showLiveList() { return this.ready; },
    get rows() { return rowsFor(this.progress, this.titles); },
    get totalCount() { return this.progress.total; },
    get processedCount() { return processed(this.progress); },
    get progressLabel() { return progressText(this.progress); },
    get statusText() { return this.localMessage || this.progress.message; },
    get hasStatusText() { return this.statusText !== ''; },
    get hasActionMessage() { return this.actionMessage !== ''; },
    get remainingToday() { return this.progress.remaining_today; },
    get playlistUrl() { return this.progress.playlist_url; },
    get hasPlaylist() { return this.progress.playlist_url !== null; },
    get isCompleted() { return this.progress.status === 'completed'; },
    get buttons() { return buttonsFor(this.progress); },
    get showPause() { return this.buttons.pause; },
    // Also after the loop stopped on network failures while the creation itself is still running.
    get showResume() { return this.buttons.resume || (!this.running && this.buttons.pause); },
    get showAbort() { return this.buttons.abort; },
    get showRetry() { return this.buttons.retry; },
    get showRecreate() { return this.buttons.recreate; },
    get retryLabel() { return `失敗分を再試行（必要な枠: 動画 ${this.progress.failed} 本）`; },
    // Reloading S6 sends a logged-out owner to the preview's login link; after login they come back here.
    get reloginHref() { return window.location.pathname; },

    // --- the step loop ---
    startLoop() {
      if (this.running || this.urls.step === '') return;
      this.running = true;
      this.failures = 0;
      this.localMessage = '';
      this.needsRelogin = false;
      this.schedule(0);
    },
    stopLoop(message = '') {
      this.running = false;
      clearTimeout(this.timer);
      this.timer = null;
      if (message !== '') this.localMessage = message;
    },
    schedule(ms) {
      clearTimeout(this.timer);
      this.timer = setTimeout(() => { this.tick(); }, ms);
    },
    apply(body) {
      const p = sanitizeProgress(body);
      if (p) {
        this.progress = p;
        this.localMessage = '';
      }
      return p;
    },
    async tick() {
      if (!this.running) return;
      const r = await post(this.urls.step);
      if (!this.running) return; // paused or stopped while the step was on its way (its result is shown on the next action)
      if (r.network || classify(r.status) === 'error') {
        if (r.status === 419) {
          this.stopLoop(PAGE_EXPIRED);
          return;
        }
        this.failures += 1;
        const wait = networkDelay(this.failures);
        if (wait === null) this.stopLoop(NETWORK_FAILED);
        else this.schedule(wait);
        return;
      }
      this.failures = 0;
      const body = r.body;
      switch (classify(r.status)) {
        case 'ok': {
          if (body && typeof body.redirect === 'string') {
            // YouTube refused the name or description: the creation was deleted; back to the edit page.
            const to = relative(body.redirect);
            this.stopLoop(serverMessage(body, ACTION_FAILED));
            if (to !== '') setTimeout(() => window.location.assign(to), REDIRECT_DELAY_MS);
            return;
          }
          const p = this.apply(body);
          if (!p || isTerminal(p.status)) {
            this.stopLoop();
            return;
          }
          this.schedule(Math.max(nextDelay(body), WAIT_MS));
          return;
        }
        case 'relogin':
          this.needsRelogin = true;
          this.stopLoop(serverMessage(body, ACTION_FAILED));
          return;
        case 'wait':
          this.schedule(WAIT_MS);
          return;
        case 'gone':
          this.stopLoop(serverMessage(body, GONE));
          return;
        default:
          this.stopLoop(serverMessage(body, ACTION_FAILED));
      }
    },

    // --- buttons ---
    async send(name) {
      this.busy = true;
      this.actionMessage = '';
      try {
        const r = await post(this.urls[name]);
        if (r.network) {
          this.actionMessage = NETWORK_FAILED;
          return false;
        }
        if (r.status === 419) {
          this.actionMessage = PAGE_EXPIRED;
          return false;
        }
        const kind = classify(r.status);
        if (kind === 'ok' && this.apply(r.body)) return true;
        if (kind === 'relogin') this.needsRelogin = true;
        // 409 (not enough quota today, or a video still being sent) and the others carry a server message.
        this.actionMessage = serverMessage(r.body, kind === 'gone' ? GONE : ACTION_FAILED);
        return false;
      } finally {
        this.busy = false;
      }
    },
    async onPause() {
      this.stopLoop();
      await this.send('pause');
    },
    async onResume() {
      if (this.busy) return;
      if (this.progress.status === 'paused' && !(await this.send('resume'))) return;
      if (!isTerminal(this.progress.status)) this.startLoop();
    },
    async onAbort() {
      if (this.busy) return;
      const wasRunning = this.running;
      this.stopLoop();
      const ok = await this.send('abort');
      if (!ok && wasRunning) this.startLoop();
    },
    async onRetry() {
      if (this.busy) return;
      if ((await this.send('retry')) && !isTerminal(this.progress.status)) this.startLoop();
    },
    async onRecreate() {
      if (this.busy) return;
      if ((await this.send('recreate')) && !isTerminal(this.progress.status)) this.startLoop();
    },
  }));
});
