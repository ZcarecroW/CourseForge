/**
 * The one place that talks to the server.
 *
 * Responsibilities kept here so no view ever has to think about them:
 *   - attaches the CSRF token to every write
 *   - replays a request once after a 419 (the server ships a fresh token with it)
 *   - reports a lost session through a single callback
 *   - turns any non-ok envelope into a real Error with the server's message
 *   - marks a failure the application has already explained in plain words, so
 *     that one event never produces two messages saying the same thing
 */

const ENDPOINT = 'api/index.php';

let csrf = '';
let onUnauthorized = () => {};

export function setCsrf(token) {
  if (typeof token === 'string' && token) csrf = token;
}

export function getCsrf() {
  return csrf;
}

/**
 * Registered once by the store so a 401 can clear the client state.
 *
 * The handler returns true when it told the person what happened. That is what
 * lets the error below be marked as already announced.
 */
export function setUnauthorizedHandler(handler) {
  onUnauthorized = typeof handler === 'function' ? handler : () => false;
}

/**
 * Thrown for every failed call; `status` lets callers react to 401/404/422.
 *
 * `announced` means somebody has already been shown a message about this, in
 * the application's own words - the lost session is the case that matters. The
 * server's wording for the same event is written for a developer reading a log
 * ("Not authenticated."), and printing both leaves a person with two notices
 * for one problem, one of which they cannot act on. Anything that turns an
 * error into a message checks this first; see attempt() in toast.js.
 */
export class ApiError extends Error {
  constructor(message, status, payload, announced = false) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.payload = payload ?? null;
    this.announced = announced;
  }
}

/**
 * @param {string} path   route without a leading slash, e.g. `projects/3/pages/9`
 * @param {object} [options]
 * @param {string} [options.method='GET']
 * @param {object|null} [options.body]
 * @param {boolean} [options.soft=false]  resolve with the payload instead of throwing
 * @param {boolean} [options.retry=true]  internal: guards the single 419 replay
 */
export async function api(path, { method = 'GET', body = null, soft = false, retry = true } = {}) {
  const headers = { Accept: 'application/json' };
  if (body !== null) headers['Content-Type'] = 'application/json';
  if (csrf) headers['X-CSRF-Token'] = csrf;

  let response;
  try {
    response = await fetch(`${ENDPOINT}?r=${encodeURI(path)}`, {
      method,
      credentials: 'same-origin',
      headers,
      body: body === null ? null : JSON.stringify(body),
    });
  } catch (cause) {
    throw new ApiError('The server could not be reached. Check your connection and try again.', 0, null);
  }

  let payload = null;
  try {
    payload = await response.json();
  } catch {
    throw new ApiError(`The server returned an invalid response (HTTP ${response.status}).`, response.status, null);
  }

  setCsrf(payload?.csrf);

  // The 419 body already carries the fresh token, so one silent replay is enough.
  if (response.status === 419 && retry) {
    return api(path, { method, body, soft, retry: false });
  }
  let announced = false;
  if (response.status === 401 && path !== 'session') {
    announced = onUnauthorized() === true;
  }

  if (soft) return payload;
  if (!payload || payload.ok !== true) {
    throw new ApiError(
      payload?.error || `Request failed (HTTP ${response.status}).`,
      response.status,
      payload,
      announced,
    );
  }
  return payload;
}

export const get = (path) => api(path);
export const post = (path, body = {}) => api(path, { method: 'POST', body });
export const put = (path, body = {}) => api(path, { method: 'PUT', body });
export const del = (path, body = {}) => api(path, { method: 'DELETE', body });
