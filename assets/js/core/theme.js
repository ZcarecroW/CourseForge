/**
 * Theme handling. index.html already stamped an initial value on <html> before
 * the first paint; this module owns every change after that.
 *
 * Three states are stored: 'dark', 'light' and 'system'. Only the first two are
 * ever written to the DOM – 'system' means "follow the OS and keep following it".
 */
import { ref, computed } from 'vue';

const STORAGE_KEY = 'cf.theme';
const query = matchMedia('(prefers-color-scheme: light)');

const preference = ref(read());

function read() {
  const stored = localStorage.getItem(STORAGE_KEY);
  return stored === 'light' || stored === 'dark' ? stored : 'system';
}

function systemTheme() {
  return query.matches ? 'light' : 'dark';
}

/**
 * What the OS is currently asking for, held in a ref rather than read from the
 * media query on demand: `matches` is a plain DOM property, so a computed that
 * consulted it directly would never be invalidated when it changed, and
 * 'system' would quietly stop following the system.
 */
const system = ref(systemTheme());

export const resolvedTheme = computed(() =>
  preference.value === 'system' ? system.value : preference.value
);

function apply() {
  document.documentElement.dataset.theme = resolvedTheme.value;
}

export function setTheme(next) {
  preference.value = next === 'light' || next === 'dark' ? next : 'system';
  if (preference.value === 'system') {
    localStorage.removeItem(STORAGE_KEY);
  } else {
    localStorage.setItem(STORAGE_KEY, preference.value);
  }
  apply();
}

/** Dark ⇄ light. Picking either one leaves 'system' behind on purpose. */
export function toggleTheme() {
  setTheme(resolvedTheme.value === 'dark' ? 'light' : 'dark');
}

export function themePreference() {
  return preference;
}

query.addEventListener('change', () => {
  system.value = systemTheme();
  if (preference.value === 'system') apply();
});

apply();
