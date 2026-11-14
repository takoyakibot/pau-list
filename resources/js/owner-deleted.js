// After the owner deletes a proposal: drop this tab's edit draft for it (it may hold unlisted video ids).
// Drafts in other tabs stay until those tabs are closed (sessionStorage is per tab).
import { safeSessionStorage } from './lib/safe-storage.js';
import { clearDraft } from './lib/draft.js';

const el = document.getElementById('deleted-proposal');
if (el) clearDraft(safeSessionStorage(), el.dataset.draftId ?? '');
