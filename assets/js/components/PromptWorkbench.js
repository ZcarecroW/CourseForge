/**
 * The prompt workbench — the shape both prompt screens are.
 *
 * There are two screens that edit prompts, and they edit the same forty-one
 * slots. The administrator's screen writes the base layer, the one every course
 * starts from; a profile's Prompts tab overrides the same slots for the courses
 * that use it. What differs between them is meaning — which layer, what a mark
 * means, what the badge says, who owns the Save button. What does not differ is
 * how you get around: forty-one slots is too many for one page, so both are a
 * group tabbar, a searchable list, and one prompt open at a time.
 *
 * That navigation used to be written twice. The two copies drifted, which is
 * the whole reason this file exists: the profile tab had been rebuilt out of
 * cards and ended up floating in a padded scroller while the administrator's
 * screen filled the window. Extracting it means they cannot drift again,
 * because there is only one of them.
 *
 * **This renders as a fragment, not a wrapper `<div>`.** That is the load-
 * bearing decision. Three roots — the note strip, the tabbar, the workspace —
 * sit directly inside whatever box the caller puts them in, so the flex chain
 * that makes `.workspace` fill its parent is inherited rather than interrupted.
 * `.main` gives that chain on the administrator's screen and `.pane` gives the
 * identical one inside a profile, which is why the same markup fills both. Wrap
 * this component in a padded, scrolling `<div>` and you get the floating layout
 * back — that is what the padding and the scroller do.
 *
 * Four of the slots below are *scoped*, and they are the first scoped slots in
 * this codebase, so it is worth saying what the rule is: a slot prop is passed
 * through under the name it is authored with. Vue camelises component props
 * (`box-placeholder` arrives as `boxPlaceholder`) but it does **not** camelise
 * slot props, so `:slotsIn` here has to be destructured as `{ slotsIn }` there,
 * spelled the same way on both sides. Writing `:slots-in` and reading
 * `{ slotsIn }` yields `undefined`, and because every consumer of it is a
 * `v-if`, the failure is a badge that silently never appears.
 */
import { ref, computed, watch, defineAsyncComponent } from 'vue';
import { useFuzzy } from '@/core/fuzzy.js';

import AppIcon from '@/components/AppIcon.js';
import EmptyState from '@/components/EmptyState.js';

/**
 * The slots a BookStackDev look reads literally: what they ask the model for
 * is what a wiki wearing a look renders, so the two have to agree. Named here
 * so both prompt screens mark them the same way.
 */
const LOOK_SLOTS = {
  feature_mathjax_on: 'formula delimiters',
  feature_mermaid_on: 'diagram blocks',
  feature_code_examples_on: 'code fences',
};

/** The glyph each group of prompts is filed under, on both prompt screens. */
const GROUP_ICONS = { global: 'globe', structure: 'sitemap', page: 'file-text', features: 'list-check' };
const groupIcon = (key) => GROUP_ICONS[key] ?? 'folder';

/**
 * A prompt is a Markdown-ish template — headings, bullets, fenced examples and
 * `{{placeholder}}` slots — so it is edited in the same CodeMirror a page is,
 * with the gutter and the page-only markers switched off and the placeholder
 * marker switched on. Fetched only when somebody opens a prompt screen, exactly
 * as the content editor is, so nothing about the sign-in path changes.
 */
const notice = (text, tone = '') => ({
  inheritAttrs: false,          // Vue hands the error component the error itself
  template: `<div class="cf-editor cf-editor--notice ${tone}">${text}</div>`,
});

const MarkdownEditor = defineAsyncComponent({
  loader: () => import('@/components/MarkdownEditor.js'),
  delay: 250,
  loadingComponent: notice('Loading the editor…'),
  errorComponent: notice('The editor could not be loaded. Reload the page to try again.', 'c-danger'),
});

export const PromptWorkbench = {
  name: 'PromptWorkbench',
  components: { AppIcon, EmptyState, MarkdownEditor },
  props: {
    /** [{ key, label, description, order }] — the group tabs, in display order. */
    groups: { type: Array, required: true },
    /**
     * [{ key, label, group, description, placeholders, order }].
     *
     * Named `slotList` rather than `slots` on purpose: `slots` is the second
     * half of `setup(props, { slots })`, and a prop that shadows it here is a
     * bug waiting to be written.
     */
    slotList: { type: Array, required: true },
    /** key → the text that belongs in the box. The caller owns it. */
    values: { type: Object, required: true },
    /** While the first fetch is in flight, so neither screen flashes the empty state. */
    loading: { type: Boolean, default: false },
    boxPlaceholder: { type: String, default: '' },
    emptyTitle: { type: String, default: 'Pick a prompt on the left' },
    emptyHint: { type: String, default: '' },
    /**
     * Where a BookStackDev look disagrees with a slot: [{ slot, message,
     * recommended, bookstackdev_name, ... }]. The caller decides what a fix
     * writes - an installation slot, a profile override - and hears about it
     * through `fix`.
     */
    issues: { type: Array, default: () => [] },
  },
  emits: ['edit', 'fix'],
  setup(props, { emit }) {
    const groupKey = ref('');
    const slotKey = ref('');
    const search = ref('');
    /** The slot list is a drawer rather than a column below 1024px. */
    const listOpen = ref(false);

    const box = ref(null);

    /* --------------------------------------------------------------- lists */

    const orderedGroups = computed(() =>
      [...props.groups].sort((a, b) => (a.order ?? 999) - (b.order ?? 999)));

    const orderedSlots = computed(() =>
      [...props.slotList].sort((a, b) => (a.order ?? 999) - (b.order ?? 999)));

    const inGroup = (key) => orderedSlots.value.filter((slot) => slot.group === key);

    const groupLabel = (key) => orderedGroups.value.find((group) => group.key === key)?.label ?? key;

    const currentGroup = computed(() =>
      orderedGroups.value.find((group) => group.key === groupKey.value) ?? null);

    /**
     * A search looks through every group, not only the open one. Somebody
     * hunting for the Mermaid instructions has no reason to know that they live
     * under Content details, and making them guess is the failure this screen
     * is most likely to have.
     */
    const searching = computed(() => search.value.trim() !== '');

    /**
     * Searchable text for one slot, including whatever is currently written in
     * it — somebody looking for the prompt that mentions Mermaid is far more
     * likely to remember a phrase from the text than the slot's name.
     */
    const searchable = computed(() => orderedSlots.value.map((slot) => ({
      slot,
      label: slot.label,
      key: slot.key,
      description: slot.description ?? '',
      text: props.values[slot.key] ?? '',
    })));

    const matches = useFuzzy(searchable, search, {
      keys: ['label', 'key', 'description', 'text'],
    });

    const visibleSlots = computed(() =>
      searching.value ? matches.value.map((hit) => hit.slot) : inGroup(groupKey.value));

    const current = computed(() =>
      orderedSlots.value.find((slot) => slot.key === slotKey.value) ?? null);

    /**
     * The data arrives after the first render on both screens — one fetches the
     * catalogue, the other switches profiles under a component that stays
     * mounted — so the opening choice is made whenever the list changes rather
     * than once on mount. Anything still valid is left alone, so switching
     * profiles does not throw away the slot somebody was reading.
     */
    watch(() => props.slotList, () => {
      if (!orderedGroups.value.some((group) => group.key === groupKey.value)) {
        groupKey.value = orderedGroups.value[0]?.key ?? '';
      }
      if (!orderedSlots.value.some((slot) => slot.key === slotKey.value)) {
        slotKey.value = inGroup(groupKey.value)[0]?.key ?? '';
      }
    }, { immediate: true, deep: false });

    const select = (slot) => {
      slotKey.value = slot.key;
      // Picking a search result out of another group takes the group with it,
      // so clearing the search does not make the open slot vanish from the list.
      if (slot.group !== groupKey.value) groupKey.value = slot.group;
      listOpen.value = false;
    };

    const pickGroup = (key) => {
      groupKey.value = key;
      search.value = '';
      if (!inGroup(key).some((slot) => slot.key === slotKey.value)) {
        slotKey.value = inGroup(key)[0]?.key ?? '';
      }
    };

    /* -------------------------------------------------------- placeholders */

    /**
     * Drops a placeholder at the caret, so nobody has to type the braces.
     *
     * CodeMirror owns its own selection, so it is asked to do the insertion and
     * reports the change back through `update:modelValue` like any other edit.
     * The append is the fallback for the window before the editor has loaded,
     * where there is no caret to insert at.
     */
    const insert = (slot, placeholder) => {
      const token = `{{${placeholder}}}`;
      if (typeof box.value?.insertAtCursor === 'function') {
        box.value.insertAtCursor(token);
        return;
      }
      emit('edit', { key: slot.key, text: (props.values[slot.key] ?? '') + token });
    };

    // A literal pair of braces cannot appear in a template string — Vue closes
    // the interpolation at the first one — so the example travels as data.
    const tokenExample = `{${'{page_title}'}}`;

    const lookNote = (key) => LOOK_SLOTS[key] ?? '';
    const issuesFor = (key) => props.issues.filter((issue) => issue.slot === key);

    return {
      groupKey, slotKey, search, listOpen, box,
      orderedGroups, visibleSlots, current, currentGroup, searching,
      inGroup, groupLabel, groupIcon, select, pickGroup, insert, tokenExample, lookNote, issuesFor,
    };
  },
  template: `
    <div v-if="$slots.note" class="prompt-note">
      <slot name="note"/>
    </div>

    <nav class="tabbar" role="tablist" aria-label="Groups of prompts">
      <button v-for="group in orderedGroups" :key="group.key" class="tab"
              role="tab" :aria-selected="!searching && groupKey === group.key"
              :class="{ 'is-active': !searching && groupKey === group.key }" @click="pickGroup(group.key)">
        <app-icon :name="groupIcon(group.key)" :size="14"/>{{ group.label }}
        <slot name="group-badge" :group="group" :slotsIn="inGroup(group.key)"/>
      </button>
    </nav>

    <div class="workspace workspace--two">
      <div v-if="listOpen" class="scrim" @click="listOpen = false"></div>

      <!-- ------------------------------------------------ slots in a group -->
      <aside class="pane pane--left" :class="{ 'is-open': listOpen }">
        <div class="pane__head">
          <span class="eyebrow grow truncate">
            {{ searching ? 'Matches in every group' : (currentGroup ? currentGroup.label : 'Prompts') }}
          </span>
          <span class="badge none">{{ visibleSlots.length }}</span>
          <button class="btn btn--ghost btn--sm btn--icon none outline-toggle" title="Close"
                  aria-label="Close the prompt list"
                  @click="listOpen = false"><app-icon name="x" :size="14"/></button>
        </div>

        <div class="pane__body">
          <div style="padding:var(--s-3) var(--s-3) 0">
            <div class="input-icon">
              <app-icon name="search" :size="13"/>
              <input v-model="search" placeholder="Find a prompt…" spellcheck="false" aria-label="Find a prompt">
            </div>
            <p v-if="!searching && currentGroup" class="hint mt-2">{{ currentGroup.description }}</p>
            <p v-else-if="searching" class="hint mt-2">
              Searching labels, keys and prompt text across all groups. Picking one opens its group.
            </p>
          </div>

          <div class="tree">
            <button v-for="slot in visibleSlots" :key="slot.key"
                    class="tree__page" :class="{ 'is-active': slotKey === slot.key }"
                    :title="slot.label" @click="select(slot)">
              <span class="grow truncate">
                {{ slot.label }}
                <span v-if="searching" class="t-2xs faint"> · {{ groupLabel(slot.group) }}</span>
              </span>
              <slot name="mark" :item="slot"/>
              <app-icon v-if="issuesFor(slot.key).length" name="alert" :size="12" class="c-warning none"
                        title="A BookStackDev look disagrees with this prompt"/>
              <app-icon v-else-if="lookNote(slot.key)" name="palette" :size="12" class="faint none"
                        :title="'A BookStackDev look renders the ' + lookNote(slot.key) + ' this slot asks for'"/>
            </button>

            <p v-if="!visibleSlots.length" class="t-xs faint" style="padding:var(--s-4);text-align:center">
              Nothing matches that.
            </p>
          </div>
        </div>
      </aside>

      <!-- ---------------------------------------------------- one slot -->
      <section class="pane">
        <template v-if="current">
          <div class="pane__head">
            <button class="btn btn--ghost btn--sm btn--icon none outline-toggle" title="Show the other prompts"
                    aria-label="Show the other prompts"
                    @click="listOpen = true"><app-icon name="menu" :size="15"/></button>
            <span class="tile tile--sm tile--accent none hide-sm"><app-icon :name="groupIcon(current.group)" :size="14"/></span>
            <div class="col grow" style="min-width:0;gap:1px">
              <span class="strong truncate">{{ current.label }}</span>
              <code class="t-2xs faint truncate">{{ current.key }}</code>
            </div>
            <slot name="status" :item="current"/>
          </div>

          <div class="pane__body view-pad col gap-3">
            <p v-if="current.description" class="hint">{{ current.description }}</p>

            <!-- A slot a look reads literally says so, and says where a look
                 disagrees - with the wording that would settle it, one click
                 away. The caller decides which layer that click writes. -->
            <div v-if="lookNote(current.key)" class="note-strip" :class="issuesFor(current.key).length ? 'note-strip--warning' : ''">
              <app-icon :name="issuesFor(current.key).length ? 'alert' : 'palette'" :size="15"
                        :class="issuesFor(current.key).length ? 'c-warning' : 'c-accent'"/>
              <div class="col gap-2 grow">
                <span>
                  <strong>BookStackDev reads this slot literally.</strong> The {{ lookNote(current.key) }} it asks for are
                  what a wiki wearing a look renders - change one here and the look has to change with it, or the other way round.
                  The BookStackDev screen compares the two.
                </span>
                <div v-for="(issue, i) in issuesFor(current.key)" :key="i" class="col gap-2">
                  <span>{{ issue.message }}</span>
                  <div class="row wrap gap-2">
                    <button class="btn btn--sm" @click="$emit('fix', { key: current.key, text: issue.recommended, issue })">
                      <app-icon name="wrench" :size="13"/>
                      Use the wording the look "{{ issue.bookstackdev_name || '' }}" recommends
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <div v-if="current.placeholders.length" class="col gap-1">
              <span class="label row gap-2"><app-icon name="hash" :size="12"/> Placeholders</span>
              <div class="row wrap gap-1">
                <button v-for="placeholder in current.placeholders" :key="placeholder"
                        class="placeholder-token" title="Insert this at the cursor"
                        @click="insert(current, placeholder)">{{ placeholder }}</button>
              </div>
              <p class="hint">
                Filled in for each request. Click one to drop it in at the cursor as
                <code>{{ tokenExample }}</code>. A placeholder you leave out is simply not sent - nothing
                breaks, the model is just told less.
              </p>
            </div>
            <p v-else class="hint">This slot takes no placeholders - it is sent exactly as written.</p>

            <markdown-editor ref="box" class="prompt-editor"
                             :model-value="values[current.key] ?? ''"
                             @update:model-value="$emit('edit', { key: current.key, text: $event })"
                             :reset-key="current.key"
                             :gutter="false" :markers="false" tokens
                             :label="current.label + ', prompt template'"
                             :placeholder="boxPlaceholder"/>

            <slot name="footer" :item="current"/>
          </div>
        </template>

        <empty-state v-else-if="!loading" icon="file-text" :title="emptyTitle" :hint="emptyHint"/>
      </section>
    </div>`,
};

export default PromptWorkbench;
