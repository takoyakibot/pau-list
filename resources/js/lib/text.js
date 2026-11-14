// Counts Unicode code points (as the server's mb_strlen does), with CRLF as one character.
// The browser's maxlength counts UTF-16 units instead, so it is not used.
export function countChars(s) {
  return [...String(s ?? '').replace(/\r\n/g, '\n')].length;
}

export function isOverLimit(s, limit) {
  return countChars(s) > limit;
}
