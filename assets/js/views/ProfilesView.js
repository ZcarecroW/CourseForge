import { ref, reactive, computed, watch, nextTick } from 'vue';
import { state, loadProfiles } from '@/core/store.js';
import { post, put, del } from '@/core/api.js';
import { toast, attempt } from '@/core/toast.js';
import { clone, uid, LANGUAGES } from '@/core/format.js';

import AppIcon from '@/components/AppIcon.js';
import AppModal from '@/components/AppModal.js';
import ComboBox from '@/components/ComboBox.js';
import EmptyState from '@/components/EmptyState.js';
import ViewHeader from '@/components/ViewHeader.js';

const MODEL_SLOTS = [
  {
    key: 'overview',
    label: 'Course outline',
    hint: 'Designs and revises the chapter/page structure. One call per course.',
    batchable: false,
  },
  {
    key: 'page',
    label: 'Course pages',
    hint: 'Writes the actual teaching content. One call per page — this is where the budget goes.',
    batchable: true,
  },
];

const BATCH_SUFFIX = ':batch';

export const ProfilesView = {
  name: 'ProfilesView',
  components: { AppIcon, AppModal, ComboBox, EmptyState, ViewHeader },
  setup() {
    const tab = ref('accounts');
    const selectedId = ref(null);
    const draft = ref(null);
    const saving = ref(false);
    const models = reactive({});           // ai account id -> string[]
    const modelMeta = reactive({});        // ai account id -> { batch:Set, supportsBatch:bool }
    const checks = reactive({});           // ai account id -> { ok, detail, busy }
    const openGroups = reactive({});
    const confirmDelete = ref(false);
    const listOpen = ref(false);            // the profile list is a drawer below 1024px
    const textareas = {};

    const select = (profile) => {
      selectedId.value = profile.id;
      draft.value = { id: profile.id, name: profile.name, data: clone(profile.data) };
      if (!draft.value.data.prompts || Array.isArray(draft.value.data.prompts)) draft.value.data.prompts = {};
      listOpen.value = false;
    };

    watch(() => state.profiles.length, () => {
      if (!draft.value && state.profiles.length) select(state.profiles[0]);
    }, { immediate: true });

    /* ------------------------------------------------------------ CRUD */

    const create = () => attempt(async () => {
      const data = await post('profiles', { name: 'New profile', data: clone(state.profileDefaults) });
      await loadProfiles();
      select(state.profiles.find((p) => p.id === data.profile.id) ?? data.profile);
      tab.value = 'accounts';
      toast.success('Profile created.');
    }, 'Create profile');

    const save = async (silent = false) => {
      if (!draft.value) return false;
      saving.value = true;
      const result = await attempt(async () => {
        await put(`profiles/${draft.value.id}`, { name: draft.value.name, data: draft.value.data });
        await loadProfiles();
        const fresh = state.profiles.find((p) => p.id === draft.value.id);
        if (fresh) select(fresh);              // pick up the redacted secrets
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

    /* -------------------------------------------------------- accounts */

    const addBookstack = () => draft.value.data.bookstack.push({
      id: uid(), name: 'BookStack', base_url: 'https://', token_id: '', token_secret: '', token_secret_set: false,
    });
    /** The catalogue entry for an account kind, with a usable fallback. */
    const kindInfo = (kind) => state.providers.find((p) => p.kind === kind)
      ?? { kind, label: kind, base_url: '', needs_key: true, batch: false, hint: '' };

    const addAi = () => {
      const first = state.providers[0] ?? { kind: 'openai', label: 'OpenAI', base_url: 'https://api.openai.com/v1' };
      draft.value.data.ai.push({
        id: uid(), name: first.label, kind: first.kind, base_url: first.base_url,
        api_key: '', organization: '', cli_path: '', site_url: '', site_name: '', api_key_set: false,
      });
    };

    /**
     * Changing the type resets the base URL to that provider's default, but
     * only when the field still holds another provider's default - a URL the
     * user typed themselves is never thrown away.
     */
    const setKind = (account, kind) => {
      const previous = kindInfo(account.kind);
      const next = kindInfo(kind);
      account.kind = kind;
      if (!account.base_url || account.base_url === previous.base_url) account.base_url = next.base_url;
      delete models[account.id];
      delete modelMeta[account.id];
      delete checks[account.id];
    };

    const aiAccounts = computed(() => draft.value?.data.ai ?? []);
    const modelList = (accountId) => models[accountId] ?? [];

    const loadModels = (accountId) => attempt(async () => {
      if (!accountId) { toast.error('Pick an AI account first.'); return; }
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
     * Proves an account works before a course depends on it. For the Claude
     * subscription this is the only way to see the three things that can be
     * wrong: no CLI, not signed in, or an API key in the server environment
     * quietly taking over the billing.
     */
    const checkAccount = (accountId) => attempt(async () => {
      if (!await save(true)) return;
      checks[accountId] = { busy: true, ok: false, detail: 'Checking...' };
      try {
        const data = await post(`profiles/${draft.value.id}/check`, { ai_id: accountId });
        checks[accountId] = { ...data.check, busy: false };
      } catch (error) {
        checks[accountId] = { busy: false, ok: false, detail: error.message };
      }
    }, 'Check account');

    /* ----------------------------------------------------------- batching */

    const slotModel = (slotKey) => draft.value?.data.models[slotKey]?.model ?? '';
    const isBatch = (slotKey) => slotModel(slotKey).toLowerCase().endsWith(BATCH_SUFFIX);

    /** The suffix is CourseForge's own marker, so toggling it is a string edit. */
    const setBatch = (slotKey, on) => {
      const config = draft.value?.data.models[slotKey];
      if (!config) return;
      const bare = config.model.replace(/:batch$/i, '');
      config.model = on ? `${bare}${BATCH_SUFFIX}` : bare;
    };

    /**
     * Whether the account behind a slot has a queue at all. Unknown until the
     * model list has been fetched once, and permissive until then - the server
     * refuses the submission with a real reason if it turns out not to.
     */
    const batchState = (slotKey) => {
      const config = draft.value?.data.models[slotKey];
      const account = aiAccounts.value.find((a) => a.id === config?.ai_id);
      if (!account) return { allowed: false, reason: 'Pick an AI account for this slot first.' };

      const info = kindInfo(account.kind);
      if (!info.batch) return { allowed: false, reason: `${info.label} has no batch queue.` };

      const meta = modelMeta[account.id];
      if (!meta) return { allowed: true, reason: '' };
      if (!meta.supportsBatch) {
        return { allowed: false, reason: 'This endpoint answered without a batch API.' };
      }
      const bare = (config?.model ?? '').replace(/:batch$/i, '');
      if (meta.batch.size && bare && !meta.batch.has(bare)) {
        return { allowed: true, reason: `${bare} was not listed as batchable - it may be refused.` };
      }
      return { allowed: true, reason: '' };
    };

    /* --------------------------------------------------------- prompts */

    const groups = computed(() => {
      const buckets = {};
      for (const [key, slot] of Object.entries(state.promptSlots)) {
        (buckets[slot.group] ??= []).push({ key, ...slot });
      }
      return Object.entries(state.promptGroups)
        .filter(([id]) => buckets[id]?.length)
        .map(([id, group]) => ({ id, ...group, slots: buckets[id] }));
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
    const customInGroup = (group) => group.slots.filter((slot) => isCustom(slot.key)).length;

    const resetAllPrompts = () => {
      if (!draft.value || !customCount.value) return;
      draft.value.data.prompts = {};
      toast.success('All prompts follow the config defaults again.');
    };

    /** Drops a {{placeholder}} at the caret, so nobody has to type them by hand. */
    const insertPlaceholder = async (key, placeholder) => {
      const el = textareas[key];
      const token = `{{${placeholder}}}`;
      const current = textOf(key);
      if (!el) { setPrompt(key, `${current}${token}`); return; }
      const start = el.selectionStart ?? current.length;
      const end = el.selectionEnd ?? start;
      setPrompt(key, current.slice(0, start) + token + current.slice(end));
      await nextTick();
      el.focus();
      el.setSelectionRange(start + token.length, start + token.length);
    };

    const registerTextarea = (key, el) => { if (el) textareas[key] = el; };
    const toggleGroup = (id) => { openGroups[id] = !(openGroups[id] ?? true); };
    const groupOpen = (id) => openGroups[id] ?? true;

    // Literal mustaches must never appear in a template – Vue's parser would
    // close the interpolation at the first `}}`. Ship them as data instead.
    const languageToken = '{' + '{language}' + '}';

    return {
      state, tab, draft, selectedId, saving, confirmDelete, listOpen, MODEL_SLOTS, LANGUAGES, languageToken,
      select, create, save, remove, addBookstack, addAi, aiAccounts, modelList, loadModels,
      kindInfo, setKind, checks, checkAccount, isBatch, setBatch, batchState,
      groups, defaultOf, textOf, isCustom, setPrompt, resetPrompt, resetAllPrompts,
      customCount, slotCount, customInGroup, insertPlaceholder, registerTextarea,
      toggleGroup, groupOpen,
    };
  },
  template: `
    <view-header title="Profiles" icon="sliders">
      <template #actions>
        <button class="btn btn--primary" @click="create"><app-icon name="plus" :size="15"/> New profile</button>
      </template>
    </view-header>

    <div class="workspace workspace--two">
      <div v-if="listOpen" class="scrim" @click="listOpen = false"></div>

      <aside class="pane pane--left" :class="{ 'is-open': listOpen }">
        <div class="pane__head">
          <span class="eyebrow grow">{{ state.profiles.length }} profile(s)</span>
          <button class="btn btn--ghost btn--sm btn--icon none outline-toggle" title="Close"
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
            <button class="btn btn--ghost btn--sm btn--icon none outline-toggle" title="Show all profiles"
                    @click="listOpen = true"><app-icon name="menu" :size="15"/></button>
            <input v-model="draft.name" class="grow" style="max-width:340px" placeholder="Profile name">
            <button class="btn btn--primary push" :disabled="saving" @click="save()">
              <app-icon v-if="saving" name="refresh" :size="14" spin/><app-icon v-else name="save" :size="14"/>
              {{ saving ? 'Saving…' : 'Save' }}
            </button>
            <button class="btn btn--danger btn--icon" title="Delete this profile" @click="confirmDelete = true">
              <app-icon name="trash" :size="14"/>
            </button>
          </div>

          <nav class="tabbar">
            <button v-for="entry in [['accounts','Accounts','user'],['models','Models & output','zap'],['prompts','Prompts','file-text']]"
                    :key="entry[0]" class="tab" :class="{ 'is-active': tab === entry[0] }" @click="tab = entry[0]">
              <app-icon :name="entry[2]" :size="14"/>{{ entry[1] }}
              <span v-if="entry[0] === 'prompts' && customCount" class="badge badge--warning">{{ customCount }}</span>
            </button>
          </nav>

          <div class="pane__body view-pad">

            <!-- ------------------------------------------------ accounts -->
            <div v-if="tab === 'accounts'" class="grid grid-2 container">
              <section class="col gap-3">
                <div class="row between">
                  <h3 class="t-md">BookStack instances</h3>
                  <button class="btn btn--sm" @click="addBookstack"><app-icon name="plus" :size="13"/> Add</button>
                </div>
                <div v-for="(instance, i) in draft.data.bookstack" :key="instance.id" class="card card--pad col gap-3">
                  <div class="row gap-2">
                    <input v-model="instance.name" placeholder="Name" class="grow">
                    <button class="btn btn--ghost btn--sm btn--icon none" title="Remove"
                            @click="draft.data.bookstack.splice(i, 1)"><app-icon name="trash" :size="13"/></button>
                  </div>
                  <input v-model="instance.base_url" class="mono" placeholder="https://bookstack.example.com">
                  <div class="grid grid-2" style="gap:var(--s-2)">
                    <input v-model="instance.token_id" class="mono" placeholder="Token ID">
                    <input v-model="instance.token_secret" type="password" class="mono"
                           :placeholder="instance.token_secret_set ? '•••••••• stored' : 'Token secret'">
                  </div>
                  <p class="hint">Leave the secret empty to keep the stored one.</p>
                </div>
                <p v-if="!draft.data.bookstack.length" class="hint">
                  Add the BookStack instance a course gets published into.
                </p>
              </section>

              <section class="col gap-3">
                <div class="row between">
                  <h3 class="t-md">AI accounts</h3>
                  <button class="btn btn--sm" @click="addAi"><app-icon name="plus" :size="13"/> Add</button>
                </div>

                <div v-for="(account, i) in draft.data.ai" :key="account.id" class="card card--pad col gap-3">
                  <div class="row gap-2">
                    <input v-model="account.name" placeholder="Name" class="grow">
                    <button class="btn btn--ghost btn--sm btn--icon none" title="Remove"
                            @click="draft.data.ai.splice(i, 1)"><app-icon name="trash" :size="13"/></button>
                  </div>

                  <div class="form-row">
                    <label>Type</label>
                    <select :value="account.kind" @change="setKind(account, $event.target.value)">
                      <option v-for="provider in state.providers" :key="provider.kind" :value="provider.kind">
                        {{ provider.label }}
                      </option>
                    </select>
                  </div>

                  <!-- Everything reached over HTTP: a URL and a key. -->
                  <template v-if="kindInfo(account.kind).needs_key">
                    <input v-model="account.base_url" class="mono" :placeholder="kindInfo(account.kind).base_url">
                    <div class="grid grid-2" style="gap:var(--s-2)">
                      <input v-model="account.api_key" type="password" class="mono"
                             :placeholder="account.api_key_set ? '•••••••• stored' : 'API key'">
                      <input v-if="account.kind === 'openai'" v-model="account.organization"
                             class="mono" placeholder="Organization (optional)">
                    </div>
                  </template>

                  <!-- OpenRouter identifies applications by these two headers. -->
                  <div v-if="account.kind === 'openrouter'" class="grid grid-2" style="gap:var(--s-2)">
                    <input v-model="account.site_url" class="mono" placeholder="Your site URL (optional)">
                    <input v-model="account.site_name" class="mono" placeholder="App name (optional)">
                  </div>

                  <!-- The subscription account stores no credential at all. -->
                  <template v-if="account.kind === 'claude_cli'">
                    <input v-model="account.cli_path" class="mono" placeholder="claude (or the full path to it)">
                    <p v-if="!state.canSpawn" class="hint c-danger">
                      This server does not let PHP start other programs, so this account cannot be used here.
                    </p>
                  </template>

                  <p class="hint">{{ kindInfo(account.kind).hint }}</p>

                  <div class="row wrap gap-2">
                    <button class="btn btn--sm" :disabled="checks[account.id]?.busy" @click="checkAccount(account.id)">
                      <app-icon :name="checks[account.id]?.busy ? 'refresh' : 'zap'" :size="13"
                                :spin="checks[account.id]?.busy"/>
                      Test this account
                    </button>
                    <span v-if="checks[account.id] && !checks[account.id].busy"
                          class="badge" :class="checks[account.id].ok ? 'badge--success' : 'badge--warning'">
                      {{ checks[account.id].ok ? 'working' : 'check it' }}
                    </span>
                    <span v-if="checks[account.id]?.detail" class="t-xs dim grow">{{ checks[account.id].detail }}</span>
                  </div>
                </div>

                <p v-if="!draft.data.ai.length" class="hint">
                  Add at least one AI account, otherwise nothing can be generated.
                </p>
              </section>
            </div>

            <!-- -------------------------------------------------- models -->
            <div v-else-if="tab === 'models'" class="col gap-5 container-narrow">
              <div v-for="slot in MODEL_SLOTS" :key="slot.key" class="card card--pad">
                <h3 class="t-md">{{ slot.label }}</h3>
                <p class="hint mb-4">{{ slot.hint }}</p>
                <div class="grid grid-model" style="gap:var(--s-3)">
                  <div class="form-row">
                    <label>AI account</label>
                    <select v-model="draft.data.models[slot.key].ai_id">
                      <option value="">— none —</option>
                      <option v-for="account in aiAccounts" :key="account.id" :value="account.id">{{ account.name }}</option>
                    </select>
                  </div>
                  <div class="form-row">
                    <label class="row between">
                      <span>Model</span>
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

                <!-- Batching only earns its latency on the per-page slot: the
                     outline is a single call, and queueing one request means
                     waiting up to a day for it. -->
                <div v-if="slot.batchable" class="card card--flat card--pad mt-3 col gap-2">
                  <label class="row gap-2" style="cursor:pointer">
                    <input type="checkbox" :checked="isBatch(slot.key)"
                           :disabled="!batchState(slot.key).allowed"
                           @change="setBatch(slot.key, $event.target.checked)">
                    <span class="strong grow">Write these pages through the provider's batch queue</span>
                    <span v-if="isBatch(slot.key)" class="badge badge--accent">:batch</span>
                  </label>
                  <p class="hint">
                    Half the token price, answered within 24 hours instead of straight away. The course is queued
                    in one go and the pages appear as they come back &mdash; you can close the tab.
                    Regenerating a single page from the editor always stays live, whatever this says.
                  </p>
                  <p v-if="batchState(slot.key).reason" class="hint c-warning">{{ batchState(slot.key).reason }}</p>
                </div>
              </div>

              <div class="card card--pad grid grid-2">
                <div class="form-row">
                  <label>Course language</label>
                  <div class="row"><combo-box v-model="draft.data.language" :options="LANGUAGES" placeholder="English"/></div>
                  <p class="hint">Fuzzy search, or type any language. It fills the <code>{{ languageToken }}</code> placeholder.</p>
                </div>
                <div class="form-row">
                  <label>Pages generated in parallel</label>
                  <input v-model.number="draft.data.concurrency" type="number" min="1" max="12">
                  <p class="hint">How many pages are written simultaneously. Raise it only if your provider allows it.</p>
                </div>
              </div>
            </div>

            <!-- ------------------------------------------------- prompts -->
            <div v-else class="col gap-4 container">
              <div class="card card--pad row wrap gap-3">
                <p class="hint grow" style="min-width:280px">
                  Every prompt starts as the value in <code>data/config.json</code>. Editing one overrides it
                  <strong>for this profile only</strong>; clearing an override makes it follow the config again.
                  Click a placeholder chip to drop it at the cursor.
                </p>
                <span class="badge none">{{ customCount }} / {{ slotCount }} customised</span>
                <button class="btn btn--sm none" :disabled="!customCount" @click="resetAllPrompts">
                  <app-icon name="inherit" :size="13"/> Reset all
                </button>
              </div>

              <section v-for="group in groups" :key="group.id" class="card section" :class="{ 'is-open': groupOpen(group.id) }">
                <button class="section__head" @click="toggleGroup(group.id)">
                  <app-icon class="section__chevron" name="chevron-right" :size="14"/>
                  <span class="grow">
                    <span class="strong">{{ group.label }}</span>
                    <span class="t-xs dim"> · {{ group.slots.length }} prompt(s)</span>
                  </span>
                  <span v-if="customInGroup(group)" class="badge badge--warning">{{ customInGroup(group) }} customised</span>
                </button>

                <div v-if="groupOpen(group.id)" class="section__body col gap-4">
                  <p class="hint">{{ group.description }}</p>

                  <article v-for="slot in group.slots" :key="slot.key" class="card card--flat card--pad col gap-2">
                    <div class="row wrap between gap-2">
                      <div class="row gap-2">
                        <h4>{{ slot.label }}</h4>
                        <code class="t-2xs dim">{{ slot.key }}</code>
                      </div>
                      <div class="row gap-2">
                        <span class="badge" :class="isCustom(slot.key) ? 'badge--warning' : ''">
                          {{ isCustom(slot.key) ? 'customised' : 'config default' }}
                        </span>
                        <button class="btn btn--ghost btn--sm" :disabled="!isCustom(slot.key)" @click="resetPrompt(slot.key)">
                          Reset
                        </button>
                      </div>
                    </div>

                    <p class="hint">{{ slot.description }}</p>

                    <div v-if="slot.placeholders.length" class="row wrap gap-1">
                      <button v-for="placeholder in slot.placeholders" :key="placeholder"
                              class="placeholder-token" :title="'Insert this placeholder'"
                              @click="insertPlaceholder(slot.key, placeholder)">{{ placeholder }}</button>
                    </div>

                    <textarea :ref="el => registerTextarea(slot.key, el)"
                              :value="textOf(slot.key)"
                              @input="setPrompt(slot.key, $event.target.value)"
                              rows="9" spellcheck="false" class="mono"
                              placeholder="Empty → nothing is sent for this slot"></textarea>

                    <details v-if="isCustom(slot.key) && defaultOf(slot.key)">
                      <summary class="t-xs dim" style="cursor:pointer">Show the config default</summary>
                      <pre class="log mt-2" style="white-space:pre-wrap;max-height:280px">{{ defaultOf(slot.key) }}</pre>
                    </details>
                  </article>
                </div>
              </section>
            </div>
          </div>
        </template>

        <empty-state v-else icon="sliders" title="No profile selected"
                     hint="A profile bundles the AI account, the BookStack instance, the model choices, the language and the prompt overrides.">
          <button class="btn btn--primary mt-2" @click="create"><app-icon name="plus" :size="15"/> Create a profile</button>
        </empty-state>
      </section>
    </div>

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
