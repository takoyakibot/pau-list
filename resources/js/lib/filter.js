export function normalizeText(s) {
  return String(s ?? '').normalize('NFKC').toLowerCase().trim();
}

export function filterVideos(videos, query) {
  const words = normalizeText(query).split(/\s+/).filter(Boolean);
  if (words.length === 0) return [...videos];
  return videos.filter((v) => {
    const t = normalizeText(v.title);
    return words.every((w) => t.includes(w));
  });
}
