/**
 * Fuzzy search, wrapped so views never touch Fuse's option object directly and
 * a swap of the library stays a one-file change.
 */
import Fuse from 'fuse';
import { computed, unref } from 'vue';

const DEFAULTS = { threshold: 0.4, ignoreLocation: true, minMatchCharLength: 1 };

/**
 * A reactive search over a reactive list.
 *
 * @param {import('vue').Ref<Array>|Array} items
 * @param {import('vue').Ref<string>|string} query
 * @param {object} [options]
 * @param {string[]} [options.keys]  object keys to index; omit for a list of strings
 * @param {number}  [options.limit=60]
 */
export function useFuzzy(items, query, { keys = [], limit = 60 } = {}) {
  const index = computed(() => new Fuse(unref(items) ?? [], { ...DEFAULTS, keys }));

  return computed(() => {
    const term = String(unref(query) ?? '').trim();
    const list = unref(items) ?? [];
    if (term === '') return list.slice(0, limit);
    return index.value.search(term).slice(0, limit).map((hit) => hit.item);
  });
}

/** One-shot search for the places that do not need reactivity. */
export function search(items, query, keys = []) {
  const term = String(query ?? '').trim();
  if (term === '') return [...items];
  return new Fuse(items, { ...DEFAULTS, keys }).search(term).map((hit) => hit.item);
}
