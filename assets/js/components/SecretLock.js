/**
 * The red mark beside a field that would store a secret while the server has
 * not been shown to keep secrets.
 *
 * Until Administration › Security has taken its verdict - or an administrator
 * has read it and accepted the risk in so many words - every API key and
 * token field is greyed out and carries this mark. An administrator who
 * presses it lands on the Security screen; anybody else is told whom to ask,
 * because the screen is not theirs to open.
 */
import { ref, computed } from 'vue';
import { state, isAdmin, go } from '@/core/store.js';
import AppIcon from '@/components/AppIcon.js';
import AppModal from '@/components/AppModal.js';

export const SecretLock = {
  name: 'SecretLock',
  components: { AppIcon, AppModal },
  props: {
    /** What the field holds, for the sentence: "API key", "token secret". */
    what: { type: String, default: 'secret' },
    size: { type: [Number, String], default: 14 },
  },
  setup(props) {
    const explaining = ref(false);

    const verdict = computed(() => state.security?.verdict ?? 'unknown');

    const reason = computed(() => ({
      exposed: 'This server hands out the files that would hold it - the data directory can be read over HTTP.',
      unverified: 'CourseForge could not verify that this server keeps its data directory private.',
      unknown: 'Nobody has checked yet whether this server keeps its data directory private.',
    })[verdict.value] ?? 'The server has not passed the security check.');

    const title = computed(() => `Locked: a ${props.what} cannot be stored yet. ${reason.value}`
      + (isAdmin.value ? ' Open Administration › Security.' : ' Ask an administrator.'));

    const open = () => {
      if (isAdmin.value) go('security');
      else explaining.value = true;
    };

    return { explaining, verdict, reason, title, open, isAdmin };
  },
  template: `
    <button type="button" class="secret-lock" :title="title" :aria-label="title" @click="open">
      <app-icon name="triangle-warning" :size="size"/>
    </button>
    <app-modal v-if="explaining" title="This field is locked" icon="shield-alert" @close="explaining = false">
      <div class="col gap-3">
        <p class="t-sm">
          A {{ what }} is stored in CourseForge's database, and that database lives on this server.
          {{ reason }}
        </p>
        <p class="t-sm">
          Until an administrator has fixed that - or has looked at the verdict and accepted the risk - nothing that
          would store a secret is allowed to. Ask an administrator to open
          <strong>Administration › Security</strong>; the check and the instructions for this server are there.
        </p>
      </div>
      <template #footer>
        <button class="btn" @click="explaining = false">Close</button>
      </template>
    </app-modal>`,
};

export default SecretLock;
