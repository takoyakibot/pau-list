import { rememberProposal, clearSelection } from './lib/storage.js';
import { safeStorage } from './lib/safe-storage.js';

function setup() {
  const root = document.getElementById('proposal-shared');
  if (!root) return;
  const { url = '', title = '', channelTitle = '', channelId = '', expiresAt = '' } = root.dataset;
  const storage = safeStorage();
  const now = Date.now();
  const expires = Date.parse(expiresAt);

  if (url !== '' && Number.isFinite(expires)) {
    rememberProposal(storage, { url, title, channelTitle, channelId, expiresAt: expires }, now);
  }
  if (channelId !== '') clearSelection(storage, channelId);

  const input = document.getElementById('share-url');
  const button = document.getElementById('copy-share-url');
  const status = document.getElementById('copy-status');
  if (!input || !button || !status) return;

  const fallback = () => {
    input.focus();
    input.select();
    status.textContent = 'コピーできませんでした。選択された URL を手動でコピーしてください';
  };

  button.addEventListener('click', async () => {
    try {
      if (!navigator.clipboard) throw new Error('no clipboard');
      await navigator.clipboard.writeText(input.value);
      status.textContent = 'コピーしました';
    } catch {
      fallback();
    }
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', setup);
} else {
  setup();
}
