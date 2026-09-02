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
 *
 * That navigation is not written here. It is `components/PromptWorkbench.js`,
 * and the profile Prompts tab is the same component with different words in it.
 * What stays in this file is everything about MEANING: which layer is being
 * edited, that a slot can differ from the shipped text and separately be
 * unsaved, and the Save button that writes the installation layer.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { get, put } from '@/core/api.js';
import { toast, attempt } from '@/core/toast.js';
import { plural } from '@/core/format.js';
import { declareUnsaved } from '@/core/store.js';

import AppIcon from '@/components/AppIcon.js';
import PromptWorkbench from '@/components/PromptWorkbench.js';
import ViewHeader from '@/components/ViewHeader.js';

export const PromptsView = {
  name: 'PromptsView',
  components: { AppIcon, PromptWorkbench, ViewHeader },
  setup() {
    const loading = ref(true);
    const saving = ref(false);

    const groups = ref({});
    const slots = ref({});

    /**
     * Where a BookStackDev look disagrees with the installation's wording.
     * Only the findings about this layer: a profile that overrides the slot
     * reads its own text, and its own Prompts tab reports those.
     */
    const issues = ref([]);
    const loadIssues = async () => {
      try {
        const data = await get('bookstackdev/audit');
        issues.value = (data.issues ?? []).filter((issue) => issue.layer === 'installation' && issue.recommended);
      } catch {
        issues.value = [];        // a failed check is not a reason to hold the prompts back
      }
    };

    /** key -> the text in the box, which may not be what the server holds. */
    const draft = reactive({});

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
      loading.value = false;
      loadIssues();
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
        loadIssues();
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

    /** Stages a look's recommended wording; Save writes it like any other edit. */
    const useRecommended = ({ key, text }) => {
      draft[key] = text;
      toast.info('The recommended wording is in the box. Save changes to write it.');
    };

    return {
      loading, saving, draft, issues, useRecommended,
      load, save, discard, resetSlot,
      groupList, slotList,
      textOf, differs, isDirty, dirtyCount, customCount, slotCount, customIn,
      plural,
    };
  },
  template: `
    <view-header title="Prompts" icon="file-text" subtitle="The words this installation sends to the model, slot by slot">
      <template #actions>
        <span v-if="customCount" class="badge badge--accent hide-sm">{{ customCount }} of {{ slotCount }} changed</span>
        <span v-if="dirtyCount" class="badge badge--warning">{{ plural(dirtyCount, 'unsaved edit') }}</span>
        <button v-if="dirtyCount" class="btn btn--ghost btn--sm" @click="discard">
          <app-icon name="undo" :size="12"/> Discard
        </button>
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

    <prompt-workbench :groups="groupList" :slot-list="slotList" :values="draft" :loading="loading" :issues="issues"
                      @fix="useRecommended($event)"
                      box-placeholder="Empty puts the shipped text back - it does not send an empty prompt."
                      empty-title="Pick a prompt on the left"
                      empty-hint="Every AI request is built from these slots. Editing one here changes it for every course on this installation that does not override it in its profile."
                      @edit="draft[$event.key] = $event.text">

      <!-- The one thing somebody has to know before editing anything here. -->
      <template #note>
        <app-icon name="layers" :size="15" class="c-accent none" style="margin-top:1px"/>
        <span>
          These are the prompts the <strong>whole installation</strong> starts from. Every profile can override
          any of the same slots for its own courses, and where it does, the profile wins. If you change
          something here and a course carries on exactly as before, open that course's profile and look at its
          Prompts tab - the override is what it is reading.
        </span>
      </template>

      <template #group-badge="{ group }">
        <span v-if="customIn(group.key)" class="badge badge--accent">{{ customIn(group.key) }}</span>
      </template>

      <template #mark="{ item }">
        <span v-if="isDirty(item)" class="dot none" style="background:var(--warning)"
              title="Edited and not yet saved"></span>
        <span v-else-if="differs(item)" class="dot none" style="background:var(--accent)"
              title="Different from the text the release ships"></span>
      </template>

      <template #status="{ item }">
        <span v-if="isDirty(item)" class="badge badge--warning none">unsaved</span>
        <span v-else-if="differs(item)" class="badge badge--accent none">changed</span>
        <span v-else class="badge none hide-sm">as shipped</span>
        <button class="btn btn--sm none" :disabled="!differs(item)" @click="resetSlot(item)">
          <app-icon name="undo" :size="13"/> Reset this slot
        </button>
      </template>

      <template #footer="{ item }">
        <p class="hint">
          Emptying this box does not send an empty instruction: on save it drops your version and puts the
          text the release ships back. That is also what <strong>Reset this slot</strong> does.
        </p>

        <details v-if="differs(item)">
          <summary class="t-xs dim" style="cursor:pointer">Show the text the release ships</summary>
          <pre class="log mt-2" style="white-space:pre-wrap">{{ item.default || '(this slot ships empty)' }}</pre>
        </details>
      </template>
    </prompt-workbench>`,
};

export default PromptsView;
