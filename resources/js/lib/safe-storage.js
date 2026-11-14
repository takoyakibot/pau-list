// localStorage can throw on access (private mode, blocked site data).
export function safeStorage() {
  try {
    return window.localStorage ?? null;
  } catch {
    return null;
  }
}
