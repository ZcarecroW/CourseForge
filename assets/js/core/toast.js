/** Transient notifications. Kept separate from the store so anything can raise one. */
import { reactive } from 'vue';

export const toasts = reactive([]);

let sequence = 0;

function push(message, type, duration) {
  const id = ++sequence;
  toasts.push({ id, type, message: String(message ?? '') });
  if (duration > 0) {
    setTimeout(() => dismiss(id), duration);
  }
  return id;
}

export function dismiss(id) {
  const index = toasts.findIndex((t) => t.id === id);
  if (index > -1) toasts.splice(index, 1);
}

export const toast = {
  info: (message, duration = 4000) => push(message, 'info', duration),
  success: (message, duration = 3500) => push(message, 'success', duration),
  /** Errors linger: they usually carry a server message worth reading. */
  error: (message, duration = 9000) => push(message, 'error', duration),
};

/**
 * Runs an async action, turning any failure into an error toast.
 * Returns the action's value, or `undefined` when it failed – so callers can
 * check the result instead of writing their own try/catch.
 *
 * One failure, one message. A lost session is announced by the store the
 * moment the server refuses the request, in words that say what to do about
 * it; the server's own wording for the same refusal is developer language
 * ("Not authenticated.") and would arrive alongside it as a second notice for
 * the same event. api.js marks those errors as already announced, and they are
 * dropped here rather than at every call site.
 */
export async function attempt(action, label = '') {
  try {
    return await action();
  } catch (error) {
    if (error?.announced === true) return undefined;
    toast.error(label ? `${label}: ${error.message}` : error.message);
    return undefined;
  }
}
