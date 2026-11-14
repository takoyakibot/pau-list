// The S6 Alpine component (registered as `creationRunner` by ../creation-runner.js). Browser APIs come in
// through `deps` so the step loop can be tested in node with a fake fetch and fake timers.
import {
  isTerminal, nextDelay, processed, progressText, classify, networkDelay, buttonsFor, sanitizeProgress, rowsFor,
  NETWORK_FAILED, PAGE_EXPIRED, IDLE_HINT,
} from './creation-runner.js';

const ACTION_FAILED = '操作できませんでした。時間をおいて再度お試しください';
const GONE = '作成の記録が見つかりません。ページを再読み込みしてください';
const REDIRECT_DELAY_MS = 3000;
const WAIT_MS = 1000;
const ACTIONS = ['step', 'pause', 'resume', 'abort', 'retry', 'recreate'];

function serverMessage(body, fallback) {
  return (body && typeof body.message === 'string' && body.message) || fallback;
}

/** Only relative paths of this page (e.g. 'edit', 'creation/step') are followed or fetched. */
function relative(url) {
  return typeof url === 'string' && /^[a-z]+(\/[a-z]+)?$/.test(url) ? url : '';
}

function csrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

/** POST to a JSON endpoint: {status, body}, or {network: true} when no answer arrived. */
export async function postJson(url) {
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

const BROWSER = {
  post: postJson,
  setTimer: (fn, ms) => setTimeout(fn, ms),
  clearTimer: (t) => clearTimeout(t),
  assign: (url) => window.location.assign(url),
  pathname: () => window.location.pathname,
};

export function createCreationRunner(deps = BROWSER) {
  return {
    ready: false,
    progress: sanitizeProgress({ status: 'pending' }),
    titles: [],
    urls: {},
    running: false,
    // Each start/stop of the loop is a new generation: a step answer or timer of an older one is dropped.
    gen: 0,
    // At most one step request at a time (a resume during a step waits for it instead of starting a second chain).
    inFlight: false,
    busy: false,
    timer: null,
    failures: 0,
    localMessage: '',
    actionMessage: '',
    needsRelogin: false,
    // 「残りを中止」 is two clicks: the first arms it (and shows the warning), the second sends it.
    abortArmed: false,

    init() {
      let data = null;
      try { data = JSON.parse(this.$el.dataset.runner ?? ''); } catch { data = null; }
      this.load(data ?? {});
    },
    load(data) {
      this.progress = sanitizeProgress(data.progress) ?? sanitizeProgress({ status: 'pending' });
      this.titles = Array.isArray(data.titles) ? data.titles.map((t) => (typeof t === 'string' ? t : '')) : [];
      const urls = data.urls && typeof data.urls === 'object' ? data.urls : {};
      for (const k of ACTIONS) this.urls[k] = relative(urls[k]);
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
    get statusText() {
      if (this.localMessage || this.progress.message) return this.localMessage || this.progress.message;
      // Running on the server but not stepping here (opened again): it only advances while this page runs it.
      return this.ready && !this.running && this.buttons.pause ? IDLE_HINT : '';
    },
    get hasStatusText() { return this.statusText !== ''; },
    get hasActionMessage() { return this.actionMessage !== ''; },
    get remainingToday() { return this.progress.remaining_today; },
    get playlistUrl() { return this.progress.playlist_url; },
    get hasPlaylist() { return this.progress.playlist_url !== null; },
    get isCompleted() { return this.progress.status === 'completed'; },
    get showRemakeHint() { return this.isCompleted && !this.hasPlaylist && this.progress.playlist_created !== true; },
    get buttons() { return buttonsFor(this.progress); },
    get showPause() { return this.buttons.pause; },
    // Also after the loop stopped on network failures while the creation itself is still running.
    get showResume() { return this.buttons.resume || (!this.running && this.buttons.pause); },
    get showAbort() { return this.buttons.abort; },
    get showAbortWarning() { return this.abortArmed && this.showAbort; },
    get abortLabel() { return this.showAbortWarning ? '中止する（確定）' : '残りを中止'; },
    get showRetry() { return this.buttons.retry; },
    get showRecreate() { return this.buttons.recreate; },
    get retryLabel() { return `失敗分を再試行（必要な枠: 動画 ${this.progress.failed} 本）`; },
    // Reloading S6 sends a logged-out owner to the preview's login link; after login they come back here.
    get reloginHref() { return deps.pathname(); },

    // --- the step loop ---
    startLoop() {
      if (this.running || this.urls.step === '') return;
      this.running = true;
      this.gen += 1;
      this.failures = 0;
      this.localMessage = '';
      this.needsRelogin = false;
      // A step of the previous generation is still on its way: tick() schedules this loop when it returns.
      if (!this.inFlight) this.schedule(0);
    },
    stopLoop(message = '') {
      this.running = false;
      this.gen += 1;
      this.cancelTimer();
      if (message !== '') this.localMessage = message;
    },
    cancelTimer() {
      if (this.timer !== null) deps.clearTimer(this.timer);
      this.timer = null;
    },
    schedule(ms) {
      this.cancelTimer();
      const gen = this.gen;
      this.timer = deps.setTimer(() => {
        this.timer = null;
        if (gen === this.gen) this.tick();
      }, ms);
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
      if (!this.running || this.inFlight) return;
      const gen = this.gen;
      this.inFlight = true;
      let r;
      try {
        r = await deps.post(this.urls.step);
      } finally {
        this.inFlight = false;
      }
      if (gen !== this.gen) {
        // Paused / stopped (and maybe resumed) meanwhile: drop this answer; a resumed loop starts now.
        if (this.running && this.timer === null) this.schedule(0);
        return;
      }
      this.handleStep(r);
    },
    handleStep(r) {
      if (r.network) {
        this.retryLater();
        return;
      }
      const body = r.body;
      if (r.status === 419) {
        this.stopLoop(PAGE_EXPIRED);
        return;
      }
      if (r.status === 403) {
        // Same-origin check or another channel's login: retrying cannot help.
        this.stopLoop(serverMessage(body, ACTION_FAILED));
        return;
      }
      switch (classify(r.status)) {
        case 'ok': {
          this.failures = 0;
          if (body && typeof body.redirect === 'string') {
            // YouTube refused the name or description: the creation was deleted; back to the edit page.
            const to = relative(body.redirect);
            this.stopLoop(serverMessage(body, ACTION_FAILED));
            if (to !== '') deps.setTimer(() => deps.assign(to), REDIRECT_DELAY_MS);
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
        case 'conflict':
          this.stopLoop(serverMessage(body, ACTION_FAILED));
          return;
        default:
          this.retryLater(); // 5xx: like a network failure
      }
    },
    retryLater() {
      this.failures += 1;
      const wait = networkDelay(this.failures);
      if (wait === null) this.stopLoop(NETWORK_FAILED);
      else this.schedule(wait);
    },

    // --- buttons ---
    async send(name) {
      this.busy = true;
      this.actionMessage = '';
      try {
        const r = await deps.post(this.urls[name]);
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
        // 409 (not enough quota today, Google's limit not reset yet, or a video still being sent) carry a server message.
        this.actionMessage = serverMessage(r.body, kind === 'gone' ? GONE : ACTION_FAILED);
        return false;
      } finally {
        this.busy = false;
      }
    },
    async onPause() {
      this.abortArmed = false;
      this.stopLoop();
      await this.send('pause');
    },
    async onResume() {
      if (this.busy) return;
      this.abortArmed = false;
      if (this.progress.status === 'paused' && !(await this.send('resume'))) return;
      if (!isTerminal(this.progress.status)) this.startLoop();
    },
    onAbortCancel() {
      this.abortArmed = false;
    },
    async onAbort() {
      if (this.busy) return;
      if (!this.showAbortWarning) {
        this.abortArmed = true;
        return;
      }
      this.abortArmed = false;
      const wasRunning = this.running;
      this.stopLoop();
      const ok = await this.send('abort');
      if (!ok && wasRunning) this.startLoop();
    },
    async onRetry() {
      if (this.busy) return;
      this.abortArmed = false;
      if ((await this.send('retry')) && !isTerminal(this.progress.status)) this.startLoop();
    },
    async onRecreate() {
      if (this.busy) return;
      this.abortArmed = false;
      if ((await this.send('recreate')) && !isTerminal(this.progress.status)) this.startLoop();
    },
  };
}
