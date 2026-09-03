/**
 * Administration › Security - does this server keep the data directory
 * private, and what to do if it does not.
 *
 * CourseForge protects itself with .htaccess files, which Apache reads and
 * nginx, Caddy, IIS and PHP's own server do not. Rather than explain that,
 * the server is asked: it fetches its own private files over HTTP and reports
 * which came back. Until every one is refused - or an administrator has read
 * the verdict and accepted the risk in the red box at the bottom - every
 * field that would store a secret stays locked.
 *
 * The instructions are per server, chosen from what was detected and
 * switchable by hand, because the detection can be wrong behind a proxy.
 */
import { ref, computed, onMounted } from 'vue';
import { state, applySecurity } from '@/core/store.js';
import { get, post, del } from '@/core/api.js';
import { toast, attempt } from '@/core/toast.js';
import { relativeTime, formatDateTime } from '@/core/format.js';

import AppIcon from '@/components/AppIcon.js';
import ViewHeader from '@/components/ViewHeader.js';

const FAMILIES = [
  { key: 'apache', label: 'Apache', icon: 'server' },
  { key: 'litespeed', label: 'LiteSpeed', icon: 'server' },
  { key: 'nginx', label: 'nginx', icon: 'server-2' },
  { key: 'caddy', label: 'Caddy', icon: 'server-2' },
  { key: 'iis', label: 'IIS', icon: 'window' },
  { key: 'builtin', label: 'PHP built-in', icon: 'terminal' },
];

const DENY_TYPES = 'sqlite|sqlite3|sqlite-wal|sqlite-shm|sqlite-journal|db|db-wal|db-shm|json|md|log|ini|txt|zip|tar|gz|bak|sql|sh|tmp';

const SNIPPETS = {
  nginx: `# Block the private directories outright
location ^~ /data/   { deny all; return 403; }
location ^~ /config/ { deny all; return 403; }
location ^~ /src/    { deny all; return 403; }
location ^~ /tools/  { deny all; return 403; }
location ^~ /tests/  { deny all; return 403; }
location ~ /\\.(?!well-known) { deny all; return 403; }

# Block sensitive file types anywhere. .txt is here for INVITE-CODE.txt.
location ~* \\.(${DENY_TYPES})$ {
    deny all; return 403;
}

# API front controller. The regex location below matches .php first, so
# api/mcp.php is handed to PHP rather than routed into index.php.
location /api/ {
    try_files $uri /api/index.php$is_args$args;
}
location = /mcp { rewrite ^ /api/mcp.php last; }

location ~ \\.php$ {
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_read_timeout 1800;          # a page generation may take minutes
}`,
  caddy: `courseforge.example.com {
    root * /var/www/courseforge

    @privateDirs path /data/* /config/* /src/* /tools/* /tests/* /.git/*
    respond @privateDirs 403

    @privateFiles path ${DENY_TYPES.split('|').map((t) => `*.${t}`).join(' ')} /.htaccess /.user.ini
    respond @privateFiles 403

    rewrite /mcp /api/mcp.php
    php_fastcgi unix//run/php/php-fpm.sock
    file_server
}`,
  iis: `<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <security>
      <requestFiltering>
        <hiddenSegments>
          <add segment="data" />
          <add segment="config" />
          <add segment="src" />
          <add segment="tools" />
          <add segment="tests" />
          <add segment=".git" />
        </hiddenSegments>
        <fileExtensions allowUnlisted="true">
${DENY_TYPES.split('|').map((t) => `          <add fileExtension=".${t}" allowed="false" />`).join('\n')}
        </fileExtensions>
      </requestFiltering>
    </security>
  </system.webServer>
</configuration>`,
  apache: `# In the virtual host, or in httpd.conf - .htaccess is only read when
# AllowOverride permits it. "All" is simplest; "FileInfo AuthConfig Limit
# Options=Indexes" is the least that lets every shipped rule work.
<Directory "/var/www/courseforge">
    AllowOverride All
    Require all granted
</Directory>

# Then make sure mod_rewrite, mod_headers and mod_authz_core are loaded:
#   a2enmod rewrite headers && systemctl reload apache2`,
  litespeed: `# LiteSpeed reads the same .htaccess files Apache does. If a file still
# comes back, the vhost has rewrite support off, or the .htaccess files are
# missing - restore them from the release archive:
#   .htaccess  data/.htaccess  config/.htaccess  tools/.htaccess  tests/.htaccess
# In the LiteSpeed WebAdmin: Virtual Hosts › Rewrite › Enable Rewrite = Yes,
# and Allow Override = Yes (or the per-directory equivalent in .htaccess).`,
  builtin: `# PHP's built-in server reads no .htaccess at all and is not meant to face
# the internet. For development, run it behind the shipped router, which
# refuses the same files Apache does:
php -S 127.0.0.1:8080 -t . tools/router-dev.php`,
};

const ENV_SNIPPET = `# Apache (httpd.conf or the vhost)
SetEnv COURSEFORGE_DATA_DIR /var/lib/courseforge

# nginx + PHP-FPM (in the location ~ \\.php$ block)
fastcgi_param COURSEFORGE_DATA_DIR /var/lib/courseforge;

# PHP-FPM pool (php-fpm.d/www.conf)
env[COURSEFORGE_DATA_DIR] = /var/lib/courseforge

# Docker / systemd
COURSEFORGE_DATA_DIR=/var/lib/courseforge`;

const VERDICTS = {
  secure: { icon: 'shield-check', tone: 'success', title: 'This server keeps its private files private.',
    text: 'Every file that holds a secret was refused when asked for over HTTP. API keys and tokens may be stored.' },
  exposed: { icon: 'shield-alert', tone: 'danger', title: 'This server hands out its private files.',
    text: 'At least one file that must never be served came back over HTTP. Until that is fixed, nothing that would store a secret is allowed to - and anything already stored should be treated as read.' },
  unverified: { icon: 'question', tone: 'warning', title: 'The verdict could not be taken.',
    text: 'CourseForge could not reach itself over HTTP, so it does not know whether the private files are refused. Fix what the reason below names and check again, or accept the risk deliberately at the bottom of this page.' },
  unknown: { icon: 'question', tone: 'warning', title: 'Nobody has checked yet.',
    text: 'The check runs the first time this screen is opened. Until it has passed, every field that would store a secret stays locked.' },
};

const OUTCOMES = {
  refused: { icon: 'check-circle', tone: 'c-success', label: 'refused' },
  exposed: { icon: 'x-circle', tone: 'c-danger', label: 'served' },
  undecided: { icon: 'alert', tone: 'c-warning', label: 'unclear' },
  unreachable: { icon: 'alert', tone: 'c-warning', label: 'no answer' },
};

export const SecurityView = {
  name: 'SecurityView',
  components: { AppIcon, ViewHeader },
  setup() {
    const loading = ref(true);
    const checking = ref(false);
    const security = ref(null);
    const ackCode = ref('');
    const typed = ref('');
    const family = ref('');
    const busy = ref(false);

    const apply = (data) => {
      security.value = data.security;
      if (typeof data.ack_code === 'string') ackCode.value = data.ack_code;
      applySecurity(data);
      if (!family.value) {
        const detected = data.security?.server?.family ?? 'unknown';
        family.value = FAMILIES.some((f) => f.key === detected) ? detected : 'nginx';
      }
    };

    const load = () => attempt(async () => {
      loading.value = true;
      try {
        apply(await get('admin/security'));
      } finally {
        loading.value = false;
      }
    }, 'Security');

    onMounted(load);

    const check = () => attempt(async () => {
      if (checking.value) return;
      checking.value = true;
      try {
        apply(await post('admin/security/check'));
        const v = security.value?.verdict;
        if (v === 'secure') toast.success('Every private file was refused. Secrets may be stored.');
        else if (v === 'exposed') toast.error('The server handed back a private file. See what was found.');
        else toast.info('The verdict could not be taken - see the reason on the screen.');
      } finally {
        checking.value = false;
      }
    }, 'Security check');

    const acknowledge = () => attempt(async () => {
      if (busy.value) return;
      busy.value = true;
      try {
        apply(await post('admin/security/acknowledge', { code: typed.value }));
        typed.value = '';
        toast.success('Recorded. Secrets may be stored; the verdict is unchanged and your name is on the audit log.');
      } finally {
        busy.value = false;
      }
    }, 'Accept the risk');

    const revoke = () => attempt(async () => {
      if (busy.value) return;
      busy.value = true;
      try {
        apply(await del('admin/security/acknowledge'));
        toast.info('The acknowledgement was withdrawn. The lock holds again.');
      } finally {
        busy.value = false;
      }
    }, 'Withdraw');

    const verdict = computed(() => VERDICTS[security.value?.verdict] ?? VERDICTS.unknown);
    const server = computed(() => security.value?.server ?? {});
    const probes = computed(() => security.value?.probes ?? []);
    const ack = computed(() => security.value?.acknowledged ?? null);
    const locked = computed(() => security.value?.locked === true);
    const outcome = (probe) => OUTCOMES[probe.outcome] ?? OUTCOMES.undecided;

    const familyLabel = computed(() => FAMILIES.find((f) => f.key === family.value)?.label ?? 'this server');
    const detectedLabel = computed(() => {
      const f = FAMILIES.find((x) => x.key === server.value.family);
      return f ? f.label : (server.value.software || 'unknown');
    });
    const snippet = computed(() => SNIPPETS[family.value] ?? '');
    const codeReady = computed(() => typed.value.replace(/\s+/g, '').length === 6);

    const copied = ref('');
    let copiedTimer = 0;
    const copy = async (text, id) => {
      try {
        await navigator.clipboard.writeText(text);
        copied.value = id;
        clearTimeout(copiedTimer);
        copiedTimer = setTimeout(() => { copied.value = ''; }, 2000);
        toast.success('Copied.');
      } catch {
        toast.info('Copying is blocked here - select the text and copy it by hand.');
      }
    };

    return {
      state, loading, checking, busy, security, verdict, server, probes, ack, locked, outcome,
      family, FAMILIES, familyLabel, detectedLabel, snippet, ENV_SNIPPET,
      ackCode, typed, codeReady, check, acknowledge, revoke, copy, copied,
      relativeTime, formatDateTime,
    };
  },
  template: `
    <view-header title="Security" icon="shield-lock" subtitle="Whether this server keeps CourseForge's private files private, and what to do if it does not">
      <template #actions>
        <span v-if="security" class="badge hide-sm" :class="'badge--' + verdict.tone">{{ security.verdict }}</span>
        <button class="btn btn--primary" :disabled="checking || loading" @click="check">
          <app-icon :name="checking ? 'refresh' : 'shield-check'" :size="14" :spin="checking"/>
          {{ checking ? 'Checking…' : 'Check again' }}
        </button>
      </template>
    </view-header>

    <div class="view-scroll">
      <div class="view-pad container-narrow col gap-5">

        <!-- the verdict ------------------------------------------------------ -->
        <section class="card card--pad col gap-4 verdict" :class="'verdict--' + verdict.tone" data-tour="security-verdict">
          <div class="row-top gap-4">
            <span class="tile tile--lg" :class="'tile--' + verdict.tone"><app-icon :name="verdict.icon" :size="24"/></span>
            <div class="col gap-2 grow">
              <h2 class="t-xl">{{ loading ? 'Taking the verdict…' : verdict.title }}</h2>
              <p class="t-sm muted">{{ verdict.text }}</p>
              <p v-if="security && security.reason" class="t-sm" :class="security.verdict === 'secure' ? 'dim' : 'c-' + verdict.tone">
                {{ security.reason }}
              </p>
              <p v-if="security && security.checked_at" class="t-xs faint">
                Checked {{ relativeTime(security.checked_at) }} against {{ security.base_url || 'this address' }}.
                The scheduler takes the verdict again every six hours.
              </p>
            </div>
          </div>

          <div v-if="security" class="row wrap gap-3 items-end">
            <div class="note-strip grow" :class="locked ? 'note-strip--danger' : 'note-strip--success'" style="min-width:260px">
              <app-icon :name="locked ? 'lock' : 'unlock'" :size="15" :class="locked ? 'c-danger' : 'c-success'"/>
              <span>
                <strong>{{ locked ? 'Secrets are locked.' : 'Secrets may be stored.' }}</strong>
                <template v-if="locked">
                  Every API key, BookStack token, cron token and GitHub token field is greyed out with a red mark
                  until the verdict is secure, or the risk is accepted below.
                </template>
                <template v-else-if="ack">
                  Not because the server passed - because <strong>{{ ack.by }}</strong> accepted the risk
                  {{ relativeTime(ack.at) }} against a verdict of "{{ ack.verdict }}".
                  <button class="btn btn--ghost btn--sm" style="padding:0 4px" :disabled="busy" @click="revoke">withdraw that</button>
                </template>
                <template v-else>The server refused every private file it was asked for.</template>
              </span>
            </div>
          </div>
        </section>

        <!-- the server ------------------------------------------------------- -->
        <section class="card" data-tour="security-server">
          <div class="card__head">
            <span class="tile tile--accent"><app-icon name="server-2" :size="17"/></span>
            <div class="card__heading">
              <span class="card__title">What was detected</span>
              <span class="card__desc">How this server presents itself from inside a request, and where CourseForge keeps its data.</span>
            </div>
          </div>
          <div class="card__body">
            <div class="facts">
              <div class="fact"><app-icon name="server" :size="15" class="dim"/><div class="fact__text"><div class="fact__label">Server</div><div class="fact__value" :title="server.software || ''">{{ detectedLabel }}</div></div></div>
              <div class="fact"><app-icon name="cpu" :size="15" class="dim"/><div class="fact__text"><div class="fact__label">PHP</div><div class="fact__value">{{ server.php }} · {{ server.sapi }}</div></div></div>
              <div class="fact"><app-icon name="lock" :size="15" :class="server.https ? 'c-success' : 'c-warning'"/><div class="fact__text"><div class="fact__label">HTTPS</div><div class="fact__value">{{ server.https === null ? 'unknown' : (server.https ? 'yes' : 'no - use it') }}</div></div></div>
              <div class="fact"><app-icon name="file-text" :size="15" :class="server.reads_htaccess ? 'c-success' : 'c-warning'"/><div class="fact__text"><div class="fact__label">.htaccess</div><div class="fact__value">{{ server.reads_htaccess ? 'read by this server' : 'ignored by this server' }}</div></div></div>
              <div class="fact"><app-icon name="folder" :size="15" :class="security && security.data_under_root ? 'c-warning' : 'c-success'"/><div class="fact__text"><div class="fact__label">Data directory</div><div class="fact__value" :title="security ? security.data_dir : ''">{{ security && security.data_under_root ? 'inside the web root' : 'outside the web root' }}</div></div></div>
              <div class="fact"><app-icon name="git-branch" :size="15" class="dim"/><div class="fact__text"><div class="fact__label">mod_rewrite</div><div class="fact__value">{{ server.mod_rewrite === null || server.mod_rewrite === undefined ? 'not askable' : (server.mod_rewrite ? 'loaded' : 'not loaded') }}</div></div></div>
            </div>
            <p class="hint mt-3">
              The server name comes from the request, so behind a proxy it may name the proxy. Pick the right
              instructions below by hand if so - the probes are what decide, not the name.
            </p>
          </div>
        </section>

        <!-- the probes -------------------------------------------------------- -->
        <section class="card" data-tour="security-probes">
          <div class="card__head">
            <span class="tile" :class="probes.some((p) => p.outcome === 'exposed') ? 'tile--danger' : 'tile--success'"><app-icon name="firewall" :size="17"/></span>
            <div class="card__heading">
              <span class="card__title">What the server was asked for</span>
              <span class="card__desc">Each file fetched over HTTP from this address, and what came back. The starred ones decide the verdict.</span>
            </div>
          </div>
          <div v-if="probes.length" class="scroll-x">
            <table class="table">
              <thead><tr><th>File</th><th>Why it matters</th><th style="width:170px">Answer</th></tr></thead>
              <tbody>
                <tr v-for="probe in probes" :key="probe.path">
                  <td>
                    <div class="row gap-2"><code class="t-xs">{{ probe.path }}</code><span v-if="probe.critical" class="badge badge--outline" title="Decides the verdict">decides</span></div>
                    <p class="hint">{{ probe.label }}</p>
                  </td>
                  <td class="t-xs dim">{{ probe.why }}</td>
                  <td>
                    <div class="row gap-2" :class="outcome(probe).tone">
                      <app-icon :name="outcome(probe).icon" :size="14"/>
                      <span class="t-sm semi">{{ outcome(probe).label }}</span>
                    </div>
                    <p class="hint">{{ probe.detail }}</p>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="card__body">
            <p class="hint">{{ loading ? 'Asking the server…' : 'No probe has run yet. Press Check again.' }}</p>
          </div>
        </section>

        <!-- the instructions -------------------------------------------------- -->
        <section class="card" data-tour="security-instructions">
          <div class="card__head">
            <span class="tile tile--accent"><app-icon name="wrench" :size="17"/></span>
            <div class="card__heading">
              <span class="card__title">How to fix it on {{ familyLabel }}</span>
              <span class="card__desc">Chosen from what was detected. Switch if the detection is wrong.</span>
            </div>
            <div class="btn-group none">
              <button v-for="f in FAMILIES" :key="f.key" :class="{ 'is-active': family === f.key }" @click="family = f.key">
                <app-icon :name="f.icon" :size="12"/> {{ f.label }}
              </button>
            </div>
          </div>
          <div class="card__body col gap-4">
            <div class="col gap-2">
              <template v-if="family === 'apache' || family === 'litespeed'">
                <p class="t-sm">
                  {{ familyLabel }} reads the shipped <code>.htaccess</code> files, so if a file still came back one
                  of two things is wrong: the files are missing - a deployment that skipped dot files, or an upload
                  that dropped them - or the server is told to ignore them (<code>AllowOverride None</code>).
                </p>
                <ol class="t-sm col gap-1" style="padding-left:20px">
                  <li>Check that <code>.htaccess</code>, <code>data/.htaccess</code>, <code>config/.htaccess</code>, <code>tools/.htaccess</code> and <code>tests/.htaccess</code> exist. CourseForge rewrites <code>data/.htaccess</code> itself; the others come from the release archive.</li>
                  <li>Make sure the directory allows overrides and the modules are loaded - the block below.</li>
                  <li>Press <strong>Check again</strong>.</li>
                </ol>
              </template>
              <template v-else-if="family === 'nginx' || family === 'caddy'">
                <p class="t-sm">
                  {{ familyLabel }} ignores <code>.htaccess</code> entirely, so every refusal has to be written into the
                  server configuration. The block below denies the private directories and every file type that
                  holds a secret, and routes the API. Put it into the server block for this site, reload, and press
                  <strong>Check again</strong>.
                </p>
              </template>
              <template v-else-if="family === 'iis'">
                <p class="t-sm">
                  IIS reads <code>web.config</code>, never <code>.htaccess</code>. The one below hides the private
                  directories and refuses the sensitive extensions through request filtering. Save it as
                  <code>web.config</code> in the installation root, then press <strong>Check again</strong>.
                </p>
              </template>
              <template v-else>
                <p class="t-sm">
                  PHP's built-in server is a development tool. It reads no <code>.htaccess</code> and refuses
                  nothing on its own - run it behind the shipped router for development, and put a real web
                  server in front of CourseForge for anybody else.
                </p>
              </template>
            </div>

            <div v-if="snippet" class="form-row">
              <label class="row between">
                <span>{{ family === 'iis' ? 'web.config' : (family === 'caddy' ? 'Caddyfile' : 'Configuration') }}</span>
                <button class="btn btn--ghost btn--sm" @click="copy(snippet, 'snippet')">
                  <app-icon :name="copied === 'snippet' ? 'check' : 'copy'" :size="12"/> {{ copied === 'snippet' ? 'copied' : 'copy' }}
                </button>
              </label>
              <pre class="log" style="white-space:pre;max-height:420px">{{ snippet }}</pre>
            </div>

            <div class="note-strip">
              <app-icon name="lightbulb" :size="15" class="c-accent"/>
              <span>
                <strong>The safest arrangement on any server</strong> is to keep the data directory outside the
                web root, so the question of who refuses it never arises. Point CourseForge at a directory PHP can
                write with the environment variable below, move <code>data/</code> there, and reload. Then only
                <code>index.html</code>, <code>assets/</code>, <code>api/</code>, <code>cron.php</code> and
                <code>bs.php</code> need to be public at all.
              </span>
            </div>
            <div class="form-row">
              <label class="row between">
                <span>COURSEFORGE_DATA_DIR</span>
                <button class="btn btn--ghost btn--sm" @click="copy(ENV_SNIPPET, 'env')">
                  <app-icon :name="copied === 'env' ? 'check' : 'copy'" :size="12"/> {{ copied === 'env' ? 'copied' : 'copy' }}
                </button>
              </label>
              <pre class="log" style="white-space:pre">{{ ENV_SNIPPET }}</pre>
            </div>

            <p class="hint">
              Two more things worth doing whatever the server: serve CourseForge over <strong>HTTPS</strong> - API
              tokens and the session cookie travel with every request - and keep <strong>Debug mode</strong> off
              under Settings › General on anything reachable from the internet.
            </p>
          </div>
        </section>

        <!-- accepting the risk ----------------------------------------------- -->
        <div class="danger-zone" data-tour="security-acknowledge">
          <p class="danger-zone__title row gap-2"><app-icon name="triangle-warning" :size="16"/> Store secrets anyway</p>
          <p class="t-sm">
            If the check cannot pass on this server - a proxy that will not let it fetch itself, a host you have
            already verified by hand - an administrator can accept the risk and unlock the secret fields. The
            verdict does not change and the acknowledgement is written to the audit log with your name.
          </p>
          <p class="t-sm">
            Be sure. If the data directory really is readable over HTTP, every API key and BookStack token you store
            afterwards can be downloaded by anybody who guesses the address.
          </p>
          <template v-if="security && security.verdict === 'secure'">
            <p class="hint">The server passed the check, so there is nothing to accept.</p>
          </template>
          <template v-else-if="ack">
            <p class="hint">
              Accepted by <strong>{{ ack.by }}</strong> on {{ formatDateTime(ack.at) }} against a verdict of
              "{{ ack.verdict }}".
              <button class="btn btn--danger btn--sm" style="margin-left:var(--s-2)" :disabled="busy" @click="revoke">
                <app-icon name="lock" :size="12"/> Withdraw and lock again
              </button>
            </p>
          </template>
          <template v-else>
            <p class="t-sm">
              If you are absolutely sure, enter <strong class="mono ack-code">{{ ackCode || '······' }}</strong>
              into this field and click the button.
            </p>
            <div class="row gap-2 wrap">
              <input v-model="typed" class="mono" style="max-width:180px" maxlength="8" autocomplete="off" spellcheck="false"
                     placeholder="the code" aria-label="The confirmation code shown above" @keydown.enter="codeReady && acknowledge()">
              <button class="btn btn--danger" :disabled="busy || !codeReady || loading" @click="acknowledge">
                <app-icon name="unlock" :size="14"/> I accept the risk - unlock the secret fields
              </button>
            </div>
            <p class="hint">The code is case-sensitive and changes every time this screen is opened.</p>
          </template>
        </div>
      </div>
    </div>`,
};

export default SecurityView;
