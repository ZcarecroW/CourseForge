/**
 * Connect — pointing a Claude client at this installation.
 *
 * The whole feature is one screen and three steps: create a connection, copy
 * the line, paste it into Claude. Everything the server knows that the user
 * would otherwise have to work out — the endpoint address, the exact command —
 * is assembled here so nothing has to be typed by hand.
 *
 * The token is shown exactly once, on the card that created it. That is not a
 * limitation to apologise for: it is why a copy of the database is not a copy of
 * the access.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { get, post, del } from '@/core/api.js';
import { toast, attempt } from '@/core/toast.js';
import { relativeTime } from '@/core/format.js';

import AppIcon from '@/components/AppIcon.js';
import AppModal from '@/components/AppModal.js';
import EmptyState from '@/components/EmptyState.js';
import ViewHeader from '@/components/ViewHeader.js';

export const ConnectView = {
  name: 'ConnectView',
  components: { AppIcon, AppModal, EmptyState, ViewHeader },
  setup() {
    const state = reactive({ url: '', clients: [], enabled: true });
    const loading = ref(true);
    const busy = ref(false);
    const name = ref('');
    /** The one and only time this token is readable. */
    const fresh = ref(null);
    const confirmDelete = ref(null);

    const load = () => attempt(async () => {
      const data = await get('connect');
      Object.assign(state, data.connect ?? {});
      loading.value = false;
    }, 'Load connections');

    onMounted(load);

    const create = () => attempt(async () => {
      busy.value = true;
      try {
        const data = await post('connect', { name: name.value.trim() || 'Claude' });
        Object.assign(state, data.connect ?? {});
        fresh.value = { token: data.token, client: data.client };
        name.value = '';
      } finally {
        busy.value = false;
      }
    }, 'Create connection');

    const remove = () => attempt(async () => {
      const target = confirmDelete.value;
      confirmDelete.value = null;
      if (!target) return;
      const data = await del('connect', { client_id: target.id });
      Object.assign(state, data.connect ?? {});
      if (fresh.value?.client?.id === target.id) fresh.value = null;
      toast.success('Connection removed. That token no longer works.');
    }, 'Remove connection');

    /* ------------------------------------------------------------ copying */

    const command = computed(() => fresh.value
      ? `claude mcp add --transport http courseforge ${state.url} --header "Authorization: Bearer ${fresh.value.token}"`
      : '');

    /** The same endpoint with the token in the URL, for clients that take only a URL. */
    const urlWithToken = computed(() => fresh.value
      ? `${state.url}?token=${encodeURIComponent(fresh.value.token)}`
      : '');

    const copy = async (text, what) => {
      try {
        await navigator.clipboard.writeText(text);
        toast.success(`${what} copied.`);
      } catch {
        // A page served over plain http has no clipboard API; the text is on
        // screen and selectable, so this is a nudge rather than a failure.
        toast.info('Copying is blocked here — select the text and copy it manually.');
      }
    };

    return {
      state, loading, busy, name, fresh, confirmDelete,
      create, remove, command, urlWithToken, copy, relativeTime,
    };
  },
  template: `
    <view-header title="Connect" icon="link">
      <template #actions>
        <span class="badge none">{{ state.clients.length }} connection(s)</span>
      </template>
    </view-header>

    <div class="pane">
      <div class="pane__body view-pad">
        <div class="container-narrow col gap-5">

          <section class="card card--pad col gap-3">
            <h3 class="t-md">Let Claude write your courses</h3>
            <p class="hint">
              CourseForge can hand a Claude client the same brief it would send a model itself — the course
              structure, the page's place in it, the content details resolved for it — and take the finished
              page back. The writing then happens inside Claude, on your own plan, and CourseForge never sees
              a key. This is the way to use a Claude Pro or Max subscription with a hosted CourseForge.
            </p>
            <p class="hint">
              Create a connection below, then paste the line into a terminal on the machine where you use
              Claude Code. The Claude desktop app takes the URL form instead, under
              <strong>Settings → Connectors → Add custom connector</strong>.
            </p>
          </section>

          <!-- the token, once ------------------------------------------- -->
          <section v-if="fresh" class="card card--pad col gap-3" style="border-color:var(--success)">
            <div class="row gap-2">
              <app-icon name="check-circle" :size="16" class="c-success"/>
              <h3 class="t-md grow">"{{ fresh.client.name }}" is ready</h3>
            </div>
            <p class="hint c-warning">
              Copy this now. It is stored only as a hash and cannot be shown again — if you lose it,
              remove the connection and make another.
            </p>

            <div class="form-row">
              <label class="row between">
                <span>Claude Code</span>
                <button class="btn btn--ghost btn--sm" @click="copy(command, 'Command')">
                  <app-icon name="copy" :size="12"/> copy
                </button>
              </label>
              <pre class="log" style="white-space:pre-wrap;word-break:break-all">{{ command }}</pre>
            </div>

            <div class="form-row">
              <label class="row between">
                <span>Claude desktop app (URL only)</span>
                <button class="btn btn--ghost btn--sm" @click="copy(urlWithToken, 'URL')">
                  <app-icon name="copy" :size="12"/> copy
                </button>
              </label>
              <pre class="log" style="white-space:pre-wrap;word-break:break-all">{{ urlWithToken }}</pre>
              <p class="hint">
                This form carries the secret in the address, which will appear in browser history and in
                server logs. Prefer the header form above wherever the client allows it.
              </p>
            </div>

            <button class="btn btn--sm none" @click="fresh = null">I have copied it</button>
          </section>

          <!-- create --------------------------------------------------- -->
          <section class="card card--pad col gap-3">
            <h3 class="t-md">New connection</h3>
            <p class="hint">
              One per client, so you can revoke the laptop without touching the desktop.
            </p>
            <div class="row gap-2">
              <input v-model="name" class="grow" placeholder="Laptop" @keyup.enter="create">
              <button class="btn btn--primary" :disabled="busy" @click="create">
                <app-icon :name="busy ? 'refresh' : 'plus'" :size="14" :spin="busy"/> Create
              </button>
            </div>
            <p class="hint">Endpoint: <code>{{ state.url }}</code></p>
          </section>

          <!-- existing -------------------------------------------------- -->
          <section class="col gap-2">
            <h3 class="t-md">Connected clients</h3>
            <div v-for="client in state.clients" :key="client.id" class="card card--flat card--pad row wrap gap-3">
              <div class="grow">
                <p class="strong">{{ client.name }}</p>
                <p class="t-xs dim">
                  added {{ relativeTime(client.created_at) }}
                  <template v-if="client.last_used_at">
                    · last used {{ relativeTime(client.last_used_at) }} · {{ client.uses }} call(s)
                  </template>
                  <template v-else> · never used</template>
                </p>
              </div>
              <button class="btn btn--ghost btn--sm" @click="confirmDelete = client">
                <app-icon name="trash" :size="13"/> Revoke
              </button>
            </div>

            <empty-state v-if="!loading && !state.clients.length" icon="link" title="Nothing connected yet"
                         hint="Create a connection above and paste it into Claude."/>
          </section>

          <p v-if="!state.enabled" class="hint c-danger">
            The MCP endpoint is switched off in data/config.json, so these connections will be refused.
          </p>
        </div>
      </div>
    </div>

    <app-modal v-if="confirmDelete" title="Revoke this connection?" icon="alert" @close="confirmDelete = null">
      <p class="t-sm">
        <strong>{{ confirmDelete.name }}</strong> will stop working immediately. Anything it already wrote stays.
      </p>
      <template #footer>
        <button class="btn" @click="confirmDelete = null">Cancel</button>
        <button class="btn btn--danger" @click="remove"><app-icon name="trash" :size="14"/> Revoke</button>
      </template>
    </app-modal>`,
};

export default ConnectView;
