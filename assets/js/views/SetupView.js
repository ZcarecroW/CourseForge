/**
 * Turning an invite code into an account, with nobody signed in.
 *
 * Two different people arrive here, and they get the same form because they are
 * making the same request - a code, a name, a password:
 *
 *   first run   Somebody has unpacked a folder onto a server and typed the
 *               address into a browser. There are no accounts at all, so there
 *               is nobody who could authorise the creation of the first one.
 *   an invite   An administrator issued a one-off code from the Accounts screen
 *               and passed it to somebody who has no account yet. That person
 *               lands on the sign-in screen and comes here from it.
 *
 * The code exists because a fresh installation is a public web address with no
 * accounts and a form that makes an administrator. Whoever reaches that form
 * first would own the installation, and a machine that scans for new hosts will
 * always be faster than a person. INVITE-CODE.txt sits on the file system next
 * to the application, so answering with it proves the one thing that matters:
 * you are the person who put these files here. An invite issued later is the
 * same mechanism pointed at somebody else - holding the code proves you were
 * sent it and nothing more, which is why the role comes from the invite row and
 * is never asked for on this screen.
 *
 * Whoever sees it has read nothing, so the screen explains itself in full: what
 * it is about to create, why it is asking for a code, and - on a first run -
 * exactly where on the server that code was written. Nothing here assumes the
 * documentation was opened first.
 *
 * On success the new account is signed in by the same request that created it -
 * being asked to log in immediately after choosing a password would be a
 * pointless second step.
 */
import { ref, reactive, computed, onMounted } from 'vue';
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

/** How many characters of the code itself - hyphens and rubbish aside - `text` holds. */
function codeChars(text) {
  return (String(text ?? '').toUpperCase().match(/[A-Z0-9]/g) ?? []).length;
}

/**
 * Where the caret belongs in a normalised code once `count` of its characters
 * stand before it. Hyphens are skipped, because nobody types them.
 */
function caretAfter(tidy, count) {
  if (count <= 0) return 0;
  let seen = 0;
  for (let index = 0; index < tidy.length; index += 1) {
    if (tidy[index] === '-') continue;
    seen += 1;
    if (seen === count) return index + 1;
  }
  return tidy.length;
}

export const SetupView = {
  name: 'SetupView',
  components: { AppIcon },
  props: {
    /**
     * Which of the two doors this is: `setup` for the first run, `redeem` for
     * an invite issued after that.
     *
     * One component rather than two, because the server takes one body for both
     * and the difference is the route it goes to and the words around it. A
     * second screen would have meant a second invite-code field - one that
     * normalises as you type and has a caret to keep in the right group - and
     * the copy of it nobody was looking at would have been the one that drifted.
     */
    mode: { type: String, default: 'setup' },
  },
  setup(props) {
    const redeeming = computed(() => props.mode === 'redeem');

    const form = reactive({ code: '', username: '', displayName: '', password: '', confirm: '' });
    const error = ref('');
    const busy = ref(false);
    const revealed = ref(false);
    /** Set the first time the form is sent, after which every field speaks up. */
    const attempted = ref(false);

    const codeField = ref(null);
    const userField = ref(null);
    const displayField = ref(null);
    const passField = ref(null);
    const confirmField = ref(null);

    const info = computed(() => state.setupInfo);

    /**
     * The caret starts in the invite code: it is the first thing to type and
     * the only thing on this screen that has to be fetched from somewhere else.
     *
     * Done here rather than with the `autofocus` attribute, which a browser
     * honours for the first such element to enter a document and for no other.
     * Somebody arriving with an invite has come through the sign-in form, whose
     * user name field spent it before this screen existed.
     */
    onMounted(() => codeField.value?.focus());

    /**
     * What is wrong with each field, in the words the person needs, or an empty
     * string when nothing is.
     *
     * Everything the screen says about a field is read from here - the line
     * under it, whether it is marked invalid, what the summary above the button
     * says, and which field the cursor is dropped into. One source means the
     * form cannot say two contradictory things about the same field, which is
     * what it used to do.
     */
    const problems = computed(() => {
      const typed = codeChars(form.code);
      const length = form.password.length;
      const where = redeeming.value ? 'from whoever sent you the invite' : 'from the file on the server';
      return {
        code: typed === CODE_LENGTH ? '' : (typed === 0
          ? `Nothing typed yet. Six groups of four, ${where}.`
          : `Six groups of four - ${CODE_LENGTH} characters in all. This one has ${typed}.`),
        username: form.username.trim() === ''
          ? 'Needed - this is what you will type to sign in.'
          : '',
        password: length >= minPassword.value ? '' : (length === 0
          ? `At least ${minPassword.value} characters. This one is empty.`
          : `At least ${minPassword.value} characters. This one has ${length}.`),
        confirm: form.confirm === form.password ? '' : (form.confirm === ''
          ? 'Type the password a second time.'
          : 'This does not match the password above.'),
      };
    });

    /**
     * A field starts explaining itself as soon as it has something in it, and
     * unconditionally once the form has been sent - so a half-typed code counts
     * itself up while you work, and an empty one only complains once you have
     * asked for the account.
     */
    const started = computed(() => ({
      code: form.code !== '',
      username: form.username !== '',
      password: form.password !== '',
      confirm: form.confirm !== '',
    }));

    const shows = (key) => problems.value[key] !== '' && (attempted.value || started.value[key]);

    /** In the order they appear, which is the order to be sent to them. */
    const checks = [
      { key: 'code', label: 'Invite code', field: codeField },
      { key: 'username', label: 'User name', field: userField },
      { key: 'password', label: 'Password', field: passField },
      { key: 'confirm', label: 'Password again', field: confirmField },
    ];

    /**
     * Vue only writes the element back when the bound value changed, so a
     * character the code cannot hold would stay on screen after being dropped.
     * Writing the element by hand keeps the field showing exactly what will be
     * sent, whatever was typed or pasted into it.
     *
     * That rewrite renumbers every position in the field, so a caret left where
     * it was would land in a different group - which is why correcting one
     * character in the middle of a 24-character code used to be impossible.
     * What survives the rewrite is how many code characters stand before the
     * caret, the hyphens being decoration this function puts back, so that
     * count is measured before and restored afterwards.
     */
    const onCodeInput = (event) => {
      const element = event.target;
      const raw = element.value;
      const before = codeChars(raw.slice(0, element.selectionStart ?? raw.length));
      const tidy = normaliseCode(raw);
      form.code = tidy;
      if (raw === tidy) return;

      const caret = caretAfter(tidy, before);
      element.value = tidy;
      element.setSelectionRange(caret, caret);
    };

    /**
     * Copies what the fields actually hold into the model.
     *
     * A field can carry text Vue has never seen: a password manager that fills
     * the boxes without raising an `input` event leaves the model empty behind a
     * screen full of asterisks. Judging the model then produces a message that
     * is plainly untrue - a thirteen-character password called too short - and,
     * worse, sends the empty value. Reading the elements at the moment of
     * sending is the only way to be sure the form is talking about what is on
     * screen.
     */
    const commitFields = () => {
      if (codeField.value) form.code = normaliseCode(codeField.value.value);
      if (userField.value) form.username = userField.value.value;
      if (displayField.value) form.displayName = displayField.value.value;
      if (passField.value) form.password = passField.value.value;
      if (confirmField.value) form.confirm = confirmField.value.value;
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

    /** For somebody who followed the link and turns out to have an account. */
    const backToSignIn = () => {
      state.redeeming = false;
    };

    const submit = async () => {
      if (busy.value) return;
      error.value = '';
      commitFields();
      attempted.value = true;

      // Everything answerable from what is on screen is answered here, naming
      // the field and putting the cursor in it, because a button that quietly
      // does nothing is the worst thing a first-run form can do. Everything
      // else the server decides, and whatever it says is what the person reads.
      const bad = checks.find((check) => problems.value[check.key] !== '');
      if (bad) {
        error.value = `${bad.label} - ${problems.value[bad.key]}`;
        bad.field.value?.focus();
        return;
      }

      busy.value = true;
      try {
        // No role in this body, on purpose. An invite is worth what the
        // administrator who issued it decided it was worth, and a field asking
        // its holder what they would rather be would be a field the server has
        // to ignore.
        const data = await api(redeeming.value ? 'redeem' : 'setup', {
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
          state.redeeming = false;
          form.password = '';
          form.confirm = '';
          attempted.value = false;
          await loadWorkspace();
          toast.success(redeeming.value
            ? `Welcome, ${state.user.display_name}. Your account is ready.`
            : `Welcome, ${state.user.display_name}. This installation is yours.`);
          return;
        }

        error.value = data?.error || 'The account could not be created.';

        // Ask the server what it is now, because the answer changes what this
        // screen is for. Somebody else may have finished setup in the meantime,
        // in which case this screen has no reason to exist and the shell should
        // show the sign-in form instead - so the message goes to a toast, where
        // it survives the swap. An invite that has been spent or has expired
        // will never work again however carefully it is retyped, and saying so
        // is kinder than letting somebody keep trying.
        await loadSetup();
        if (!redeeming.value) {
          if (!state.needsSetup) toast.error(error.value);
        } else if (!info.value.invite_open) {
          // Replacing rather than adding to what the server said. Its wording
          // is shared with the first run and sends the reader to the file the
          // code was published in, which is on a server this person has no
          // account on - and no code at all would work now anyway.
          error.value = 'There is no invite open on this installation any more, so no code will work. '
            + 'Ask whoever sent you this one for a new one.';
        }
      } catch (failure) {
        error.value = failure.message;
      } finally {
        busy.value = false;
      }
    };

    return {
      state, form, info, error, busy, revealed, minPassword, attempted, redeeming,
      codeField, userField, displayField, passField, confirmField,
      problems, shows,
      onCodeInput, copyPath, backToSignIn, submit, resolvedTheme, toggleTheme,
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

          <template v-if="redeeming">
            <h1 class="t-xl mt-2">Create your {{ state.app.name }} account</h1>
            <p class="t-sm dim" style="max-width:52ch">
              Somebody who administers this installation sent you a one-off invite code. Fill this in and the
              account is made and signed in straight away, with a password only you know.
            </p>
          </template>

          <template v-else>
            <h1 class="t-xl mt-2">Set up {{ state.app.name }}</h1>
            <p class="t-sm dim" style="max-width:52ch">
              There are no accounts on this installation yet. Fill this in once and you will have an
              administrator account, signed in and ready to write courses.
            </p>
          </template>
        </div>

        <div class="col gap-4">

          <!-- why a code, and where it is. Only on a first run: the holder of
               an invite cannot read files on this server, which is the whole
               reason somebody had to send them the code. -->
          <section v-if="!redeeming" class="card card--pad col gap-3">
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
              <h2 class="t-md grow">{{ redeeming ? 'Create your account' : 'Create the administrator' }}</h2>
            </div>

            <div class="form-row">
              <label for="setup-code">Invite code</label>
              <input id="setup-code" ref="codeField" :value="form.code" @input="onCodeInput"
                     placeholder="XXXX-XXXX-XXXX-XXXX-XXXX-XXXX" aria-describedby="setup-code-hint"
                     :aria-invalid="shows('code') ? 'true' : null"
                     spellcheck="false" autocapitalize="characters" autocomplete="off"
                     style="font-family:var(--mono);letter-spacing:0.06em">
              <p v-if="shows('code')" id="setup-code-hint" class="hint"
                 :class="attempted ? 'c-danger' : 'c-warning'">{{ problems.code }}</p>
              <p v-else id="setup-code-hint" class="hint">
                Six groups of four<template v-if="redeeming">, exactly as it was sent to you</template>. Type
                it with or without the hyphens, in any case - the field tidies itself up as you go.
                <template v-if="redeeming">
                  It works once, and what the account it makes may do was decided by the administrator who
                  issued it.
                </template>
              </p>
            </div>

            <div class="divider"></div>

            <div class="form-row">
              <label for="setup-user">User name</label>
              <input id="setup-user" ref="userField" v-model="form.username" autocomplete="username"
                     spellcheck="false" aria-describedby="setup-user-hint"
                     :aria-invalid="shows('username') ? 'true' : null">
              <p v-if="shows('username')" id="setup-user-hint" class="hint"
                 :class="attempted ? 'c-danger' : 'c-warning'">{{ problems.username }}</p>
              <p v-else id="setup-user-hint" class="hint">
                What you will type to sign in. It cannot be changed later.
              </p>
            </div>

            <div class="form-row">
              <label for="setup-display">Display name <span class="faint">- optional</span></label>
              <input id="setup-display" ref="displayField" v-model="form.displayName" autocomplete="name">
              <p class="hint">
                The name shown in the sidebar and against anything you create. Leave it empty to be called
                by your user name.
              </p>
            </div>

            <div class="form-row">
              <label for="setup-pass">Password</label>
              <div class="row gap-2">
                <input id="setup-pass" ref="passField" v-model="form.password" class="grow"
                       :type="revealed ? 'text' : 'password'" autocomplete="new-password"
                       aria-describedby="setup-pass-hint" :aria-invalid="shows('password') ? 'true' : null">
                <button type="button" class="btn btn--ghost btn--icon none" @click="revealed = !revealed"
                        :title="revealed ? 'Hide the password' : 'Show the password'">
                  <app-icon :name="revealed ? 'eye-off' : 'eye'" :size="15"/>
                </button>
              </div>
              <p v-if="shows('password')" id="setup-pass-hint" class="hint"
                 :class="attempted ? 'c-danger' : 'c-warning'">{{ problems.password }}</p>
              <p v-else-if="redeeming" id="setup-pass-hint" class="hint">
                At least {{ minPassword }} characters, chosen by you and known to nobody else - not even to
                whoever sent you the code. There is no e-mail here to send a reset link, so use a password
                manager.
              </p>
              <p v-else id="setup-pass-hint" class="hint">
                At least {{ minPassword }} characters. Nobody can reset this for you - there is no second
                account and no e-mail - so use a password manager.
              </p>
            </div>

            <div class="form-row">
              <label for="setup-confirm">Password again</label>
              <input id="setup-confirm" ref="confirmField" v-model="form.confirm"
                     :type="revealed ? 'text' : 'password'" autocomplete="new-password"
                     aria-describedby="setup-confirm-hint"
                     :aria-invalid="shows('confirm') ? 'true' : null">
              <p v-if="shows('confirm')" id="setup-confirm-hint" class="hint"
                 :class="attempted ? 'c-danger' : 'c-warning'">{{ problems.confirm }}</p>
              <p v-else id="setup-confirm-hint" class="hint">
                The same password once more, so a typo cannot lock you out of
                {{ redeeming ? 'the account you are creating' : 'your own installation' }}.
              </p>
            </div>

            <p v-if="error" class="card card--flat t-sm c-danger" role="alert"
               style="padding:9px 12px;border-color:var(--danger-line)">{{ error }}</p>

            <!-- Enabled whatever the form holds. A disabled button leaves the
                 keyboard out of the tab order and answers a press with silence;
                 pressing this one always says what is missing and goes there. -->
            <button type="submit" class="btn btn--primary btn--block" :disabled="busy">
              <app-icon v-if="busy" name="refresh" :size="14" spin/>
              <template v-if="busy">Creating the account…</template>
              <template v-else-if="redeeming">Create account and sign in</template>
              <template v-else>Create account and start</template>
            </button>

            <button v-if="redeeming" type="button" class="btn btn--ghost btn--block" @click="backToSignIn">
              I already have an account - take me back to sign in
            </button>
          </form>

          <p class="t-2xs faint" style="text-align:center">{{ state.app.name }} v{{ state.app.version }}</p>
        </div>
      </div>
    </div>`,
};

export default SetupView;
