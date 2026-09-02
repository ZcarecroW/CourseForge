/**
 * Signing in - and, for somebody who cannot yet, the way to an account.
 *
 * This is the screen everybody who is not signed in lands on, including the one
 * person here who has no account at all: the holder of an invite code an
 * administrator issued. There is nowhere else for them to land, so the way to
 * the redemption form starts here, under the sign-in button.
 *
 * It is offered only while the server says an invite is actually open. A box
 * asking for a code that could not exist is a dead end dressed up as an
 * option, and on the great majority of installations - which have no invite out
 * at any given moment - the sign-in screen should be a sign-in screen and
 * nothing else.
 */
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { state, inviteOpen, loadWorkspace } from '@/core/store.js';
import { api, setCsrf } from '@/core/api.js';
import { toast } from '@/core/toast.js';
import { resolvedTheme, toggleTheme } from '@/core/theme.js';
import AppIcon from '@/components/AppIcon.js';

export const LoginView = {
  name: 'LoginView',
  components: { AppIcon },
  setup() {
    const username = ref('');
    const password = ref('');
    const error = ref('');
    const busy = ref(false);
    const locked = ref(state.lockedFor || 0);
    const userField = ref(null);
    let timer = null;

    onMounted(() => {
      timer = setInterval(() => { if (locked.value > 0) locked.value -= 1; }, 1000);
      // Not the `autofocus` attribute: a browser honours that for the first
      // such element to enter a document and no other, and this screen can be
      // reached a second time - by coming back from the redemption form.
      userField.value?.focus();
    });
    onBeforeUnmount(() => clearInterval(timer));

    const submit = async () => {
      busy.value = true;
      error.value = '';
      try {
        const data = await api('session', {
          method: 'POST',
          body: { username: username.value, password: password.value },
          soft: true,
        });
        setCsrf(data?.csrf);
        if (data?.ok) {
          state.user = data.user;
          password.value = '';
          // Nothing is fetched for an account that still owes a password
          // change; loadWorkspace() knows, and the shell puts the dialog that
          // fixes it on screen.
          await loadWorkspace();
          toast.success(`Welcome back, ${state.user.display_name}.`);
        } else {
          error.value = data?.error || 'Sign in failed.';
          locked.value = data?.locked_for || 0;
        }
      } catch (e) {
        error.value = e.message;
      } finally {
        busy.value = false;
      }
    };

    return {
      state, inviteOpen, username, password, error, busy, locked, userField, submit,
      resolvedTheme, toggleTheme,
    };
  },
  template: `
    <div style="display:grid;place-items:center;height:100%;padding:var(--s-6);position:relative">
      <button class="btn btn--ghost btn--icon" style="position:absolute;top:16px;right:16px"
              :title="'Switch to ' + (resolvedTheme === 'dark' ? 'light' : 'dark') + ' theme'" @click="toggleTheme">
        <app-icon :name="resolvedTheme === 'dark' ? 'moon' : 'sun'" :size="17"/>
      </button>

      <form class="card card--pad" style="width:100%;max-width:400px" @submit.prevent="submit">
        <div class="col gap-2 center" style="align-items:center;text-align:center;margin-bottom:var(--s-6)">
          <span class="tile tile--lg tile--brand">
            <app-icon name="graduation-cap" :size="24"/>
          </span>
          <h1 style="font-size:var(--t-xl);margin-top:var(--s-2)">{{ state.app.name }}</h1>
          <p class="t-sm dim">AI course generation for BookStack</p>
        </div>

        <div class="col gap-4">
          <div class="form-row">
            <label for="login-user" class="row gap-2"><app-icon name="user" :size="13"/> Username</label>
            <input id="login-user" ref="userField" v-model="username" autocomplete="username" required>
          </div>
          <div class="form-row">
            <label for="login-pass" class="row gap-2"><app-icon name="key" :size="13"/> Password</label>
            <input id="login-pass" v-model="password" type="password" autocomplete="current-password" required>
          </div>

          <p v-if="error" class="note-strip note-strip--danger" role="alert">
            <app-icon name="alert-circle" :size="14" class="c-danger"/><span>{{ error }}</span>
          </p>
          <p v-if="locked > 0" class="t-xs c-warning" style="text-align:center">
            Locked for {{ locked }} more second(s).
          </p>

          <button type="submit" class="btn btn--primary btn--block" :disabled="busy || locked > 0">
            <app-icon :name="busy ? 'refresh' : 'log-in'" :size="14" :spin="busy"/>
            {{ busy ? 'Signing in…' : 'Sign in' }}
          </button>

          <!-- Only while there is something to redeem. type="button" because
               this sits inside the sign-in form and must not submit it. -->
          <template v-if="inviteOpen">
            <div class="divider"></div>
            <div class="col gap-2">
              <p class="hint" style="text-align:center">
                Been sent an invite code? It makes your account here, and you choose the password yourself.
              </p>
              <button type="button" class="btn btn--block" @click="state.redeeming = true">
                <app-icon name="ticket" :size="14"/> I have an invite code
              </button>
            </div>
          </template>
        </div>
      </form>
    </div>`,
};

export default LoginView;
