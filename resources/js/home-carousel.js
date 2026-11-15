// Home「List Maker のしくみ」: a CSS scroll-snap carousel. Swiping and keyboard scrolling are native (and work
// without JS); this component only keeps the dots in step with the scroll position and scrolls on button clicks.
import { indexFromScroll, stepIndex, scrollLeftFor, scrollBehavior, navBehavior, parseIndex } from './lib/carousel.js';

function prefersReducedMotion() {
  try {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  } catch {
    return false;
  }
}

document.addEventListener('alpine:init', () => {
  window.Alpine.data('howtoCarousel', () => ({
    index: 0,
    count: 0,
    frame: 0,

    init() {
      const track = this.$refs.track;
      this.count = track.children.length;
      // Hides the scrollbar once the arrow and dot buttons are usable (they are x-cloak until then).
      track.dataset.enhanced = '';
      track.addEventListener('scroll', () => this.queueSync(), { passive: true });
      this.sync();
    },

    queueSync() {
      if (this.frame) return;
      this.frame = window.requestAnimationFrame(() => {
        this.frame = 0;
        this.sync();
      });
    },

    sync() {
      const track = this.$refs.track;
      this.index = indexFromScroll(track.scrollLeft, track.clientWidth, this.count);
      for (const dot of this.$refs.dots.querySelectorAll('[data-index]')) {
        if (parseIndex(dot.dataset.index) === this.index) {
          dot.setAttribute('aria-current', 'true');
        } else {
          dot.removeAttribute('aria-current');
        }
      }
    },

    show(i, behavior = scrollBehavior(prefersReducedMotion())) {
      const track = this.$refs.track;
      track.scrollTo({ left: scrollLeftFor(i, track.clientWidth, this.count), behavior });
    },

    // Wrapping (last → first, first → last) jumps instantly instead of sliding past every slide.
    step(delta) {
      const to = stepIndex(this.index, delta, this.count);
      this.show(to, navBehavior(this.index, to, prefersReducedMotion()));
    },

    prev() {
      this.step(-1);
    },

    next() {
      this.step(1);
    },

    goTo(event) {
      const i = parseIndex(event?.currentTarget?.dataset?.index);
      if (i !== null) this.show(i);
    },
  }));
});
