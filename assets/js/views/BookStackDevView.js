/**
 * BookStackDev - the look a BookStack instance wears.
 *
 * BookStackDev is the set of front-end enhancements a BookStack gets from one
 * script tag in its custom head: Shiki code highlighting in every language,
 * Mermaid diagrams, MathJax formulas, link embeds, an audio player, decorated
 * external links, a light/dark button and a few page-styling opinions. It used
 * to be a folder hosted somewhere and configured by editing a JavaScript file.
 * Here it is a profile: every feature is a card with a switch and its options,
 * CourseForge serves the whole thing from its own address, and the line to
 * paste into BookStack is on the second tab.
 *
 * Three things about this screen are worth knowing before reading it.
 *
 * The link is locked to the wikis it was made for. It carries a key, but the
 * key is public - it sits in the head of every page of the wiki - so it is not
 * what keeps the link honest. What does is that the browser tells CourseForge
 * which site is loading the script, and CourseForge answers only for the
 * addresses on the profile's list: the instances wearing the look, plus any
 * address typed in by hand. Copy the line into another wiki and it is refused,
 * by name, in the browser's network tab.
 *
 * One look serves any number of wikis. A profile is a configuration, not a
 * wiki, so the same tag goes into three instances and changing a theme here
 * changes all three within minutes.
 *
 * And the prompts CourseForge writes pages with assume what the look renders.
 * The third tab is where the two are compared: a look that typesets formulas
 * with $ ... $ while the prompts ask for \( ... \) publishes pages whose
 * formulas never render, and the check says so before that happens, with the
 * wording that would put them back in step.
 */
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { state, loadBookStackDev, applyBookStackDev, loadProfiles, declareUnsaved, isAdmin } from '@/core/store.js';
import { post, put, del } from '@/core/api.js';
import { toast, attempt } from '@/core/toast.js';
import { useFuzzy } from '@/core/fuzzy.js';
import { clone, plural, relativeTime } from '@/core/format.js';

import AppIcon from '@/components/AppIcon.js';
import AppModal from '@/components/AppModal.js';
import AppSwitch from '@/components/AppSwitch.js';
import ComboBox from '@/components/ComboBox.js';
import EmptyState from '@/components/EmptyState.js';
import ViewHeader from '@/components/ViewHeader.js';

/** The three tabs of a look, each with the glyph the rest of the application uses for the same thing. */
const TABS = [
  { key: 'features', label: 'Features', icon: 'list-check', hint: 'What the look does, feature by feature, and how' },
  { key: 'link', label: 'Link & wikis', icon: 'link', hint: 'The line to paste into BookStack, and the wikis it works on' },
  { key: 'conventions', label: 'Conventions', icon: 'badge-check', hint: 'Whether the prompts write what this look renders' },
];

/** The words for a finding's weight, and the glyph that says it without the words. */
const LEVELS = {
  warning: { icon: 'alert', tone: 'c-warning', strip: 'note-strip--warning', label: 'needs attention' },
  info: { icon: 'info', tone: 'c-accent', strip: '', label: 'worth knowing' },
};

export const BookStackDevView = {
  name: 'BookStackDevView',
  components: { AppIcon, AppModal, AppSwitch, ComboBox, EmptyState, ViewHeader },
  setup() {
    const tab = ref('features');
    const selectedId = ref(null);
    const draft = ref(null);
    const pristine = ref('');
    const saving = ref(false);
    const creating = ref(false);
    const busy = ref(false);
    const listOpen = ref(false);
    const search = ref('');
    const showAdvanced = reactive({});
    const confirmDelete = ref(false);
    const confirmRotate = ref(false);
    const copied = ref('');
    let copiedTimer = 0;

    /* ----------------------------------------------------------- the list */

    const looks = computed(() => state.bookstackdev.profiles);
    const catalogue = computed(() => state.bookstackdev.catalogue);
    const themes = computed(() => state.bookstackdev.themes);
    const instances = computed(() => state.bookstackdev.instances);

    const found = useFuzzy(looks, search, { keys: ['name'] });

    const isDirty = computed(() => draft.value !== null && JSON.stringify(draft.value) !== pristine.value);
    declareUnsaved(() => (isDirty.value ? 'unsaved changes to this look' : ''));

    const open = (look) => {
      selectedId.value = look.id;
      draft.value = {
        id: look.id,
        name: look.name,
        settings: clone(look.settings),
        origins: [...(look.origins ?? [])],
        instance_ids: (look.instances ?? []).map((i) => i.instance_id),
      };
      pristine.value = JSON.stringify(draft.value);
      listOpen.value = false;
    };

    const select = (look) => {
      if (isDirty.value && draft.value && look.id !== draft.value.id) {
        toast.error('This look has unsaved changes. Save or discard them before opening another.');
        return;
      }
      open(look);
    };

    const discard = () => {
      const current = looks.value.find((look) => look.id === selectedId.value);
      if (current) {
        open(current);
        toast.info('Changes discarded.');
      }
    };

    /** The row the draft was opened from, as the server last described it. */
    const current = computed(() => looks.value.find((look) => look.id === selectedId.value) ?? null);

    // The list arrives after the first render, and again after every write.
    // Whatever is open stays open; a look that vanished gives way to the first.
    watch(looks, (list) => {
      if (draft.value && list.some((look) => look.id === draft.value.id)) return;
      if (list.length) open(list[0]);
      else { draft.value = null; selectedId.value = null; }
    }, { immediate: true });

    onMounted(() => attempt(loadBookStackDev, 'Load BookStackDev'));

    /* ----------------------------------------------------------- features */

    const groupOn = (group) => draft.value?.settings?.[group.key]?.[group.toggle] === true;
    const setGroup = (group, on) => { if (draft.value) draft.value.settings[group.key][group.toggle] = on; };

    /** The fields of a group other than its switch, plain first. */
    const fieldsOf = (group) => group.fields.filter((field) => field.key !== group.toggle);
    const plainFields = (group) => fieldsOf(group).filter((field) => !field.advanced);
    const advancedFields = (group) => fieldsOf(group).filter((field) => field.advanced);
    const advancedShown = (group) => showAdvanced[group.key] === true;
    const toggleAdvanced = (group) => { showAdvanced[group.key] = !advancedShown(group); };

    const onCount = computed(() => (draft.value ? catalogue.value.filter(groupOn).length : 0));

    /** Suggestions for a text field, by the name the catalogue gives. */
    const suggestionsFor = (field) => (field.suggest === 'shiki_themes' ? themes.value : null);

    /** A list field is edited one entry per line. */
    const listText = (group, field) => (draft.value?.settings[group.key][field.key] ?? []).join('\n');
    const setList = (group, field, text) => {
      if (!draft.value) return;
      draft.value.settings[group.key][field.key] = String(text).split('\n').map((s) => s.trim()).filter(Boolean);
    };

    const setNumber = (group, field, raw) => {
      if (!draft.value) return;
      const n = Number(raw);
      draft.value.settings[group.key][field.key] = Number.isFinite(n) ? n : field.default;
    };

    /** Puts one feature back to what the release ships, as an edit. */
    const resetGroup = (group) => {
      if (!draft.value) return;
      for (const field of group.fields) draft.value.settings[group.key][field.key] = clone(field.default);
      toast.info(`${group.label} put back to the shipped settings. Save to keep that.`);
    };

    /** A short line for the list: what a look does, in numbers. */
    const summaryOf = (look) => {
      const on = catalogue.value.filter((group) => look.settings?.[group.key]?.[group.toggle] === true).length;
      const wikis = (look.instances ?? []).length + (look.origins ?? []).length;
      return `${plural(on, 'feature')} on · ${plural(wikis, 'wiki')}`;
    };

    /* --------------------------------------------------------- the link */

    const embed = computed(() => current.value?.embed ?? { url: '', snippet: '' });

    const copy = async (text, what, id) => {
      try {
        await navigator.clipboard.writeText(text);
        copied.value = id;
        clearTimeout(copiedTimer);
        copiedTimer = setTimeout(() => { copied.value = ''; }, 2000);
        toast.success(`${what} copied.`);
      } catch {
        toast.info('Copying is blocked here - select the text and copy it by hand.');
      }
    };

    const wears = (instance) => (draft.value?.instance_ids ?? []).includes(instance.instance_id);
    const setWears = (instance, on) => {
      if (!draft.value) return;
      const ids = new Set(draft.value.instance_ids);
      if (on) ids.add(instance.instance_id); else ids.delete(instance.instance_id);
      draft.value.instance_ids = [...ids];
    };

    /** The other look an instance wears right now, if it is not this one. */
    const otherLook = (instance) => {
      const id = instance.bookstackdev_id;
      if (!id || id === draft.value?.id) return null;
      return looks.value.find((look) => look.id === id) ?? null;
    };

    const originsText = computed({
      get: () => (draft.value?.origins ?? []).join('\n'),
      set: (text) => { if (draft.value) draft.value.origins = String(text).split('\n').map((s) => s.trim()).filter(Boolean); },
    });

    /** Every address the link will answer for once this draft is saved. */
    const allowed = computed(() => {
      if (!draft.value) return [];
      const out = [];
      for (const instance of instances.value) {
        if (wears(instance) && instance.origin && !out.includes(instance.origin)) out.push(instance.origin);
      }
      for (const raw of draft.value.origins) {
        const origin = normaliseOrigin(raw);
        if (origin && !out.includes(origin)) out.push(origin);
      }
      return out;
    });

    /**
     * The same rule the server applies, so the list below the box says what
     * will be stored rather than what was typed. Scheme, host, a port that is
     * not the default; anything else is not an address a page can have.
     */
    function normaliseOrigin(raw) {
      let text = String(raw ?? '').trim();
      if (!text) return '';
      if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(text)) text = `https://${text}`;
      try {
        const url = new URL(text);
        if (url.protocol !== 'http:' && url.protocol !== 'https:') return '';
        return url.origin.toLowerCase();
      } catch {
        return '';
      }
    }

    const badOrigins = computed(() => (draft.value?.origins ?? []).filter((raw) => !normaliseOrigin(raw)));

    /* ------------------------------------------------------- conventions */

    const audit = computed(() => current.value?.audit ?? { ok: true, checked: 0, issues: [] });
    const issueCount = computed(() => audit.value.issues.filter((issue) => issue.level === 'warning').length);
    const levelOf = (issue) => LEVELS[issue.level] ?? LEVELS.info;

    /**
     * Writes a finding's recommended wording where the finding says the text
     * is read from: the profile's own override, or - for an administrator -
     * the installation's slot. A normal account can always fix it on the
     * profile, which wins over the installation for the courses on it.
     */
    const fixing = ref('');
    const fix = (issue, where) => attempt(async () => {
      if (fixing.value) return;
      fixing.value = `${issue.profile_id}:${issue.slot}:${where}`;
      try {
        if (where === 'installation') {
          await put('admin/prompts', { prompts: { [issue.slot]: issue.recommended } });
          toast.success('The installation prompt now asks for what this look renders.');
        } else {
          await put(`profiles/${issue.profile_id}`, { data: { prompts: { [issue.slot]: issue.recommended } } });
          await loadProfiles();
          toast.success(`The profile "${issue.profile_name}" now asks for what this look renders.`);
        }
        await loadBookStackDev();
      } finally {
        fixing.value = '';
      }
    }, 'Adjust the prompt');

    /* --------------------------------------------------------------- CRUD */

    const create = () => attempt(async () => {
      if (creating.value) return;
      creating.value = true;
      try {
        const data = applyBookStackDev(await post('bookstackdev', { name: 'New look' }));
        const made = looks.value.find((look) => look.id === data.profile?.id);
        if (made) open(made);
        tab.value = 'features';
        toast.success('Look created. Every feature starts switched on, the way BookStackDev ships.');
      } finally {
        creating.value = false;
      }
    }, 'Create look');

    const save = () => attempt(async () => {
      if (!draft.value || saving.value) return;
      if (badOrigins.value.length) {
        toast.error(`Not an address a page can have: ${badOrigins.value.join(', ')}`);
        return;
      }
      saving.value = true;
      try {
        const id = draft.value.id;
        applyBookStackDev(await put(`bookstackdev/${id}`, {
          name: draft.value.name,
          settings: draft.value.settings,
          origins: draft.value.origins,
          instance_ids: draft.value.instance_ids,
        }));
        // Profiles carry the assignment too, and the Profiles screen reads it
        // from there.
        await loadProfiles();
        const fresh = looks.value.find((look) => look.id === id);
        if (fresh) open(fresh);
        toast.success('Look saved. Every wiki wearing it picks the change up within a few minutes.');
      } finally {
        saving.value = false;
      }
    }, 'Save look');

    const remove = () => attempt(async () => {
      if (!draft.value || busy.value) return;
      busy.value = true;
      try {
        applyBookStackDev(await del(`bookstackdev/${draft.value.id}`));
        confirmDelete.value = false;
        draft.value = null;
        selectedId.value = null;
        await loadProfiles();
        if (looks.value.length) open(looks.value[0]);
        toast.success('Look deleted. Its link stopped answering.');
      } finally {
        busy.value = false;
      }
    }, 'Delete look');

    const rotate = () => attempt(async () => {
      if (!draft.value || busy.value) return;
      busy.value = true;
      try {
        const id = draft.value.id;
        applyBookStackDev(await post(`bookstackdev/${id}/key`));
        confirmRotate.value = false;
        const fresh = looks.value.find((look) => look.id === id);
        if (fresh && !isDirty.value) open(fresh);
        toast.success('A new link. The old one is refused from now on - paste the new line into every wiki.');
      } finally {
        busy.value = false;
      }
    }, 'Regenerate the link');

    const reload = () => attempt(loadBookStackDev, 'Reload');

    return {
      state, isAdmin, tab, TABS, draft, selectedId, saving, creating, busy, listOpen, search, found, looks, current,
      catalogue, instances, isDirty, select, discard, create, save, remove, rotate, reload,
      confirmDelete, confirmRotate,
      groupOn, setGroup, plainFields, advancedFields, advancedShown, toggleAdvanced, onCount,
      suggestionsFor, listText, setList, setNumber, resetGroup, summaryOf,
      embed, copy, copied, wears, setWears, otherLook, originsText, allowed, badOrigins,
      audit, issueCount, levelOf, fix, fixing,
      plural, relativeTime,
    };
  },
  template: `
    <view-header title="BookStackDev" icon="palette"
                 subtitle="The look your BookStack instances wear - highlighting, diagrams, formulas, embeds - and the one line that switches it on">
      <template #actions>
        <span class="badge hide-sm">{{ plural(looks.length, 'look') }}</span>
        <button class="btn btn--ghost btn--icon" title="Reload" aria-label="Reload the looks" @click="reload">
          <app-icon name="refresh" :size="15"/>
        </button>
        <button class="btn btn--primary" :disabled="creating" @click="create">
          <app-icon :name="creating ? 'refresh' : 'plus'" :size="15" :spin="creating"/>
          {{ creating ? 'Creating…' : 'New look' }}
        </button>
      </template>
    </view-header>

    <div class="workspace workspace--two">
      <div v-if="listOpen" class="scrim" @click="listOpen = false"></div>

      <aside class="pane pane--left" :class="{ 'is-open': listOpen }">
        <div class="pane__head">
          <span class="eyebrow grow">{{ plural(looks.length, 'look') }}</span>
          <button class="btn btn--ghost btn--sm btn--icon none outline-toggle" title="Close" aria-label="Close the list"
                  @click="listOpen = false"><app-icon name="x" :size="14"/></button>
        </div>
        <div v-if="looks.length > 5" class="pane__head" style="border-bottom:1px solid var(--border-soft)">
          <div class="input-icon grow">
            <app-icon name="search" :size="13"/>
            <input v-model="search" placeholder="Find a look…" spellcheck="false" aria-label="Find a look" style="font-size:var(--t-xs)">
          </div>
        </div>
        <div class="pane__body" style="padding:var(--s-2)">
          <button v-for="look in found" :key="look.id" class="tree__page" style="align-items:flex-start"
                  :class="{ 'is-active': selectedId === look.id }" @click="select(look)">
            <app-icon name="palette" :size="14" style="margin-top:2px"/>
            <span class="col grow" style="gap:1px;min-width:0">
              <span class="truncate">{{ look.name }}</span>
              <span class="t-2xs faint truncate">{{ summaryOf(look) }}</span>
            </span>
            <span v-if="look.audit && !look.audit.ok" class="dot none" style="background:var(--warning);margin-top:6px"
                  title="The conventions check found something"></span>
          </button>
          <p v-if="!looks.length" class="t-xs faint" style="padding:var(--s-4);text-align:center">No looks yet.</p>
          <p v-else-if="!found.length" class="t-xs faint" style="padding:var(--s-4);text-align:center">Nothing matches that.</p>
        </div>
      </aside>

      <section class="pane">
        <template v-if="draft">
          <div class="pane__head">
            <button class="btn btn--ghost btn--sm btn--icon none outline-toggle" title="Show all looks" aria-label="Show all looks"
                    @click="listOpen = true"><app-icon name="menu" :size="15"/></button>
            <span class="tile tile--sm tile--accent none hide-sm"><app-icon name="palette" :size="14"/></span>
            <input v-model="draft.name" class="grow" style="max-width:340px" placeholder="Name of this look" aria-label="Name of this look">
            <span v-if="isDirty" class="badge badge--warning push">unsaved changes</span>
            <button v-if="isDirty" class="btn btn--ghost btn--sm" @click="discard">Discard</button>
            <button class="btn btn--primary" :class="{ push: !isDirty }" :disabled="saving" @click="save">
              <app-icon :name="saving ? 'refresh' : 'save'" :size="14" :spin="saving"/>
              {{ saving ? 'Saving…' : 'Save' }}
            </button>
            <button class="btn btn--danger btn--icon" title="Delete this look" aria-label="Delete this look" @click="confirmDelete = true">
              <app-icon name="trash" :size="14"/>
            </button>
          </div>

          <nav class="tabbar" role="tablist" aria-label="Look">
            <button v-for="entry in TABS" :key="entry.key" class="tab" :class="{ 'is-active': tab === entry.key }"
                    role="tab" :aria-selected="tab === entry.key" :title="entry.hint" @click="tab = entry.key">
              <app-icon :name="entry.icon" :size="14"/>{{ entry.label }}
              <span v-if="entry.key === 'features'" class="tab__count">{{ onCount }}</span>
              <span v-else-if="entry.key === 'link'" class="tab__count">{{ allowed.length }}</span>
              <span v-else-if="entry.key === 'conventions' && issueCount" class="tab__count" style="background:var(--warning-soft);color:var(--warning)">{{ issueCount }}</span>
            </button>
          </nav>

          <!-- ============================================== features ===== -->
          <div v-if="tab === 'features'" class="pane__body view-pad">
            <div class="col gap-4 container">
              <div class="note-strip">
                <app-icon name="palette" :size="15" class="c-accent"/>
                <span class="grow">
                  Every feature is a card: a switch, and under it what the feature can be told. A wiki only ever
                  loads what is switched on. Everything here ships the way BookStackDev itself does, so a new look
                  works as it is - change what you would have changed in the file.
                </span>
                <span class="badge none badge--accent">{{ onCount }} of {{ catalogue.length }} on</span>
              </div>

              <div class="look-grid">
                <section v-for="group in catalogue" :key="group.key" class="card look-card" :class="{ 'is-on': groupOn(group) }">
                  <div class="card__head">
                    <span class="tile" :class="groupOn(group) ? 'tile--accent' : ''"><app-icon :name="group.icon" :size="17"/></span>
                    <div class="card__heading">
                      <span class="card__title">{{ group.label }}</span>
                      <span class="card__desc">{{ group.summary }}</span>
                    </div>
                    <app-switch :model-value="groupOn(group)" :label="group.label" @update:model-value="setGroup(group, $event)"/>
                  </div>

                  <div v-if="groupOn(group)" class="card__body col gap-3">
                    <p v-if="group.description" class="hint">{{ group.description }}</p>

                    <div v-for="field in [...plainFields(group), ...(advancedShown(group) ? advancedFields(group) : [])]"
                         :key="field.key" class="look-field" :class="{ 'look-field--switch': field.type === 'bool' }">
                      <!-- bool: the whole row is the control -->
                      <template v-if="field.type === 'bool'">
                        <div class="look-field__text">
                          <span class="look-field__label">{{ field.label }}</span>
                          <span v-if="field.description" class="look-field__desc">{{ field.description }}</span>
                        </div>
                        <app-switch :model-value="draft.settings[group.key][field.key] === true" :label="field.label"
                                    @update:model-value="draft.settings[group.key][field.key] = $event"/>
                      </template>

                      <template v-else>
                        <div class="look-field__text">
                          <span class="look-field__label">{{ field.label }}<span v-if="field.advanced" class="badge badge--outline" style="margin-left:6px">advanced</span></span>
                          <span v-if="field.description" class="look-field__desc">{{ field.description }}</span>
                        </div>
                        <div class="look-field__control">
                          <!-- number -->
                          <div v-if="field.type === 'int' || field.type === 'float'" class="row gap-2">
                            <input type="number" :value="draft.settings[group.key][field.key]" :min="field.min" :max="field.max"
                                   :step="field.step || 1" :aria-label="field.label"
                                   @change="setNumber(group, field, $event.target.value)">
                            <span v-if="field.unit" class="t-xs dim none">{{ field.unit }}</span>
                          </div>
                          <!-- choice -->
                          <select v-else-if="field.type === 'enum'" v-model="draft.settings[group.key][field.key]" :aria-label="field.label">
                            <option v-for="option in field.options" :key="option" :value="option">{{ option }}</option>
                          </select>
                          <!-- list, one per line -->
                          <textarea v-else-if="field.type === 'list'" rows="3" class="mono" spellcheck="false" :aria-label="field.label"
                                    :value="listText(group, field)" @change="setList(group, field, $event.target.value)"
                                    placeholder="One per line"></textarea>
                          <!-- text with suggestions: fuzzy-searched, never constrained -->
                          <div v-else-if="suggestionsFor(field)" class="row">
                            <combo-box :model-value="String(draft.settings[group.key][field.key] ?? '')"
                                       @update:model-value="draft.settings[group.key][field.key] = $event"
                                       :options="suggestionsFor(field)" :placeholder="String(field.default)"/>
                          </div>
                          <!-- text -->
                          <input v-else v-model="draft.settings[group.key][field.key]" :placeholder="field.placeholder || ''"
                                 :class="{ mono: /url|selector|key|containers|scope/i.test(field.key) }" :aria-label="field.label">
                        </div>
                      </template>
                    </div>
                  </div>

                  <div v-if="groupOn(group)" class="card__foot row wrap gap-2">
                    <button v-if="advancedFields(group).length" class="btn btn--ghost btn--sm" @click="toggleAdvanced(group)">
                      <app-icon :name="advancedShown(group) ? 'chevron-up' : 'chevron-down'" :size="13"/>
                      {{ advancedShown(group) ? 'Hide the advanced settings' : 'Show ' + plural(advancedFields(group).length, 'advanced setting') }}
                    </button>
                    <button class="btn btn--ghost btn--sm push" @click="resetGroup(group)" title="Put this feature back to what BookStackDev ships">
                      <app-icon name="undo" :size="12"/> Shipped settings
                    </button>
                  </div>
                </section>
              </div>
            </div>
          </div>

          <!-- ============================================== link ========= -->
          <div v-else-if="tab === 'link'" class="pane__body view-pad">
            <div class="col gap-5 container">
              <section class="card">
                <div class="card__head">
                  <span class="tile tile--accent"><app-icon name="link" :size="17"/></span>
                  <div class="card__heading">
                    <span class="card__title">The line for BookStack</span>
                    <span class="card__desc">Paste it into Settings › Customization › Custom HTML head content, in every wiki listed below. Nothing else changes in BookStack.</span>
                  </div>
                  <button class="btn btn--sm none" @click="copy(embed.snippet, 'The script line', 'snippet')">
                    <app-icon :name="copied === 'snippet' ? 'check' : 'copy'" :size="12"/> {{ copied === 'snippet' ? 'copied' : 'Copy the line' }}
                  </button>
                </div>
                <div class="card__body col gap-3">
                  <pre class="log" style="white-space:pre-wrap;word-break:break-all;max-height:none">{{ embed.snippet }}</pre>
                  <div class="row wrap gap-2">
                    <span class="badge badge--outline mono" title="The key in the link"><app-icon name="key" :size="10"/> {{ current ? current.key : '' }}</span>
                    <button class="btn btn--ghost btn--sm" @click="copy(embed.url, 'The address', 'url')">
                      <app-icon :name="copied === 'url' ? 'check' : 'copy'" :size="12"/> {{ copied === 'url' ? 'copied' : 'copy the address alone' }}
                    </button>
                    <button class="btn btn--ghost btn--sm push" :disabled="busy" @click="confirmRotate = true" title="A new key, and so a new line; the old one stops answering">
                      <app-icon name="refresh" :size="12"/> Regenerate the link
                    </button>
                  </div>
                  <p class="hint">
                    Keep <code>crossorigin="anonymous"</code>: it is what makes the browser say which wiki is loading the
                    script, which is what the link is checked against. The key in it is not a secret - it sits in the head of
                    every page - and that is fine, because the address list below is what keeps the link honest.
                  </p>
                </div>
              </section>

              <div class="grid grid-2">
                <section class="card">
                  <div class="card__head">
                    <span class="tile tile--accent"><app-icon name="server" :size="17"/></span>
                    <div class="card__heading">
                      <span class="card__title">Your BookStack instances</span>
                      <span class="card__desc">The instances on your profiles. Switch one on and this look is served to it.</span>
                    </div>
                  </div>
                  <div class="card__body col" style="gap:2px">
                    <button v-for="instance in instances" :key="instance.profile_id + ':' + instance.instance_id" type="button" class="switch-row"
                            @click="setWears(instance, !wears(instance))">
                      <span class="tile tile--sm none" :class="wears(instance) ? 'tile--accent' : ''"><app-icon name="book-open" :size="14"/></span>
                      <span class="col grow" style="gap:1px;min-width:0">
                        <span class="t-sm semi truncate">{{ instance.instance_name }}</span>
                        <span class="t-2xs dim truncate">{{ instance.origin || instance.base_url || 'no address yet' }} · profile {{ instance.profile_name }}</span>
                        <span v-if="otherLook(instance)" class="t-2xs c-warning">wears "{{ otherLook(instance).name }}" - switching it on moves it here</span>
                      </span>
                      <app-switch :model-value="wears(instance)" :label="instance.instance_name" @update:model-value="setWears(instance, $event)"/>
                    </button>
                    <empty-state v-if="!instances.length" icon="server" title="No BookStack instance yet"
                                 hint="Add one under Profiles › Accounts, or allow a wiki by its address on the right."/>
                  </div>
                </section>

                <section class="card">
                  <div class="card__head">
                    <span class="tile tile--accent"><app-icon name="globe" :size="17"/></span>
                    <div class="card__heading">
                      <span class="card__title">Other wikis</span>
                      <span class="card__desc">A BookStack CourseForge holds no credentials for. Its administrator pastes the same line.</span>
                    </div>
                  </div>
                  <div class="card__body col gap-3">
                    <textarea v-model="originsText" rows="4" class="mono" spellcheck="false" aria-label="Other wikis, one address per line"
                              placeholder="https://wiki.example.com&#10;docs.example.org"></textarea>
                    <p class="hint">One address per line. Only the scheme and the host matter - <code>https://wiki.example.com/books/x</code> is the same wiki as <code>wiki.example.com</code>.</p>
                    <p v-if="badOrigins.length" class="hint c-danger">Not an address a page can have: {{ badOrigins.join(', ') }}</p>
                  </div>
                </section>
              </div>

              <section class="card card--flat card--pad col gap-3">
                <div class="row gap-3">
                  <span class="tile tile--success"><app-icon name="shield-check" :size="17"/></span>
                  <div class="card__heading">
                    <h3 class="card__title">Where this link works</h3>
                    <span class="card__desc">Everywhere else it answers with a refusal that names the address, visible in the browser's network tab.</span>
                  </div>
                </div>
                <div class="row wrap gap-1">
                  <span v-for="origin in allowed" :key="origin" class="chip"><app-icon name="globe" :size="11"/>{{ origin }}</span>
                  <span v-if="!allowed.length" class="hint c-warning">Nowhere yet - switch an instance on or add an address, then save.</span>
                </div>
                <p v-if="isDirty" class="hint">This is what the list will be once you save.</p>
              </section>
            </div>
          </div>

          <!-- ============================================== conventions == -->
          <div v-else class="pane__body view-pad">
            <div class="col gap-5 container-narrow">
              <div class="note-strip">
                <app-icon name="badge-check" :size="15" class="c-accent"/>
                <span class="grow">
                  The prompts CourseForge writes pages with assume what this look renders: fenced <code>mermaid</code>
                  blocks become diagrams, and formulas are written with the delimiters MathJax is told to look for.
                  This check compares the look with the prompts of every profile whose instance wears it - the
                  profile's own override where it has one, the installation's prompt otherwise.
                </span>
                <span class="badge none" :class="audit.ok ? 'badge--success' : 'badge--warning'">
                  {{ audit.checked ? plural(audit.checked, 'profile') + ' checked' : 'nothing to check' }}
                </span>
              </div>

              <empty-state v-if="!audit.checked" icon="server" title="No profile publishes into this look yet"
                           hint="Switch a BookStack instance on under Link & wikis and save. The profiles that publish into it are then compared with this look."/>

              <empty-state v-else-if="audit.ok" icon="badge-check" title="Every checked profile agrees with this look"
                           hint="Formulas, diagrams and code fences are written the way this look renders them. Change the delimiters or switch a feature off, and this is where you hear about it."/>

              <section v-for="(issue, i) in audit.issues" :key="i" class="card">
                <div class="card__head">
                  <span class="tile" :class="issue.level === 'warning' ? 'tile--warning' : 'tile--accent'"><app-icon :name="levelOf(issue).icon" :size="17"/></span>
                  <div class="card__heading">
                    <span class="card__title">{{ issue.profile_name }}</span>
                    <span class="card__desc">{{ levelOf(issue).label }}<template v-if="issue.slot"> · prompt slot <code>{{ issue.slot }}</code> · read from the {{ issue.layer }}</template></span>
                  </div>
                  <span class="badge none" :class="issue.level === 'warning' ? 'badge--warning' : 'badge--accent'">{{ issue.level }}</span>
                </div>
                <div class="card__body col gap-3">
                  <p class="t-sm">{{ issue.message }}</p>
                  <template v-if="issue.recommended">
                    <details>
                      <summary class="t-xs dim" style="cursor:pointer">Show the recommended wording</summary>
                      <pre class="log mt-2" style="white-space:pre-wrap;max-height:320px">{{ issue.recommended }}</pre>
                    </details>
                    <div class="row wrap gap-2">
                      <button class="btn btn--sm" :disabled="fixing !== ''" @click="fix(issue, 'profile')">
                        <app-icon name="wrench" :size="13"/> Write it into the profile "{{ issue.profile_name }}"
                      </button>
                      <button v-if="isAdmin && issue.layer === 'installation'" class="btn btn--sm" :disabled="fixing !== ''" @click="fix(issue, 'installation')">
                        <app-icon name="wrench" :size="13"/> Write it into the installation prompts
                      </button>
                    </div>
                    <p class="hint">
                      The profile is the safer place: it changes only the courses written with it. The installation prompt
                      is what every profile without an override reads, which is right when every wiki you publish into wears this look.
                    </p>
                  </template>
                  <p v-else class="hint">
                    This one is a content decision rather than wording, so nothing here can write it for you.
                    The profile's <strong>Content defaults</strong> tab is where the element is switched.
                  </p>
                </div>
              </section>
            </div>
          </div>
        </template>

        <empty-state v-else icon="palette" title="No look yet"
                     hint="A look is a BookStackDev configuration - highlighting, diagrams, formulas, embeds, the light/dark button - served to any number of BookStack instances by one line in their custom head.">
          <button class="btn btn--primary mt-2" :disabled="creating" @click="create"><app-icon name="plus" :size="15"/> Create a look</button>
        </empty-state>
      </section>
    </div>

    <app-modal v-if="confirmDelete" title="Delete this look?" icon="alert" @close="confirmDelete = false">
      <p class="t-sm">
        <strong>{{ draft.name }}</strong> is removed, its link stops answering at once, and every instance wearing it goes back to
        plain BookStack. The script line pasted into those wikis then loads nothing until it is taken out.
      </p>
      <template #footer>
        <button class="btn" @click="confirmDelete = false">Cancel</button>
        <button class="btn btn--danger" :disabled="busy" @click="remove"><app-icon name="trash" :size="14"/> Delete</button>
      </template>
    </app-modal>

    <app-modal v-if="confirmRotate" title="Regenerate the link?" icon="alert" @close="confirmRotate = false">
      <p class="t-sm">
        This look gets a new key, and so a new line to paste. The line every wiki has now is refused from that moment -
        which is the point, if it was copied somewhere it should not have been - so each of them has to paste the new one.
      </p>
      <template #footer>
        <button class="btn" @click="confirmRotate = false">Cancel</button>
        <button class="btn btn--primary" :disabled="busy" @click="rotate"><app-icon name="refresh" :size="14"/> Regenerate</button>
      </template>
    </app-modal>`,
};

export default BookStackDevView;
