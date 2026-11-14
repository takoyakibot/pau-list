// After logout: drop every edit draft in this tab (drafts may hold the owner's unlisted video ids).
import { safeSessionStorage } from './lib/safe-storage.js';
import { clearAllDrafts } from './lib/draft.js';

clearAllDrafts(safeSessionStorage());
