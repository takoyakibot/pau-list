import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createCreationRunner } from '../../resources/js/lib/creation-runner-component.js';

const URLS = {
  step: 'creation/step', pause: 'creation/pause', resume: 'creation/resume',
  abort: 'creation/abort', retry: 'creation/retry', recreate: 'creation/recreate',
};

function progress(status, extra = {}) {
  return {
    status, pause_reason: null, done: 0, failed: 0, skipped: 0, total: 2, playlist_url: null,
    message: null, remaining_today: 'r', items: [], ...extra,
  };
}

/** A fetch stand-in: every request waits until the test answers it. */
function harness() {
  const requests = [];
  const timers = [];
  const deps = {
    post: (url) => new Promise((resolve) => { requests.push({ url, resolve }); }),
    setTimer: (fn, ms) => { const t = { fn, ms, cleared: false }; timers.push(t); return t; },
    clearTimer: (t) => { if (t) t.cleared = true; },
    assign: () => {},
    pathname: () => '/p/x/creation',
  };
  const runner = createCreationRunner(deps);
  const flush = () => new Promise((r) => setImmediate(r));
  const fire = async () => {
    const t = timers.filter((x) => !x.cleared && !x.fired).at(-1);
    assert.ok(t, 'a pending timer');
    t.fired = true;
    t.fn();
    await flush();
  };
  const pendingTimers = () => timers.filter((x) => !x.cleared && !x.fired);
  const steps = () => requests.filter((r) => r.url === URLS.step);
  return { runner, requests, timers, flush, fire, pendingTimers, steps };
}

test('pause then resume while a step is in flight keeps a single step chain', async () => {
  const h = harness();
  h.runner.load({ progress: progress('adding'), autostart: true, urls: URLS, titles: [] });
  await h.fire(); // step A sent
  assert.equal(h.steps().length, 1);

  const pausing = h.runner.onPause();
  await h.flush();
  h.requests.at(-1).resolve({ status: 200, body: progress('paused', { pause_reason: 'user' }) });
  await pausing;

  const resuming = h.runner.onResume();
  await h.flush();
  h.requests.at(-1).resolve({ status: 200, body: progress('adding') });
  await resuming;
  await h.flush();
  // Step A is still on its way: resuming must not send a second step now.
  assert.equal(h.steps().length, 1);
  assert.equal(h.pendingTimers().length, 0);

  // A answers late: its result is dropped, and exactly one next step is scheduled for the new loop.
  h.steps()[0].resolve({ status: 200, body: { ...progress('adding', { done: 1 }), next_delay_ms: 1000 } });
  await h.flush();
  assert.equal(h.runner.progress.done, 0);
  assert.equal(h.pendingTimers().length, 1);
  await h.fire();
  assert.equal(h.steps().length, 2);
  assert.equal(h.pendingTimers().length, 0);

  h.steps()[1].resolve({ status: 200, body: { ...progress('adding', { done: 1 }), next_delay_ms: 1000 } });
  await h.flush();
  assert.equal(h.runner.progress.done, 1);
  assert.equal(h.pendingTimers().length, 1);
});

test('a late step answer after a pause is ignored and schedules nothing', async () => {
  const h = harness();
  h.runner.load({ progress: progress('adding'), autostart: true, urls: URLS, titles: [] });
  await h.fire();
  const pausing = h.runner.onPause();
  await h.flush();
  h.requests.at(-1).resolve({ status: 200, body: progress('paused', { pause_reason: 'user' }) });
  await pausing;
  h.steps()[0].resolve({ status: 200, body: { ...progress('adding', { done: 1 }), next_delay_ms: 1000 } });
  await h.flush();
  assert.equal(h.runner.progress.status, 'paused');
  assert.equal(h.pendingTimers().length, 0);
  assert.equal(h.runner.running, false);
});

test('403 on a step stops the loop with the server message (no retry)', async () => {
  const h = harness();
  h.runner.load({ progress: progress('adding'), autostart: true, urls: URLS, titles: [] });
  await h.fire();
  h.steps()[0].resolve({ status: 403, body: { message: 'ログイン中のチャンネルはこの提案の対象ではありません' } });
  await h.flush();
  assert.equal(h.runner.running, false);
  assert.equal(h.runner.statusText, 'ログイン中のチャンネルはこの提案の対象ではありません');
  assert.equal(h.pendingTimers().length, 0);
});

test('network failures back off and stop after 5', async () => {
  const h = harness();
  h.runner.load({ progress: progress('adding'), autostart: true, urls: URLS, titles: [] });
  const waits = [];
  for (let i = 0; i < 6; i++) {
    await h.fire();
    h.steps().at(-1).resolve({ network: true });
    await h.flush();
    const t = h.pendingTimers().at(-1);
    waits.push(t ? t.ms : null);
  }
  assert.deepEqual(waits, [2000, 4000, 8000, 16000, 32000, null]);
  assert.equal(h.runner.running, false);
  assert.equal(h.runner.statusText, '通信に失敗しました。「続きから」で再開できます');
  assert.equal(h.runner.showResume, true);
});

// ---- final review (UX m3, m6) ----

test('a running creation whose loop is not running (reload) says it only advances while the page is open', () => {
  const h = harness();
  h.runner.load({ progress: progress('adding'), autostart: false, urls: URLS, titles: [] });
  assert.equal(h.runner.running, false);
  assert.equal(h.runner.statusText, 'この画面を開いている間だけ進みます。「続きから」で再開できます');
  assert.equal(h.runner.showResume, true);

  // Running (autostart) or paused/completed: no such hint.
  const a = harness();
  a.runner.load({ progress: progress('adding'), autostart: true, urls: URLS, titles: [] });
  assert.equal(a.runner.statusText, '');
  const p = harness();
  p.runner.load({ progress: progress('paused', { message: '一時停止中です。「続きから」で再開できます' }), autostart: false, urls: URLS, titles: [] });
  assert.equal(p.runner.statusText, '一時停止中です。「続きから」で再開できます');
  const c = harness();
  c.runner.load({ progress: progress('completed'), autostart: false, urls: URLS, titles: [] });
  assert.equal(c.runner.statusText, '');
});

test('「残りを中止」 needs a second click to confirm; the first only arms it', async () => {
  const h = harness();
  h.runner.load({ progress: progress('adding'), autostart: true, urls: URLS, titles: [] });
  assert.equal(h.runner.abortLabel, '残りを中止');
  assert.equal(h.runner.showAbortWarning, false);

  await h.runner.onAbort();
  assert.equal(h.requests.filter((r) => r.url === URLS.abort).length, 0);
  assert.equal(h.runner.running, true); // nothing stopped yet
  assert.equal(h.runner.abortLabel, '中止する（確定）');
  assert.equal(h.runner.showAbortWarning, true);

  // Cancelling disarms without sending.
  h.runner.onAbortCancel();
  assert.equal(h.runner.showAbortWarning, false);
  assert.equal(h.runner.abortLabel, '残りを中止');

  await h.runner.onAbort();
  const aborting = h.runner.onAbort();
  await h.flush();
  assert.equal(h.requests.filter((r) => r.url === URLS.abort).length, 1);
  h.requests.at(-1).resolve({ status: 200, body: progress('completed', { skipped: 2 }) });
  await aborting;
  assert.equal(h.runner.progress.status, 'completed');
  assert.equal(h.runner.showAbortWarning, false);
  assert.equal(h.runner.abortLabel, '残りを中止');
});

test('another button disarms a pending abort confirmation', async () => {
  const h = harness();
  h.runner.load({ progress: progress('paused', { pause_reason: 'user' }), autostart: false, urls: URLS, titles: [] });
  await h.runner.onAbort();
  assert.equal(h.runner.showAbortWarning, true);
  const resuming = h.runner.onResume();
  assert.equal(h.runner.showAbortWarning, false);
  await h.flush();
  h.requests.at(-1).resolve({ status: 200, body: progress('adding') });
  await resuming;
  assert.equal(h.requests.filter((r) => r.url === URLS.abort).length, 0);
});
