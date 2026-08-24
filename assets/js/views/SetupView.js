/**
 * First run - the screen that turns a copied directory into an installation.
 *
 * This is the very first thing anybody ever sees of CourseForge, and the person
 * looking at it has just unpacked a folder onto a server and typed the address
 * into a browser. They have read nothing. So the screen explains itself in full:
 * what it is about to create, why it is asking for a code, and exactly where on
 * the server that code was written. Nothing here assumes the documentation was
 * opened first.
 *
 * The code exists because a fresh installation is a public web address with no
 * accounts and a form that makes an administrator. Whoever reaches that form
 * first would own the installation, and a machine that scans for new hosts will
 * always be faster than a person. INVITE-CODE.txt sits on the file system next
 * to the application, so answering with it proves the one thing that matters:
 * you are the person who put these files here.
 *
 * On success the new administrator is signed in by the same request that
 * created them - being asked to log in immediately after choosing a password
 * would be a pointless second step.
 */
import { ref, reactive, computed } from 'vue';
import { state, loadSetup, loadWorkspace, minPassword } from '@/core/store.js';
import { api, setCsrf } from '@/core/api.js';
import { toast } from '@/core/toast.js';
import { resolvedTheme, toggleTheme } from '@/core/theme.js';
import AppIcon from '@/components/AppIcon.js';

/** The code is six groups of four, from an alphabet with no ambiguous letters. */
const GROUPS = 6;
const GROUP_SIZE = 4;
const CODE_LENGTH = GROUPS * GROUP_SIZE;

/**
 * Accepts the code however it was typed - lower case, no hyphens, spaces
 * pasted in from the text file - and gives back the one canonical spelling.
 * The server normalises the same way before comparing, so this only decides
 * what the field looks like while it is being filled in.
 */
function normaliseCode(raw) {
  const bare = String(raw ?? '').toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, CODE_LENGTH);
  return (bare.match(/.{1,4}/g) ?? []).join('-');
}

export const SetupView = {
  name: 'SetupView',
  components: { AppIcon },
  setup() {
    const form = reactive({ code: '', username: '', displayName: '', password: '', confirm: '' });
    const error = ref('');
    const busy = ref(false);
    const revealed = ref(false);

    const info = computed(() => state.setupInfo);
    const codeComplete = computed(() => form.code.replace(/-/g, '').length === CODE_LENGTH);
    const tooShort = computed(() => form.password.length > 0 && form.password.length < minPassword.value);
    const mismatch = computed(() => form.confirm.length > 0 && form.confirm !== form.password);

    const ready = computed(() =>
      codeComplete.value &&
      form.username.trim() !== '' &&
      form.password.length >= minPassword.value &&
      form.confirm === form.password
    );

    /**
     * Vue only writes the element back when the bound value changed, so a
     * character the code cannot hold would stay on screen after being dropped.
     * Writing the element by hand keeps the field showing exactly what will be
     * sent, whatever was typed or pasted into it.
     */
    const onCodeInput = (event) => {
      const element = event.target;
      const tidy = normaliseCode(element.value);
      form.code = tidy;
      if (element.value !== tidy) {
        element.value = tidy;
        element.setSelectionRange(tidy.length, tidy.length);
      }
    };

    const copyPath = async () => {
      try {
        await navigator.clipboard.writeText(info.value.invite_file);
        toast.success('Path copied.');
      } catch {
        // A page served over plain http has no clipboard API. The path is on
        // screen and selectable, so this is a nudge rather than a failure.
        toast.info('Copying is blocked here - select the path and copy it manually.');
      }
    };

    const submit = async () => {
      if (busy.value) return;
      error.value = '';

      // Two checks worth making before the round trip, because the answer is
      // already on screen. Everything else the server decides, and whatever it
      // says is what the person reads.
      if (form.password.length < minPassword.value) {
        error.value = `A password needs at least ${minPassword.value} characters.`;
        return;
      }
      if (form.confirm !== form.password) {
        error.value = 'The two passwords do not match.';
        return;
      }

      busy.value = true;
      try {
        const data = await api('setup', {
          method: 'POST',
          soft: true,
          body: {
            invite_code: form.code,
            username: form.username.trim(),
            display_name: form.displayName.trim(),
            password: form.password,
            password_confirm: form.confirm,
          },
        });
        setCsrf(data?.csrf);

        if (data?.ok) {
          state.user = data.user;
          state.needsSetup = false;
          form.password = '';
          form.confirm = '';
          await loadWorkspace();
          toast.success(`Welcome, ${state.user.display_name}. This installation is yours.`);
          return;
        }

        error.value = data?.error || 'The account could not be created.';

        // Somebody else may have finished setup in the meantime - in which case
        // this screen has no reason to exist any more and the shell should show
        // the sign-in form instead. The message goes to a toast so it survives
        // the swap.
        await loadSetup();
        if (!state.needsSetup) toast.error(error.value);
      } catch (failure) {
        error.value = failure.message;
      } finally {
        busy.value = false;
      }
    };

    return {
      state, form, info, error, busy, revealed, minPassword,
      codeComplete, tooShort, mismatch, ready,
      onCodeInput, copyPath, submit, resolvedTheme, toggleTheme,
    };
  },
  template: `
    <div class="view-scroll" style="height:100%;position:relative">
      <button class="btn btn--ghost btn--icon" style="position:absolute;top:16px;right:16px"
              :title="'Switch to ' + (resolvedTheme === 'dark' ? 'light' : 'dark') + ' theme'" @click="toggleTheme">
        <app-icon :name="resolvedTheme === 'dark' ? 'moon' : 'sun'" :size="17"/>
      </button>

      <div class="view-pad" style="max-width:620px;margin-inline:auto">
        <div class="col gap-2" style="align-items:center;text-align:center;margin-bottom:var(--s-6)">
          <span class="sidebar__mark" style="width:42px;height:42px;border-radius:var(--r-lg)">
            <app-icon name="book" :size="22"/>
          </span>
          <h1 class="t-xl mt-2">Set up {{ state.app.name }}</h1>
          <p class="t-sm dim" style="max-width:52ch">
            There are no accounts on this installation yet. Fill this in once and you will have an
            administrator account, signed in and ready to write courses.
          </p>
        </div>

        <div class="col gap-4">

          <!-- why a code, and where it is ------------------------------- -->
          <section class="card card--pad col gap-3">
            <div class="row gap-2">
              <app-icon name="file-text" :size="16" class="c-accent none"/>
              <h2 class="t-md grow">Find the invite code</h2>
            </div>
            <p class="hint">
              This address is reachable from the internet and has nobody in charge of it, so the form below
              asks for a code that exists only on the server itself. Reading that file proves you are the
              person who installed CourseForge, and stops anyone who merely found the address from claiming it.
            </p>

            <div v-if="info.invite_file" class="form-row">
              <label class="row between">
                <span>Open this file on the server</span>
                <button class="btn btn--ghost btn--sm" @click="copyPath">
                  <app-icon name="copy" :size="12"/> copy
                </button>
              </label>
              <pre class="log" style="white-space:pre-wrap;word-break:break-all">{{ info.invite_file }}</pre>
              <p class="hint">
                It was written the first time this page was opened, and it is deleted the moment the account
                below is created. The web server is configured to refuse it, so it cannot be fetched over http.
              </p>
            </div>

            <p v-else-if="info.error" class="card card--flat t-sm c-danger"
               style="padding:9px 12px;border-color:var(--danger-line)">
              {{ info.error }}
            </p>

            <p v-else class="hint c-warning">
              No invite is currently open. Restart the web server and reload this page - CourseForge writes a
              fresh code whenever it finds an installation with no accounts.
            </p>
          </section>

          <!-- the account ---------------------------------------------- -->
          <form class="card card--pad col gap-4" @submit.prevent="submit">
            <div class="row gap-2">
              <app-icon name="user" :size="16" class="c-accent none"/>
              <h2 class="t-md grow">Create the administrator</h2>
            </div>

            <div class="form-row">
              <label for="setup-code">Invite code</label>
              <input id="setup-code" :value="form.code" @input="onCodeInput"
                     placeholder="XXXX-XXXX-XXXX-XXXX-XXXX-XXXX"
                     spellcheck="false" autocapitalize="characters" autocomplete="off" autofocus
                     style="font-family:var(--mono);letter-spacing:0.06em">
              <p class="hint">
                Six groups of four. Type it with or without the hyphens, in any case - the field tidies
                itself up as you go.
              </p>
            </div>

            <div class="divider"></div>

            <div class="form-row">
              <label for="setup-user">User name</label>
              <input id="setup-user" v-model="form.username" autocomplete="username" spellcheck="false">
              <p class="hint">What you will type to sign in. It cannot be changed later.</p>
            </div>

            <div class="form-row">
              <label for="setup-display">Display name <span class="faint">- optional</span></label>
              <input id="setup-display" v-model="form.displayName" autocomplete="name">
              <p class="hint">
                The name shown in the sidebar and against anything you create. Leave it empty to be called
                by your user name.
              </p>
            </div>

            <div class="form-row">
              <label for="setup-pass">Password</label>
              <div class="row gap-2">
                <input id="setup-pass" v-model="form.password" class="grow"
                       :type="revealed ? 'text' : 'password'" autocomplete="new-password">
                <button type="button" class="btn btn--ghost btn--icon none" @click="revealed = !revealed"
                        :title="revealed ? 'Hide the password' : 'Show the password'">
                  <app-icon :name="revealed ? 'eye-off' : 'eye'" :size="15"/>
                </button>
              </div>
              <p class="hint" :class="{ 'c-warning': tooShort }">
                At least {{ minPassword }} characters. Nobody can reset this for you - there is no second
                account and no e-mail - so use a password manager.
              </p>
            </div>

            <div class="form-row">
              <label for="setup-confirm">Password again</label>
              <input id="setup-confirm" v-model="form.confirm" :type="revealed ? 'text' : 'password'"
                     autocomplete="new-password" @keydown.enter.prevent="submit">
              <p v-if="mismatch" class="hint c-warning">The two passwords do not match yet.</p>
            </div>

            <p v-if="error" class="card card--flat t-sm c-danger"
               style="padding:9px 12px;border-color:var(--danger-line)">{{ error }}</p>

            <button type="submit" class="btn btn--primary btn--block" :disabled="busy || !ready">
              <app-icon v-if="busy" name="refresh" :size="14" spin/>
              {{ busy ? 'Creating the account…' : 'Create account and start' }}
            </button>
          </form>

          <p class="t-2xs faint" style="text-align:center">{{ state.app.name }} v{{ state.app.version }}</p>
        </div>
      </div>
    </div>`,
};

export default SetupView;
