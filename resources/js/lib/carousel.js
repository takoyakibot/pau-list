// Pure helpers for the home page's scroll-snap carousel (resources/js/home-carousel.js).

/** Keeps an index inside 0..count-1 (0 when there are no slides or the input is not a number). */
export function clampIndex(index, count) {
  if (!Number.isFinite(index) || !Number.isInteger(count) || count < 1) return 0;
  return Math.min(count - 1, Math.max(0, Math.round(index)));
}

/** The slide that fills most of the track: slides are as wide as the track, so round scrollLeft / width. */
export function indexFromScroll(scrollLeft, slideWidth, count) {
  if (!Number.isFinite(scrollLeft) || !Number.isFinite(slideWidth) || slideWidth <= 0) return 0;
  return clampIndex(scrollLeft / slideWidth, count);
}

/** The next/previous slide, wrapping around at either end. */
export function stepIndex(index, delta, count) {
  if (!Number.isInteger(count) || count < 1) return 0;
  const i = clampIndex(index, count) + Math.trunc(delta);
  return ((i % count) + count) % count;
}

/** The scrollLeft that shows slide `index`. */
export function scrollLeftFor(index, slideWidth, count) {
  if (!Number.isFinite(slideWidth) || slideWidth <= 0) return 0;
  return clampIndex(index, count) * slideWidth;
}

/** Instant jumps for prefers-reduced-motion, smooth scrolling otherwise. */
export function scrollBehavior(reducedMotion) {
  return reducedMotion ? 'auto' : 'smooth';
}

/**
 * Behaviour for a move from slide `from` to slide `to`: smooth for a neighbouring slide, but instant for a
 * longer jump (e.g. wrapping from the last slide to the first) or when reduced motion is preferred.
 */
export function navBehavior(from, to, reducedMotion) {
  return reducedMotion || Math.abs(to - from) > 1 ? 'auto' : 'smooth';
}

/** Reads a dot's data-index; null when it is missing or not an integer. */
export function parseIndex(value) {
  if (typeof value !== 'string' || !/^\d+$/.test(value)) return null;
  return Number(value);
}
