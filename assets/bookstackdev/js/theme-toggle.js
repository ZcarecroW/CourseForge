(function () {
  'use strict';

  /* The loader owns the key name (CONFIG.theme.storageKey). Reading it back
     here - instead of hardcoding it and overwriting the global - keeps a
     custom storageKey working end to end. */
  var cfg = (window.MILELO_CONFIG && window.MILELO_CONFIG.theme) || {};
  var THEME_STORAGE_KEY = cfg.storageKey || window.BOOKSTACK_THEME_STORAGE_KEY || 'bookstack-guest-dark-mode';
  window.BOOKSTACK_THEME_STORAGE_KEY = THEME_STORAGE_KEY;

  /* Where the button sits and how big it is. The stylesheet reads the two
     lengths as custom properties and the corner as a class, so a badly typed
     value falls back to the stylesheet's own defaults rather than to nowhere. */
  var CORNERS = ['bottom-left', 'bottom-right', 'top-left', 'top-right'];
  var position = CORNERS.indexOf(cfg.position) === -1 ? 'bottom-left' : cfg.position;
  var px = function (v) {
    return (typeof v === 'number' && isFinite(v) && v >= 0) ? Math.round(v) + 'px' : '';
  };

  /* localStorage throws outright in some privacy modes and in cross-origin
     iframes; an unguarded read used to abort this whole file, leaving no
     button at all. */
  var storage = {
    get: function (key) {
      try {
        return localStorage.getItem(key);
      } catch (e) {
        return null;
      }
    },
    set: function (key, val) {
      try {
        localStorage.setItem(key, val);
      } catch (e) { /* ignore */
      }
    },
    remove: function (key) {
      try {
        localStorage.removeItem(key);
      } catch (e) { /* ignore */
      }
    },
  };

  function applyTheme(isDark) {
    document.documentElement.classList.toggle('dark-mode', isDark);
  }

  function isUserLoggedIn() {
    /* BookStack ships the token as <meta name="token">; the csrf-token spelling
       is kept as a fallback for forks that rename it. Querying only the latter
       made this always return false, so a signed-in user silently took the
       guest path and the toggle POST went out unsigned. */
    var csrfMeta = document.querySelector('meta[name="token"], meta[name="csrf-token"]');
    var hasToken = csrfMeta && csrfMeta.getAttribute('content');
    var hasUserMenu = document.querySelector('.dropdown-container [data-shortcut="favourites_view"]')
      || document.querySelector('[href*="/logout"]');
    return !!(hasToken && hasUserMenu);
  }

  /* BookStack may live under a sub-path; it publishes its root as a meta tag.
     Falling back to '' reproduces the old absolute '/preferences/...' path. */
  function bookstackUrl(path) {
    /* Only the head is asked: a page body is written by whoever edits the
       wiki, and a meta tag in it must not be able to redirect a request that
       carries the reader's CSRF token. */
    var meta = document.head ? document.head.querySelector('meta[name="base-url"]') : null;
    var root = (meta && meta.getAttribute('content')) || '';
    return root.replace(/\/+$/, '') + path;
  }

  /* The theme BookStack rendered, captured by the loader before it applied any
     guest override. Reading the class here instead would read back that
     override, which made the "restore the server theme" branch below a no-op. */
  var serverDark = (typeof window.__mileloServerDark === 'boolean')
    ? window.__mileloServerDark
    : document.documentElement.classList.contains('dark-mode');

  // Apply guest preference immediately to avoid a flash of the wrong theme
  var guestPref = storage.get(THEME_STORAGE_KEY);
  if (guestPref !== null) {
    if (!serverDark && guestPref === 'true') applyTheme(true);
    else if (guestPref === 'false' && serverDark) applyTheme(false);
  }

  function initToggle() {
    if (!document.body) return;                                  // nothing to attach to
    if (document.querySelector('.theme-toggle-fixed')) return;    // never inject twice
    var loggedIn = isUserLoggedIn();

    /* A logged-in user's theme is stored server-side. A leftover guest value
       would be re-applied before first paint on every later visit and silently
       override that server preference - permanently, if the user changes their
       theme anywhere other than this button - so it is dropped, and the
       server's own choice restored, as soon as we know the visitor is signed
       in. */
    if (loggedIn && guestPref !== null) {
      storage.remove(THEME_STORAGE_KEY);
      if (document.documentElement.classList.contains('dark-mode') !== serverDark) {
        applyTheme(serverDark);
        window.dispatchEvent(new CustomEvent('milelo-theme-change', {detail: {dark: serverDark}}));
      }
    }

    var isDarkMode = document.documentElement.classList.contains('dark-mode');

    var sunIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 15.31 23.31 12 20 8.69V4h-4.69L12 .69 8.69 4H4v4.69L.69 12 4 15.31V20h4.69L12 23.31 15.31 20H20zM12 18c-3.31 0-6-2.69-6-6s2.69-6 6-6 6 2.69 6 6-2.69 6-6 6"/></svg>';
    var moonIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a9 9 0 1 0 9 9c0-.46-.04-.92-.1-1.36a5.389 5.389 0 0 1-4.4 2.26 5.403 5.403 0 0 1-3.14-9.8c-.44-.06-.9-.1-1.36-.1z"/></svg>';

    function label(dark) {
      return dark ? 'Activate Light Mode' : 'Activate Dark Mode';
    }

    var button = document.createElement('button');
    button.className = 'theme-toggle-fixed theme-toggle-fixed--' + position;
    button.type = 'button';
    button.title = label(isDarkMode);
    button.setAttribute('aria-label', label(isDarkMode));
    button.setAttribute('aria-pressed', String(isDarkMode));
    button.innerHTML = isDarkMode ? sunIcon : moonIcon;
    var offset = px(cfg.offset);
    var size = px(cfg.size);
    if (offset) button.style.setProperty('--milelo-toggle-offset', offset);
    if (size) button.style.setProperty('--milelo-toggle-size', size);

    button.addEventListener('click', function () {
      if (loggedIn) {
        // Persist via server-side preference endpoint
        var csrfToken = '';
        var csrfMeta = document.querySelector('meta[name="token"], meta[name="csrf-token"]');
        if (csrfMeta) csrfToken = csrfMeta.getAttribute('content') || '';

        var form = document.createElement('form');
        form.method = 'POST';
        form.action = bookstackUrl('/preferences/toggle-dark-mode');
        form.hidden = true;

        /* Values are assigned as properties, never interpolated into an HTML
           string - a return URL containing a quote used to break the markup. */
        [['_token', csrfToken], ['_method', 'PATCH'], ['_return', window.location.href]]
          .forEach(function (pair) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = pair[0];
            input.value = pair[1];
            form.appendChild(input);
          });

        document.body.appendChild(form);
        form.submit();
        return;
      }

      // Persist client-side for guests
      var newMode = !document.documentElement.classList.contains('dark-mode');
      applyTheme(newMode);
      storage.set(THEME_STORAGE_KEY, newMode.toString());
      button.innerHTML = newMode ? sunIcon : moonIcon;
      button.title = label(newMode);
      button.setAttribute('aria-label', label(newMode));
      button.setAttribute('aria-pressed', String(newMode));
      /* Mermaid re-renders its diagrams in the matching theme. */
      window.dispatchEvent(new CustomEvent('milelo-theme-change', {detail: {dark: newMode}}));
    });

    document.body.appendChild(button);
  }

  /* The loader injects this file dynamically, so DOMContentLoaded may already
     have fired by the time it executes - run immediately in that case. */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initToggle);
  } else {
    initToggle();
  }
})();
