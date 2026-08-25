/**
 * Prompts - the words this installation sends to the model.
 *
 * Every request CourseForge makes is assembled from named slots: a persona, a
 * language reminder, the contract that says how a page must be formatted, and
 * one pair of instructions for each content detail a course can switch on. This
 * screen is where those slots are edited, and it is the only place where they
 * can be edited for the whole installation at once.
 *
 * The thing a person has to understand before touching anything here is that
 * there are two layers. What this screen writes is the base layer - what every
 * course starts from. A profile may override any of the same slots for the
 * courses that use it, and that override wins. Somebody who edits the wrong
 * layer sees no change at all and has no way of telling why, so the difference
 * is stated at the top of the screen rather than buried in a help page, and the
 * slots are presented in the same order, with the same labels, as the profile
 * editor presents them - the same list in two places, so it is recognisably the
 * same list.
 *
 * Navigation is the whole design problem here: forty-one slots is far too many
 * for one long page. So it is three panes of narrowing scope - group, slot,
 * editor - with a search that ignores the groups entirely, because the usual
 * question is "where is the one about diagrams" rather than "show me group
 * three".
 */
import { ref, reactive, computed, onMounted, nextTick } from 'vue';
import { get, put } from '@/core/api.js';
import { toast, attempt } from '@/core/toast.js';
import { useFuzzy } from '@/core/fuzzy.js';
import { plural } from '@/core/format.js';
import { declareUnsaved } from '@/core/store.js';

import AppIcon from '@/components/AppIcon.js';
import EmptyState from '@/components/EmptyState.js';
import ViewHeader from '@/components/ViewHeader.js';

export const PromptsView = {
  name: 'PromptsView',
  components: { AppIcon, EmptyState, ViewHeader },
  setup() {
    const loading = ref(true);
    const saving = ref(false);

    const groups = ref({});
    const slots = ref({});

    const groupKey = ref('');
    const selectedKey = ref('');
    const search = ref('');
    /** The slot list is a drawer rather than a column below 1024px. */
    const listOpen = ref(false);

    /** key -> the text in the box, which may not be what the server holds. */
    const draft = reactive({});
    const boxes = {};

    /* ---------------------------------------------------------------- load */

    const seed = () => {
      for (const key of Object.keys(draft)) delete draft[key];
      for (const [key, slot] of Object.entries(slots.value)) draft[key] = slot.value;
    };

    const load = () => attempt(async () => {
      const data = await get('admin/prompts');
      groups.value = data.groups ?? {};
      slots.value = data.slots ?? {};
      seed();
      if (!groupKey.value) groupKey.value = groupList.value[0]?.key ?? '';
      if (!selectedKey.value || !slots.value[selectedKey.value]) {
        selectedKey.value = inGroup(groupKey.value)[0]?.key ?? '';
      }
      loading.value = false;
    }, 'Load prompts');

    onMounted(load);

    /* --------------------------------------------------------------- lists */

    const groupList = computed(() =>
      Object.entries(groups.value)
        .map(([key, group]) => ({ key, ...group }))
        .sort((a, b) => (a.order ?? 999) - (b.order ?? 999))
    );

    const slotList = computed(() =>
      Object.entries(slots.value)
        .map(([key, slot]) => ({ key, ...slot }))
        .sort((a, b) => (a.order ?? 999) - (b.order ?? 999))
    );

    const inGroup = (key) => slotList.value.filter((slot) => slot.group === key);

    const groupLabel = (key) => groups.value[key]?.label ?? key;

    const currentGroup = computed(() => groupList.value.find((group) => group.key === groupKey.value) ?? null);

    /**
     * A search looks through every group, not only the open one. Somebody
     * hunting for the Mermaid instructions has no reason to know that they live
     * under Content details, and making them guess is the failure this screen
     * is most likely to have.
     */
    const searching = computed(() => search.value.trim() !== '');

    /**
     * Searchable text for one slot, including whatever is currently written in
     * it - somebody looking for the prompt that mentions Mermaid is far more
     * likely to remember a phrase from the text than the slot's name.
     */
    const searchable = computed(() => slotList.value.map((slot) => ({
      slot,
      label: slot.label,
      key: slot.key,
      description: slot.description ?? '',
      text: draft[slot.key] ?? '',
    })));

    const matches = useFuzzy(searchable, search, {
      keys: ['label', 'key', 'description', 'text'],
      limit: 200,
    });

    const visibleSlots = computed(() =>
      searching.value ? matches.value.map((hit) => hit.slot) : inGroup(groupKey.value)
    );

    const current = computed(() => {
      const slot = slots.value[selectedKey.value];
      return slot ? { key: selectedKey.value, ...slot } : null;
    });

    const select = (slot) => {
      selectedKey.value = slot.key;
      // Picking a search result out of another group takes the group with it,
      // so clearing the search does not make the open slot vanish from the list.
      if (slot.group !== groupKey.value) groupKey.value = slot.group;
      listOpen.value = false;
    };

    const pickGroup = (key) => {
      groupKey.value = key;
      search.value = '';
      if (!inGroup(key).some((slot) => slot.key === selectedKey.value)) {
        selectedKey.value = inGroup(key)[0]?.key ?? '';
      }
    };

    /* -------------------------------------------------------------- states */

    const textOf = (key) => draft[key] ?? '';

    /** Different from the text the release ships, saved or not. */
    const differs = (slot) => textOf(slot.key) !== slot.default;
    /** Different from what the server currently holds - an unsaved edit. */
    const isDirty = (slot) => textOf(slot.key) !== slot.value;

    const dirtySlots = computed(() => slotList.value.filter(isDirty));
    const dirtyCount = computed(() => dirtySlots.value.length);

    // See SettingsView: the shell holds the navigation, the screen knows
    // what would be lost, and this is the sentence that joins them.
    declareUnsaved(() => (dirtyCount.value ? plural(dirtyCount.value, 'unsaved edit') : ''));
    const customCount = computed(() => slotList.value.filter(differs).length);
    const slotCount = computed(() => slotList.value.length);
    const customIn = (key) => inGroup(key).filter(differs).length;

    /* --------------------------------------------------------------- write */

    /**
     * One request for the whole screen. A slot whose text is back to the
     * shipped default is sent as an empty string, which is how the server is
     * told to drop the override rather than to store a copy of its own default.
     */
    const save = () => attempt(async () => {
      // `saving` disables the button, but only on the next tick, so two clicks
      // in one tick would both get through and the second would send an already
      // saved edit a second time.
      if (!dirtyCount.value || saving.value) return;
      saving.value = true;
      try {
        const prompts = {};
        for (const slot of dirtySlots.value) {
          const text = textOf(slot.key);
          prompts[slot.key] = text === slot.default ? '' : text;
        }
        const count = Object.keys(prompts).length;
        const data = await put('admin/prompts', { prompts });
        groups.value = data.groups ?? groups.value;
        slots.value = data.slots ?? slots.value;
        seed();
        toast.success(`${plural(count, 'prompt')} saved.`);
      } finally {
        saving.value = false;
      }
    }, 'Save prompts');

    const discard = () => {
      seed();
      toast.info('Your unsaved edits were thrown away.');
    };

    const resetSlot = (slot) => {
      draft[slot.key] = slot.default;
      toast.info(slot.is_overridden
        ? 'Back to the text the release ships. Save to make that stick.'
        : 'Back to the text the release ships.');
    };

    /* -------------------------------------------------------- placeholders */

    const registerBox = (key, el) => { if (el) boxes[key] = el; };

    /** Drops a placeholder at the caret, so nobody has to type the braces. */
    const insert = async (slot, placeholder) => {
      const token = `{{${placeholder}}}`;
      const el = boxes[slot.key];
      const text = textOf(slot.key);
      if (!el) { draft[slot.key] = text + token; return; }
      const start = el.selectionStart ?? text.length;
      const end = el.selectionEnd ?? start;
      draft[slot.key] = text.slice(0, start) + token + text.slice(end);
      await nextTick();
      el.focus();
      el.setSelectionRange(start + token.length, start + token.length);
    };

    // A literal pair of braces cannot appear in a template string - Vue closes
    // the interpolation at the first one - so the example travels as data.
    const tokenExample = `{${'{page_title}'}}`;

    return {
      loading, saving, groupKey, selectedKey, search, listOpen, draft,
      load, save, discard, resetSlot,
      groupList, visibleSlots, current, currentGroup, searching, groupLabel,
      select, pickGroup, textOf, differs, isDirty, dirtyCount, customCount, slotCount, customIn,
      insert, registerBox, tokenExample, plural,
    };
  },
  template: `
    <view-header title="Prompts" icon="file-text">
      <template #actions>
        <span v-if="customCount" class="badge badge--accent hide-sm">{{ customCount }} of {{ slotCount }} changed</span>
        <span v-if="dirtyCount" class="badge badge--warning">{{ plural(dirtyCount, 'unsaved edit') }}</span>
        <button v-if="dirtyCount" class="btn btn--ghost btn--sm" @click="discard">Discard</button>
        <button class="btn btn--ghost btn--icon hide-sm" title="Reload from the server"
                aria-label="Reload the prompts from the server" @click="load">
          <app-icon name="refresh" :size="15"/>
        </button>
        <button class="btn btn--primary" :disabled="saving || !dirtyCount" @click="save">
          <app-icon :name="saving ? 'refresh' : 'save'" :size="14" :spin="saving"/>
          {{ saving ? 'Saving…' : 'Save changes' }}
        </button>
      </template>
    </view-header>

    <!-- The one thing somebody has to know before editing anything here. -->
    <div class="prompt-note">
      <app-icon name="layers" :size="15" class="c-accent none" style="margin-top:1px"/>
      <span>
        These are the prompts the <strong>whole installation</strong> starts from. Every profile can override
        any of the same slots for its own courses, and where it does, the profile wins. If you change
        something here and a course carries on exactly as before, open that course's profile and look at its
        Prompts tab - the override is what it is reading.
      </span>
    </div>

    <nav class="tabbar">
      <button v-for="group in groupList" :key="group.key" class="tab"
              :class="{ 'is-active': !searching && groupKey === group.key }" @click="pickGroup(group.key)">
        {{ group.label }}
        <span v-if="customIn(group.key)" class="badge badge--accent">{{ customIn(group.key) }}</span>
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
            <div style="position:relative">
              <app-icon name="search" :size="13"
                        style="position:absolute;left:9px;top:50%;transform:translateY(-50%);color:var(--text-faint)"/>
              <input v-model="search" placeholder="Find a prompt…" spellcheck="false"
                     style="padding-left:28px">
            </div>
            <p v-if="!searching && currentGroup" class="hint mt-2">{{ currentGroup.description }}</p>
            <p v-else-if="searching" class="hint mt-2">
              Searching labels, keys and prompt text across all groups. Picking one opens its group.
            </p>
          </div>

          <div class="tree">
            <button v-for="slot in visibleSlots" :key="slot.key"
                    class="tree__page" :class="{ 'is-active': selectedKey === slot.key }"
                    :title="slot.label" @click="select(slot)">
              <span class="grow truncate">
                {{ slot.label }}
                <span v-if="searching" class="t-2xs faint"> · {{ groupLabel(slot.group) }}</span>
              </span>
              <span v-if="isDirty(slot)" class="dot none" style="background:var(--warning)"
                    title="Edited and not yet saved"></span>
              <span v-else-if="differs(slot)" class="dot none" style="background:var(--accent)"
                    title="Different from the text the release ships"></span>
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
            <div class="col grow" style="min-width:0;gap:1px">
              <span class="strong truncate">{{ current.label }}</span>
              <code class="t-2xs faint truncate">{{ current.key }}</code>
            </div>
            <span v-if="isDirty(current)" class="badge badge--warning none">unsaved</span>
            <span v-else-if="differs(current)" class="badge badge--accent none">changed</span>
            <span v-else class="badge none hide-sm">as shipped</span>
            <button class="btn btn--sm none" :disabled="!differs(current)" @click="resetSlot(current)">
              <app-icon name="inherit" :size="13"/> Reset this slot
            </button>
          </div>

          <div class="pane__body view-pad col gap-3">
            <p v-if="current.description" class="hint">{{ current.description }}</p>

            <div v-if="current.placeholders.length" class="col gap-1">
              <span class="label">Placeholders</span>
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

            <textarea :key="current.key" :ref="el => registerBox(current.key, el)"
                      :value="textOf(current.key)"
                      @input="draft[current.key] = $event.target.value"
                      class="mono prompt-editor" spellcheck="false"
                      placeholder="Empty puts the shipped text back - it does not send an empty prompt."></textarea>

            <p class="hint">
              Emptying this box does not send an empty instruction: on save it drops your version and puts the
              text the release ships back. That is also what <strong>Reset this slot</strong> does.
            </p>

            <details v-if="differs(current)">
              <summary class="t-xs dim" style="cursor:pointer">Show the text the release ships</summary>
              <pre class="log mt-2" style="white-space:pre-wrap">{{ current.default || '(this slot ships empty)' }}</pre>
            </details>
          </div>
        </template>

        <empty-state v-else-if="!loading" icon="file-text" title="Pick a prompt on the left"
                     hint="Every AI request is built from these slots. Editing one here changes it for every course on this installation that does not override it in its profile."/>
      </section>
    </div>`,
};

export default PromptsView;
