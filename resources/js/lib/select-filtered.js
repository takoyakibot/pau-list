// 「絞り込み結果をすべて選択」 (proposer S2 and owner S5). Pure helpers; never mutate arguments.
// Videos are added through selection.js addVideo, the same path as a checkbox, in the list's displayed order.
import { addVideo } from './selection.js';
import { normalizeText } from './filter.js';

// Only a non-blank filter counts (the button is not a "select everything" shortcut).
export function hasFilter(query) {
  return normalizeText(query) !== '';
}

// Filtered videos not selected yet (an already selected id, placeholder or not, is left alone), first occurrence only.
export function unselectedFiltered(filtered, selected) {
  const seen = new Set((Array.isArray(selected) ? selected : []).map((x) => x.id));
  const out = [];
  for (const v of Array.isArray(filtered) ? filtered : []) {
    if (v === null || typeof v !== 'object' || typeof v.id !== 'string' || seen.has(v.id)) continue;
    seen.add(v.id);
    out.push(v);
  }
  return out;
}

// The number shown on the button: 0 without a filter.
export function selectFilteredCount(query, filtered, selected) {
  return hasFilter(query) ? unselectedFiltered(filtered, selected).length : 0;
}

// Like the per-row checkboxes, nothing can be added once the selection is at the cap (or the cap is unknown).
export function canSelectFiltered(query, filtered, selected, max) {
  const n = Array.isArray(selected) ? selected.length : 0;
  return max >= 1 && n < max && selectFilteredCount(query, filtered, selected) > 0;
}

// The count only when there is something to add.
export function selectFilteredLabel(count) {
  return count >= 1 ? `絞り込み結果をすべて選択（${count} 本）` : '絞り込み結果をすべて選択';
}

// Adds the filtered, not-yet-selected videos in order until the cap. `resolve` maps a list video to the item the
// checkbox would add (keeps the outside / unlisted flags of that screen). Returns {list, wanted, added}.
export function selectFiltered(selected, filtered, max, resolve = (v) => v) {
  const wanted = unselectedFiltered(filtered, selected);
  let list = [...selected];
  let added = 0;
  for (const v of wanted) {
    const r = addVideo(list, resolve(v), max);
    if (r.error === 'limit') break;
    if (r.error === null) {
      list = r.list;
      added += 1;
    }
  }
  return { list, wanted: wanted.length, added };
}

// The result, announced politely next to the button: how many were added, and why not all when the cap cut it short.
export function selectFilteredMessage(wanted, added, max) {
  if (!(wanted >= 1)) return '';
  return added < wanted ? `上限 ${max} 本のため、${wanted} 本中 ${added} 本を選択しました` : `${added} 本を選択しました`;
}
