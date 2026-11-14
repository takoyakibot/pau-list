// S6: runs the creation one step at a time (spec 7.1) and offers pause / resume / stop / retry / recreate.
// The component lives in lib/creation-runner-component.js (tested in node with a fake fetch).
import { createCreationRunner } from './lib/creation-runner-component.js';

document.addEventListener('alpine:init', () => {
  window.Alpine.data('creationRunner', () => createCreationRunner());
});
