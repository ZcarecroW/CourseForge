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
 * simply check the result instead of writing their own try/catch.
 */
export async function attempt(action, label = '') {
  try {
    return await action();
  } catch (error) {
    toast.error(label ? `${label}: ${error.message}` : error.message);
    return undefined;
  }
}
