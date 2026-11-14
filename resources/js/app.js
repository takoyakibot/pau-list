import Alpine from '@alpinejs/csp';

window.Alpine = Alpine;
// Page entries (e.g. propose.js) register Alpine.data(...) on 'alpine:init' before this runs.
document.addEventListener('DOMContentLoaded', () => Alpine.start());
