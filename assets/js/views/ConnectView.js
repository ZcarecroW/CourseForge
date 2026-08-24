/**
 * Connect - handing a language model a key to this installation.
 *
 * In CourseForge 3 this screen did one thing: it made a token so Claude could
 * be asked to write a page. In 4.0 the endpoint offers an MCP client nearly
 * everything the browser offers - creating courses, starting runs that spend
 * money for a day after the client has disconnected, and, for an administrator,
 * the accounts and settings of the whole installation. So the screen is no
 * longer a button. It is the place where somebody decides how much of their
 * installation to hand over, and every word here is written on the assumption
 * that the person reading it has never seen the code and cannot check.
 *
 * Three facts drive the layout. A connection is one client, because that is the
 * unit you revoke - the laptop, not the account. Its groups of tools and its
 * expiry are decided when it is made and can never be widened afterwards, which
 * is why the checklist sits above the Create button rather than behind an edit
 * link. And the token is readable exactly once, on the card that issued it:
 * that is not a limitation to apologise for, it is why a stolen copy of the
 * database is not a stolen connection.
 */
import { ref, reactive, computed, nextTick, onMounted } from 'vue';
import { isAdmin } from '@/core/store.js';
import { get, post, put, del } from '@/core/api.js';
import { toast, attempt } from '@/core/toast.js';
import { relativeTime, formatDate, plural } from '@/core/format.js';

import AppIcon from '@/components/AppIcon.js';
import AppModal from '@/components/AppModal.js';
import EmptyState from '@/components/EmptyState.js';
import ViewHeader from '@/components/ViewHeader.js';

const DAY = 86400;

/** What the expiry dropdown offers. The server refuses anything past a year. */
const TTL_CHOICES = [
  { days: 0, label: 'Never - it works until it is revoked' },
  { days: 1, label: 'One day' },
  { days: 7, label: 'One week' },
  { days: 30, label: 'One month' },
  { days: 90, label: 'Three months' },
  { days: 365, label: 'One year (the longest allowed)' },
];

export const ConnectView = {
  name: 'ConnectView',
  components: { AppIcon, AppModal, EmptyState, ViewHeader },
  setup() {
    /** Exactly what GET connect answers with. */
    const connect = reactive({
      url: '', clients: [], enabled: true, scopes: [], scopes_unavailable: false,
    });

    const loading = ref(true);
    const busy = ref(false);
    const draft = reactive({ name: '', note: '', ttl: 0 });
    /** The chosen tool groups, by key. Empty is refused - see create(). */
    const chosen = reactive(new Set());
    /** The one and only time this token is readable. */
    const fresh = ref(null);
    const freshPanel = ref(null);
    const editing = ref(null);
    const confirmDelete = ref(null);

    /**
     * Brings the panel that carries the new token onto the screen.
     *
     * The Create button is at the foot of a long form and the token is rendered
     * at the top of the same page, so on any window shorter than the form the
     * secret appears roughly a thousand pixels above the fold. What the person
     * sees after clicking is the connection arriving in the list below, which
     * reads as success - and then they navigate away, and the token is gone for
     * good, because only a hash of it was ever stored. Scrolling to it is what
     * makes the once-only rule survivable.
     */
    const revealToken = async () => {
      await nextTick();
      const panel = freshPanel.value;
      if (!panel || typeof panel.scrollIntoView !== 'function') return;

      const before = panel.getBoundingClientRect().top;
      const still = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true;
      panel.scrollIntoView({ block: 'start', behavior: still ? 'auto' : 'smooth' });

      // A browser can decline to animate - a tab in the background, an engine
      // that ignores the option - and then nothing moves at all, which is the
      // defect this exists to fix. So if the panel has not budged a pixel, put
      // it on screen without the animation. Anything that did move is a scroll
      // in progress, or the reader's own, and is left alone.
      window.setTimeout(() => {
        const box = panel.getBoundingClientRect();
        if (Math.abs(box.top - before) < 1 && (box.top < 0 || box.top > window.innerHeight)) {
          panel.scrollIntoView({ block: 'start', behavior: 'auto' });
        }
      }, 600);
    };

    /**
     * The checklist, with the one thing the catalogue does not say: whether
     * this account could be given the group at all. Administration is gated on
     * the account rather than on the token, so offering it to a normal account
     * would be offering something that silently grants nothing.
     */
    const scopeList = computed(() => connect.scopes.map((scope) => ({
      ...scope,
      grantable: !scope.admin || isAdmin.value,
    })));

    const grantable = computed(() => scopeList.value.filter((s) => s.grantable));

    /** Ticking every box means the same as ticking none - see create(). */
    const allChosen = computed(() =>
      grantable.value.length > 0 && grantable.value.every((s) => chosen.has(s.key))
    );

    const selectAll = () => {
      chosen.clear();
      for (const scope of grantable.value) chosen.add(scope.key);
    };

    const toggleScope = (key, on) => {
      if (on) chosen.add(key);
      else chosen.delete(key);
    };

    const load = () => attempt(async () => {
      const data = await get('connect');
      Object.assign(connect, data.connect ?? {});
      // Everything on, every time the catalogue arrives: a connection made
      // without a thought should be able to do what its owner can do, and
      // narrowing it is the deliberate act.
      selectAll();
      loading.value = false;
    }, 'Load connections');

    onMounted(load);

    /* ------------------------------------------------------------- writing */

    const create = () => attempt(async () => {
      if (chosen.size === 0) {
        // An empty list is how the server spells "everything this account may
        // do", so submitting an empty checklist would hand over more than a
        // full one. Refusing here is the only way the two cannot be confused.
        toast.error('Tick at least one group. A connection with none would be given all of them.');
        return;
      }

      busy.value = true;
      try {
        const data = await post('connect', {
          name: draft.name.trim() || 'Claude',
          note: draft.note.trim(),
          ttl_days: draft.ttl,
          // All ticked is sent as nothing on purpose: that is the form that
          // tracks the account. If this account is later made an administrator,
          // a connection created as "everything" gains the administration tools
          // and a connection created as an explicit list of ten does not.
          scopes: allChosen.value ? [] : [...chosen],
        });
        Object.assign(connect, data.connect ?? {});
        fresh.value = { token: data.token, client: data.client };
        draft.name = '';
        draft.note = '';
        draft.ttl = 0;
        selectAll();
        await revealToken();
      } finally {
        busy.value = false;
      }
    }, 'Create connection');

    const startEdit = (client) => {
      editing.value = { id: client.id, name: client.name, note: client.note ?? '' };
    };

    const saveEdit = () => attempt(async () => {
      const target = editing.value;
      if (!target) return;
      busy.value = true;
      try {
        const data = await put(`connect/${target.id}`, {
          name: target.name.trim() || 'Claude',
          note: target.note.trim(),
        });
        Object.assign(connect, data.connect ?? {});
        editing.value = null;
        toast.success('Connection renamed. The token itself is unchanged.');
      } finally {
        busy.value = false;
      }
    }, 'Rename connection');

    const remove = () => attempt(async () => {
      const target = confirmDelete.value;
      confirmDelete.value = null;
      if (!target) return;
      const data = await del(`connect/${target.id}`);
      Object.assign(connect, data.connect ?? {});
      if (fresh.value?.client?.id === target.id) fresh.value = null;
      toast.success('Connection revoked. That token stopped working immediately.');
    }, 'Revoke connection');

    /* ------------------------------------------------------------- reading */

    const labelFor = (key) => connect.scopes.find((s) => s.key === key)?.label ?? key;
    const spendsFor = (key) => connect.scopes.find((s) => s.key === key)?.spends === true;

    /**
     * How a connection's remaining life reads on its card.
     *
     * An expired connection is shown as expired rather than left to fail
     * quietly at the client end, where the only symptom is a Claude that has
     * stopped being able to see anything and cannot say why.
     */
    const expiry = (client) => {
      if (!client.expires_at) return { tone: '', label: 'No expiry' };
      if (client.expired) {
        return { tone: 'badge--danger', label: `Expired ${relativeTime(client.expires_at)}` };
      }
      const days = Math.ceil((client.expires_at - Date.now() / 1000) / DAY);
      return {
        tone: days <= 7 ? 'badge--warning' : 'badge--outline',
        label: days <= 1 ? 'Expires within a day' : `Expires in ${days} days`,
      };
    };

    /* ------------------------------------------------------------- copying */

    const command = computed(() => (fresh.value
      ? `claude mcp add --transport http courseforge ${connect.url} --header "Authorization: Bearer ${fresh.value.token}"`
      : ''));

    /** The same endpoint with the token in the URL, for clients that take only a URL. */
    const urlWithToken = computed(() => (fresh.value
      ? `${connect.url}?token=${encodeURIComponent(fresh.value.token)}`
      : ''));

    const copy = async (text, what) => {
      try {
        await navigator.clipboard.writeText(text);
        toast.success(`${what} copied.`);
      } catch {
        // A page served over plain http has no clipboard API; the text is on
        // screen and selectable, so this is a nudge rather than a failure.
        toast.info('Copying is blocked here - select the text and copy it by hand.');
      }
    };

    return {
      connect, loading, busy, draft, chosen, fresh, freshPanel, editing, confirmDelete, isAdmin,
      scopeList, grantable, allChosen, selectAll, toggleScope,
      load, create, startEdit, saveEdit, remove,
      labelFor, spendsFor, expiry, command, urlWithToken, copy,
      TTL_CHOICES, relativeTime, formatDate, plural,
    };
  },
  template: `
    <view-header title="Connect" icon="link">
      <template #actions>
        <span class="badge hide-sm">{{ plural(connect.clients.length, 'connection') }}</span>
        <button class="btn btn--ghost btn--icon" title="Reload" @click="load">
          <app-icon name="refresh" :size="15"/>
        </button>
      </template>
    </view-header>

    <div class="view-scroll">
      <div class="view-pad container-narrow col gap-6">

        <!-- what this is ---------------------------------------------- -->
        <section class="card card--pad col gap-3">
          <h3 class="card__title">Let a Claude client work on your courses</h3>
          <p class="hint">
            CourseForge can hand a Claude client the same brief it would send a model itself - the course
            outline, the page's place in it, the content details resolved for that page - and take the
            finished page back. The writing then happens inside Claude, on your own plan, and CourseForge
            never sees an API key. That is still the cheapest way to write a course, and it is what a
            Claude Pro or Max subscription buys you here.
          </p>
          <p class="hint">
            In 4.0 a connection can do far more than that: create courses, start generation runs that carry
            on for a day after you close the client, publish into BookStack, and - if you are an
            administrator - reach accounts, settings and updates. Which of those it may do is decided below,
            once, when you create it.
          </p>
          <p class="hint">
            Create a connection, then paste the line it gives you into a terminal on the machine where you
            use Claude Code. The Claude desktop app takes the URL form instead, under
            <strong>Settings, Connectors, Add custom connector</strong>.
          </p>
          <div class="divider"></div>
          <p class="hint">
            <strong>Endpoint:</strong> <code>{{ connect.url }}</code>
          </p>
          <p class="hint">
            It answers both generations of the protocol, so you do not have to know which one your client
            speaks. Older clients - which is every client installed on a machine today - get the
            <code>initialize</code> handshake they expect; clients on the stateless
            <code>2026-07-28</code> revision get that instead. The tools are identical either way.
          </p>
        </section>

        <!-- the token, once -------------------------------------------- -->
        <section v-if="fresh" ref="freshPanel" class="card card--pad col gap-3"
                 style="border-color:var(--success-line);scroll-margin-top:var(--s-4)">
          <div class="row gap-2">
            <app-icon name="check-circle" :size="16" class="c-success none"/>
            <h3 class="card__title grow">"{{ fresh.client.name }}" is ready</h3>
          </div>
          <p class="hint c-warning">
            Copy it now. Only a hash of this token is stored, so it cannot be shown again - if you lose it,
            revoke this connection and make another.
          </p>

          <div class="row wrap gap-1">
            <template v-if="fresh.client.scopes && fresh.client.scopes.length">
              <span v-for="key in fresh.client.scopes" :key="key" class="chip">
                <app-icon v-if="spendsFor(key)" name="zap" :size="10"/>{{ labelFor(key) }}
              </span>
            </template>
            <span v-else class="chip chip--magic">
              <app-icon name="sparkles" :size="10"/>Everything this account may do
            </span>
            <span v-if="fresh.client.expires_at" class="badge badge--outline">
              until {{ formatDate(fresh.client.expires_at) }}
            </span>
          </div>

          <div class="form-row">
            <label class="row between">
              <span>Claude Code - paste this into a terminal</span>
              <button class="btn btn--ghost btn--sm" @click="copy(command, 'Command')">
                <app-icon name="copy" :size="12"/> copy
              </button>
            </label>
            <pre class="log" style="white-space:pre-wrap;word-break:break-all">{{ command }}</pre>
          </div>

          <div class="form-row">
            <label class="row between">
              <span>Clients that accept only a URL</span>
              <button class="btn btn--ghost btn--sm" @click="copy(urlWithToken, 'URL')">
                <app-icon name="copy" :size="12"/> copy
              </button>
            </label>
            <pre class="log" style="white-space:pre-wrap;word-break:break-all">{{ urlWithToken }}</pre>
            <p class="hint">
              This form carries the secret in the address, which means it is written into browser history
              and into the log of every server and proxy the request passes through. Use the header form
              above wherever the client allows it.
            </p>
          </div>

          <div class="row end">
            <button class="btn btn--sm none" @click="fresh = null">I have copied it</button>
          </div>
        </section>

        <!-- create ------------------------------------------------------ -->
        <section class="card card--pad col gap-4">
          <div>
            <h3 class="card__title">New connection</h3>
            <p class="hint mt-1">
              One per client, so you can revoke the laptop without touching the desktop.
            </p>
          </div>

          <div class="grid grid-2">
            <div class="form-row">
              <label for="conn-name">Name</label>
              <input id="conn-name" v-model="draft.name" maxlength="60" placeholder="Laptop"
                     @keydown.enter="create">
              <p class="hint">How you will recognise it in the list below when you come to revoke it.</p>
            </div>
            <div class="form-row">
              <label for="conn-ttl">Stops working after</label>
              <select id="conn-ttl" v-model.number="draft.ttl">
                <option v-for="choice in TTL_CHOICES" :key="choice.days" :value="choice.days">
                  {{ choice.label }}
                </option>
              </select>
              <p class="hint">
                An expiry cannot be extended later. A connection made for an afternoon should end by itself.
              </p>
            </div>
          </div>

          <div class="form-row">
            <label for="conn-note">Note <span class="faint">(optional)</span></label>
            <input id="conn-note" v-model="draft.note" maxlength="200"
                   placeholder="The work laptop, set up on 3 March">
            <p class="hint">Anything that will still make sense to you in six months. Which machine, whose.</p>
          </div>

          <div class="divider"></div>

          <div class="col gap-3">
            <div class="row wrap between gap-2">
              <div>
                <p class="semi">What this connection may do</p>
                <p class="hint">
                  This cannot be changed afterwards. Widening a connection means revoking it and issuing a
                  new one, which is the whole point: the token you handed out yesterday cannot quietly
                  become more powerful today.
                </p>
              </div>
              <button class="btn btn--sm none" :disabled="allChosen || !grantable.length" @click="selectAll">
                <app-icon name="list-check" :size="13"/> Tick everything
              </button>
            </div>

            <p v-if="connect.scopes_unavailable" class="hint c-danger">
              The list of tool groups could not be read from the server, so there is no way to say what a
              connection would be allowed to do. No token can be issued until that is fixed - the server log
              says why.
            </p>

            <div v-else class="col gap-3">
              <label v-for="scope in scopeList" :key="scope.key" class="check">
                <input type="checkbox" :checked="chosen.has(scope.key)" :disabled="!scope.grantable"
                       @change="toggleScope(scope.key, $event.target.checked)">
                <span class="col gap-1 grow">
                  <span class="row wrap gap-2">
                    <span class="semi">{{ scope.label }}</span>
                    <span v-if="scope.spends" class="badge badge--warning">
                      <app-icon name="zap" :size="10"/> spends money
                    </span>
                    <span v-if="scope.admin" class="badge badge--danger">administrator only</span>
                    <span class="badge badge--outline nums">{{ plural(scope.tools, 'tool') }}</span>
                  </span>
                  <span class="hint">{{ scope.description }}</span>
                  <span v-if="!scope.grantable" class="hint c-warning">
                    Your account is not an administrator, so this group cannot be given to a connection of
                    yours at all - ticking it would grant nothing.
                  </span>
                </span>
              </label>
            </div>

            <!-- Silent while the catalogue is unreadable: the red message above
                 is the whole story then, and "nothing is ticked" would be
                 advice to tick something that is not on screen. -->
            <template v-if="!connect.scopes_unavailable">
              <p v-if="allChosen" class="hint">
                Everything is ticked, which is stored as "whatever this account may do". If your account is
                given or loses administrator rights later, this connection follows.
              </p>
              <p v-else-if="chosen.size" class="hint">
                {{ plural(chosen.size, 'group') }} chosen, and nothing outside them. The one thing every
                connection keeps is the single tool that answers <em>what account am I connected as</em> -
                it gives away nothing the token has not already proved. The rest of
                <strong>This account</strong> - changing your password, revoking your other connections -
                comes only if you tick that group above.
              </p>
              <p v-else class="hint c-danger">
                Nothing is ticked. A connection has to be able to do something, so tick at least one group.
              </p>
            </template>
          </div>

          <div class="row end">
            <button class="btn btn--primary" :disabled="busy || connect.scopes_unavailable || !chosen.size"
                    @click="create">
              <app-icon :name="busy ? 'refresh' : 'plus'" :size="14" :spin="busy"/>
              {{ busy ? 'Creating…' : 'Create connection' }}
            </button>
          </div>
        </section>

        <!-- existing ---------------------------------------------------- -->
        <section class="col gap-3">
          <h3 class="card__title">Connected clients</h3>

          <article v-for="client in connect.clients" :key="client.id"
                   class="card card--flat card--pad col gap-3">
            <div class="row wrap between gap-3">
              <div class="grow" style="min-width:220px">
                <p class="row wrap gap-2">
                  <span class="strong">{{ client.name }}</span>
                  <span v-if="client.expired" class="badge badge--danger">expired</span>
                </p>
                <p v-if="client.note" class="t-xs dim">{{ client.note }}</p>
              </div>
              <div class="row gap-1 none">
                <button class="btn btn--ghost btn--sm" @click="startEdit(client)">
                  <app-icon name="pencil" :size="13"/> Rename
                </button>
                <button class="btn btn--ghost btn--sm" @click="confirmDelete = client">
                  <app-icon name="trash" :size="13"/> Revoke
                </button>
              </div>
            </div>

            <div class="row wrap gap-1">
              <template v-if="client.scopes && client.scopes.length">
                <span v-for="key in client.scopes" :key="key" class="chip">
                  <app-icon v-if="spendsFor(key)" name="zap" :size="10"/>{{ labelFor(key) }}
                </span>
              </template>
              <span v-else class="chip chip--magic">
                <app-icon name="sparkles" :size="10"/>Everything this account may do
              </span>
              <span class="badge none" :class="expiry(client).tone">{{ expiry(client).label }}</span>
            </div>

            <p class="t-xs dim">
              Added {{ relativeTime(client.created_at) }}
              <template v-if="client.last_used_at">
                · last used {{ relativeTime(client.last_used_at) }} ·
                <span class="nums">{{ plural(client.uses, 'call') }}</span>
              </template>
              <template v-else> · never used</template>
              <template v-if="client.expires_at">
                · expiry {{ formatDate(client.expires_at) }}
              </template>
            </p>

            <p v-if="client.expired" class="hint c-warning">
              This connection has passed its expiry date, so the endpoint now refuses it. The client at the
              other end will report that it can no longer see anything. Revoke it and make a new one.
            </p>
          </article>

          <empty-state v-if="!loading && !connect.clients.length" icon="link" title="Nothing connected yet"
                       hint="Create a connection above and paste the line it gives you into your Claude client."/>
        </section>

        <p v-if="!connect.enabled" class="hint c-danger">
          The MCP endpoint is switched off for this installation, so every one of these connections will be
          refused. An administrator can turn it back on under Settings.
        </p>
      </div>
    </div>

    <app-modal v-if="editing" title="Rename this connection" icon="pencil" @close="editing = null">
      <div class="col gap-4">
        <p class="hint">
          Only the name and the note change. The token keeps working, and what the connection may do stays
          exactly as it was - that was fixed when it was created.
        </p>
        <div class="form-row">
          <label for="edit-name">Name</label>
          <input id="edit-name" v-model="editing.name" maxlength="60" @keydown.enter="saveEdit">
        </div>
        <div class="form-row">
          <label for="edit-note">Note</label>
          <input id="edit-note" v-model="editing.note" maxlength="200"
                 placeholder="Which machine this is on" @keydown.enter="saveEdit">
        </div>
      </div>
      <template #footer>
        <button class="btn" @click="editing = null">Cancel</button>
        <button class="btn btn--primary" :disabled="busy" @click="saveEdit">
          <app-icon name="save" :size="14"/> Save
        </button>
      </template>
    </app-modal>

    <app-modal v-if="confirmDelete" title="Revoke this connection?" icon="alert" @close="confirmDelete = null">
      <p class="t-sm">
        <strong>{{ confirmDelete.name }}</strong> stops working immediately, on every machine it was pasted
        into. Anything it has already written stays where it is.
      </p>
      <p class="hint mt-2">There is no way to bring the same token back - you would issue a new one.</p>
      <template #footer>
        <button class="btn" @click="confirmDelete = null">Cancel</button>
        <button class="btn btn--danger" @click="remove"><app-icon name="trash" :size="14"/> Revoke</button>
      </template>
    </app-modal>`,
};

export default ConnectView;
