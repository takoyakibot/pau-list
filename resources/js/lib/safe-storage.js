// localStorage can throw on access (private mode, blocked site data).
export function safeStorage() {
  try {
    return window.localStorage ?? null;
  } catch {
    return null;
  }
}

// sessionStorage (per tab): the owner's edit drafts.
export function safeSessionStorage() {
  try {
    return window.sessionStorage ?? null;
  } catch {
    return null;
  }
}
