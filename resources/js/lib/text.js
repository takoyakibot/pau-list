export function countChars(s) {
  return [...String(s ?? '').replace(/\r\n/g, '\n')].length;
}
