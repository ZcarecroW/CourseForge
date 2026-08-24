/**
 * Updates - is this installation current, and what happens if it is not.
 *
 * An administrator opens this screen with one question in their head, so the
 * top of it answers that question in a sentence before anything else: you are
 * up to date, or version 4.1.0 has been published and here is what changed.
 * Everything below is what somebody needs once the answer is "no".
 *
 * Installing is the most consequential button in CourseForge. It downloads a
 * release, moves every shipped file aside into a backup and copies new ones
 * over the top - of the very code that is running the request. So it confirms
 * first, names the version, says that a backup is taken and that the site will
 * be unreachable for a moment, and when it is done it shows the update's own
 * log rather than a spinner and a shrug. A person whose installation has just
 * half-failed needs to read what happened far more than they need reassurance.
 *
 * The preconditions list is not decoration either. When Install is unavailable
 * this is the only place that says why, one condition at a time, so "the
 * install directory is not writable" is something that can be fixed rather than
 * something that has to be guessed at.
 *
 * The switches for automatic checking and installing live on the Settings
 * screen and are not repeated here. What is here is the consequence of however
 * they are set, in a sentence - including the case that matters most, where
 * automatic installation is switched on but nothing is calling the scheduler,
 * so it can never actually happen.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { state, loadUpdate, loadSettings, go } from '@/core/store.js';
import { get, post } from '@/core/api.js';
import { attempt } from '@/core/toast.js';
import { renderMarkdown } from '@/core/markdown.js';
import { formatDateTime, relativeTime, plural } from '@/core/format.js';

import AppIcon from '@/components/AppIcon.js';
import AppModal from '@/components/AppModal.js';
import EmptyState from '@/components/EmptyState.js';
import ViewHeader from '@/components/ViewHeader.js';

/** What the history table calls each way an update can have been started. */
const TRIGGERS = {
  manual: 'By hand',
  schedule: 'Automatically',
  cli: 'Command line',
};

/**
 * How each outcome reads. The server writes one of these five words onto the
 * history row and it is the only trustworthy record of what happened - see
 * `finished` below for why the response envelope is not.
 */
const OUTCOMES = {
  running: { label: 'Still running', tone: 'badge--warning', ok: false },
  installed: { label: 'Installed', tone: 'badge--success', ok: true },
  restored: { label: 'Restored', tone: 'badge--success', ok: true },
  rolled_back: { label: 'Rolled back', tone: 'badge--danger', ok: false },
  failed: { label: 'Failed', tone: 'badge--danger', ok: false },
};

export const UpdatesView = {
  name: 'UpdatesView',
  components: { AppIcon, AppModal, EmptyState, ViewHeader },
  setup() {
    const loading = ref(true);
    const checking = ref(false);
    const history = ref([]);
    const openLog = ref(null);

    /**
     * The install or the rollback, from the confirmation to the log.
     *
     * One object with a phase rather than three booleans, because the three
     * states are mutually exclusive and the middle one must not be escapable:
     * a dialog that can be dismissed while files are being swapped invites
     * somebody to navigate away mid-update.
     */
    const job = reactive({ kind: '', phase: '', result: null, error: '' });

    const info = computed(() => state.updateInfo ?? {});
    const latest = computed(() => info.value.latest ?? null);
    const settings = computed(() => info.value.settings ?? {});
    const schedule = computed(() => info.value.schedule ?? {});
    const checks = computed(() => info.value.preconditions ?? []);
    const backups = computed(() => info.value.backups ?? []);

    /* ------------------------------------------------------------- loading */

    const loadHistory = async () => {
      const data = await get('admin/update/history');
      history.value = data.history ?? [];
    };

    /**
     * The scheduler heartbeat lives with the settings, not with the update
     * status, and it is the difference between "installed automatically at
     * 05:00" and "never". It is worth one extra request to be able to say which
     * of those is true - but only on an installation that has switched some
     * automation on, and a failure here is swallowed: it would be a strange
     * thing to interrupt this screen with.
     */
    const ensureScheduler = async () => {
      if (state.scheduler) return;
      if (!schedule.value.auto_check && !schedule.value.auto_install) return;
      try {
        await loadSettings();
      } catch {
        /* the sentence below then says less, which is the right way to fail here */
      }
    };

    onMounted(() => attempt(async () => {
      await loadUpdate();
      await loadHistory();
      await ensureScheduler();
      loading.value = false;
    }, 'Load updates'));

    /* -------------------------------------------------------------- reading */

    const currentVersion = computed(() => info.value.version || state.app.version);

    /** The one-sentence answer at the top of the screen. */
    const headline = computed(() => {
      if (loading.value) {
        return {
          icon: 'refresh', tone: 'dim', spin: true,
          title: 'Reading where this installation stands',
          detail: 'Asking the server which version is installed and what GitHub last reported.',
        };
      }
      if (info.value.running) {
        return {
          icon: 'refresh', tone: 'c-warning', spin: true,
          title: 'An update is running',
          detail: 'Files are being replaced right now. Wait for it to finish before doing anything else here.',
        };
      }
      if (info.value.available && latest.value) {
        return {
          icon: 'download', tone: 'c-accent', spin: false,
          title: `Version ${latest.value.version} is available`,
          detail: `This installation is running ${currentVersion.value}. Read what changed below, then install when it suits you.`,
        };
      }
      if (info.value.error) {
        return {
          icon: 'alert', tone: 'c-warning', spin: false,
          title: 'CourseForge could not find out',
          detail: info.value.error,
        };
      }
      if (latest.value) {
        return {
          icon: 'check-circle', tone: 'c-success', spin: false,
          title: 'Up to date',
          detail: `${currentVersion.value} is the newest release published on ${info.value.repository}.`,
        };
      }
      return {
        icon: 'info', tone: 'dim', spin: false,
        title: 'Nothing has been checked yet',
        detail: 'CourseForge has not read a release list from GitHub on this installation. Check now to find out where it stands.',
      };
    });

    const blocking = computed(() => checks.value.filter((check) => !check.ok && check.blocking));
    const warnings = computed(() => checks.value.filter((check) => !check.ok && !check.blocking));

    const canInstall = computed(() =>
      info.value.available === true && !info.value.running && blocking.value.length === 0);

    /* How automatic updating is set, as a sentence rather than as switches. */
    const automatic = computed(() => {
      const auto = schedule.value;
      const at = `${auto.time || '05:00'} ${auto.timezone || 'UTC'}`;
      if (auto.auto_check && auto.auto_install) {
        return `CourseForge checks for a new release once a day and installs it by itself at ${at}.`;
      }
      if (auto.auto_check) {
        return 'CourseForge checks for a new release once a day but never installs one on its own - '
          + 'a new version waits here until somebody presses Install.';
      }
      if (auto.auto_install) {
        return `CourseForge installs a newer release by itself at ${at}, but nothing asks GitHub whether `
          + 'there is one, so it will only ever install a release a manual check has already found.';
      }
      return 'CourseForge does nothing on its own. It neither looks for a new release nor installs one '
        + 'unless somebody on this screen asks it to.';
    });

    /**
     * The case worth shouting about: automation switched on with nothing behind
     * it. Both of those settings are acted on by the scheduler and by nothing
     * else, so without it they are a promise the installation cannot keep.
     */
    const schedulerProblem = computed(() => {
      const auto = schedule.value;
      if (!auto.auto_check && !auto.auto_install) return '';
      const cron = state.scheduler;
      if (!cron) return '';
      if (!cron.configured) {
        return 'Nothing is running the scheduler on this installation, and the scheduler is what acts on '
          + 'those two settings. As things stand neither the daily check nor the automatic install will '
          + 'ever happen. Set a scheduler token in Settings and have your host call the cron address once '
          + 'a minute.';
      }
      if (!cron.healthy) {
        return cron.last_at
          ? `The scheduler is configured but was last called ${relativeTime(cron.last_at)}. It is meant to run `
            + 'every minute, and until it does again, nothing on this screen happens by itself.'
          : 'The scheduler is configured but has never called in, so nothing here has happened by itself yet.';
      }
      return '';
    });

    /** Release notes are Markdown written by somebody else, so they are sanitised. */
    const notes = computed(() => renderMarkdown(latest.value?.body ?? ''));

    /* -------------------------------------------------------------- checking */

    const checkNow = () => attempt(async () => {
      checking.value = true;
      try {
        // The response is the whole status, freshly read, so it replaces what
        // the screen is holding rather than being merged into it.
        state.updateInfo = await post('admin/update/check');
      } finally {
        checking.value = false;
      }
    }, 'Check for updates');

    /* ------------------------------------------------------- install and back */

    const askInstall = () => {
      job.kind = 'install';
      job.phase = 'confirm';
      job.result = null;
      job.error = '';
    };

    const askRollback = () => {
      job.kind = 'rollback';
      job.phase = 'confirm';
      job.result = null;
      job.error = '';
    };

    /** Refused while files are being replaced - there is nothing safe to do but wait. */
    const closeJob = () => {
      if (job.phase === 'running') return;
      job.phase = '';
      job.kind = '';
    };

    const startJob = async () => {
      job.phase = 'running';
      job.result = null;
      job.error = '';
      try {
        job.result = await post(`admin/update/${job.kind === 'install' ? 'install' : 'rollback'}`);
      } catch (error) {
        // A refusal before anything was touched - a failed precondition, a lock
        // somebody else holds, no release to install. It arrives as a message
        // and there is no log, because nothing ran.
        job.error = error.message;
      }
      job.phase = 'done';

      // Whatever happened, what is on screen is now out of date. This request
      // is answered by the code that has just been replaced, so the version it
      // reports may still be the old one - which is exactly what the note in
      // the response says, and why a reload is offered rather than assumed.
      await attempt(async () => {
        await loadUpdate();
        await loadHistory();
      }, 'Reload update status');
    };

    /**
     * What the finished job actually did.
     *
     * Read off the history row, never off the response's own `ok`: the server
     * wraps every payload in an envelope that sets `ok` to true, and that key
     * wins over the one the update handler put there, so a failed install comes
     * back looking like a successful request. The status word on the row is
     * written by the updater itself and is the honest record.
     */
    const finished = computed(() => {
      if (job.error) {
        return { ok: false, label: 'Refused', tone: 'c-danger', message: job.error };
      }
      const row = job.result?.history ?? null;
      const outcome = OUTCOMES[row?.status] ?? { label: row?.status || 'Unknown', tone: 'badge--warning', ok: false };
      return {
        ok: outcome.ok && !(row?.error),
        label: outcome.label,
        tone: outcome.ok && !(row?.error) ? 'c-success' : 'c-danger',
        message: row?.error || job.result?.note || '',
      };
    });

    /**
     * The dialog's heading. It asks a question while it is still a question,
     * disappears entirely while files are being replaced - AppModal draws its
     * close button beside a title, and a close button that has to refuse would
     * be worse than none - and drops the question mark once it is a report.
     */
    const jobTitle = computed(() => {
      if (job.phase === 'running') return '';
      const version = latest.value ? latest.value.version : 'the new version';
      const asked = job.phase === 'confirm' ? '?' : '';
      return job.kind === 'install'
        ? `Install ${version}${asked}`
        : `Restore the previous version${asked}`;
    });

    /** The log, split so each line can carry its own timestamp and colour. */
    const logLines = computed(() => {
      const text = String(job.result?.log ?? '');
      if (text.trim() === '') return [];
      return text.split('\n').map((line) => {
        const match = /^(\d{2}:\d{2}:\d{2})\s{2}(.*)$/.exec(line);
        const body = match ? match[2] : line;
        let tone = '';
        if (/FAILED|could not|cannot/i.test(body)) tone = 'log__line--removed';
        else if (/complete|Rolled back to|parses and its configuration/i.test(body)) tone = 'log__line--added';
        return { stamp: match ? match[1] : '', body, tone };
      });
    });

    /** The same split for a row read back out of the history table. */
    const historyLines = computed(() => {
      const text = String(openLog.value?.log ?? '');
      if (text.trim() === '') return [];
      return text.split('\n').map((line) => {
        const match = /^(\d{2}:\d{2}:\d{2})\s{2}(.*)$/.exec(line);
        return { stamp: match ? match[1] : '', body: match ? match[2] : line };
      });
    });

    const triggerLabel = (key) => TRIGGERS[key] ?? key;
    const outcomeFor = (status) =>
      OUTCOMES[status] ?? { label: status || 'Unknown', tone: 'badge--outline', ok: false };

    const reload = () => window.location.reload();

    return {
      state, loading, checking, info, latest, settings, schedule, checks, backups, history,
      currentVersion, headline, blocking, warnings, canInstall, automatic, schedulerProblem, notes,
      checkNow, job, jobTitle, askInstall, askRollback, closeJob, startJob, finished, logLines,
      openLog, historyLines, triggerLabel, outcomeFor, reload, go,
      formatDateTime, relativeTime, plural,
    };
  },
  template: `
    <view-header title="Updates" icon="download">
      <template #actions>
        <span class="badge hide-sm">v{{ currentVersion }}</span>
        <button class="btn btn--sm" :disabled="checking || info.running" @click="checkNow">
          <app-icon name="refresh" :size="13" :spin="checking"/>
          {{ checking ? 'Asking GitHub…' : 'Check now' }}
        </button>
      </template>
    </view-header>

    <div class="view-scroll">
      <div class="view-pad container-narrow col gap-6">

        <!-- are you up to date ---------------------------------------------- -->
        <section class="card card--pad">
          <div class="row wrap gap-4 between">
            <div class="row-top gap-3 grow" style="min-width:280px">
              <app-icon :name="headline.icon" :size="22" :class="headline.tone" :spin="headline.spin"
                        class="none" style="margin-top:3px"/>
              <div class="col gap-1">
                <h2 class="t-lg">{{ headline.title }}</h2>
                <p class="t-sm dim" style="max-width:60ch">{{ headline.detail }}</p>
              </div>
            </div>

            <div class="col gap-2 none" style="align-items:flex-end">
              <button class="btn btn--primary" :disabled="!canInstall" @click="askInstall"
                      :title="canInstall ? '' : 'See the conditions below.'">
                <app-icon name="download" :size="14"/>
                Install {{ latest ? latest.version : '' }}
              </button>
              <p v-if="blocking.length && !info.running" class="t-2xs c-warning"
                 style="max-width:26ch;text-align:right">
                {{ plural(blocking.length, 'condition') }} below {{ blocking.length === 1 ? 'is' : 'are' }}
                not met, so no update can start yet.
              </p>
            </div>
          </div>

          <div class="divider mt-4 mb-4"></div>

          <div class="row wrap gap-6 t-xs dim">
            <span>Installed <strong class="mono">{{ currentVersion }}</strong></span>
            <span>Repository
              <strong class="mono">{{ info.repository || 'not configured' }}</strong>
            </span>
            <span>Channel <strong>{{ info.channel || 'stable' }}</strong></span>
            <span>
              Last checked
              <strong :title="info.checked_at ? formatDateTime(info.checked_at) : ''">
                {{ info.checked_at ? relativeTime(info.checked_at) : 'never' }}
              </strong>
            </span>
          </div>

          <!-- Only when the headline is saying something else; otherwise this
               would repeat the sentence directly above it. -->
          <p v-if="info.error && headline.detail !== info.error" class="hint c-danger mt-3">{{ info.error }}</p>
          <p v-if="!settings.token_set" class="hint mt-2">
            No GitHub token is stored, so these checks are made anonymously. That is fine for a public
            repository and enough for a handful of checks an hour; a private one needs a token, set in
            Settings under Updates.
          </p>
        </section>

        <!-- what changed ---------------------------------------------------- -->
        <section v-if="latest" class="card">
          <div class="card__head">
            <h2 class="card__title grow">{{ latest.name || latest.version }}</h2>
            <span v-if="latest.prerelease" class="badge badge--warning none">pre-release</span>
            <span class="badge badge--outline none">{{ latest.tag }}</span>
            <span class="t-xs faint none">
              published {{ latest.published_at ? relativeTime(latest.published_at) : 'at an unknown time' }}
            </span>
          </div>
          <div class="card__body">
            <div v-if="notes" class="prose" v-html="notes"></div>
            <p v-else class="hint">This release was published without any notes.</p>
          </div>
        </section>

        <!-- can it run ------------------------------------------------------ -->
        <section class="card">
          <div class="card__head">
            <h2 class="card__title grow">Before an update can run</h2>
            <span v-if="blocking.length" class="badge badge--danger none">
              {{ plural(blocking.length, 'problem') }}
            </span>
            <span v-else-if="warnings.length" class="badge badge--warning none">
              {{ plural(warnings.length, 'warning') }}
            </span>
            <span v-else class="badge badge--success none">all clear</span>
          </div>
          <div class="card__body col gap-4">
            <p class="hint">
              Every one of these is checked before a single file is touched. Anything marked as required has
              to pass; the rest are worth reading but will not stop an update.
            </p>

            <div v-for="check in checks" :key="check.key" class="row-top gap-3">
              <app-icon :name="check.ok ? 'check-circle' : (check.blocking ? 'x-circle' : 'alert-circle')"
                        :size="16" class="none" style="margin-top:2px"
                        :class="check.ok ? 'c-success' : (check.blocking ? 'c-danger' : 'c-warning')"/>
              <div class="col gap-1 grow" style="min-width:0">
                <div class="row gap-2">
                  <span class="t-sm semi grow">{{ check.label }}</span>
                  <span v-if="!check.blocking" class="badge badge--outline none">advisory</span>
                </div>
                <p v-if="check.detail" class="hint">{{ check.detail }}</p>
              </div>
            </div>

            <empty-state v-if="!checks.length && !loading" icon="info" title="No conditions were reported"
                         hint="The server answered without a precondition list, which usually means the update feature is switched off."/>
          </div>
        </section>

        <!-- automatic ------------------------------------------------------- -->
        <section class="card card--pad col gap-3">
          <div class="row gap-2">
            <app-icon name="zap" :size="16" class="c-accent none"/>
            <h2 class="t-md grow">On its own</h2>
            <button class="btn btn--ghost btn--sm none" @click="go('settings')">
              <app-icon name="cog" :size="13"/> Change this in Settings
            </button>
          </div>

          <p class="t-sm">{{ automatic }}</p>

          <p v-if="schedule.auto_install && schedule.next_install_at" class="hint">
            The next automatic install would happen on {{ formatDateTime(schedule.next_install_at) }}, and only
            if a newer release has been published by then.
          </p>

          <div v-if="schedulerProblem" class="row-top gap-3 card card--flat card--pad"
               style="border-color:var(--warning)">
            <app-icon name="alert" :size="16" class="c-warning none" style="margin-top:2px"/>
            <p class="t-sm">{{ schedulerProblem }}</p>
          </div>

          <p class="hint">
            The three switches behind this - whether to check, whether to install, and at what time - are on
            the Settings screen under Updates, so there is one place they can be changed and one answer to
            what they are.
          </p>
        </section>

        <!-- history --------------------------------------------------------- -->
        <section class="card" style="overflow:hidden">
          <div class="card__head">
            <h2 class="card__title grow">Every update this installation has attempted</h2>
          </div>

          <div v-if="history.length" class="scroll-x">
            <table class="table">
              <thead>
                <tr>
                  <th style="width:170px">When</th>
                  <th style="width:150px">Version</th>
                  <th style="width:130px">How</th>
                  <th style="width:140px">By</th>
                  <th style="width:120px">Outcome</th>
                  <th>What happened</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in history" :key="row.id">
                  <td class="t-xs dim" :title="formatDateTime(row.started_at)">
                    {{ relativeTime(row.started_at) }}
                  </td>
                  <td class="t-xs mono">
                    {{ row.from_version || '?' }} → {{ row.to_version || '?' }}
                  </td>
                  <td class="t-xs dim">{{ triggerLabel(row.trigger) }}</td>
                  <td class="t-xs dim truncate">{{ row.actor || 'the scheduler' }}</td>
                  <td>
                    <span class="badge" :class="outcomeFor(row.status).tone">
                      {{ outcomeFor(row.status).label }}
                    </span>
                  </td>
                  <td>
                    <div class="row gap-2">
                      <span class="t-xs grow truncate" :class="row.error ? 'c-danger' : 'dim'"
                            :title="row.error || ''">
                        {{ row.error || (row.log ? 'Finished without an error.' : 'No log was written.') }}
                      </span>
                      <button v-if="row.log" class="btn btn--ghost btn--sm none" @click="openLog = row">
                        log
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div v-else class="card__body">
            <empty-state icon="file-text" title="Nothing has been installed yet"
                         hint="Every attempt to update, successful or not, is recorded here with the log that explains it."/>
          </div>
        </section>

        <!-- going back ------------------------------------------------------ -->
        <div class="danger-zone">
          <p class="danger-zone__title">Go back to the previous version</p>
          <p class="t-sm">
            Every update copies the files it is about to replace into a backup first. Rolling back unpacks the
            most recent of those over the installation, which puts the code back as it was - your courses,
            pages and settings live in the database and the data directory and are not part of it.
          </p>

          <p v-if="backups.length" class="hint">
            The newest backup was taken {{ relativeTime(backups[0].created_at) }}, going from
            <strong class="mono">{{ backups[0].from_version || 'an unrecorded version' }}</strong>
            to <strong class="mono">{{ backups[0].to_version || 'an unrecorded version' }}</strong>, and holds
            {{ plural(backups[0].files, 'file') }}.
            <template v-if="backups.length > 1">
              {{ plural(backups.length - 1, 'older backup') }} {{ backups.length === 2 ? 'is' : 'are' }} also
              on disk, but only the newest one can be restored from here.
            </template>
            <template v-if="settings.keep_backups">
              CourseForge keeps the {{ settings.keep_backups }} most recent and deletes the rest.
            </template>
          </p>
          <p v-else class="hint">
            There is no backup on this installation yet, so there is nothing to go back to. One is made the
            first time an update runs.
          </p>

          <div class="row end">
            <button class="btn btn--danger none" :disabled="!backups.length || info.running"
                    @click="askRollback">
              <app-icon name="arrow-left" :size="14"/> Restore the previous version
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- install / rollback ------------------------------------------------------ -->
    <app-modal v-if="job.phase" wide :title="jobTitle"
               :icon="job.kind === 'install' ? 'download' : 'alert'"
               @close="closeJob">

      <!-- ask ------------------------------------------------------------- -->
      <div v-if="job.phase === 'confirm'" class="col gap-4">
        <template v-if="job.kind === 'install'">
          <p class="t-sm">
            CourseForge will download <strong>{{ latest ? latest.version : 'the newest release' }}</strong>
            from {{ info.repository }} and replace its own program files with it. This installation is
            currently running <strong class="mono">{{ currentVersion }}</strong>.
          </p>
          <ul class="col gap-2 t-sm" style="padding-left:18px">
            <li>Every file that is about to be replaced is copied into a backup first, so this can be undone.</li>
            <li>The site will be unreachable for a moment while the files are swapped - seconds on a fast
              server, a minute or two on a slow one.</li>
            <li>Your courses, pages, profiles, tags and settings are not touched. They live in the database
              and the data directory, and neither is part of an update.</li>
            <li>Do not close this window or reload the page until it has finished.</li>
          </ul>
          <p v-if="warnings.length" class="hint c-warning">
            {{ plural(warnings.length, 'advisory check') }} did not pass. They will not stop the update, but
            they are listed on the screen behind this one and are worth reading first.
          </p>
        </template>

        <template v-else>
          <p class="t-sm">
            CourseForge will unpack the most recent backup over the installation, putting the program files
            back as they were before the last update. This installation is currently running
            <strong class="mono">{{ currentVersion }}</strong>.
          </p>
          <p v-if="backups.length" class="t-sm">
            The backup being restored was taken {{ relativeTime(backups[0].created_at) }} and holds
            {{ plural(backups[0].files, 'file') }} from
            <strong class="mono">{{ backups[0].from_version || 'an unrecorded version' }}</strong>.
          </p>
          <ul class="col gap-2 t-sm" style="padding-left:18px">
            <li>The site will be unreachable for a moment while the files are put back.</li>
            <li>Your courses, pages and settings are not touched, including anything written since the
              update - a rollback moves code, not content.</li>
            <li>If a newer release added a column to the database, that column stays. Going back is safe for
              the version immediately before this one and gets less safe the further back you go.</li>
          </ul>
        </template>
      </div>

      <!-- work ------------------------------------------------------------- -->
      <div v-else-if="job.phase === 'running'" class="col gap-3" style="align-items:center;text-align:center">
        <app-icon name="refresh" :size="26" :spin="true" class="c-accent"/>
        <h3 class="t-lg">
          {{ job.kind === 'install' ? 'Installing ' + (latest ? latest.version : 'the new version') : 'Restoring the previous version' }}
        </h3>
        <p class="t-sm dim" style="max-width:48ch">
          Downloading, backing up and swapping the files. This can take a minute or two on a slow server.
          Leave this window open - the whole log appears here when it is done.
        </p>
      </div>

      <!-- report ------------------------------------------------------------ -->
      <div v-else class="col gap-4">
        <div class="row-top gap-3">
          <app-icon :name="finished.ok ? 'check-circle' : 'alert-circle'" :size="20" class="none"
                    :class="finished.tone" style="margin-top:2px"/>
          <div class="col gap-1">
            <h3 class="t-md">
              {{ finished.ok
                ? (job.kind === 'install' ? 'The new files are in place' : 'The previous version is back')
                : 'It did not finish' }}
            </h3>
            <p v-if="finished.message" class="t-sm dim">{{ finished.message }}</p>
          </div>
        </div>

        <div v-if="logLines.length" class="form-row">
          <label>What it did</label>
          <pre class="log" style="white-space:pre-wrap"><span v-for="(line, index) in logLines" :key="index"
            class="log__line" :class="line.tone" style="display:block"><span v-if="line.stamp"
            class="log__stamp">{{ line.stamp }}</span>{{ line.body }}</span></pre>
        </div>

        <p v-if="finished.ok" class="hint">
          This page is still the one that was loaded before the swap, so it is running the old code. Reload to
          pick up the new version.
        </p>
        <p v-else-if="logLines.length" class="hint">
          Nothing was left half-installed: the update puts the previous files back the moment anything goes
          wrong. The log above is the full record, and the same log stays in the history table.
        </p>
        <p v-else class="hint">
          This was refused before anything was touched, so no file on the server was changed and there is no
          log to read. Fix what the message above describes and try again.
        </p>
      </div>

      <template #footer>
        <template v-if="job.phase === 'confirm'">
          <button class="btn" @click="closeJob">Cancel</button>
          <button class="btn" :class="job.kind === 'install' ? 'btn--primary' : 'btn--danger'"
                  @click="startJob">
            <app-icon :name="job.kind === 'install' ? 'download' : 'arrow-left'" :size="14"/>
            {{ job.kind === 'install'
              ? 'Install ' + (latest ? latest.version : 'it')
              : 'Restore the backup' }}
          </button>
        </template>
        <template v-else-if="job.phase === 'done'">
          <button class="btn" @click="closeJob">Close</button>
          <button v-if="finished.ok" class="btn btn--primary" @click="reload">
            <app-icon name="refresh" :size="14"/> Reload CourseForge
          </button>
        </template>
      </template>
    </app-modal>

    <!-- one row's log ---------------------------------------------------------- -->
    <app-modal v-if="openLog" wide icon="file-text"
               :title="'Update log · ' + (openLog.from_version || '?') + ' to ' + (openLog.to_version || '?')"
               @close="openLog = null">
      <div class="col gap-3">
        <div class="row wrap gap-4 t-xs dim">
          <span>Started {{ formatDateTime(openLog.started_at) }}</span>
          <span v-if="openLog.finished_at">Finished {{ formatDateTime(openLog.finished_at) }}</span>
          <span>{{ triggerLabel(openLog.trigger) }}</span>
          <span>{{ openLog.actor || 'the scheduler' }}</span>
          <span class="badge" :class="outcomeFor(openLog.status).tone">
            {{ outcomeFor(openLog.status).label }}
          </span>
        </div>

        <p v-if="openLog.error" class="t-sm c-danger">{{ openLog.error }}</p>

        <pre class="log" style="white-space:pre-wrap"><span v-for="(line, index) in historyLines" :key="index"
          class="log__line" style="display:block"><span v-if="line.stamp"
          class="log__stamp">{{ line.stamp }}</span>{{ line.body }}</span></pre>

        <p v-if="openLog.backup_path" class="hint">
          The backup for this attempt is at <span class="mono">{{ openLog.backup_path }}</span>.
        </p>
      </div>

      <template #footer>
        <button class="btn" @click="openLog = null">Close</button>
      </template>
    </app-modal>`,
};

export default UpdatesView;
