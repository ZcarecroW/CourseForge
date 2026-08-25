/**
 * Accounts - who may sign in to this installation, and what happens to their
 * work when they no longer may.
 *
 * An administrator comes here for one of four reasons: somebody new needs a way
 * in, somebody has forgotten their password, somebody should stop being able to
 * sign in, or somebody has left. The first three are small and reversible. The
 * fourth is not, which is why deleting an account is the one thing on this
 * screen that is written at length: the dialog counts what that account owns,
 * makes the administrator say out loud whether those courses should be handed
 * to somebody else or destroyed, and asks for the user name to be typed before
 * it will destroy anything.
 *
 * Two secrets are shown here exactly once each - a generated password and an
 * invite code. Neither is stored in a form anything can read back, so the card
 * that shows one says plainly that this is the only time it will be on screen.
 * That is not an inconvenience to apologise for: it is the reason a copy of the
 * database is not a copy of everybody's access.
 *
 * Rules the server owns and this screen deliberately does not repeat: the last
 * enabled administrator cannot be deleted, disabled or demoted. Working that
 * out in the browser would mean two implementations of one rule, and the one in
 * the browser would be the one that drifted. So the client asks, and shows
 * whatever the server says back.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { state, loadUsers, minPassword } from '@/core/store.js';
import { post, put, del } from '@/core/api.js';
import { toast, attempt } from '@/core/toast.js';
import { useFuzzy } from '@/core/fuzzy.js';
import { formatDateTime, relativeTime, plural } from '@/core/format.js';

import AppIcon from '@/components/AppIcon.js';
import AppModal from '@/components/AppModal.js';
import EmptyState from '@/components/EmptyState.js';
import ViewHeader from '@/components/ViewHeader.js';

/**
 * The alphabet a suggested password is drawn from - no character that can be
 * mistaken for another when it is read aloud or copied off a screen. It matches
 * the one the server uses when it generates a password itself, so a password
 * handed over by an administrator looks like one the installation made.
 */
const ALPHABET = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

/** Four groups of four: long enough to be safe, short enough to read out. */
function suggestPassword() {
  const numbers = crypto.getRandomValues(new Uint32Array(16));
  const letters = [...numbers].map((n) => ALPHABET[n % ALPHABET.length]);
  return [0, 4, 8, 12].map((at) => letters.slice(at, at + 4).join('')).join('-');
}

/** How long an invite stays usable, offered as whole hours and days. */
const TTL_CHOICES = [
  { hours: 1, label: '1 hour' },
  { hours: 8, label: '8 hours' },
  { hours: 24, label: '1 day' },
  { hours: 48, label: '2 days' },
  { hours: 168, label: '1 week' },
  { hours: 720, label: '30 days' },
];

export const UsersView = {
  name: 'UsersView',
  components: { AppIcon, AppModal, EmptyState, ViewHeader },
  setup() {
    const loading = ref(true);
    const busy = ref(false);
    const search = ref('');

    /** The new-account form. An empty password means "generate one for me". */
    const draft = reactive({
      username: '', displayName: '', role: 'user', password: '', mustChange: true,
    });

    /** The invite form. */
    const inviteDraft = reactive({ role: 'user', ttl: 48 });

    /**
     * The two things that are readable once and never again. They are held here
     * rather than in the store because they must not survive leaving the screen
     * - a password left on a card behind a tab someone forgot about is exactly
     * the accident the once-only rule exists to prevent.
     */
    const fresh = reactive({ password: null, invite: null });

    const passwordFor = ref(null);
    const passwordDraft = reactive({ value: '', mustChange: true });

    const deleting = ref(null);
    const deleteChoice = ref('transfer');
    const transferTo = ref('');
    const typedName = ref('');

    /* ------------------------------------------------------------ loading */

    const reload = () => attempt(async () => {
      await loadUsers();
      loading.value = false;
    }, 'Load accounts');

    onMounted(reload);

    /**
     * Every write here is followed by a full reload rather than by merging the
     * response into the list. The responses carry an account as the server
     * stores it, which has no ownership counts and no "this is you" marker on
     * it, so merging one would quietly blank the two columns this screen is
     * actually about.
     *
     * It is also the re-entrancy guard for the whole screen. Every button here
     * carries `:disabled="busy"`, but that is the sign of the guard rather than
     * the guard itself: Vue writes the attribute on the next tick, so three
     * clicks landing in one tick all see an enabled button. Issuing an invite
     * three times that way really did issue three, each one cancelling the last.
     * Nothing on this screen is worth doing twice at once, so a second call
     * while one is in flight is dropped here.
     */
    const run = (action, label) => attempt(async () => {
      if (busy.value) return;
      busy.value = true;
      try {
        const result = await action();
        await loadUsers();
        return result;
      } finally {
        busy.value = false;
      }
    }, label);

    /* -------------------------------------------------------------- the list */

    const roles = computed(() => (state.userRoles.length
      ? state.userRoles
      : [{ key: 'user', label: 'User', hint: '' }, { key: 'admin', label: 'Administrator', hint: '' }]));

    const roleLabel = (key) => roles.value.find((r) => r.key === key)?.label ?? key;

    // Fuzzy, like every other list in the application: a display name and a
    // user name are exactly the pair somebody half-remembers, and a substring
    // match fails on a transposed letter or the two words the other way round.
    const visible = useFuzzy(computed(() => state.users), search, {
      keys: ['username', 'display_name'],
    });

    /** What one account owns, as a list of phrases - empty when it owns nothing. */
    const owns = (user) => {
      const content = user.content ?? {};
      return [
        [content.courses, 'course'],
        [content.pages, 'page'],
        [content.profiles, 'profile'],
        [content.tags, 'tag'],
        [content.connections, 'connection'],
      ]
        .filter(([count]) => Number(count) > 0)
        .map(([count, noun]) => plural(Number(count), noun));
    };

    const ownsAnything = (user) => owns(user).length > 0;

    /* ------------------------------------------------------------- creating */

    const canCreate = computed(() =>
      draft.username.trim() !== ''
      && (draft.password === '' || draft.password.length >= minPassword.value));

    const create = () => run(async () => {
      const data = await post('admin/users', {
        username: draft.username.trim(),
        display_name: draft.displayName.trim(),
        role: draft.role,
        password: draft.password,
        // A password nobody chose has to be replaced by one somebody did, so
        // an account created with a generated password always owes a change.
        must_change_password: draft.password === '' ? true : draft.mustChange,
      });

      if (data.password) {
        fresh.password = { username: data.user.username, password: data.password };
      } else {
        toast.success(`"${data.user.username}" can now sign in with the password you set.`);
      }

      draft.username = '';
      draft.displayName = '';
      draft.password = '';
      draft.role = 'user';
      draft.mustChange = true;
    }, 'Create account');

    /* -------------------------------------------------------------- editing */

    /**
     * The select is bound with :value and @change rather than v-model: when the
     * server refuses a change - the last administrator demoting themselves -
     * the reload puts the old role back, and a v-model would have kept the new
     * one on screen next to a message saying it did not happen.
     */
    const changeRole = (user, role) => run(async () => {
      if (role === user.role) return;
      await put(`admin/users/${user.id}`, { role });
      toast.success(`${user.display_name} is now ${roleLabel(role).toLowerCase()}.`);
    }, 'Change role');

    const setDisabled = (user, disabled) => run(async () => {
      await put(`admin/users/${user.id}`, { disabled });
      toast.success(disabled
        ? `${user.display_name} can no longer sign in. Their courses are untouched.`
        : `${user.display_name} can sign in again.`);
    }, disabled ? 'Disable account' : 'Enable account');

    /* ------------------------------------------------------------- password */

    const openPassword = (user) => {
      passwordFor.value = user;
      passwordDraft.value = '';
      passwordDraft.mustChange = true;
    };

    const savePassword = () => run(async () => {
      const user = passwordFor.value;
      if (!user) return;
      await put(`admin/users/${user.id}`, {
        password: passwordDraft.value,
        must_change_password: passwordDraft.mustChange,
      });
      passwordFor.value = null;
      // A password shown on the card above no longer works, so the card has to go.
      if (fresh.password?.username === user.username) fresh.password = null;
      toast.success(`The password for "${user.username}" has been changed. Pass it on now - it is not stored anywhere you can read.`);
    }, 'Set password');

    /* --------------------------------------------------------------- delete */

    const openDelete = (user) => {
      deleting.value = user;
      deleteChoice.value = 'transfer';
      typedName.value = '';
      // The signed-in administrator is the safest default owner: they are here,
      // and the server would pick them anyway if no account were named.
      const me = state.users.find((u) => u.is_you && u.id !== user.id);
      const other = state.users.find((u) => u.id !== user.id);
      transferTo.value = me?.username ?? other?.username ?? '';
    };

    const inheritors = computed(() =>
      state.users.filter((u) => deleting.value && u.id !== deleting.value.id));

    /** Typing the user name is asked for only when content is about to be destroyed. */
    const needsTyping = computed(() =>
      deleteChoice.value === 'delete' && deleting.value !== null && ownsAnything(deleting.value));

    const deleteReady = computed(() => {
      if (!deleting.value) return false;
      // An account that owns nothing is never asked what should happen to its
      // work, so there is nothing for this to wait for.
      if (!ownsAnything(deleting.value)) return true;
      if (deleteChoice.value === 'transfer') return transferTo.value !== '';
      return !needsTyping.value
        || typedName.value.trim().toLowerCase() === deleting.value.username.toLowerCase();
    });

    const confirmDelete = () => run(async () => {
      const user = deleting.value;
      if (!user) return;
      // Read before the row goes: the dialog says something different about an
      // account that owned nothing, and so should the message afterwards.
      const owned = ownsAnything(user);
      await del(`admin/users/${user.id}`, {
        content: deleteChoice.value,
        transfer_to: deleteChoice.value === 'transfer' ? transferTo.value : '',
      });
      deleting.value = null;
      // The card above would otherwise go on offering a password for an account
      // that no longer exists.
      if (fresh.password?.username === user.username) fresh.password = null;

      if (!owned) {
        toast.success(`"${user.username}" has been removed. It owned nothing, so nothing changed hands.`);
      } else {
        toast.success(deleteChoice.value === 'transfer'
          ? `"${user.username}" has been removed. Everything it owned now belongs to ${transferTo.value}.`
          : `"${user.username}" and everything it owned have been deleted.`);
      }
    }, 'Delete account');

    /* --------------------------------------------------------------- invite */

    const issueInvite = () => run(async () => {
      const data = await post('admin/invite', {
        role: inviteDraft.role,
        ttl_hours: inviteDraft.ttl,
      });
      fresh.invite = data.invite;
    }, 'Issue invite');

    /* --------------------------------------------------------------- copying */

    /**
     * Which copy button has just worked, so the button itself can say so.
     *
     * The things copied here are shown exactly once - a generated password, an
     * invite code - and somebody who is not sure it reached the clipboard will
     * dismiss the card and find out too late that it did not. A toast in the
     * corner is easy to miss; the button under the cursor is not. Held as the
     * label because the three buttons on this screen copy three different
     * things and only the pressed one should change.
     */
    const copied = ref('');
    let copiedTimer = 0;

    const copy = async (text, what) => {
      try {
        await navigator.clipboard.writeText(text);
        copied.value = what;
        clearTimeout(copiedTimer);
        copiedTimer = setTimeout(() => { copied.value = ''; }, 2000);
        toast.success(`${what} copied.`);
      } catch {
        // A page served over plain http has no clipboard API. The text is on
        // screen and selectable, so this is a nudge rather than a failure.
        copied.value = '';
        toast.info('Copying is blocked here - select the text and copy it manually.');
      }
    };

    return {
      state, loading, busy, search, minPassword,
      draft, canCreate, create, suggestPassword,
      roles, roleLabel, visible, owns, ownsAnything,
      changeRole, setDisabled,
      passwordFor, passwordDraft, openPassword, savePassword,
      deleting, deleteChoice, transferTo, typedName, inheritors, needsTyping, deleteReady,
      openDelete, confirmDelete,
      inviteDraft, issueInvite, TTL_CHOICES,
      fresh, copy, copied, reload,
      formatDateTime, relativeTime, plural,
    };
  },
  template: `
    <view-header title="Accounts" icon="users">
      <template #actions>
        <span class="badge hide-sm">{{ plural(state.users.length, 'account') }}</span>
        <button class="btn btn--ghost btn--icon" title="Reload" aria-label="Reload the accounts"
                :disabled="busy" @click="reload">
          <app-icon name="refresh" :size="15" :spin="busy"/>
        </button>
      </template>
    </view-header>

    <div class="view-scroll">
      <div class="view-pad container-narrow col gap-6">

        <!-- the generated password, once ------------------------------------ -->
        <section v-if="fresh.password" class="card card--pad col gap-3" style="border-color:var(--success)">
          <div class="row gap-2">
            <app-icon name="check-circle" :size="16" class="c-success none"/>
            <h2 class="t-md grow">"{{ fresh.password.username }}" has been created</h2>
          </div>
          <p class="hint c-warning">
            Write this down or send it now. Only a hash of it is kept, so it cannot be shown again - if it is
            lost, come back and set a new one.
          </p>

          <div class="form-row">
            <label class="row between">
              <span>Password for {{ fresh.password.username }}</span>
              <button class="btn btn--ghost btn--sm" @click="copy(fresh.password.password, 'Password')">
                <app-icon :name="copied === 'Password' ? 'check' : 'copy'" :size="12"/>
                {{ copied === 'Password' ? 'copied' : 'copy' }}
              </button>
            </label>
            <pre class="log" style="white-space:pre-wrap;word-break:break-all">{{ fresh.password.password }}</pre>
          </div>

          <p class="hint">
            The account has to replace it with one of its own the first time it signs in, so nobody but the
            person using it ever knows the password it settles on - including you.
          </p>

          <button class="btn btn--sm none" @click="fresh.password = null">I have passed it on</button>
        </section>

        <!-- the invite code, once ------------------------------------------- -->
        <section v-if="fresh.invite" class="card card--pad col gap-3" style="border-color:var(--accent)">
          <div class="row gap-2">
            <app-icon name="check-circle" :size="16" class="c-accent none"/>
            <h2 class="t-md grow">An invite is open</h2>
          </div>
          <p class="hint c-warning">
            This code is shown here once. It is also in the file below on the server, until it is used.
          </p>

          <div class="form-row">
            <label class="row between">
              <span>Invite code ({{ roleLabel(fresh.invite.role) }})</span>
              <button class="btn btn--ghost btn--sm" @click="copy(fresh.invite.code, 'Invite code')">
                <app-icon :name="copied === 'Invite code' ? 'check' : 'copy'" :size="12"/>
                {{ copied === 'Invite code' ? 'copied' : 'copy' }}
              </button>
            </label>
            <pre class="log" style="white-space:pre-wrap;word-break:break-all">{{ fresh.invite.code }}</pre>
          </div>

          <div class="form-row">
            <label class="row between">
              <span>Written to</span>
              <button class="btn btn--ghost btn--sm" @click="copy(fresh.invite.path, 'Path')">
                <app-icon :name="copied === 'Path' ? 'check' : 'copy'" :size="12"/>
                {{ copied === 'Path' ? 'copied' : 'copy' }}
              </button>
            </label>
            <pre class="log" style="white-space:pre-wrap;word-break:break-all">{{ fresh.invite.path }}</pre>
          </div>

          <p class="hint">
            <template v-if="fresh.invite.expires_at">
              It stops working on {{ formatDateTime(fresh.invite.expires_at) }}, or the moment somebody uses it -
              whichever comes first.
            </template>
            <template v-else>It does not expire, but it can only be used once.</template>
          </p>

          <button class="btn btn--sm none" @click="fresh.invite = null">I have passed it on</button>
        </section>

        <!-- who can sign in --------------------------------------------------- -->
        <section class="card" style="overflow:hidden">
          <div class="card__head">
            <h2 class="card__title grow">Who can sign in</h2>
            <input v-if="state.users.length > 6" v-model="search" class="none" style="max-width:220px"
                   placeholder="Search accounts…" spellcheck="false">
          </div>

          <div v-if="visible.length" class="scroll-x">
            <table class="table">
              <thead>
                <tr>
                  <th>Account</th>
                  <th style="width:160px">Role</th>
                  <th style="width:150px">State</th>
                  <th style="width:150px">Last signed in</th>
                  <th>Owns</th>
                  <th style="width:150px"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="user in visible" :key="user.id">
                  <td>
                    <div class="row gap-2">
                      <span class="strong truncate">{{ user.display_name }}</span>
                      <span v-if="user.is_you" class="badge badge--accent none">you</span>
                    </div>
                    <p class="t-xs faint mono truncate">{{ user.username }}</p>
                  </td>

                  <td>
                    <!-- The minimum width is what keeps this column readable on a
                         phone. Without it the table shrinks to the screen and the
                         select collapses to a bare chevron, so an administrator
                         and a user look identical. With it the table is wider than
                         the screen and scrolls sideways in its own container,
                         which is the trade this table already makes elsewhere. -->
                    <select :value="user.role" :disabled="busy || user.is_you" style="min-width:140px"
                            :title="user.is_you ? 'You cannot take your own administrator rights away.' : ''"
                            @change="changeRole(user, $event.target.value)">
                      <option v-for="role in roles" :key="role.key" :value="role.key">{{ role.label }}</option>
                    </select>
                  </td>

                  <td>
                    <span v-if="user.disabled" class="badge badge--danger">Disabled</span>
                    <span v-else-if="user.must_change_password" class="badge badge--warning"
                          title="They will be asked to choose their own password before they can do anything else.">
                      Owes a password
                    </span>
                    <span v-else class="badge badge--success">Active</span>
                  </td>

                  <td class="t-xs dim">
                    <span :title="user.last_login_at ? formatDateTime(user.last_login_at) : ''">
                      {{ user.last_login_at ? relativeTime(user.last_login_at) : 'never' }}
                    </span>
                  </td>

                  <td class="t-xs dim">
                    <span v-if="owns(user).length">{{ owns(user).join(' · ') }}</span>
                    <span v-else class="faint">nothing yet</span>
                  </td>

                  <td>
                    <!-- These four carry no text, so each needs a name of its
                         own. The title says what the button does and why it may
                         be refused; the label names the account it would do it
                         to, which a row read out on its own does not otherwise
                         say. -->
                    <div class="row gap-1 end">
                      <button class="btn btn--ghost btn--sm btn--icon" title="Set a password for this account"
                              :aria-label="'Set a password for ' + user.username"
                              :disabled="busy" @click="openPassword(user)">
                        <app-icon name="cog" :size="13"/>
                      </button>
                      <button v-if="user.disabled" class="btn btn--ghost btn--sm btn--icon"
                              title="Let this account sign in again"
                              :aria-label="'Let ' + user.username + ' sign in again'" :disabled="busy"
                              @click="setDisabled(user, false)">
                        <app-icon name="eye" :size="13"/>
                      </button>
                      <button v-else class="btn btn--ghost btn--sm btn--icon"
                              :title="user.is_you ? 'You cannot disable the account you are signed in with.' : 'Stop this account signing in, without deleting anything'"
                              :aria-label="'Stop ' + user.username + ' signing in'"
                              :disabled="busy || user.is_you" @click="setDisabled(user, true)">
                        <app-icon name="eye-off" :size="13"/>
                      </button>
                      <button class="btn btn--ghost btn--sm btn--icon"
                              :title="user.is_you ? 'You cannot delete the account you are signed in with.' : 'Delete this account'"
                              :aria-label="'Delete ' + user.username"
                              :disabled="busy || user.is_you" @click="openDelete(user)">
                        <app-icon name="trash" :size="13"/>
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div v-else class="card__body">
            <empty-state v-if="loading" icon="users" title="Reading the accounts…"/>
            <empty-state v-else-if="state.users.length" icon="search" title="No account matches that search"/>
            <empty-state v-else icon="users" title="No accounts"
                         hint="This should not be possible - you are signed in. Reload the screen."/>
          </div>
        </section>

        <!-- add somebody ------------------------------------------------------ -->
        <section class="card card--pad col gap-4">
          <div>
            <h2 class="t-md">Add an account</h2>
            <p class="hint mt-1">
              Creates the account straight away and gives you a password to pass on. If you would rather the
              person chose their own password without it ever going through you, send them an invite instead.
            </p>
          </div>

          <div class="grid grid-2 gap-3">
            <div class="form-row">
              <label for="new-username">User name</label>
              <input id="new-username" v-model="draft.username" autocomplete="off" spellcheck="false"
                     placeholder="jhalliwell" @keydown.enter="canCreate && create()">
              <p class="hint">What they type to sign in. It cannot be changed afterwards.</p>
            </div>

            <div class="form-row">
              <label for="new-display">Display name <span class="faint">(optional)</span></label>
              <input id="new-display" v-model="draft.displayName" autocomplete="off"
                     placeholder="Jo Halliwell" @keydown.enter="canCreate && create()">
              <p class="hint">Shown in the sidebar and against everything they create. Defaults to the user name.</p>
            </div>

            <div class="form-row">
              <label for="new-role">Role</label>
              <select id="new-role" v-model="draft.role">
                <option v-for="role in roles" :key="role.key" :value="role.key">{{ role.label }}</option>
              </select>
              <p class="hint">{{ roles.find((r) => r.key === draft.role)?.hint }}</p>
            </div>

            <div class="form-row">
              <label class="row between" for="new-password">
                <span>Password <span class="faint">(optional)</span></span>
                <button class="btn btn--ghost btn--sm" @click="draft.password = suggestPassword()">
                  <app-icon name="refresh" :size="12"/> suggest one
                </button>
              </label>
              <input id="new-password" v-model="draft.password" class="mono" autocomplete="off"
                     spellcheck="false" placeholder="leave empty and one will be made for you"
                     @keydown.enter="canCreate && create()">
              <p v-if="draft.password && draft.password.length < minPassword" class="hint c-danger">
                At least {{ minPassword }} characters. This one has {{ draft.password.length }}.
              </p>
              <p v-else class="hint">
                Shown here rather than hidden, because you have to be able to read it out. Leave it empty and
                CourseForge will make one and show it to you once.
              </p>
            </div>
          </div>

          <label class="check">
            <input type="checkbox" v-model="draft.mustChange" :disabled="draft.password === ''">
            <span>
              Make them choose their own password the first time they sign in
              <span v-if="draft.password === ''" class="hint" style="display:block">
                Always on for a password CourseForge generated - nobody has chosen it, so it has to be replaced.
              </span>
            </span>
          </label>

          <div class="row end">
            <button class="btn btn--primary none" :disabled="busy || !canCreate" @click="create">
              <app-icon name="plus" :size="14"/> Create account
            </button>
          </div>
        </section>

        <!-- invite ------------------------------------------------------------- -->
        <section class="card card--pad col gap-4">
          <div>
            <h2 class="t-md">Invite somebody</h2>
            <p class="hint mt-1">
              An invite is a one-off code that lets a person create their own account, choosing their own
              password, so it never passes through you or through a chat window. CourseForge writes the code
              to a file on the server as well as showing it here.
            </p>
            <p class="hint mt-2">
              Whoever you send it to types it on the sign-in screen, under
              <span class="semi">I have an invite code</span>. The account it makes is the role you choose
              below and nothing else - the code carries that with it, and its holder is never asked.
            </p>
            <p class="hint mt-2">
              An invite grants nothing that reading files on this server did not already grant, which is why
              an administrator is allowed to issue one. It is not a way back in when every administrator
              password has been lost, though: issuing one needs an administrator session, which is precisely
              what is missing then. That case is repaired from the database - delete the rows in the
              <span class="mono">users</span> table, and the first-run screen and a fresh code come back.
            </p>
          </div>

          <div v-if="state.invite && state.invite.open" class="card card--flat card--pad col gap-2">
            <div class="row gap-2">
              <app-icon name="alert" :size="14" class="c-warning none"/>
              <p class="t-sm grow">
                An invite for {{ roleLabel(state.invite.role).toLowerCase() }} is already open.
              </p>
            </div>
            <p class="hint">
              Its code is in <span class="mono">{{ state.invite.path }}</span
              ><template v-if="state.invite.file_present === false"> - except that the file is no longer
              there, so the code cannot be read by anybody, including you</template>.
              <template v-if="state.invite.expires_at">
                It stops working on {{ formatDateTime(state.invite.expires_at) }}.
              </template>
              <template v-else>It does not expire.</template>
              Issuing a new one below cancels it.
            </p>
          </div>

          <div class="row wrap gap-3 items-end">
            <div class="form-row none" style="min-width:200px">
              <label for="invite-role">The account it creates will be</label>
              <select id="invite-role" v-model="inviteDraft.role">
                <option v-for="role in roles" :key="role.key" :value="role.key">{{ role.label }}</option>
              </select>
            </div>
            <div class="form-row none" style="min-width:160px">
              <label for="invite-ttl">Usable for</label>
              <select id="invite-ttl" v-model.number="inviteDraft.ttl">
                <option v-for="choice in TTL_CHOICES" :key="choice.hours" :value="choice.hours">
                  {{ choice.label }}
                </option>
              </select>
            </div>
            <button class="btn none push" :disabled="busy" @click="issueInvite">
              <app-icon name="link" :size="14"/> Issue an invite
            </button>
          </div>

          <p class="hint">
            Only one invite is ever open at a time, it works for exactly one account, and it is deleted the
            moment that account is created.
          </p>
        </section>
      </div>
    </div>

    <!-- set somebody's password ------------------------------------------------ -->
    <app-modal v-if="passwordFor" :title="'Set a password for ' + passwordFor.username" icon="user"
               @close="passwordFor = null">
      <div class="col gap-4">
        <p class="t-sm">
          This replaces whatever <strong>{{ passwordFor.display_name }}</strong> is using now. Their courses,
          profiles and tags are not touched, and a browser tab they have open is not signed out.
        </p>
        <p class="t-sm">
          Every MCP connection they made before now stops working, because a reset is how somebody who has held
          this password is cut off. They can make new ones from the Connect screen once they have signed in.
        </p>

        <div class="form-row">
          <label class="row between" for="set-password">
            <span>New password</span>
            <button class="btn btn--ghost btn--sm" @click="passwordDraft.value = suggestPassword()">
              <app-icon name="refresh" :size="12"/> suggest one
            </button>
          </label>
          <input id="set-password" v-model="passwordDraft.value" class="mono" autocomplete="off"
                 spellcheck="false" @keydown.enter="passwordDraft.value.length >= minPassword && savePassword()">
          <p v-if="passwordDraft.value && passwordDraft.value.length < minPassword" class="hint c-danger">
            At least {{ minPassword }} characters. This one has {{ passwordDraft.value.length }}.
          </p>
          <p v-else class="hint">
            At least {{ minPassword }} characters. It is on screen because you have to pass it on - once this
            dialog closes, nothing can read it back.
          </p>
        </div>

        <label class="check">
          <input type="checkbox" v-model="passwordDraft.mustChange">
          <span>
            Make them choose their own the next time they sign in
            <span class="hint" style="display:block">
              Leave this on unless you are resetting your own second account. A password you know is one they
              have not chosen.
            </span>
          </span>
        </label>
      </div>

      <template #footer>
        <button class="btn" @click="passwordFor = null">Cancel</button>
        <button class="btn btn--primary" :disabled="busy || passwordDraft.value.length < minPassword"
                @click="savePassword">
          Set password
        </button>
      </template>
    </app-modal>

    <!-- delete an account ------------------------------------------------------ -->
    <app-modal v-if="deleting" :title="'Delete ' + deleting.username + '?'" icon="alert" wide
               @close="deleting = null">
      <div class="col gap-4">
        <p class="t-sm">
          <strong>{{ deleting.display_name }}</strong> will stop existing. They will not be able to sign in,
          and their name will no longer appear anywhere in CourseForge. This cannot be undone.
        </p>

        <div v-if="ownsAnything(deleting)" class="col gap-2">
          <p class="eyebrow">What this account owns</p>
          <div class="grid gap-2" style="grid-template-columns:repeat(auto-fit,minmax(88px,1fr))">
            <div class="stat"><div class="stat__value">{{ deleting.content.courses }}</div><div class="stat__label">Courses</div></div>
            <div class="stat"><div class="stat__value">{{ deleting.content.pages }}</div><div class="stat__label">Pages</div></div>
            <div class="stat"><div class="stat__value">{{ deleting.content.profiles }}</div><div class="stat__label">Profiles</div></div>
            <div class="stat"><div class="stat__value">{{ deleting.content.tags }}</div><div class="stat__label">Tags</div></div>
            <div class="stat"><div class="stat__value">{{ deleting.content.connections }}</div><div class="stat__label">Connections</div></div>
          </div>
          <p class="hint">Decide what happens to all of it before the account goes.</p>
        </div>

        <p v-else class="hint">
          This account has not written anything yet, so there is no work to save or lose and nothing to
          decide - deleting it removes the account and nothing else.
        </p>

        <!-- The choice below is only a choice when there is something to choose
             between. An account that owns nothing goes either way to the same
             place, and offering the radios anyway contradicts the line above. -->
        <div v-if="ownsAnything(deleting)" class="col gap-3">
          <label class="check">
            <input type="radio" value="transfer" v-model="deleteChoice">
            <span>
              <span class="semi">Hand the work to somebody else</span>
              <span class="hint" style="display:block">
                Every course, profile, tag and connection changes owner. Nothing is lost and nothing is
                republished - the new owner finds all of it in their own lists. This is almost always what you
                want when somebody leaves.
              </span>
            </span>
          </label>

          <div v-if="deleteChoice === 'transfer'" class="form-row" style="padding-left:26px">
            <label for="transfer-to">New owner</label>
            <select id="transfer-to" v-model="transferTo">
              <option v-for="user in inheritors" :key="user.id" :value="user.username">
                {{ user.display_name }} ({{ user.username }})
              </option>
            </select>
            <p class="hint">
              A tag whose name they already use is merged into theirs rather than duplicated.
            </p>
          </div>

          <label class="check">
            <input type="radio" value="delete" v-model="deleteChoice">
            <span>
              <span class="semi c-danger">Delete the work as well</span>
              <span class="hint" style="display:block">
                Every course, chapter, page, profile, tag and connection this account owns is removed from the
                database. Pages already pushed to BookStack stay where they are; nothing else does. There is no
                undo and no backup.
              </span>
            </span>
          </label>
        </div>

        <div v-if="needsTyping" class="danger-zone">
          <p class="danger-zone__title">Type the user name to confirm</p>
          <p class="t-sm">
            You are about to destroy {{ owns(deleting).join(', ') }}. Type
            <strong class="mono">{{ deleting.username }}</strong> below to say you mean it.
          </p>
          <input v-model="typedName" class="mono" autocomplete="off" spellcheck="false"
                 :placeholder="deleting.username">
        </div>
      </div>

      <template #footer>
        <button class="btn" @click="deleting = null">Keep this account</button>
        <button class="btn btn--danger" :disabled="busy || !deleteReady" @click="confirmDelete">
          <app-icon name="trash" :size="14"/>
          {{ !ownsAnything(deleting)
            ? 'Delete account'
            : (deleteChoice === 'transfer' ? 'Delete account, keep the work' : 'Delete account and all its work') }}
        </button>
      </template>
    </app-modal>`,
};

export default UsersView;
