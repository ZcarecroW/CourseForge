/**
 * The release stamp that goes on an asset URL.
 *
 * Everything the import map resolves is stamped in `index.html` by
 * `tools/assets.php` — `?v=4.4.1` on every module and every vendored library, so
 * that a release which changes what is inside a file also changes the URL it
 * lives at. Without that, `assets/vendor/.htaccess` promising `immutable` for a
 * year means an upgraded installation keeps serving the previous release's Vue
 * and Shiki to anybody who had visited before, through reloads and hard reloads
 * alike.
 *
 * Three libraries cannot be stamped that way, because their names are decided at
 * runtime rather than written in the map: Shiki's grammars and themes, Mermaid
 * and MathJax are all reached by building a URL against `import.meta.url`. A
 * query string is not inherited through that resolution, so they are stamped
 * here instead, from the one number the page already carries.
 *
 * An installation whose `index.html` predates the stamp has no meta tag; the
 * empty string then leaves every URL exactly as it was, which is the behaviour
 * that shipped before this existed.
 */

/** The release these assets belong to, or '' where nothing said. */
export const ASSET_VERSION =
  document.querySelector('meta[name="cf-assets"]')?.content?.trim() ?? '';

/** One URL with the release stamp on it, for the libraries the map cannot reach. */
export function stamped(url) {
  if (ASSET_VERSION === '') return url;
  return url + (url.includes('?') ? '&' : '?') + 'v=' + encodeURIComponent(ASSET_VERSION);
}
