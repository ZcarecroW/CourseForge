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
 * There is no limit unless one is asked for. A default cap belongs to a
 * dropdown, where showing five hundred rows helps nobody - but the same cap on
 * a full-page list quietly drops rows off the end, and a list that states a
 * total in its header and then renders fewer than that is giving a confidently
 * wrong answer to the only question it exists to answer. The screens that want
 * a cap say so.
 *
 * @param {object} [options]
 * @param {string[]} [options.keys]  object keys to index; omit for a list of strings
 * @param {number}  [options.limit]  omit for everything
 */
export function useFuzzy(items, query, { keys = [], limit = Infinity } = {}) {
  const index = computed(() => new Fuse(unref(items) ?? [], { ...DEFAULTS, keys }));

  return computed(() => {
    const term = String(unref(query) ?? '').trim();
    const list = unref(items) ?? [];
    if (term === '') return limit === Infinity ? [...list] : list.slice(0, limit);

    const hits = index.value.search(term).map((hit) => hit.item);
    return limit === Infinity ? hits : hits.slice(0, limit);
  });
}

/** One-shot search for the places that do not need reactivity. */
export function search(items, query, keys = []) {
  const term = String(query ?? '').trim();
  if (term === '') return [...items];
  return new Fuse(items, { ...DEFAULTS, keys }).search(term).map((hit) => hit.item);
}
