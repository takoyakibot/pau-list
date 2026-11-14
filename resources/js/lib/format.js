const JST_DATE = new Intl.DateTimeFormat('ja-JP', { timeZone: 'Asia/Tokyo', month: 'numeric', day: 'numeric' });

// "n月j日" in Japan time (the server shows every date in JST), '' when missing or invalid.
export function formatPublishedDate(iso) {
  if (typeof iso !== 'string' || iso === '') return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  const parts = Object.fromEntries(JST_DATE.formatToParts(d).map((p) => [p.type, p.value]));
  return `${parts.month}月${parts.day}日`;
}
