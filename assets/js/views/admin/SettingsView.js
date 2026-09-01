/**
 * Settings - the screen that makes "everything is configurable in the UI" true.
 *
 * CourseForge 3.x expected somebody to open data/config.json in an editor over
 * FTP, which meant that in practice nothing was ever configured. This screen
 * exists so that no setting requires a shell, an editor, or knowing that the
 * file exists at all. Every field below is drawn from the catalogue the server
 * sends, from its declared type - so a setting added to the catalogue in a
 * later release appears here on its own, with its own label, description,
 * limits and default, and nothing in this file has to be touched for it.
 *
 * Three things on this screen matter more than the rest. The scheduler card at
 * the top of its group is the one thing most installations get wrong, and it is
 * the difference between courses that keep being written after you close the
 * tab and courses that stop. The "changed" markers say what this installation
 * has moved away from the release, so an inherited install can be understood by
 * somebody who did not set it up. And the installation check at the bottom is
 * the diagnostic that used to live in a command-line tool, which was the wrong
 * place for it: the person who needs to be told that the data directory is
 * read-only is sitting in front of the application, on a host with no shell.
 *
 * Nothing is written until Save is pressed, and the whole form is saved in one
 * request, so a half-applied set of settings is not a state this screen can
 * leave the installation in.
 */
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import { state, loadSettings, applySettings, declareUnsaved } from '@/core/store.js';
import { get, put, post } from '@/core/api.js';
import { toast, attempt } from '@/core/toast.js';
import { relativeTime, formatDateTime, plural, LANGUAGES } from '@/core/format.js';

import AppIcon from '@/components/AppIcon.js';
import AppModal from '@/components/AppModal.js';
import ComboBox from '@/components/ComboBox.js';
import EmptyState from '@/components/EmptyState.js';
import ViewHeader from '@/components/ViewHeader.js';

/**
 * The one key the generic list does not draw a row for.
 *
 * It is not an exception to "render from the type" so much as a relocation: the
 * scheduler card above the rows already shows whether a token is stored, hands
 * out the finished URL that contains it, and generates a new one. A second
 * password box labelled "Cron token" directly underneath would read as a
 * different setting. The card carries the field itself, under a disclosure, for
 * the one case that needs it - pasting a token chosen somewhere else.
 */
const CRON_TOKEN = 'app.cron_token';

/** How a diagnostics status is drawn. Distinct glyphs, not only colour. */
const STATUS = {
  ok: { icon: 'check-circle', tone: 'c-success' },
  warn: { icon: 'alert', tone: 'c-warning' },
  fail: { icon: 'x-circle', tone: 'c-danger' },
};

export const SettingsView = {
  name: 'SettingsView',
  components: { AppIcon, AppModal, ComboBox, EmptyState, ViewHeader },
  setup() {
    const loading = ref(true);
    const saving = ref(false);
    const busy = ref(false);

    /** key -> the value as the form holds it, which is not always the API shape. */
    const draft = reactive({});
    const showAdvanced = reactive({});

    const confirmToken = ref(false);
    const confirmResetAll = ref(false);
    /** The secret whose deletion is waiting to be confirmed. See resetField. */
    const confirmSecret = ref(null);
    /** The freshly minted cron URL, readable until the administrator dismisses it. */
    const freshToken = ref(null);

    const report = ref(null);
    const diagBusy = ref(false);
    const diagOpen = ref(false);

    /* ------------------------------------------------- the form's own shape */

    /**
     * A list is edited as one entry per line, because a comma-separated string
     * hides trailing spaces and makes a long URL unreadable. A secret always
     * starts empty - the server never sends one back, and an empty box is
     * exactly what "leave what is stored alone" looks like.
     */
    const toDraft = (field) => {
      if (field.type === 'secret') return '';
      if (field.type === 'list') return (field.value ?? []).join('\n');
      return field.value;
    };

    const fromDraft = (field, raw) => {
      if (field.type === 'list') {
        return String(raw ?? '')
          .split('\n')
          .map((line) => line.trim())
          .filter((line) => line !== '');
      }
      // An emptied number box goes to the server as the empty string rather
      // than as 0, so the answer is "that has to be a number" instead of a
      // silent zero or a confusing complaint about the lower limit.
      if (field.type === 'int') return raw === '' || raw === null ? '' : Number(raw);
      if (field.type === 'bool') return raw === true;
      return raw;
    };

    const seed = () => {
      for (const key of Object.keys(draft)) delete draft[key];
      for (const field of state.settings) draft[field.key] = toDraft(field);
    };

    const load = () => attempt(async () => {
      await loadSettings();
      seed();
      loading.value = false;
    }, 'Load settings');

    onMounted(load);

    /* ------------------------------------------------------------- changes */

    /** Unsaved, as opposed to "changed from the shipped default". */
    const isDirty = (field) => {
      const raw = draft[field.key];
      if (field.type === 'secret') return String(raw ?? '') !== '';
      if (field.type === 'list') return JSON.stringify(fromDraft(field, raw)) !== JSON.stringify(field.value ?? []);
      if (field.type === 'int') return Number(raw) !== Number(field.value);
      if (field.type === 'bool') return (raw === true) !== (field.value === true);
      return String(raw ?? '') !== String(field.value ?? '');
    };

    const dirtyFields = computed(() => state.settings.filter(isDirty));
    const dirtyCount = computed(() => dirtyFields.value.length);

    // What the shell asks before it lets somebody navigate away. Without
    // this the guard exists and has nothing to guard: the count is right
    // here on the screen, and the shell cannot see it.
    declareUnsaved(() => (dirtyCount.value ? plural(dirtyCount.value, 'unsaved change') : ''));
    const overridden = computed(() => state.settings.filter((field) => field.is_overridden));

    /* -------------------------------------------------------------- groups */

    const groupsOf = (key) => state.settings.filter((field) => field.group === key && field.key !== CRON_TOKEN);

    const groups = computed(() => {
      const declared = state.settingGroups.map((group) => ({ ...group, fields: groupsOf(group.key) }));

      // A setting whose group the server did not describe would otherwise be
      // invisible. It gets a bucket of its own rather than being dropped,
      // because a setting nobody can find is worse than an unlabelled heading.
      const known = new Set(state.settingGroups.map((group) => group.key));
      const orphans = state.settings.filter((field) => !known.has(field.group) && field.key !== CRON_TOKEN);
      if (orphans.length) {
        declared.push({
          key: '_other',
          label: 'Other',
          description: 'Settings this version of the screen has no heading for. They work the same way.',
          fields: orphans,
        });
      }

      return declared
        .map((group) => ({
          ...group,
          plain: group.fields.filter((field) => !field.advanced),
          advanced: group.fields.filter((field) => field.advanced),
          changed: group.fields.filter((field) => field.is_overridden).length,
          unsaved: group.fields.filter(isDirty).length,
        }))
        .filter((group) => group.fields.length || group.key === 'scheduler');
    });

    /**
     * Advanced fields stay folded away, but never while they hold an edit that
     * has not been saved - hiding somebody's own unsaved change is the fastest
     * way to make a Save button look broken.
     */
    const advancedShown = (group) => showAdvanced[group.key] === true || group.advanced.some(isDirty);
    const toggleAdvanced = (group) => { showAdvanced[group.key] = !advancedShown(group); };

    const fieldsOf = (group) => (advancedShown(group) ? [...group.plain, ...group.advanced] : group.plain);

    /* -------------------------------------------------------------- values */

    const optionLabel = (field, value) =>
      (field.options ?? []).find((option) => option.value === value)?.label ?? String(value);

    /** Whether a number field declares limits worth printing under the box. */
    const hasRange = (field) => typeof field.min === 'number' && typeof field.max === 'number';

    /**
     * The lists a field may ask to be suggested from, by name.
     *
     * The catalogue is server-side and these lists are not, so a field says
     * which one it wants rather than carrying it: shipping the languages down
     * with the settings would be a second copy of what the profile editor
     * already reads out of core/format.js, and the two would drift. A name
     * nobody has a list for is simply not suggested, so a later release can
     * add `suggest` to a field before this screen knows about it.
     */
    const SUGGESTIONS = { languages: LANGUAGES };
    const suggestionsFor = (field) => SUGGESTIONS[field.suggest] ?? null;

    /** What the release ships, in words, so "reset" is never a leap of faith. */
    const defaultText = (field) => {
      const value = field.default;
      if (field.type === 'secret') return 'nothing stored';
      if (field.type === 'bool') return value ? 'on' : 'off';
      if (field.type === 'enum') return optionLabel(field, value);
      if (field.type === 'list') return (value ?? []).length ? value.join(', ') : 'empty';
      if (value === '' || value === null || value === undefined) return 'empty';
      return field.unit ? `${value} ${field.unit}` : String(value);
    };

    /* --------------------------------------------------------------- write */

    const save = () => attempt(async () => {
      if (!dirtyCount.value || saving.value) return;
      saving.value = true;
      try {
        const values = {};
        for (const field of dirtyFields.value) values[field.key] = fromDraft(field, draft[field.key]);
        applySettings(await put('admin/settings', { values }));
        seed();
        toast.success(`${plural(Object.keys(values).length, 'setting')} saved.`);
      } finally {
        saving.value = false;
      }
    }, 'Save settings');

    const discard = () => {
      seed();
      toast.info('Your unsaved changes were thrown away.');
    };

    /**
     * Deletes overrides on the server, immediately.
     *
     * This is the only path that can truly remove a value from the override
     * file, which is why it is kept - but it is a write, so it is used only
     * where staging cannot do the job: a secret, and "reset everything" in the
     * danger zone. Both are behind a confirmation that says so, because this is
     * the one thing on the screen the "nothing is written until you save"
     * promise does not cover, and a control that quietly breaks that promise is
     * worse than one that never made it.
     *
     * The dialogs close here rather than at the call site, so a refusal from
     * the server leaves the question on screen instead of dismissing it as
     * though it had been answered.
     */
    const resetKeys = (keys, message = '') => attempt(async () => {
      if (busy.value) return;
      busy.value = true;
      try {
        applySettings(await post('admin/settings/reset', { keys }));
        seed();
        confirmResetAll.value = false;
        confirmSecret.value = null;
        toast.success(message
          || (keys.length === 1 ? 'Back to the shipped default.' : `${plural(keys.length, 'setting')} put back.`));
      } finally {
        busy.value = false;
      }
    }, 'Reset');

    /**
     * Puts one field back to what the release ships - as an edit, not a write.
     *
     * The sentence at the top of this screen promises that nothing is written
     * until Save is pressed, and a per-field reset that persisted on the spot
     * was the one thing on the page that broke that promise. So it now sets the
     * control back to the shipped default and marks the row unsaved, and Save
     * writes it with everything else.
     *
     * Nothing is lost by writing the default rather than deleting the override:
     * Config::setMany() drops any value equal to the default from
     * data/config.json, so a saved reset leaves that file in exactly the state a
     * deletion would have left it in.
     *
     * A secret is the exception, and it cannot be staged at all. The server
     * never sends one back and an empty box means "keep what is stored", so
     * there is no value this form could hold that means "remove it". That one
     * has to be deleted on the server, which is what resetKeys is for - and
     * because it is a write that cannot wait for Save, and an irreversible one
     * on a screen where every other reset is reversible until saved, it asks
     * first and says why. The two controls looked identical; only one of them
     * destroyed a credential.
     */
    const resetField = (field) => {
      if (field.type === 'secret') {
        confirmSecret.value = field;
        return;
      }
      draft[field.key] = toDraft({ type: field.type, value: field.default });
      toast.info('Put back to the shipped default. Press Save changes to write it.');
    };

    /** The confirmed deletion of one stored secret. */
    const removeSecret = () => {
      const field = confirmSecret.value;
      if (field) resetKeys([field.key], `${field.label} is no longer stored.`);
    };

    const resetEverything = () => resetKeys(overridden.value.map((field) => field.key));

    /* ----------------------------------------------------------- scheduler */

    const scheduler = computed(() => state.scheduler ?? {
      configured: false, url: '', cli: '', healthy: false, last_at: 0, seconds_ago: 0, workers: 0, seconds: 0,
    });

    /** One sentence saying what state the scheduler is actually in. */
    const schedulerHealth = computed(() => {
      const info = scheduler.value;
      if (!info.configured) {
        return {
          tone: 'c-warning', icon: 'alert',
          text: 'No token is set, so cron.php turns every caller away and background generation is not offered at all.',
        };
      }
      if (!info.last_at) {
        return {
          tone: 'c-warning', icon: 'alert',
          text: 'A token is set, but nothing has ever called the scheduler. Add the line below to your host and it will start.',
        };
      }
      if (info.healthy) {
        return {
          tone: 'c-success', icon: 'check-circle',
          text: `Running. It last worked ${relativeTime(info.last_at)}.`,
        };
      }
      return {
        tone: 'c-warning', icon: 'alert',
        text: `It last ran ${relativeTime(info.last_at)}, and it is meant to run every minute. Whatever calls it has stopped.`,
      };
    });

    /**
     * The cron URL with its real token, once somebody has asked for it.
     *
     * The settings response carries a masked URL: this screen is read on every
     * visit and that URL is a credential. The real one is fetched on demand.
     */
    const revealedCronUrl = ref('');

    const cronUrlShown = computed(() => revealedCronUrl.value || scheduler.value.url || '');

    const copyCronUrl = () => attempt(async () => {
      if (!revealedCronUrl.value) {
        const data = await post('admin/settings/cron-url');
        revealedCronUrl.value = data.url ?? '';
      }
      await copy(revealedCronUrl.value, 'URL', 'scheduler-url');
    }, 'Copy the cron URL');

    /* ------------------------------------------------------------- PHP */

    const php = computed(() => state.settingsPhp ?? {});
    const phpBusy = ref(false);

    const setUpPhp = (release = false) => attempt(async () => {
      if (phpBusy.value) return;
      phpBusy.value = true;
      try {
        const data = await post('admin/settings/php', release ? { release: true } : undefined);
        state.settingsPhp = data.php;

        if (data.php?.error) toast.error(data.php.error);
        else if (data.php?.released) toast.success(data.php.note);
        else if (data.php?.written) toast.success('PHP settings written. They take effect within a few minutes.');
        else if (release) toast.info(data.php?.note ?? 'There was nothing to remove.');
        else toast.info('Nothing to change - this host already meets everything CourseForge asks for.');
      } finally {
        phpBusy.value = false;
      }
    }, 'Set up PHP');

    const cronLine = computed(() => (scheduler.value.cli ? `* * * * * ${scheduler.value.cli}` : ''));
    const cronCurlLine = computed(() =>
      cronUrlShown.value ? `* * * * * curl -fsS "${cronUrlShown.value}" > /dev/null` : ''
    );

    const generateToken = () => attempt(async () => {
      if (busy.value) return;
      busy.value = true;
      try {
        const data = await post('admin/settings/cron-token');
        applySettings(data);
        seed();
        freshToken.value = { token: data.token, url: data.scheduler?.url ?? '' };
        // The mint call answers with the real URL, so there is nothing to ask
        // for afterwards.
        revealedCronUrl.value = data.scheduler?.url ?? '';
        confirmToken.value = false;
        toast.success('A new token is in place. Update whatever calls the scheduler.');
      } finally {
        busy.value = false;
      }
    }, 'Generate a token');

    /* --------------------------------------------------------- diagnostics */

    const runDiagnostics = () => attempt(async () => {
      if (diagBusy.value) return;
      diagBusy.value = true;
      try {
        report.value = (await get('admin/diagnostics')).report;
      } finally {
        diagBusy.value = false;
      }
    }, 'Installation check');

    /** Opening it runs it - a check nobody pressed a second button for. */
    const toggleDiagnostics = () => {
      diagOpen.value = !diagOpen.value;
      if (diagOpen.value && !report.value && !diagBusy.value) runDiagnostics();
    };

    const statusOf = (status) => STATUS[status] ?? { icon: 'info', tone: 'dim' };
    const troubled = (section) => section.checks.filter((check) => check.status !== 'ok').length;

    /** A passing check can still carry a note; it should not be shouted in red. */
    const hintTone = (check) => (check.status === 'ok' ? '' : statusOf(check.status).tone);

    /* -------------------------------------------------------------- copying */

    /**
     * Which copy button has just worked, so the button itself can say so.
     *
     * A toast answers "did that do anything" only for as long as somebody is
     * looking at the corner of the screen, and the thing being copied here is a
     * URL somebody is about to paste into a hosting control panel - they need
     * to know it is on the clipboard before they leave. Held as an id rather
     * than a boolean because four of these buttons can be on screen at once and
     * only the one that was pressed should change; the id is separate from the
     * noun in the toast because two of them copy a crontab line.
     */
    const copied = ref('');
    let copiedTimer = 0;

    // Cleared on unmount as well as on the next copy: leaving the screen
    // inside the two seconds otherwise left a timer running against a ref
    // belonging to a component that no longer exists. Harmless in Vue, and
    // still the one timer in this codebase that was not paired with its
    // own cleanup.
    onBeforeUnmount(() => clearTimeout(copiedTimer));

    const copy = async (text, what, id = what) => {
      try {
        await navigator.clipboard.writeText(text);
        copied.value = id;
        clearTimeout(copiedTimer);
        copiedTimer = setTimeout(() => { copied.value = ''; }, 2000);
        toast.success(`${what} copied.`);
      } catch {
        copied.value = '';
        toast.info('Copying is blocked here - select the text and copy it by hand.');
      }
    };

    return {
      state, loading, saving, busy, draft, load, save, discard,
      groups, fieldsOf, advancedShown, toggleAdvanced,
      isDirty, dirtyCount, overridden, defaultText, hasRange, suggestionsFor, resetField, resetEverything,
      scheduler, schedulerHealth, cronLine, cronCurlLine, cronUrlShown, copyCronUrl, revealedCronUrl,
      php, phpBusy, setUpPhp,
      confirmToken, confirmResetAll, confirmSecret, removeSecret, freshToken, generateToken, CRON_TOKEN,
      report, diagBusy, diagOpen, toggleDiagnostics, runDiagnostics, statusOf, troubled, hintTone,
      copy, copied, relativeTime, formatDateTime, plural,
    };
  },
  template: `
    <view-header title="Settings" icon="cog">
      <template #actions>
        <span v-if="dirtyCount" class="badge badge--warning">{{ plural(dirtyCount, 'unsaved change') }}</span>
        <button v-if="dirtyCount" class="btn btn--ghost btn--sm" @click="discard">Discard</button>
        <button class="btn btn--ghost btn--icon hide-sm" title="Reload from the server"
                aria-label="Reload the settings from the server" @click="load">
          <app-icon name="refresh" :size="15"/>
        </button>
        <button class="btn btn--primary" :disabled="saving || !dirtyCount" @click="save">
          <app-icon :name="saving ? 'refresh' : 'save'" :size="14" :spin="saving"/>
          {{ saving ? 'Saving…' : 'Save changes' }}
        </button>
      </template>
    </view-header>

    <div class="view-scroll">
      <div class="view-pad container-narrow col gap-5">

        <!-- what this screen is and where it writes ---------------------- -->
        <section class="card card--pad col gap-2">
          <h3 class="t-md">Everything this installation can be told to do differently</h3>
          <p class="hint">
            Nothing here is written until you press <strong>Save changes</strong>, and the whole page is saved
            in one go. A setting you have not touched keeps following the release: upgrade CourseForge and it
            picks up the new default on its own. Only the settings you actually change are written to
            <code>{{ state.settingsFiles.overrides }}</code> - everything else stays in the shipped
            <code>{{ state.settingsFiles.defaults }}</code>, which is never edited.
          </p>
          <p class="hint">
            Three things here are written the moment you confirm them rather than waiting for Save, and each
            one asks first: generating a scheduler token, deleting a stored secret, and
            <strong>Reset every setting</strong> at the bottom of the page. A secret cannot wait, because this
            screen is never sent one - so there is no value it could hold that means "remove it".
          </p>
          <p v-if="overridden.length" class="hint">
            Changed from the release on this installation:
            <strong>{{ plural(overridden.length, 'setting') }}</strong>. Each one is marked below, and each
            one can be put back on its own.
          </p>
        </section>

        <!-- the groups, in the order the server sends them ---------------- -->
        <section v-for="group in groups" :key="group.key" class="card">
          <div class="card__head">
            <div class="col gap-1 grow">
              <span class="card__title">{{ group.label }}</span>
              <span class="hint">{{ group.description }}</span>
            </div>
            <span v-if="group.unsaved" class="badge badge--warning none">{{ group.unsaved }} unsaved</span>
            <span v-else-if="group.changed" class="badge badge--accent none">{{ group.changed }} changed</span>
          </div>

          <!-- ================================================= scheduler -->
          <div v-if="group.key === 'scheduler'" class="card__body col gap-4"
               style="border-bottom:1px solid var(--border-soft)">
            <div class="row-top gap-3">
              <app-icon name="zap" :size="18" class="c-accent none" style="margin-top:2px"/>
              <div class="col gap-1">
                <h3 class="t-md">Keep writing after the browser is closed</h3>
                <p class="hint">
                  Without a scheduler, a course is only written while the tab that started it is open - close
                  the laptop and generation stops mid-course. With one, your host calls CourseForge once a
                  minute, and the pages keep arriving whether anybody is watching or not.
                </p>
              </div>
            </div>

            <div class="row wrap gap-2">
              <span class="badge" :class="scheduler.configured ? 'badge--success' : 'badge--warning'">
                {{ scheduler.configured ? 'token set' : 'no token' }}
              </span>
              <span class="badge badge--outline">{{ plural(scheduler.workers, 'worker') }}</span>
              <span class="badge badge--outline">{{ scheduler.seconds }}s of work per call</span>
              <span v-if="scheduler.last_at" class="t-xs dim push none" :title="formatDateTime(scheduler.last_at)">
                last tick {{ relativeTime(scheduler.last_at) }}
              </span>
            </div>

            <p class="hint row-top gap-2" :class="schedulerHealth.tone">
              <app-icon :name="schedulerHealth.icon" :size="14" class="none" style="margin-top:1px"/>
              <span>{{ schedulerHealth.text }}</span>
            </p>

            <!-- the finished URL, which is the whole point of this card -->
            <div v-if="scheduler.configured" class="form-row">
              <label class="row between">
                <span>Paste this into your hosting control panel</span>
                <button class="btn btn--ghost btn--sm" @click="copyCronUrl()">
                  <app-icon :name="copied === 'scheduler-url' ? 'check' : 'copy'" :size="12"/>
                  {{ copied === 'scheduler-url' ? 'copied' : 'copy' }}
                </button>
              </label>
              <pre class="log" style="white-space:pre-wrap;word-break:break-all">{{ cronUrlShown }}</pre>
              <p class="hint" v-if="scheduler.url_is_masked && !revealedCronUrl">
                The token is hidden here, because this screen is read often and the URL carries it. Press
                <strong>copy</strong> and the real one goes to your clipboard.
              </p>
              <p class="hint">
                Most hosts call this a cron job, a scheduled task or a URL monitor. Set it to run
                <strong>every minute</strong>. Anything slower and pages are written that much more slowly.
              </p>
            </div>

            <details v-if="scheduler.configured">
              <summary class="t-xs dim" style="cursor:pointer">If you have a shell instead of a control panel</summary>
              <div class="col gap-3 mt-3">
                <div class="form-row">
                  <label class="row between">
                    <span>crontab line</span>
                    <button class="btn btn--ghost btn--sm" @click="copy(cronLine, 'Crontab line', 'cron-cli')">
                      <app-icon :name="copied === 'cron-cli' ? 'check' : 'copy'" :size="12"/>
                      {{ copied === 'cron-cli' ? 'copied' : 'copy' }}
                    </button>
                  </label>
                  <pre class="log" style="white-space:pre-wrap;word-break:break-all">{{ cronLine }}</pre>
                  <p class="hint">
                    Runs the same work directly, without going through the web server, so it is not subject to
                    a request time limit. It needs no token.
                  </p>
                </div>
                <div class="form-row">
                  <label class="row between">
                    <span>or the same thing over HTTP</span>
                    <button class="btn btn--ghost btn--sm" @click="copy(cronCurlLine, 'Crontab line', 'cron-curl')">
                      <app-icon :name="copied === 'cron-curl' ? 'check' : 'copy'" :size="12"/>
                      {{ copied === 'cron-curl' ? 'copied' : 'copy' }}
                    </button>
                  </label>
                  <pre class="log" style="white-space:pre-wrap;word-break:break-all">{{ cronCurlLine }}</pre>
                </div>
              </div>
            </details>

            <div class="row wrap gap-2">
              <button class="btn btn--sm" :disabled="busy" @click="confirmToken = true">
                <app-icon name="refresh" :size="13"/>
                {{ scheduler.configured ? 'Generate a new token' : 'Generate a token' }}
              </button>
              <span class="t-xs dim">
                {{ scheduler.configured
                   ? 'The old one stops working immediately.'
                   : 'Nothing can call the scheduler until you do this.' }}
              </span>
            </div>

            <!-- Only while it is still the live one: setting the token by hand
                 or generating another must not leave a stale URL on screen. -->
            <section v-if="freshToken && freshToken.url === scheduler.url" class="card card--flat card--pad col gap-2"
                     style="border-color:var(--success-line)">
              <div class="row gap-2">
                <app-icon name="check-circle" :size="15" class="c-success"/>
                <strong class="grow">The new scheduler URL</strong>
                <button class="btn btn--ghost btn--sm" @click="copy(freshToken.url, 'URL', 'fresh-url')">
                  <app-icon :name="copied === 'fresh-url' ? 'check' : 'copy'" :size="12"/>
                  {{ copied === 'fresh-url' ? 'copied' : 'copy' }}
                </button>
              </div>
              <pre class="log" style="white-space:pre-wrap;word-break:break-all">{{ freshToken.url }}</pre>
              <p class="hint c-warning">
                Anything still calling the previous URL is now being refused. Replace it wherever you set it up.
              </p>
              <button class="btn btn--sm none" @click="freshToken = null">Done</button>
            </section>

            <details>
              <summary class="t-xs dim" style="cursor:pointer">Set the token by hand</summary>
              <div class="form-row mt-3">
                <input v-model="draft[CRON_TOKEN]" type="password" class="mono"
                       :placeholder="scheduler.configured ? '•••••••• stored - leave blank to keep it' : 'Nothing stored'">
                <p class="hint">
                  Only useful when you are matching a token chosen somewhere else. Leave it blank and the
                  stored one is kept; it is saved with the rest of the page. Sixteen characters minimum, and
                  the Generate button above is a better idea than anything you will type.
                </p>
              </div>
            </details>
          </div>

          <!-- ============================================ the generic list -->
          <div class="setting-list">
            <div v-for="field in fieldsOf(group)" :key="field.key"
                 class="setting-row" :class="{ 'is-changed': field.is_overridden }">

              <div class="setting-row__text">
                <div class="row wrap gap-2">
                  <span class="setting-row__label">{{ field.label }}</span>
                  <span v-if="field.is_overridden" class="changed-flag">
                    <app-icon name="pencil" :size="10"/> changed
                  </span>
                  <span v-if="isDirty(field)" class="badge badge--warning">unsaved</span>
                  <span v-if="field.advanced" class="badge badge--outline">advanced</span>
                </div>

                <p v-if="field.description" class="setting-row__desc">{{ field.description }}</p>

                <div class="row wrap gap-2 mt-1">
                  <code class="t-2xs faint">{{ field.key }}</code>
                  <template v-if="field.is_overridden">
                    <span class="t-2xs faint">· ships as {{ defaultText(field) }}</span>
                    <button class="btn btn--ghost btn--sm" :disabled="busy" @click="resetField(field)"
                            :title="field.type === 'secret'
                              ? 'Deletes the stored secret on the server. It cannot wait for Save, because this screen is never sent a secret to put back - so it asks first.'
                              : 'Puts the box back to the shipped default. Nothing is written until you save.'">
                      {{ field.type === 'secret' ? 'Remove what is stored' : 'Reset to default' }}
                    </button>
                  </template>
                </div>
              </div>

              <div class="setting-row__control">
                <!-- bool -->
                <label v-if="field.type === 'bool'" class="check">
                  <input type="checkbox" :checked="draft[field.key] === true"
                         @change="draft[field.key] = $event.target.checked">
                  <span>{{ draft[field.key] ? 'On' : 'Off' }}</span>
                </label>

                <!-- int -->
                <template v-else-if="field.type === 'int'">
                  <div class="row gap-2">
                    <input v-model.number="draft[field.key]" type="number" class="grow"
                           :min="field.min" :max="field.max">
                    <span v-if="field.unit" class="t-xs dim none">{{ field.unit }}</span>
                  </div>
                  <p v-if="hasRange(field)" class="hint">
                    Anything from {{ field.min }} to {{ field.max }}.
                  </p>
                </template>

                <!-- enum -->
                <select v-else-if="field.type === 'enum'" v-model="draft[field.key]">
                  <option v-for="option in field.options" :key="option.value" :value="option.value">
                    {{ option.label }}
                  </option>
                </select>

                <!-- time -->
                <input v-else-if="field.type === 'time'" v-model="draft[field.key]" type="time">

                <!-- list -->
                <template v-else-if="field.type === 'list'">
                  <textarea v-model="draft[field.key]" rows="3" spellcheck="false" class="mono"
                            placeholder="One per line"></textarea>
                  <p class="hint">One per line. An empty box means none at all.</p>
                </template>

                <!-- secret -->
                <template v-else-if="field.type === 'secret'">
                  <input v-model="draft[field.key]" type="password" class="mono"
                         :placeholder="field.is_set ? '•••••••• stored - leave blank to keep it' : 'Nothing stored'">
                  <p class="hint">
                    {{ field.is_set
                       ? 'Something is stored, and it is never sent back to this screen. Leave the box empty to keep it; type here only to replace it.'
                       : 'Nothing is stored yet. What you type here is saved and never shown again.' }}
                  </p>
                </template>

                <!-- text -->
                <textarea v-else-if="field.type === 'text'" v-model="draft[field.key]" rows="5"
                          spellcheck="false" :placeholder="field.placeholder || ''"></textarea>

                <!-- a string with suggestions: fuzzy-searched, never constrained -->
                <div v-else-if="suggestionsFor(field)" class="row">
                  <combo-box :model-value="String(draft[field.key] ?? '')"
                             @update:model-value="draft[field.key] = $event"
                             :options="suggestionsFor(field)"
                             :placeholder="field.placeholder || ''"/>
                </div>

                <!-- string, and anything a later release adds -->
                <input v-else v-model="draft[field.key]" type="text" :placeholder="field.placeholder || ''">
              </div>
            </div>
          </div>

          <div v-if="group.advanced.length" class="card__foot">
            <button class="btn btn--ghost btn--sm" @click="toggleAdvanced(group)">
              <app-icon :name="advancedShown(group) ? 'chevron-up' : 'chevron-down'" :size="13"/>
              {{ advancedShown(group)
                 ? 'Hide the advanced settings'
                 : 'Show ' + plural(group.advanced.length, 'advanced setting') }}
            </button>
            <span class="t-xs faint" style="margin-left:var(--s-2)">
              Rarely needed, and easy to make things worse with.
            </span>
          </div>
        </section>

        <empty-state v-if="!loading && !state.settings.length" icon="cog" title="No settings came back"
                     hint="The server answered without a catalogue. Reload the page; if it keeps happening, the installation is incomplete."/>

        <!-- ======================================================= PHP -->
        <section class="card card--pad col gap-3">
          <div class="row wrap between gap-3">
            <div class="col gap-1 grow" style="min-width:280px">
              <h3 class="t-md">How PHP is configured here</h3>
              <p class="hint">
                Shared hosting hands out a PHP configuration nobody chose for this application: sixty seconds
                of execution, a socket timeout shorter than a model takes to answer. CourseForge can raise the
                ones it needs by writing a <code>.user.ini</code> beside itself.
                <strong>Every number below is a floor</strong> - a limit this host is already generous about is
                left exactly as it is, never lowered.
              </p>
            </div>
            <div class="row gap-2 none">
              <button v-if="php.has_block" class="btn btn--ghost btn--sm" :disabled="phpBusy || !php.possible"
                      title="Take CourseForge's block out of .user.ini and let this host's own values come back. Do this before moving to different hosting, or to hand the settings back."
                      @click="setUpPhp(true)">
                <app-icon name="inherit" :size="13"/> Remove these settings
              </button>
              <button class="btn btn--primary" :disabled="phpBusy || !php.possible" @click="setUpPhp(false)">
                <app-icon :name="phpBusy ? 'refresh' : 'zap'" :size="14" :spin="phpBusy"/>
                {{ phpBusy ? 'Writing…' : 'Set up PHP' }}
              </button>
            </div>
          </div>

          <p v-if="php.note" class="hint row gap-2">
            <app-icon :name="php.possible ? 'info' : 'alert-triangle'" :size="14"
                      :class="php.possible ? 'c-accent none' : 'c-warning none'" style="margin-top:2px"/>
            <span>{{ php.note }}</span>
          </p>

          <div class="row wrap gap-2">
            <span class="badge none">PHP {{ php.php }}</span>
            <span class="badge none">{{ php.sapi }}</span>
            <span v-if="php.file" class="badge none mono">{{ php.file }}</span>
            <span v-if="php.cache_ttl" class="badge none">cached {{ php.cache_ttl }}s</span>
          </div>

          <div style="overflow-x:auto">
            <table class="table" v-if="(php.settings || []).length">
              <thead>
                <tr>
                  <th>Directive</th>
                  <th title="What PHP is using right now, whoever set it">In effect</th>
                  <th>CourseForge wants</th>
                  <th>State</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in php.settings" :key="row.name">
                  <td>
                    <code class="t-2xs">{{ row.name }}</code>
                    <p class="hint t-2xs" style="margin:2px 0 0">{{ row.why }}</p>
                  </td>
                  <td class="mono t-xs">
                    {{ row.effective_label }}
                    <span v-if="!row.from_host" class="t-2xs c-accent" title="Set by CourseForge, not by the host">
                      · ours
                    </span>
                  </td>
                  <td class="mono t-xs">{{ row.satisfied || row.keeping ? '—' : row.target_label }}</td>
                  <td>
                    <!-- On a host that reads no .user.ini nothing here is about
                         to be raised by anybody, and badging six rows "will be
                         raised" under a warning saying the opposite was the
                         card contradicting itself. -->
                    <span v-if="row.satisfied || row.keeping" class="badge badge--ok">
                      {{ row.keeping ? 'held by us' : 'already fine' }}
                    </span>
                    <span v-else-if="row.settable && php.possible" class="badge badge--warning">will be raised</span>
                    <span v-else class="badge badge--danger"
                          title="Nothing in the application can change this one - it is the host's to decide">
                      host decides
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- ============================================== installation check -->
        <section class="card section" :class="{ 'is-open': diagOpen }">
          <button class="section__head" @click="toggleDiagnostics">
            <app-icon class="section__chevron" name="chevron-right" :size="14"/>
            <span class="col gap-1 grow">
              <span class="strong">Installation check</span>
              <span class="t-xs dim">
                Everything CourseForge can verify about this server, without a shell: PHP, file permissions,
                the database, the scheduler, and whether Claude can be reached.
              </span>
            </span>
            <span v-if="report" class="row gap-2 none">
              <span class="badge badge--success">{{ report.summary.ok }} fine</span>
              <span v-if="report.summary.warnings" class="badge badge--warning">{{ report.summary.warnings }} to look at</span>
              <span v-if="report.summary.problems" class="badge badge--danger">{{ report.summary.problems }} broken</span>
            </span>
          </button>

          <div v-if="diagOpen" class="card__body col gap-4">
            <div class="row wrap gap-3">
              <button class="btn btn--sm" :disabled="diagBusy" @click="runDiagnostics">
                <app-icon :name="diagBusy ? 'refresh' : 'play'" :size="13" :spin="diagBusy"/>
                {{ diagBusy ? 'Checking…' : 'Run it again' }}
              </button>
              <span v-if="report" class="t-xs dim">
                CourseForge {{ report.version }} · checked {{ relativeTime(report.generated_at) }}
              </span>
            </div>

            <p v-if="diagBusy && !report" class="hint">
              Working through the checks. The last one starts the Claude command-line tool, which can take a
              few seconds.
            </p>

            <div v-for="section in (report ? report.sections : [])" :key="section.key" class="col gap-2">
              <div class="row gap-2">
                <span class="eyebrow grow">{{ section.label }}</span>
                <span v-if="troubled(section)" class="badge badge--warning">{{ troubled(section) }} to look at</span>
              </div>

              <div class="card card--flat diag-list">
                <div v-for="check in section.checks" :key="check.key" class="diag-row">
                  <app-icon :name="statusOf(check.status).icon" :size="14"
                            :class="statusOf(check.status).tone" class="diag-row__icon"/>
                  <div>
                    <div class="row wrap gap-2">
                      <span class="t-sm semi">{{ check.label }}</span>
                      <span v-if="check.detail" class="t-xs dim">{{ check.detail }}</span>
                    </div>
                    <p v-if="check.hint" class="hint mt-1" :class="hintTone(check)">{{ check.hint }}</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>

        <!-- ======================================================== danger -->
        <div v-if="overridden.length" class="danger-zone">
          <p class="danger-zone__title">Start again from the defaults</p>
          <p class="hint">
            Puts {{ plural(overridden.length, 'changed setting') }} back to what the release ships. Your
            courses, profiles, tags and accounts are not touched - this is only the configuration.
          </p>
          <p class="hint">
            Unlike the fields above, this one is written the moment you confirm it, and it throws away any
            unsaved edits on this page.
          </p>
          <div class="row">
            <button class="btn btn--danger btn--sm" :disabled="busy" @click="confirmResetAll = true">
              <app-icon name="inherit" :size="13"/> Reset every setting
            </button>
          </div>
        </div>
      </div>
    </div>

    <app-modal v-if="confirmToken" title="Generate a scheduler token?" icon="alert" @close="confirmToken = false">
      <p class="t-sm" v-if="scheduler.configured">
        The token in the current URL stops working the moment this is done. Anything already calling it - your
        hosting control panel, a crontab line, an uptime monitor - is refused until you paste in the new URL.
      </p>
      <p class="t-sm" v-else>
        This writes a fresh secret and gives you the finished URL to hand to your host. Nothing else changes.
      </p>
      <template #footer>
        <button class="btn" @click="confirmToken = false">Cancel</button>
        <button class="btn btn--primary" :disabled="busy" @click="generateToken">
          <app-icon name="refresh" :size="14"/> Generate
        </button>
      </template>
    </app-modal>

    <!-- The one field-level control that writes without waiting for Save. -->
    <app-modal v-if="confirmSecret" :title="'Delete the stored ' + confirmSecret.label + '?'" icon="alert"
               @close="confirmSecret = null">
      <p class="t-sm">
        Unlike every other box on this page, this one is written the moment you confirm it. It does not wait
        for <strong>Save changes</strong> and <strong>Discard</strong> will not bring it back: a stored secret
        is never sent to this screen, so there is nothing here to put back. If you need it again you will have
        to paste or generate a new one.
      </p>
      <p class="t-sm mt-3">
        Anything relying on <code>{{ confirmSecret.key }}</code> stops working until something is stored there
        again. This installation goes back to the shipped default, which for a secret means nothing at all.
      </p>
      <template #footer>
        <button class="btn" @click="confirmSecret = null">Keep it</button>
        <button class="btn btn--danger" :disabled="busy" @click="removeSecret">
          <app-icon name="trash" :size="14"/> Delete it
        </button>
      </template>
    </app-modal>

    <app-modal v-if="confirmResetAll" title="Reset every setting?" icon="alert" @close="confirmResetAll = false">
      <p class="t-sm">
        {{ plural(overridden.length, 'changed setting') }} go back to the shipped default, and this
        installation stops having a configuration of its own. This is written as soon as you confirm it -
        it does not wait for Save, and anything you have edited on this page without saving is thrown away.
      </p>
      <p class="t-sm c-warning mt-3" v-if="scheduler.configured">
        That includes the cron token, so your scheduler stops working until you generate a new one and paste
        the new URL into your host.
      </p>
      <template #footer>
        <button class="btn" @click="confirmResetAll = false">Cancel</button>
        <button class="btn btn--danger" :disabled="busy" @click="resetEverything">
          <app-icon name="inherit" :size="14"/> Reset everything
        </button>
      </template>
    </app-modal>`,
};

export default SettingsView;
