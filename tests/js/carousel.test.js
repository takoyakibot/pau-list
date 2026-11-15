import { test } from 'node:test';
import assert from 'node:assert/strict';
import { clampIndex, indexFromScroll, stepIndex, scrollLeftFor, scrollBehavior, navBehavior, parseIndex } from '../../resources/js/lib/carousel.js';

test('clampIndex keeps the index within the slides', () => {
  assert.equal(clampIndex(-1, 4), 0);
  assert.equal(clampIndex(2, 4), 2);
  assert.equal(clampIndex(9, 4), 3);
  assert.equal(clampIndex(1.6, 4), 2);
  assert.equal(clampIndex(NaN, 4), 0);
  assert.equal(clampIndex(2, 0), 0);
});

test('indexFromScroll rounds to the slide that fills most of the track', () => {
  assert.equal(indexFromScroll(0, 800, 4), 0);
  assert.equal(indexFromScroll(399, 800, 4), 0);
  assert.equal(indexFromScroll(401, 800, 4), 1);
  assert.equal(indexFromScroll(2400, 800, 4), 3);
  assert.equal(indexFromScroll(5000, 800, 4), 3);
  assert.equal(indexFromScroll(-30, 800, 4), 0);
  // Sub-pixel widths (e.g. 358.4px on a phone) still land on the right slide.
  assert.equal(indexFromScroll(716.8, 358.4, 4), 2);
});

test('indexFromScroll falls back to the first slide for a hidden or unmeasured track', () => {
  assert.equal(indexFromScroll(100, 0, 4), 0);
  assert.equal(indexFromScroll(100, NaN, 4), 0);
  assert.equal(indexFromScroll(undefined, 800, 4), 0);
});

test('stepIndex moves one slide and wraps around at both ends', () => {
  assert.equal(stepIndex(0, 1, 4), 1);
  assert.equal(stepIndex(3, 1, 4), 0);
  assert.equal(stepIndex(0, -1, 4), 3);
  assert.equal(stepIndex(2, -1, 4), 1);
  assert.equal(stepIndex(0, 1, 0), 0);
});

test('scrollLeftFor is the slide offset, clamped', () => {
  assert.equal(scrollLeftFor(0, 800, 4), 0);
  assert.equal(scrollLeftFor(2, 800, 4), 1600);
  assert.equal(scrollLeftFor(7, 800, 4), 2400);
  assert.equal(scrollLeftFor(1, 0, 4), 0);
});

test('scrollBehavior is instant when reduced motion is preferred', () => {
  assert.equal(scrollBehavior(true), 'auto');
  assert.equal(scrollBehavior(false), 'smooth');
});

test('navBehavior slides to a neighbour but jumps instantly when wrapping or with reduced motion', () => {
  assert.equal(navBehavior(0, 1, false), 'smooth');
  assert.equal(navBehavior(2, 1, false), 'smooth');
  assert.equal(navBehavior(3, 0, false), 'auto');
  assert.equal(navBehavior(0, 3, false), 'auto');
  assert.equal(navBehavior(0, 1, true), 'auto');
  assert.equal(navBehavior(1, 1, false), 'smooth');
});

test('parseIndex accepts only non-negative integer strings', () => {
  assert.equal(parseIndex('0'), 0);
  assert.equal(parseIndex('3'), 3);
  for (const v of ['', '-1', '1.5', 'a', undefined, null, 2]) assert.equal(parseIndex(v), null, String(v));
});
