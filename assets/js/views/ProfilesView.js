/**
 * Profiles - the AI accounts, the models and the wording a course is written with.
 *
 * A profile is the answer to "who writes this, with what, and how does it
 * sound". It bundles the AI accounts and their keys, the BookStack instances a
 * course is published into, which model writes the outline and which writes the
 * pages, the language, how many pages run at once, and any prompt this profile
 * says differently from the installation default. A course points at one
 * profile, so changing the model for twenty courses is one edit here.
 *
 * Three things about this screen are worth knowing before reading it.
 *
 * The provider catalogue is no longer four entries. CourseForge 4 knows about
 * roughly two dozen endpoints, most of which are the same OpenAI-shaped API at
 * a different address, so the picker is grouped by what actually distinguishes
 * them - whether there is a batch queue behind it, whether it runs on your own
 * machine, whether it costs anything - and it is searchable, because a list of
 * two dozen in a dropdown is a list nobody reads to the end.
 *
 * A batch queue is never claimed on trust. The catalogue can only say what an
 * endpoint's documentation promises; whether *your key* may use the queue is a
 * different question, and the only honest answer comes from asking the endpoint
 * with that key. So the queue badge appears when a probe has said yes for this
 * account, and never because a table said the provider has one.
 *
 * And the ":batch" convention is the most valuable thing on this screen, so it
 * is spelled out rather than hidden behind a tickbox: queueing a course means
 * the pages come back within a day, for roughly half the money, and the tab can
 * be closed while it happens.
 */
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { state, loadProfiles, loadCatalogue, loadBookStackDev, declareUnsaved, paramByKey, go, secretsLocked } from '@/core/store.js';
import { post, put, del } from '@/core/api.js';
import { toast, attempt } from '@/core/toast.js';
import { useFuzzy } from '@/core/fuzzy.js';
import { clone, uid, plural, relativeTime, LANGUAGES } from '@/core/format.js';

import AppIcon from '@/components/AppIcon.js';
import SecretLock from '@/components/SecretLock.js';
import AppModal from '@/components/AppModal.js';
import ComboBox from '@/components/ComboBox.js';
import DetailEditor from '@/components/DetailEditor.js';
import EmptyState from '@/components/EmptyState.js';
import PromptWorkbench from '@/components/PromptWorkbench.js';
import ViewHeader from '@/components/ViewHeader.js';

const MODEL_SLOTS = [
  {
    key: 'overview',
    label: 'Course outline',
    icon: 'sitemap',
    hint: 'Designs and revises the chapter and page structure. One call per course.',
    batchable: false,
  },
  {
    key: 'page',
    label: 'Course pages',
    icon: 'file-text',
    hint: 'Writes the actual teaching content. One call per page - this is where the budget goes.',
    batchable: true,
  },
];

/** The four tabs of a profile, each with the glyph the rest of the application uses for the same thing. */
const TABS = [
  { key: 'accounts', label: 'Accounts', icon: 'key', hint: 'BookStack instances and AI accounts, with their credentials' },
  { key: 'models', label: 'Models & output', icon: 'cpu', hint: 'Which model writes what, the language, and how many pages at once' },
  { key: 'content', label: 'Content defaults', icon: 'list-check', hint: 'What every course on this profile starts from' },
  { key: 'prompts', label: 'Prompts', icon: 'file-text', hint: 'The wording this profile says differently from the installation' },
];

/** The shape a profile's content-details layer has when there is nothing in it. */
const emptyDetails = () => ({ features: {}, params: {} });

/**
 * The details layer as the editor needs it: objects, never arrays.
 *
 * The server stores an empty map as `{}` for exactly this reason, but a
 * profile written by an older release carries no layer at all, and a
 * hand-edited one might carry `[]` - an array, which drops the first key
 * written into it on its way back to JSON.
 */
const shapeDetails = (raw) => {
  const out = emptyDetails();
  for (const group of ['features', 'params']) {
    const value = raw?.[group];
    if (value && typeof value === 'object' && !Array.isArray(value)) out[group] = { ...value };
  }
  return out;
};

const BATCH_SUFFIX = ':batch';

/**
 * The adapters CourseForge has always had a class for, used only to sort a
 * catalogue that predates the `group` field. Everything else is grouped by what
 * the entry says about itself.
 */
const NATIVE_KINDS = new Set(['openai', 'anthropic', 'openrouter']);

/**
 * The headings the provider picker is divided by, in the order they are shown.
 *
 * The order is deliberate: what CourseForge supports first-class, then what
 * costs money and can be queued, then what costs money and cannot, then what
 * costs nothing because it runs on your own hardware, and last the escape hatch
 * for an address nobody has heard of.
 */
const PROVIDER_GROUPS = [
  {
    id: 'native',
    label: 'Built into CourseForge',
    description: 'CourseForge speaks these APIs itself, quirks and all, so nothing has to be discovered.',
  },
  {
    id: 'hosted_queue',
    label: 'Hosted, with a batch queue',
    description: 'A paid endpoint whose documentation promises a queue. Queueing a long run costs roughly '
      + 'half as much - whether your own key may use it is a separate question, answered by the check '
      + 'button on the account.',
  },
  {
    id: 'hosted_sync',
    label: 'Hosted, answered straight away',
    description: 'A paid endpoint with no queue CourseForge can drive. Every page is written the moment it '
      + 'is asked for, at full price.',
  },
  {
    id: 'local',
    label: 'Running on your own machine',
    description: 'Nothing leaves the machine and nothing is billed. No key is needed, there is no queue, '
      + 'and the model id has to match exactly what the server is serving.',
  },
  {
    id: 'custom',
    label: 'Somewhere else entirely',
    description: 'Any address that speaks the OpenAI chat API. Paste it and let CourseForge find out what '
      + 'is behind it.',
  },
];

/**
 * What a capability probe concluded, in words a person can act on.
 *
 * `forbidden` is the one worth reading twice: the queue is real and this key is
 * not allowed near it, so checking again will keep saying the same thing. What
 * changes that answer is a paid tier, not another round trip.
 */
const PROBE_RESULTS = {
  yes: {
    badge: 'badge--success',
    label: 'queue works with this key',
    line: 'The batch queue answered, and this key may use it.',
  },
  no: {
    badge: 'badge--outline',
    label: 'no queue',
    line: 'There is no batch queue here that CourseForge can submit to.',
  },
  no_upload_lane: {
    badge: 'badge--warning',
    label: 'queue without an upload',
    line: 'This provider has a batch queue but no compatible file upload, so CourseForge has no way to '
      + 'hand it the work. Pages from this account are written one at a time.',
  },
  forbidden: {
    badge: 'badge--warning',
    label: 'queue not open to this key',
    line: 'The queue exists and this key may not use it. Some providers sell the queue only on a paid '
      + 'tier, so an upgrade is what changes this - checking again will not.',
  },
  unknown: {
    badge: 'badge--outline',
    label: 'undecided',
    line: 'The endpoint answered in a way that settles nothing. CourseForge will try a real submission '
      + 'only if you ask it to, and report whatever comes back.',
  },
};

/** A catalogue entry stands in for one that is missing, so nothing renders blank. */
const UNKNOWN_PROVIDER = {
  id: '', kind: '', label: 'Not chosen yet', base_url: '', needs_key: true, batch: 'probe',
  batch_note: '', hint: '', docs: '', local: false, beta: false, group: 'custom', preset_key: '', retired: false,
};

export const ProfilesView = {
  name: 'ProfilesView',
  components: { AppIcon, AppModal, ComboBox, DetailEditor, EmptyState, PromptWorkbench, SecretLock, ViewHeader },
  setup() {
    const tab = ref('accounts');
    const selectedId = ref(null);
    const draft = ref(null);
    const saving = ref(false);
    const creating = ref(false);
    const models = reactive({});           // ai account id -> string[]
    const modelMeta = reactive({});        // ai account id -> { batch:Set, supportsBatch:bool }
    const checks = reactive({});           // ai account id -> { ok, detail, probe, busy }
    const openGroups = reactive({});
    const confirmDelete = ref(false);
    const listOpen = ref(false);            // the profile list is a drawer below 1024px
    const picker = ref(null);               // the AI account whose provider is being chosen
    const providerSearch = ref('');

    /**
     * What the selected profile looked like when it was opened.
     *
     * Kept so the screen can tell whether anything has been typed since. The
     * admin Prompts screen protects the same edits, and a profile is the harder
     * of the two to redo - it is where an override lives.
     */
    const pristine = ref('');

    const isDirty = computed(() =>
      draft.value !== null && JSON.stringify(draft.value) !== pristine.value);

    const open = (profile) => {
      selectedId.value = profile.id;
      draft.value = { id: profile.id, name: profile.name, data: clone(profile.data) };
      if (!draft.value.data.prompts || Array.isArray(draft.value.data.prompts)) draft.value.data.prompts = {};
      draft.value.data.details = shapeDetails(draft.value.data.details);
      pristine.value = JSON.stringify(draft.value);
      listOpen.value = false;
    };

    const select = (profile) => {
      // Switching away from unsaved typing used to throw it away in silence.
      if (isDirty.value && draft.value && profile.id !== draft.value.id) {
        const keep = draft.value;
        toast.error(
          'That profile has unsaved changes. Save or discard them before opening another.',
        );
        draft.value = keep;
        return;
      }
      open(profile);
    };

    /** Puts the draft back to what the server holds. */
    const discard = () => {
      const current = state.profiles.find((profile) => profile.id === selectedId.value);
      if (current) {
        open(current);
        toast.info('Changes discarded.');
      }
    };

    // The same guard the admin screens use, so leaving the screen asks first.
    declareUnsaved(() => (isDirty.value ? 'unsaved changes to this profile' : ''));

    /* Somebody sent here from a course's Details tab arrives on the profile
       that course inherits from, on its Content defaults tab, rather than on
       whichever profile happens to be first. The request is read once and
       cleared, so the next visit starts where it always did. */
    watch(() => state.profiles.length, () => {
      const focus = state.profileFocus;
      if (focus) {
        const wanted = state.profiles.find((profile) => profile.id === focus.id);
        if (wanted) {
          open(wanted);
          tab.value = TABS.some((entry) => entry.key === focus.tab) ? focus.tab : 'accounts';
          state.profileFocus = null;
          return;
        }
      }
      if (!draft.value && state.profiles.length) open(state.profiles[0]);
    }, { immediate: true });

    /* ------------------------------------------------- content defaults */

    /**
     * The three views the detail editor draws from, computed here rather
     * than fetched: a profile sits directly on the installation's baseline,
     * which the catalogue already carries, so "inherited" is that baseline
     * and "effective" is the baseline with this profile's decisions laid
     * over it - the same arithmetic the server does for a course.
     */
    const profileDetails = computed(() => {
      const own = draft.value?.data.details ?? emptyDetails();
      const base = state.baseline ?? emptyDetails();
      const effective = { features: { ...base.features }, params: { ...base.params } };
      for (const [key, stateValue] of Object.entries(own.features ?? {})) {
        if (Number(stateValue) !== 0) effective.features[key] = Number(stateValue) > 0;
      }
      for (const [key, value] of Object.entries(own.params ?? {})) {
        if (value !== null && value !== '') effective.params[key] = value;
      }
      return { own, inherited: base, effective };
    });

    /** The editor's partial patch, applied to the draft the Save button writes. */
    const patchProfileDetails = (patch) => {
      if (!draft.value) return;
      const details = draft.value.data.details;
      for (const [key, stateValue] of Object.entries(patch.features ?? {})) {
        if (Number(stateValue) === 0) delete details.features[key];
        else details.features[key] = Number(stateValue) > 0 ? 1 : -1;
      }
      for (const [key, value] of Object.entries(patch.params ?? {})) {
        if (value === null || String(value).trim() === '') {
          delete details.params[key];
        } else {
          details.params[key] = paramByKey.value[key]?.type === 'text' ? String(value).trim() : Number(value);
        }
      }
    };

    const detailCount = computed(() => {
      const details = draft.value?.data.details ?? emptyDetails();
      return Object.keys(details.features ?? {}).length + Object.keys(details.params ?? {}).length;
    });

    // The prompt catalogue is what this screen calls "the config default", and
    // it was read once at sign-in. After somebody saved on the admin Prompts
    // screen it was stale here, so adding a sentence to a slot stored the OLD
    // text plus the sentence and quietly reverted their edit.
    onMounted(() => { attempt(() => loadCatalogue(), 'Load the prompt catalogue'); });

    /* ------------------------------------------------------------ CRUD */

    /**
     * The guard is inside the function rather than only on the button, because
     * a `:disabled` binding is a rendering and Vue renders on the next tick -
     * three presses inside one turn all get here first and all create a
     * profile. Every one of them is called "New profile", so what the person
     * is left with is three rows they cannot tell apart, and no hint that two
     * of them are theirs by accident.
     */
    const create = () => attempt(async () => {
      if (creating.value) return;
      creating.value = true;
      try {
        const data = await post('profiles', { name: 'New profile', data: clone(state.profileDefaults) });
        await loadProfiles();
        select(state.profiles.find((p) => p.id === data.profile.id) ?? data.profile);
        tab.value = 'accounts';
        toast.success('Profile created.');
      } finally {
        creating.value = false;
      }
    }, 'Create profile');

    const reload = () => attempt(loadProfiles, 'Reload profiles');

    const save = async (silent = false) => {
      if (!draft.value) return false;
      saving.value = true;
      const result = await attempt(async () => {
        await put(`profiles/${draft.value.id}`, { name: draft.value.name, data: draft.value.data });
        await loadProfiles();
        // The look each instance wears is read back off the profiles by the
        // BookStackDev screen, whose list is refreshed quietly here.
        loadBookStackDev().catch(() => {});
        const fresh = state.profiles.find((p) => p.id === draft.value.id);
        // open(), not select(): this is the same profile being re-read after a
        // successful write, so it must not go through the guard that stops
        // you leaving unsaved work - and it resets the snapshot the dirty
        // marker is measured against.
        if (fresh) open(fresh);                // pick up the redacted secrets
        if (!silent) toast.success('Profile saved.');
        return true;
      }, 'Save profile');
      saving.value = false;
      return result === true;
    };

    const remove = () => attempt(async () => {
      await del(`profiles/${draft.value.id}`);
      draft.value = null;
      selectedId.value = null;
      confirmDelete.value = false;
      await loadProfiles();
      if (state.profiles.length) select(state.profiles[0]);
      toast.success('Profile deleted.');
    }, 'Delete profile');

    /* ------------------------------------------------------ the catalogue */

    /**
     * Which heading a catalogue entry belongs under.
     *
     * The server says so in 4.0. The fallback exists for a catalogue served by
     * an older build, and it sorts by what the entry does say: an adapter
     * CourseForge has a class for is built in, a local server is local, and
     * everything else is separated by whether a queue is documented.
     */
    const groupOf = (provider) => {
      const stated = String(provider.group ?? '').trim();
      if (stated) return stated;
      if (NATIVE_KINDS.has(provider.kind) && !provider.preset_key) return 'native';
      if (provider.local === true) return 'local';
      if ((provider.preset_key ?? '') === 'custom') return 'custom';
      return provider.batch === true ? 'hosted_queue' : 'hosted_sync';
    };

    /**
     * The catalogue's own id for an entry.
     *
     * A dozen entries share the `oai-compat` kind and differ only by preset, so
     * the kind alone is not an identity. The server sends an id that already
     * combines the two; the fallback rebuilds it the same way for a catalogue
     * that does not.
     */
    const providerId = (provider) => String(provider.id ?? '')
      || (provider.preset_key ? `${provider.kind}:${provider.preset_key}` : provider.kind);

    const trimSlash = (url) => String(url ?? '').trim().replace(/\/+$/, '');

    /**
     * The catalogue entry an account is on.
     *
     * Three ways of asking, most specific first. A stored preset key is the
     * real answer: a dozen entries share the OpenAI-compatible kind and differ
     * only by which preset they carry. Failing that, the address identifies the
     * preset almost as well - a preset is mostly an address plus the quirks
     * that go with it - and this is what recognises an account stored before
     * presets existed. Last, the kind alone, which is all a pre-4.0 profile
     * carries.
     */
    const providerFor = (account) => {
      const key = String(account?.preset_key ?? '').trim();
      if (key) {
        const byPreset = state.providers.find((p) => String(p.preset_key ?? '') === key);
        if (byPreset) return byPreset;
      }

      const url = trimSlash(account?.base_url);
      if (url) {
        const byUrl = state.providers.find(
          (p) => p.kind === account?.kind && trimSlash(p.base_url) === url
        );
        if (byUrl) return byUrl;
      }

      return state.providers.find((p) => p.kind === account?.kind && !p.preset_key)
        ?? state.providers.find((p) => p.kind === account?.kind)
        ?? { ...UNKNOWN_PROVIDER, kind: account?.kind ?? '', label: account?.kind || UNKNOWN_PROVIDER.label };
    };

    /**
     * Whether this provider is reached at an address the user can change.
     *
     * Everything is, except a retired kind, which has no endpoint left to
     * type. The custom endpoint is the other way round - it has no address
     * until somebody pastes one, which is the whole reason it exists.
     */
    const overHttp = (provider) =>
      !provider.retired && (String(provider.base_url ?? '') !== '' || provider.group === 'custom');

    /**
     * A server on your own machine answers an unauthenticated request, so a key
     * is not asked for. The catalogue says which, per entry, rather than being
     * guessed from the address.
     */
    const needsKey = (provider) => provider.needs_key === true;

    const providerHits = useFuzzy(
      computed(() => state.providers),
      providerSearch,
      { keys: ['label', 'hint', 'base_url'], limit: 200 },
    );

    /** The picker, grouped and in heading order; a group with no hits is dropped. */
    const providerGroups = computed(() => {
      const buckets = new Map();
      for (const provider of providerHits.value) {
        const id = groupOf(provider);
        if (!buckets.has(id)) buckets.set(id, []);
        buckets.get(id).push(provider);
      }

      const out = [];
      for (const group of PROVIDER_GROUPS) {
        const items = buckets.get(group.id);
        if (items?.length) out.push({ ...group, items });
        buckets.delete(group.id);
      }
      // A group the server invented after this file was written still shows,
      // rather than silently hiding every provider in it.
      for (const [id, items] of buckets) {
        out.push({ id, label: id, description: '', items });
      }
      return out;
    });

    const openPicker = (account) => {
      picker.value = account;
      providerSearch.value = '';
    };

    /**
     * Points an account at a different provider.
     *
     * The base URL is reset to the new provider's address only when the field
     * still holds the old provider's - an address somebody typed themselves is
     * never thrown away. The name follows the same rule, so an account nobody
     * has renamed keeps saying what it is.
     */
    const setProvider = (account, provider) => {
      const previous = providerFor(account);
      account.kind = provider.kind;
      account.preset_key = provider.preset_key ?? '';
      if (!account.base_url || account.base_url === previous.base_url) {
        account.base_url = provider.base_url ?? '';
      }
      if (!account.name || account.name === previous.label) account.name = provider.label;

      // Everything already learned about this account was learned about a
      // different endpoint.
      delete models[account.id];
      delete modelMeta[account.id];
      delete checks[account.id];
    };

    const chooseProvider = (provider) => {
      const account = picker.value;
      picker.value = null;
      if (account) setProvider(account, provider);
    };

    const isCurrentProvider = (provider) =>
      picker.value !== null && providerId(providerFor(picker.value)) === providerId(provider);

    /* -------------------------------------------------------- accounts */

    const addBookstack = () => draft.value.data.bookstack.push({
      id: uid(), name: 'BookStack', base_url: 'https://', token_id: '', token_secret: '', token_secret_set: false,
      bookstackdev_id: null,
    });

    /** The look an instance wears, from the select: '' is plain BookStack. */
    const setLook = (instance, value) => { instance.bookstackdev_id = value === '' ? null : Number(value); };
    const lookName = (id) => state.bookstackdev.profiles.find((look) => look.id === id)?.name ?? '';

    /**
     * Where a look disagrees with this profile's prompts, for the Prompts tab.
     * Every look's check names the profiles it looked at; this is the slice
     * about the one open here, with the look named so the fix button can say
     * whose wording it writes.
     */
    const lookIssues = computed(() => {
      if (!draft.value) return [];
      const out = [];
      for (const look of state.bookstackdev.profiles) {
        for (const issue of look.audit?.issues ?? []) {
          if (issue.profile_id === draft.value.id && issue.recommended) out.push({ ...issue, bookstackdev_name: look.name });
        }
      }
      return out;
    });

    const addAi = () => {
      const first = state.providers[0] ?? UNKNOWN_PROVIDER;
      draft.value.data.ai.push({
        id: uid(), name: first.label, kind: first.kind, preset_key: first.preset_key ?? '',
        base_url: first.base_url ?? '', api_key: '', organization: '',
        site_url: '', site_name: '', api_key_set: false,
      });
    };

    const aiAccounts = computed(() => draft.value?.data.ai ?? []);
    const modelList = (accountId) => models[accountId] ?? [];

    const loadModels = (accountId) => attempt(async () => {
      if (!accountId) { toast.error('Choose an AI account for this slot first.'); return; }
      if (!await save(true)) return;
      const data = await post(`profiles/${draft.value.id}/models`, { ai_id: accountId });
      models[accountId] = data.models ?? [];
      modelMeta[accountId] = {
        batch: new Set(data.batch ?? []),
        supportsBatch: data.supports_batch === true,
      };
      toast.success(`${models[accountId].length} model(s) loaded.`);
    }, 'Load models');

    /**
     * Asks the server to try the endpoint with this account's key.
     *
     * For an HTTP provider this is the capability probe: a handful of free GETs
     * that decide whether the address is an API at all, whether the key works,
     * and whether there is a batch queue this key may use.
     */
    const checkAccount = (accountId) => attempt(async () => {
      if (!await save(true)) return;
      checks[accountId] = { busy: true, ok: false, detail: 'Checking…', probe: null };
      try {
        const data = await post(`profiles/${draft.value.id}/check`, { ai_id: accountId });
        const result = data.check ?? {};
        checks[accountId] = {
          ...result,
          // A server that ships the probe with the check answers the queue
          // question outright. One that does not leaves it unanswered rather
          // than letting "the key works" be read as "the queue works".
          probe: typeof result.probe?.result === 'string' ? result.probe : null,
          busy: false,
        };
      } catch (error) {
        checks[accountId] = { busy: false, ok: false, detail: error.message, probe: null };
      }
    }, 'Check account');

    /* ----------------------------------------------------------- batching */

    /**
     * What is actually known about this account's batch queue.
     *
     * A probe result outranks everything, because it was taken against this
     * key. Without one, the catalogue can only be quoted as what it is - a
     * claim about the endpoint, not about the account holding the key.
     */
    const probeOf = (account) => {
      const live = checks[account?.id]?.probe;
      if (typeof live?.result === 'string') return live;
      const stored = account?.batch_probe;
      return typeof stored?.result === 'string' ? stored : null;
    };

    const queueState = (account) => {
      const provider = providerFor(account);
      const probe = probeOf(account);
      // What the catalogue says about this provider's queue in its own words.
      // Always shown alongside whatever is known about the account, because it
      // carries the detail nothing else does - the 48-hour expiry, the tier the
      // queue is sold on, the seven-day window.
      const note = String(provider.batch_note ?? '');

      if (probe) {
        const words = PROBE_RESULTS[probe.result] ?? PROBE_RESULTS.unknown;
        return {
          confirmed: probe.result === 'yes',
          badge: words.badge,
          label: words.label,
          line: words.line,
          note,
          reason: String(probe.reason ?? ''),
          at: Number(probe.at ?? 0),
        };
      }

      const blank = { confirmed: false, badge: '', label: '', note, reason: '', at: 0 };

      if (provider.batch === false) {
        return {
          ...blank,
          line: `${provider.label} has no batch queue CourseForge can submit to, so every page is written the moment it is asked for.`,
        };
      }
      if (provider.batch === true) {
        return {
          ...blank,
          line: 'This endpoint is documented to have a batch queue. Whether your key may use it is a '
            + 'different question - check the endpoint to find out before a course depends on it.',
        };
      }
      return {
        ...blank,
        line: 'Nobody has checked yet whether there is a usable batch queue behind this address. Checking '
          + 'costs nothing and spends nothing.',
      };
    };

    const slotConfig = (slotKey) => draft.value?.data.models[slotKey] ?? null;
    const slotAccount = (slotKey) =>
      aiAccounts.value.find((a) => a.id === slotConfig(slotKey)?.ai_id) ?? null;

    const slotModel = (slotKey) => slotConfig(slotKey)?.model ?? '';
    const bareModel = (slotKey) => slotModel(slotKey).replace(/:batch$/i, '');
    const isBatch = (slotKey) => slotModel(slotKey).toLowerCase().endsWith(BATCH_SUFFIX);

    /** The suffix is CourseForge's own marker, so toggling it is a string edit. */
    const setBatch = (slotKey, on) => {
      const config = slotConfig(slotKey);
      if (!config) return;
      const bare = config.model.replace(/:batch$/i, '');
      config.model = on ? `${bare}${BATCH_SUFFIX}` : bare;
    };

    /**
     * Whether this slot may be queued at all, and what to say when it may not.
     *
     * Permissive where the answer is genuinely unknown: the server refuses the
     * submission with a real reason if it turns out there is no queue, and that
     * is a better outcome than a tickbox nobody can press and nobody can
     * explain. Only a definite no - a provider with no queue, or a probe that
     * settled it - takes the choice away.
     *
     * The reason is empty wherever `queueState()` has already said the same
     * thing on screen. Two sentences saying a provider has no queue read as a
     * screen that has lost track of itself.
     */
    const batchState = (slotKey) => {
      const account = slotAccount(slotKey);
      if (!account) return { allowed: false, reason: 'Choose an AI account for this slot first.' };

      const provider = providerFor(account);
      if (provider.batch === false) return { allowed: false, reason: '' };

      const probe = probeOf(account);
      if (probe && probe.result !== 'yes' && probe.result !== 'unknown') {
        return { allowed: false, reason: '' };
      }

      const meta = modelMeta[account.id];
      if (!meta) return { allowed: true, reason: '' };
      if (!meta.supportsBatch) {
        return { allowed: false, reason: 'This endpoint answered without a batch API.' };
      }

      const bare = bareModel(slotKey);
      if (meta.batch.size && bare && !meta.batch.has(bare)) {
        return { allowed: true, reason: `${bare} is not on the list of models the queue accepts, so it may be refused.` };
      }
      return { allowed: true, reason: '' };
    };

    /** The models the provider says its queue takes, when it says anything. */
    const batchModelsFor = (slotKey) => {
      const account = slotAccount(slotKey);
      const meta = account ? modelMeta[account.id] : null;
      return meta ? [...meta.batch] : [];
    };

    const modelsFetched = (slotKey) => {
      const account = slotAccount(slotKey);
      return account ? modelMeta[account.id] !== undefined : false;
    };

    const slotQueue = (slotKey) => {
      const account = slotAccount(slotKey);
      return account ? queueState(account) : null;
    };

    /* --------------------------------------------------------- prompts */

    /**
     * The prompt groups, shaped the way `PromptWorkbench` wants them — `key`
     * rather than `id`, because that is what the administrator's screen has
     * always called it and the two now feed the same component.
     *
     * A group with no slots in it still gets no tab. The catalogue is data, an
     * installation can empty a group out, and a tab that opens onto nothing is
     * worse than a tab that is not there.
     */
    const groups = computed(() => {
      const buckets = {};
      for (const [key, slot] of Object.entries(state.promptSlots)) {
        (buckets[slot.group] ??= []).push({ key, ...slot });
      }
      return Object.entries(state.promptGroups)
        .filter(([id]) => buckets[id]?.length)
        .map(([id, group]) => ({ key: id, ...group, slots: buckets[id] }));
    });

    const defaultOf = (key) => state.promptSlots[key]?.value ?? '';
    const textOf = (key) => {
      const own = draft.value?.data.prompts?.[key];
      return typeof own === 'string' ? own : defaultOf(key);
    };
    const isCustom = (key) => textOf(key) !== defaultOf(key);

    const setPrompt = (key, value) => {
      if (!draft.value) return;
      if (value === defaultOf(key)) delete draft.value.data.prompts[key];
      else draft.value.data.prompts[key] = value;
    };

    const resetPrompt = (key) => { if (draft.value) delete draft.value.data.prompts[key]; };

    const customCount = computed(() =>
      draft.value ? Object.keys(state.promptSlots).filter(isCustom).length : 0
    );
    const slotCount = computed(() => Object.keys(state.promptSlots).length);

    /**
     * Getting around the forty-one slots — the tabbar, the search, the two
     * panes — is `components/PromptWorkbench.js`, which is the same component
     * the administrator's Prompts screen renders. This tab supplies the two
     * things that differ: the slots, and what an override means.
     */
    const promptSlotList = computed(() =>
      groups.value.flatMap((group) => group.slots.map((slot) => ({ ...slot, group: group.key }))));

    /** Every slot's current text, which is the profile's override or the config default. */
    const promptValues = computed(() => {
      const out = {};
      for (const slot of promptSlotList.value) out[slot.key] = textOf(slot.key);
      return out;
    });

    /** No catalogue yet: the workbench holds its empty state rather than flashing it. */
    const promptsLoading = computed(() => Object.keys(state.promptSlots).length === 0);

    const resetAllPrompts = () => {
      if (!draft.value || !customCount.value) return;
      draft.value.data.prompts = {};
      toast.success('All prompts follow the config defaults again.');
    };

    const toggleGroup = (id) => { openGroups[id] = !(openGroups[id] ?? true); };
    const groupOpen = (id) => openGroups[id] ?? true;

    // Literal mustaches must never appear in a template - Vue's parser would
    // close the interpolation at the first `}}`. Ship them as data instead.
    const languageToken = '{' + '{language}' + '}';
    const batchSuffix = BATCH_SUFFIX;

    return {
      state, tab, draft, selectedId, saving, creating, confirmDelete, listOpen, MODEL_SLOTS, LANGUAGES, TABS, secretsLocked,
      languageToken, batchSuffix, profileDetails, patchProfileDetails, detailCount,
      select, create, reload, save, remove, addBookstack, addAi, aiAccounts, modelList, loadModels,
      setLook, lookName, lookIssues, go,
      providerFor, providerId, overHttp, needsKey, providerGroups, providerSearch,
      picker, openPicker, chooseProvider, isCurrentProvider,
      checks, checkAccount, queueState,
      isBatch, setBatch, batchState, bareModel, batchModelsFor, modelsFetched, slotQueue,
      groups, defaultOf, textOf, isCustom, setPrompt, resetPrompt, resetAllPrompts,
      customCount, slotCount, promptSlotList, promptValues, promptsLoading,
      toggleGroup, groupOpen, plural, relativeTime,
      isDirty, discard,
    };
  },
  template: `
    <view-header title="Profiles" icon="sliders" subtitle="Who writes a course, with what, and how it sounds">
      <template #actions>
        <span class="badge hide-sm">{{ plural(state.profiles.length, 'profile') }}</span>
        <button class="btn btn--ghost btn--icon" title="Reload" aria-label="Reload the profiles" @click="reload">
          <app-icon name="refresh" :size="15"/>
        </button>
        <button class="btn btn--primary" :disabled="creating" @click="create">
          <app-icon :name="creating ? 'refresh' : 'plus'" :size="15" :spin="creating"/>
          {{ creating ? 'Creating…' : 'New profile' }}
        </button>
      </template>
    </view-header>

    <div class="workspace workspace--two">
      <div v-if="listOpen" class="scrim" @click="listOpen = false"></div>

      <aside class="pane pane--left" :class="{ 'is-open': listOpen }">
        <div class="pane__head">
          <span class="eyebrow grow">{{ plural(state.profiles.length, 'profile') }}</span>
          <button class="btn btn--ghost btn--sm btn--icon none outline-toggle" title="Close" aria-label="Close the profile list"
                  @click="listOpen = false"><app-icon name="x" :size="14"/></button>
        </div>
        <div class="pane__body" style="padding:var(--s-2)">
          <button v-for="profile in state.profiles" :key="profile.id"
                  class="tree__page" :class="{ 'is-active': selectedId === profile.id }"
                  @click="select(profile)">
            <app-icon name="sliders" :size="13"/>
            <span class="truncate grow">{{ profile.name }}</span>
          </button>
          <p v-if="!state.profiles.length" class="t-xs faint" style="padding:var(--s-4);text-align:center">
            No profiles yet.
          </p>
        </div>
      </aside>

      <section class="pane">
        <template v-if="draft">
          <div class="pane__head">
            <button class="btn btn--ghost btn--sm btn--icon none outline-toggle" title="Show all profiles" aria-label="Show all profiles"
                    @click="listOpen = true"><app-icon name="menu" :size="15"/></button>
            <span class="tile tile--sm tile--accent none hide-sm"><app-icon name="sliders" :size="14"/></span>
            <input v-model="draft.name" class="grow" style="max-width:340px" placeholder="Profile name" aria-label="Profile name">
            <!-- The same signal the admin screens give: a badge that says
                 there is unsaved typing, and a way to throw it away on purpose
                 rather than by clicking somewhere else. -->
            <span v-if="isDirty" class="badge badge--warning push">unsaved changes</span>
            <button v-if="isDirty" class="btn btn--ghost btn--sm" @click="discard()">Discard</button>
            <button class="btn btn--primary" :class="{ push: !isDirty }" :disabled="saving" @click="save()">
              <app-icon v-if="saving" name="refresh" :size="14" spin/><app-icon v-else name="save" :size="14"/>
              {{ saving ? 'Saving…' : 'Save' }}
            </button>
            <button class="btn btn--danger btn--icon" title="Delete this profile" aria-label="Delete this profile"
                    @click="confirmDelete = true">
              <app-icon name="trash" :size="14"/>
            </button>
          </div>

          <nav class="tabbar" role="tablist" aria-label="Profile">
            <button v-for="entry in TABS" :key="entry.key" class="tab" :class="{ 'is-active': tab === entry.key }"
                    role="tab" :aria-selected="tab === entry.key" :title="entry.hint"
                    :data-tour="'profile-tab-' + entry.key" @click="tab = entry.key">
              <app-icon :name="entry.icon" :size="14"/>{{ entry.label }}
              <span v-if="entry.key === 'prompts' && customCount" class="tab__count">{{ customCount }}</span>
              <span v-else-if="entry.key === 'content' && detailCount" class="tab__count">{{ detailCount }}</span>
            </button>
          </nav>

          <!--
            Two shapes, not one. Accounts and Models & output are a column of
            cards and want a padded scroller, which is what pane__body is.
            The Prompts tab is a workbench - the same component the
            administrator's Prompts screen renders - and it wants the pane
            itself, because a workbench dropped inside a padded scroller is
            exactly what used to make this tab float in the middle of the
            window instead of filling it.
          -->
          <div v-if="tab === 'accounts'" class="pane__body view-pad">
            <div class="grid grid-2 container">
              <section class="col gap-3">
                <div class="row gap-3" data-tour="profile-bookstack">
                  <span class="tile tile--accent"><app-icon name="server" :size="17"/></span>
                  <div class="card__heading">
                    <h3 class="card__title">BookStack instances</h3>
                    <span class="card__desc">Where a course can be published. A course picks one or several of these.</span>
                  </div>
                  <button class="btn btn--sm none" @click="addBookstack"><app-icon name="plus" :size="13"/> Add</button>
                </div>
                <div v-for="(instance, i) in draft.data.bookstack" :key="instance.id" class="card card--pad col gap-3">
                  <div class="row gap-2">
                    <span class="tile tile--sm none"><app-icon name="book-open" :size="14"/></span>
                    <input v-model="instance.name" placeholder="Name" class="grow" aria-label="Instance name">
                    <button class="btn btn--ghost btn--sm btn--icon none" title="Remove this instance"
                            :aria-label="'Remove ' + (instance.name || 'this instance')"
                            @click="draft.data.bookstack.splice(i, 1)"><app-icon name="trash" :size="13"/></button>
                  </div>
                  <div class="input-icon">
                    <app-icon name="globe" :size="13"/>
                    <input v-model="instance.base_url" class="mono" placeholder="https://bookstack.example.com" aria-label="Base URL">
                  </div>
                  <div class="grid grid-2" style="gap:var(--s-2)">
                    <input v-model="instance.token_id" class="mono" placeholder="Token ID" aria-label="Token ID">
                    <div class="secret-field" :class="{ 'is-locked': secretsLocked }">
                      <input v-model="instance.token_secret" type="password" class="mono" aria-label="Token secret"
                             :disabled="secretsLocked"
                             :placeholder="secretsLocked ? 'locked - see Security' : (instance.token_secret_set ? '•••••••• stored' : 'Token secret')">
                      <secret-lock v-if="secretsLocked" what="BookStack token"/>
                    </div>
                  </div>
                  <p class="hint">
                    <template v-if="secretsLocked">A token cannot be stored until the server passes the security check{{ instance.token_secret_set ? '; the stored one keeps working' : '' }}.</template>
                    <template v-else>Leave the secret empty to keep the stored one.</template>
                  </p>
                  <div class="form-row">
                    <label class="row gap-2"><app-icon name="palette" :size="12"/> Look</label>
                    <select :value="instance.bookstackdev_id ?? ''" @change="setLook(instance, $event.target.value)"
                            :aria-label="'BookStackDev look for ' + (instance.name || 'this instance')">
                      <option value="">Plain BookStack - no look</option>
                      <option v-for="look in state.bookstackdev.profiles" :key="look.id" :value="look.id">{{ look.name }}</option>
                    </select>
                    <p class="hint">
                      A BookStackDev look - code highlighting, diagrams, formulas, embeds - served to this wiki by one line
                      in its custom head. Looks are made, and the line copied, under
                      <button class="btn btn--ghost btn--sm" style="padding:0 4px" @click="go('bookstackdev')">BookStackDev</button>.
                    </p>
                  </div>
                </div>
                <p v-if="!draft.data.bookstack.length" class="hint">
                  Add the BookStack instance a course gets published into.
                </p>
              </section>

              <section class="col gap-3">
                <div class="row gap-3" data-tour="profile-ai">
                  <span class="tile tile--magic"><app-icon name="robot" :size="17"/></span>
                  <div class="card__heading">
                    <h3 class="card__title">AI accounts</h3>
                    <span class="card__desc">Who does the writing. At least one, and the models tab decides which writes what.</span>
                  </div>
                  <button class="btn btn--sm none" @click="addAi"><app-icon name="plus" :size="13"/> Add</button>
                </div>

                <div v-for="(account, i) in draft.data.ai" :key="account.id" class="card card--pad col gap-3">
                  <div class="row gap-2">
                    <span class="tile tile--sm none"><app-icon name="sparkles" :size="14"/></span>
                    <input v-model="account.name" placeholder="Name" class="grow" aria-label="Account name">
                    <button class="btn btn--ghost btn--sm btn--icon none" title="Remove this account"
                            :aria-label="'Remove ' + (account.name || 'this account')"
                            @click="draft.data.ai.splice(i, 1)"><app-icon name="trash" :size="13"/></button>
                  </div>

                  <div class="form-row">
                    <label class="row gap-2"><app-icon name="cpu" :size="13"/> Where the models come from</label>
                    <button class="btn btn--block" style="justify-content:flex-start"
                            @click="openPicker(account)">
                      <app-icon name="layers" :size="14"/>
                      <span class="grow truncate" style="text-align:left">{{ providerFor(account).label }}</span>
                      <span v-if="providerFor(account).beta" class="badge badge--warning none">beta</span>
                      <span class="badge badge--outline none">change</span>
                    </button>
                  </div>

                  <!-- Address and key. A local server answers without a key, so
                       it is never asked for one. -->
                  <template v-if="overHttp(providerFor(account))">
                    <div class="form-row">
                      <input v-model="account.base_url" class="mono"
                             :placeholder="providerFor(account).base_url || 'https://api.example.com/v1'">
                      <p v-if="providerFor(account).local" class="hint">
                        The address of the server on your own machine. Leave it as it is unless you changed
                        the port.
                      </p>
                    </div>
                    <div v-if="needsKey(providerFor(account))" class="grid grid-2" style="gap:var(--s-2)">
                      <div class="input-icon secret-field" :class="{ 'is-locked': secretsLocked }">
                        <app-icon name="key" :size="13"/>
                        <input v-model="account.api_key" type="password" class="mono" aria-label="API key"
                               :disabled="secretsLocked"
                               :placeholder="secretsLocked ? 'locked - see Security' : (account.api_key_set ? '•••••••• stored' : 'API key')">
                        <secret-lock v-if="secretsLocked" what="API key"/>
                      </div>
                      <input v-if="account.kind === 'openai'" v-model="account.organization"
                             class="mono" placeholder="Organization (optional)">
                    </div>
                    <p v-else class="hint">
                      No key needed. This server answers whoever asks it, which is why it must not be
                      reachable from outside your network.
                    </p>
                  </template>

                  <!-- OpenRouter identifies applications by these two headers. -->
                  <div v-if="account.kind === 'openrouter'" class="grid grid-2" style="gap:var(--s-2)">
                    <input v-model="account.site_url" class="mono" placeholder="Your site URL (optional)">
                    <input v-model="account.site_name" class="mono" placeholder="App name (optional)">
                  </div>

                  <!-- An account on a kind this release no longer serves keeps its
                       row and says so, rather than vanishing with the profile it is
                       in. The hint carries the reason; the picker above is the way out. -->
                  <p v-if="providerFor(account).retired" class="note-strip note-strip--warning">
                    <app-icon name="alert" :size="14" class="c-warning"/>
                    <span>{{ providerFor(account).hint }} Pick another provider with the button above.</span>
                  </p>
                  <p v-else class="hint">{{ providerFor(account).hint }}</p>

                  <p v-if="providerFor(account).docs" class="hint">
                    <a :href="providerFor(account).docs" target="_blank" rel="noopener noreferrer">
                      Read this provider's own documentation
                    </a>
                  </p>

                  <div class="divider"></div>

                  <!-- What is actually known about the batch queue, which is
                       never the same question as "does the endpoint have one". -->
                  <div class="col gap-2">
                    <div class="row wrap gap-2">
                      <button class="btn btn--sm none" :disabled="checks[account.id]?.busy || providerFor(account).retired"
                              @click="checkAccount(account.id)">
                        <app-icon :name="checks[account.id]?.busy ? 'refresh' : 'zap'" :size="13"
                                  :spin="checks[account.id]?.busy"/>
                        {{ overHttp(providerFor(account)) ? 'Check this endpoint' : 'Check this account' }}
                      </button>
                      <span v-if="checks[account.id] && !checks[account.id].busy"
                            class="badge none" :class="checks[account.id].ok ? 'badge--success' : 'badge--warning'">
                        {{ checks[account.id].ok ? 'reachable' : 'needs attention' }}
                      </span>
                      <span v-if="queueState(account).confirmed" class="badge badge--accent none">
                        <app-icon name="layers" :size="10"/> batch queue
                      </span>
                    </div>

                    <p v-if="checks[account.id]?.detail" class="t-xs dim">{{ checks[account.id].detail }}</p>

                    <p class="hint">{{ queueState(account).line }}</p>
                    <p v-if="queueState(account).note" class="t-xs faint">{{ queueState(account).note }}</p>
                    <p v-if="queueState(account).reason" class="t-xs faint">{{ queueState(account).reason }}</p>
                    <p v-if="queueState(account).at" class="t-2xs faint">
                      Checked {{ relativeTime(queueState(account).at) }}.
                    </p>
                  </div>
                </div>

                <p v-if="!draft.data.ai.length" class="hint">
                  Add at least one AI account, otherwise nothing can be generated.
                </p>
              </section>
            </div>
          </div>

          <div v-else-if="tab === 'models'" class="pane__body view-pad">
            <div class="col gap-6 container-narrow">
              <div v-for="slot in MODEL_SLOTS" :key="slot.key" class="card card--pad">
                <div class="row gap-3 mb-4">
                  <span class="tile tile--accent"><app-icon :name="slot.icon" :size="17"/></span>
                  <div class="card__heading">
                    <h3 class="card__title">{{ slot.label }}</h3>
                    <span class="card__desc">{{ slot.hint }}</span>
                  </div>
                </div>
                <div class="grid grid-model" style="gap:var(--s-3)">
                  <div class="form-row">
                    <label class="row gap-2"><app-icon name="robot" :size="12"/> AI account</label>
                    <select v-model="draft.data.models[slot.key].ai_id" :aria-label="'AI account for ' + slot.label">
                      <option value="">— none —</option>
                      <option v-for="account in aiAccounts" :key="account.id" :value="account.id">{{ account.name }}</option>
                    </select>
                  </div>
                  <div class="form-row">
                    <label class="row between">
                      <span class="row gap-2"><app-icon name="cpu" :size="12"/> Model</span>
                      <button class="btn btn--ghost btn--sm" style="padding:0 4px"
                              @click="loadModels(draft.data.models[slot.key].ai_id)">
                        <app-icon name="refresh" :size="11"/> fetch list
                      </button>
                    </label>
                    <div class="row">
                      <combo-box v-model="draft.data.models[slot.key].model"
                                 :options="modelList(draft.data.models[slot.key].ai_id)"
                                 placeholder="gpt-4o-mini"/>
                    </div>
                  </div>
                  <div class="form-row">
                    <label title="Ignored by reasoning models">Temperature</label>
                    <input v-model.number="draft.data.models[slot.key].temperature" type="number" step="0.05" min="0" max="2">
                  </div>
                  <div class="form-row">
                    <label title="0 lets the provider decide">Max tokens</label>
                    <input v-model.number="draft.data.models[slot.key].max_tokens" type="number" min="0" step="1000">
                  </div>
                </div>

                <p class="hint mt-2">
                  The list is a shortcut, never a limit - type any model id the provider accepts, including
                  one it does not advertise. A server on your own machine usually has to be given the exact
                  id it is serving.
                </p>

                <!-- Queueing only earns its latency on the per-page slot: the
                     outline is a single call, and queueing one request means
                     waiting up to a day for it. -->
                <div v-if="slot.batchable" class="card card--flat card--pad mt-4 col gap-3">
                  <label class="row gap-2" style="cursor:pointer">
                    <input type="checkbox" :checked="isBatch(slot.key)"
                           :disabled="!batchState(slot.key).allowed"
                           @change="setBatch(slot.key, $event.target.checked)">
                    <span class="strong grow">Queue the pages of a course instead of writing them one by one</span>
                    <span v-if="isBatch(slot.key)" class="badge badge--accent none">queued</span>
                  </label>

                  <p class="hint">
                    The whole course is handed to the provider in one submission. The pages come back
                    <strong>within a day</strong> rather than within a minute, and cost
                    <strong>roughly half</strong> as much. Nothing has to stay open while it happens: close
                    the tab, and the pages appear as CourseForge collects them. Regenerating a single page
                    from the editor is always written straight away, whatever this says.
                  </p>

                  <div v-if="bareModel(slot.key)" class="col gap-1">
                    <p class="t-xs dim">
                      Queueing is stored as a marker on the model id, so this slot will ask for:
                    </p>
                    <p class="mono t-sm">
                      {{ bareModel(slot.key) }}<span v-if="isBatch(slot.key)" class="c-accent">{{ batchSuffix }}</span>
                    </p>
                  </div>

                  <template v-if="slotQueue(slot.key)">
                    <p class="hint" :class="slotQueue(slot.key).confirmed ? 'c-success' : ''">
                      {{ slotQueue(slot.key).line }}
                    </p>
                    <p v-if="slotQueue(slot.key).note" class="t-xs faint">{{ slotQueue(slot.key).note }}</p>
                    <p v-if="slotQueue(slot.key).reason" class="t-xs faint">{{ slotQueue(slot.key).reason }}</p>
                  </template>

                  <!-- Which models the queue takes is only worth saying while
                       queueing is still on the table. -->
                  <template v-if="batchState(slot.key).allowed">
                    <template v-if="batchModelsFor(slot.key).length">
                      <p class="t-xs dim">
                        This provider names the {{ batchModelsFor(slot.key).length }} models its queue
                        accepts. The one this slot asks for is highlighted:
                      </p>
                      <div class="row wrap gap-1 scroll-y" style="max-height:104px">
                        <span v-for="id in batchModelsFor(slot.key)" :key="id" class="chip"
                              :class="id === bareModel(slot.key) ? '' : 'chip--inherited'">{{ id }}</span>
                      </div>
                    </template>
                    <p v-else-if="modelsFetched(slot.key)" class="t-xs dim">
                      This provider does not say which models its queue takes, so a model can only be found
                      unacceptable when the course is actually submitted - and then nothing is lost but the
                      submission.
                    </p>
                    <p v-else class="t-xs dim">
                      Fetch the model list above to see which models this provider's queue accepts.
                    </p>
                  </template>

                  <p v-if="batchState(slot.key).reason" class="hint c-warning">
                    {{ batchState(slot.key).reason }}
                  </p>
                </div>
              </div>

              <div class="card card--pad col gap-4">
                <div class="row gap-3">
                  <span class="tile tile--accent"><app-icon name="translate" :size="17"/></span>
                  <div class="card__heading">
                    <h3 class="card__title">Language and pace</h3>
                    <span class="card__desc">What the courses are written in, and how many pages at a time.</span>
                  </div>
                </div>
                <div class="grid grid-2">
                <div class="form-row">
                  <label class="row gap-2"><app-icon name="globe" :size="12"/> Course language</label>
                  <div class="row"><combo-box v-model="draft.data.language" :options="LANGUAGES" placeholder="English" icon="translate"/></div>
                  <p class="hint">Fuzzy search, or type any language. It fills the <code>{{ languageToken }}</code> placeholder.</p>
                </div>
                <div class="form-row">
                  <label for="profile-concurrency" class="row gap-2"><app-icon name="layers" :size="12"/> Pages generated in parallel</label>
                  <input id="profile-concurrency" v-model.number="draft.data.concurrency" type="number" min="1" max="12">
                  <p class="hint">How many pages are written simultaneously. Raise it only if your provider allows it.</p>
                </div>
                <label class="check" style="grid-column:1/-1">
                  <input type="checkbox" v-model="draft.data.typography">
                  <span>
                    <app-icon name="quote" :size="12" class="dim"/> Correct the punctuation of a generated page
                    <span class="hint">
                      Quotation marks, apostrophes, ellipses, dashes and spacing set the way the language
                      above sets them, before the page is stored - a German page closes with
                      <code>&#8220;</code> rather than with whatever the model reached for, a French one gets
                      its guillemets and its narrow spaces. Code, links, formulas and CourseForge's own
                      markers are never touched. The installation decides what a new profile starts with. A
                      course that is already written is corrected from Course &rarr; Settings &rarr;
                      Punctuation instead, whatever this says.
                    </span>
                  </span>
                </label>
                </div>
              </div>
            </div>
          </div>

          <!-- Content defaults: what every course on this profile starts from.
               The same editor the Details tab of a course is, one level up, so
               the two are read as the same list - because they are. -->
          <div v-else-if="tab === 'content'" class="pane__body view-pad">
            <div class="col gap-4 container">
              <div class="note-strip">
                <app-icon name="inherit" :size="15" class="c-accent"/>
                <span class="grow">
                  Every course written with this profile starts from what is decided here. A course, a chapter
                  or a page can still decide otherwise. Anything left on <strong>installation default</strong>
                  follows Administration › Settings › Course defaults - and keeps following it when that changes.
                  Nothing already written changes; the next page generated on any of this profile's courses does.
                </span>
                <span class="badge none" :class="detailCount ? 'badge--accent' : ''">{{ detailCount }} decided</span>
              </div>
              <div class="card card--pad">
                <detail-editor level="profile" :details="profileDetails" :busy="saving"
                               @change="patchProfileDetails"/>
              </div>
              <p class="hint">
                Decisions here are saved with the profile - press <strong>Save</strong> at the top when you are done.
              </p>
            </div>
          </div>

          <prompt-workbench v-else :groups="groups" :slot-list="promptSlotList"
                            :values="promptValues" :loading="promptsLoading" :issues="lookIssues"
                            @fix="setPrompt($event.key, $event.text)"
                            box-placeholder="Empty → nothing is sent for this slot"
                            empty-title="No prompt selected"
                            empty-hint="Every AI request is built from these slots. One you leave alone follows the installation default; one you edit here applies only to the courses that use this profile."
                            @edit="setPrompt($event.key, $event.text)">

            <template #note>
              <app-icon name="layers" :size="15" class="c-accent none" style="margin-top:1px"/>
              <span class="grow">
                Every prompt starts as the value the <strong>whole installation</strong> holds, which is what
                Administration &rsaquo; Prompts writes. Editing one here overrides it
                <strong>for this profile only</strong>; clearing an override makes it follow the installation
                again.
              </span>
              <span class="badge none">{{ customCount }} / {{ slotCount }} customised</span>
              <button class="btn btn--sm none" :disabled="!customCount" @click="resetAllPrompts">
                <app-icon name="inherit" :size="13"/> Reset all
              </button>
            </template>

            <template #group-badge="{ slotsIn }">
              <span v-if="slotsIn.filter((slot) => isCustom(slot.key)).length" class="badge badge--warning">
                {{ slotsIn.filter((slot) => isCustom(slot.key)).length }}
              </span>
            </template>

            <template #mark="{ item }">
              <span v-if="isCustom(item.key)" class="dot none" style="background:var(--warning)"
                    title="Overridden by this profile"></span>
            </template>

            <template #status="{ item }">
              <span class="badge none" :class="isCustom(item.key) ? 'badge--warning' : ''">
                {{ isCustom(item.key) ? 'customised' : 'installation default' }}
              </span>
              <button class="btn btn--sm none" :disabled="!isCustom(item.key)" @click="resetPrompt(item.key)">
                <app-icon name="inherit" :size="13"/> Reset
              </button>
            </template>

            <template #footer="{ item }">
              <p class="hint">
                An override you deliberately leave empty is honoured - that slot then sends nothing at all for
                courses on this profile. <strong>Reset</strong> is what puts it back to following the
                installation.
              </p>

              <details v-if="isCustom(item.key) && defaultOf(item.key)">
                <summary class="t-xs dim" style="cursor:pointer">Show what the installation holds</summary>
                <pre class="log mt-2" style="white-space:pre-wrap;max-height:280px">{{ defaultOf(item.key) }}</pre>
              </details>
            </template>
          </prompt-workbench>
        </template>

        <empty-state v-else icon="sliders" title="No profile selected"
                     hint="A profile bundles the AI account, the BookStack instance, the model choices, the language, the content defaults and the prompt overrides.">
          <button class="btn btn--primary mt-2" @click="create"><app-icon name="plus" :size="15"/> Create a profile</button>
        </empty-state>
      </section>
    </div>

    <!-- the provider picker ------------------------------------------- -->
    <app-modal v-if="picker" title="Where should this account get its models?" icon="cpu" wide
               @close="picker = null">
      <div class="col gap-4">
        <div class="form-row">
          <div class="input-icon">
            <app-icon name="search" :size="14"/>
            <input v-model="providerSearch" placeholder="Search by name, address or what it is good at"
                   spellcheck="false" aria-label="Search providers">
          </div>
          <p class="hint">
            Most of these are the same API at a different address, so the differences that matter are
            grouped below: whether there is a queue that halves the price of a long run, whether it runs on
            your own machine, and whether it needs a key at all.
          </p>
        </div>

        <section v-for="group in providerGroups" :key="group.id" class="col gap-2">
          <div>
            <p class="eyebrow">{{ group.label }}</p>
            <p v-if="group.description" class="hint">{{ group.description }}</p>
          </div>

          <button v-for="provider in group.items" :key="providerId(provider)"
                  class="card card--action card--pad col gap-2"
                  :style="isCurrentProvider(provider) ? 'border-color:var(--accent)' : ''"
                  @click="chooseProvider(provider)">
            <span class="row wrap gap-2">
              <span class="semi">{{ provider.label }}</span>
              <span v-if="isCurrentProvider(provider)" class="badge badge--accent">in use here</span>
              <span v-if="provider.beta" class="badge badge--warning">beta</span>
              <span v-if="provider.batch === true" class="badge badge--outline">queue documented</span>
              <span v-else-if="provider.batch === 'probe'" class="badge badge--outline">queue unknown</span>
              <span v-if="provider.local" class="badge badge--success">your machine</span>
              <span v-if="!needsKey(provider)" class="badge badge--outline">no key</span>
            </span>
            <span v-if="provider.base_url" class="mono t-2xs faint truncate">{{ provider.base_url }}</span>
            <span v-if="provider.hint" class="hint">{{ provider.hint }}</span>
          </button>
        </section>

        <empty-state v-if="!providerGroups.length" icon="search" title="Nothing matches that"
                     hint="Clear the search to see every provider, or pick the custom endpoint at the bottom and paste an address."/>
      </div>

      <template #footer>
        <button class="btn" @click="picker = null">Cancel</button>
      </template>
    </app-modal>

    <app-modal v-if="confirmDelete" title="Delete this profile?" icon="alert" @close="confirmDelete = false">
      <p class="t-sm">Courses using <strong>{{ draft.name }}</strong> keep all their content but lose their profile,
        so you have to pick a new one before generating again.</p>
      <template #footer>
        <button class="btn" @click="confirmDelete = false">Cancel</button>
        <button class="btn btn--danger" @click="remove"><app-icon name="trash" :size="14"/> Delete</button>
      </template>
    </app-modal>`,
};

export default ProfilesView;
