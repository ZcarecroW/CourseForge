/**
 * The overlay that shows the tour: a spotlight on one thing, a card beside it.
 *
 * For every step it goes to the screen the step names, waits for the thing the
 * step points at to be on screen, scrolls it into view, and cuts a hole in a
 * dimmed layer around it. The card sits below the hole where there is room,
 * above it where there is not, beside it as a last resort, and in the middle
 * of the window when the step has nothing to point at. Everything is measured
 * again whenever the window moves, so a screen that finishes loading under
 * the spotlight is caught up with.
 */
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { state, isAdmin, go, openProject } from '@/core/store.js';
import { post } from '@/core/api.js';
import { attempt } from '@/core/toast.js';
import { tour, currentStep, stepCount, stopTour, nextStep, prevStep } from '@/core/tour.js';
import AppIcon from '@/components/AppIcon.js';

const PAD = 8;
const GAP = 14;
const MARGIN = 12;

export const AppTour = {
  name: 'AppTour',
  components: { AppIcon },
  setup() {
    const spot = ref(null);        // {top,left,width,height} or null for "nothing to light"
    const card = ref({ top: 0, left: 0, centered: true });
    const cardEl = ref(null);
    const settling = ref(false);
    const missing = ref(false);
    let element = null;
    let ticker = null;
    let generation = 0;

    const reduced = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true;

    /* ------------------------------------------------------------ placing */

    const place = () => {
      const c = cardEl.value;
      const vw = window.innerWidth;
      const vh = window.innerHeight;
      const cw = c ? c.offsetWidth : 380;
      const ch = c ? c.offsetHeight : 240;

      if (!element || !element.isConnected) {
        spot.value = null;
        card.value = { top: Math.max(MARGIN, (vh - ch) / 2), left: Math.max(MARGIN, (vw - cw) / 2), centered: true };
        return;
      }

      const r = element.getBoundingClientRect();
      const s = {
        top: Math.max(0, r.top - PAD),
        left: Math.max(0, r.left - PAD),
        width: Math.min(vw, r.width + PAD * 2),
        height: Math.min(vh, r.height + PAD * 2),
      };
      spot.value = s;

      const clampLeft = (x) => Math.min(Math.max(MARGIN, x), Math.max(MARGIN, vw - cw - MARGIN));
      const clampTop = (y) => Math.min(Math.max(MARGIN, y), Math.max(MARGIN, vh - ch - MARGIN));

      let top;
      let left;
      if (s.top + s.height + GAP + ch <= vh - MARGIN) {
        top = s.top + s.height + GAP;
        left = clampLeft(s.left);
      } else if (s.top - GAP - ch >= MARGIN) {
        top = s.top - GAP - ch;
        left = clampLeft(s.left);
      } else if (s.left + s.width + GAP + cw <= vw - MARGIN) {
        top = clampTop(s.top);
        left = s.left + s.width + GAP;
      } else if (s.left - GAP - cw >= MARGIN) {
        top = clampTop(s.top);
        left = s.left - GAP - cw;
      } else {
        top = clampTop(vh - ch - MARGIN);
        left = clampLeft((vw - cw) / 2);
      }
      card.value = { top, left, centered: false };
    };

    /** Waits for the step's target to appear, for a little while. */
    const waitFor = (selector, ms) => new Promise((resolve) => {
      const started = Date.now();
      const look = () => {
        const found = selector ? document.querySelector(selector) : null;
        if (found) return resolve(found);
        if (Date.now() - started > ms) return resolve(null);
        setTimeout(look, 100);
      };
      look();
    });

    const show = async (step) => {
      const mine = ++generation;
      settling.value = true;
      missing.value = false;
      element = null;
      spot.value = null;

      // Stand on the right screen first.
      if (step.view === 'project') {
        const first = state.projects[0];
        if (first) {
          if (!state.project || state.view !== 'project') {
            await attempt(() => openProject(first.id), 'Open the course');
          }
          if (step.tab) state.projectTab = step.tab;
        }
      } else if (step.view && state.view !== step.view) {
        go(step.view);
      }
      if (mine !== generation) return;

      await nextTick();
      const found = await waitFor(step.target, 4000);
      if (mine !== generation) return;

      element = found;
      missing.value = Boolean(step.target) && !found;
      if (found) {
        found.scrollIntoView({ block: 'center', inline: 'nearest', behavior: reduced() ? 'auto' : 'smooth' });
        await new Promise((r) => setTimeout(r, reduced() ? 50 : 380));
        if (mine !== generation) return;
      }
      await nextTick();
      place();
      settling.value = false;
      cardEl.value?.focus?.();
    };

    watch(currentStep, (step) => {
      if (tour.active && step) show(step);
    }, { immediate: true });

    /* ------------------------------------------------------------ events */

    const onKey = (event) => {
      if (!tour.active) return;
      if (event.key === 'Escape') { event.preventDefault(); finish(); }
      else if (event.key === 'ArrowRight' || event.key === 'Enter') { event.preventDefault(); nextStep(); }
      else if (event.key === 'ArrowLeft') { event.preventDefault(); prevStep(); }
    };
    const onMove = () => { if (tour.active && !settling.value) place(); };

    onMounted(() => {
      document.addEventListener('keydown', onKey);
      window.addEventListener('resize', onMove);
      window.addEventListener('scroll', onMove, true);
      ticker = setInterval(onMove, 400);
    });
    onBeforeUnmount(() => {
      document.removeEventListener('keydown', onKey);
      window.removeEventListener('resize', onMove);
      window.removeEventListener('scroll', onMove, true);
      clearInterval(ticker);
    });

    /** Ends the tour, and tells the server it has been seen so it stops starting by itself. */
    const finish = () => {
      stopTour();
      if (state.user && !state.user.tour_seen) {
        attempt(async () => {
          const data = await post('account/tour', {});
          if (data.user) state.user = data.user;
        }, 'Tour');
      }
    };

    const last = computed(() => tour.index >= stepCount.value - 1);
    const spotStyle = computed(() => (spot.value
      ? { top: `${spot.value.top}px`, left: `${spot.value.left}px`, width: `${spot.value.width}px`, height: `${spot.value.height}px` }
      : null));
    const cardStyle = computed(() => ({ top: `${card.value.top}px`, left: `${card.value.left}px` }));
    const progress = computed(() => (stepCount.value ? Math.round(((tour.index + 1) / stepCount.value) * 100) : 0));

    return {
      tour, currentStep, stepCount, isAdmin, spot, spotStyle, cardStyle, cardEl, settling, missing, last, progress,
      nextStep, prevStep, finish,
    };
  },
  template: `
    <div v-if="tour.active && currentStep" class="tour" role="dialog" aria-modal="true" :aria-label="'Guided tour: ' + currentStep.title">
      <div v-if="spotStyle" class="tour__spot" :style="spotStyle"></div>
      <div v-else class="tour__dim"></div>

      <div ref="cardEl" class="tour__card" :class="{ 'tour__card--centered': !spot }" :style="cardStyle" tabindex="-1">
        <div class="tour__bar"><div class="tour__bar-fill" :style="{ width: progress + '%' }"></div></div>
        <div class="tour__head">
          <span class="tile tile--sm tile--brand"><app-icon name="compass" :size="14"/></span>
          <span class="tour__count">Step {{ tour.index + 1 }} of {{ stepCount }}</span>
          <button class="btn btn--ghost btn--sm btn--icon push" title="Close the tour" aria-label="Close the tour" @click="finish">
            <app-icon name="x" :size="14"/>
          </button>
        </div>
        <h2 class="tour__title">{{ currentStep.title }}</h2>
        <p v-for="(paragraph, i) in currentStep.body" :key="i" class="tour__text">{{ paragraph }}</p>
        <p v-if="missing && !settling" class="tour__note">
          <app-icon name="info" :size="12"/> This part of the screen is not on show right now, so the tour is describing it from here.
        </p>
        <div class="tour__foot">
          <button class="btn btn--ghost btn--sm" @click="finish">Skip tour</button>
          <span class="push"></span>
          <button class="btn btn--sm" :disabled="tour.index === 0" @click="prevStep">
            <app-icon name="arrow-left" :size="12"/> Back
          </button>
          <button class="btn btn--primary btn--sm" @click="nextStep">
            {{ last ? 'Finish' : 'Next' }} <app-icon v-if="!last" name="arrow-right" :size="12"/>
          </button>
        </div>
      </div>
    </div>`,
};

export default AppTour;
