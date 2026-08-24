/** Small presentation helpers. No state, no side effects. */

const dateTime = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });
const dateOnly = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' });

export const formatDateTime = (seconds) => (seconds ? dateTime.format(new Date(seconds * 1000)) : '—');
export const formatDate = (seconds) => (seconds ? dateOnly.format(new Date(seconds * 1000)) : '—');

/** "3 minutes ago" – falls back to an absolute date beyond a month. */
export function relativeTime(seconds) {
  if (!seconds) return '—';
  const delta = Math.round(Date.now() / 1000) - seconds;
  const steps = [
    [60, 'second', 1],
    [3600, 'minute', 60],
    [86400, 'hour', 3600],
    [2592000, 'day', 86400],
  ];
  if (delta < 15) return 'just now';
  for (const [limit, unit, divisor] of steps) {
    if (delta < limit) {
      const value = Math.floor(delta / divisor);
      return `${value} ${unit}${value === 1 ? '' : 's'} ago`;
    }
  }
  return formatDate(seconds);
}

export const plural = (count, one, many = `${one}s`) => `${count} ${count === 1 ? one : many}`;

export const percent = (value, total) => (total > 0 ? Math.round((value / total) * 100) : 0);

/** Compact word counts: 1240 → "1.2k". */
export function compactNumber(value) {
  const n = Number(value) || 0;
  if (n < 1000) return String(n);
  if (n < 10000) return `${(n / 1000).toFixed(1).replace(/\.0$/, '')}k`;
  return `${Math.round(n / 1000)}k`;
}

export function uid() {
  return crypto.randomUUID
    ? crypto.randomUUID()
    : `id-${Math.random().toString(36).slice(2)}${Date.now().toString(36)}`;
}

/**
 * Deep copy of JSON-shaped data.
 *
 * A JSON round trip rather than structuredClone on purpose: everything this is
 * used on comes from the API and goes back to it, and structuredClone throws a
 * DataCloneError on Vue's reactive proxies.
 */
export const clone = (value) => (value === undefined || value === null ? null : JSON.parse(JSON.stringify(value)));

export const LANGUAGES = [
  'English', 'German', 'French', 'Spanish', 'Italian', 'Portuguese', 'Dutch',
  'Polish', 'Czech', 'Swedish', 'Norwegian', 'Danish', 'Finnish', 'Greek',
  'Turkish', 'Russian', 'Ukrainian', 'Arabic', 'Hebrew', 'Hindi',
  'Chinese (Simplified)', 'Chinese (Traditional)', 'Japanese', 'Korean',
  'Vietnamese', 'Thai', 'Indonesian',
];
