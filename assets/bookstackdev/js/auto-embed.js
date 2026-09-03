/**
 * auto-embed.js - replace a paragraph that contains nothing but a single link
 * with a responsive provider iframe.
 *
 * Two hard rules, both configurable through MILELO_CONFIG.autoEmbed:
 *
 *   1. `pageViewOnly` - embeds are only produced on an individual page view.
 *      A book, chapter or shelf overview renders its description in its own
 *      container, so a link sitting at the top of that description stays a
 *      plain link. Listings, comments, search results and the editors are
 *      excluded as well.
 *
 *   2. `linksOnly` - only a real <a href> is embedded. A bare URL typed as
 *      plain text is left exactly as it was written.
 *
 * Providers are matched on the parsed hostname, never on a substring of the
 * raw text: "https://example.com/?ref=vimeo.com/12345" must not become a Vimeo
 * player. The iframe is built with DOM APIs rather than an outerHTML string,
 * so no provider output can break out of an attribute.
 */
(function () {
  'use strict';

  /* Same list the Shiki highlighter uses, so both agree on "this is an editor". */
  const EDITOR_SEL = '.editor-toolbox, .markdown-editor, .code-editor, [contenteditable="true"],' +
    '#markdown-editor, .page-editor, .mce-content-body, .tox';

  /* Containers that are NOT an individual page's body. */
  const OVERVIEW_SEL = '.book-content, .chapter-content, .shelf-content';

  const cfg = Object.assign({
    scope: '.page-content',
    exclude: OVERVIEW_SEL + ', .description, .entity-list, .entity-list-item,' +
      '.comment-container, .comment-box, .search-results, .page-revision, ' + EDITOR_SEL,
    linksOnly: true,
    pageViewOnly: true,
  }, (window.MILELO_CONFIG && window.MILELO_CONFIG.autoEmbed) || {});

  /* ------------------------------------------------------------- providers */

  const host = (u) => u.hostname.replace(/^www\./i, '').toLowerCase();
  const on = (u, ...names) => names.indexOf(host(u)) !== -1;
  const seg = (u) => u.pathname.split('/').filter(Boolean);
  const ID = /^[\w-]+$/;

  /* "1h2m3s" / "90" -> seconds */
  function toSeconds(raw) {
    if (!raw) return 0;
    if (/^\d+$/.test(raw)) return parseInt(raw, 10);
    const m = /^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/i.exec(raw);
    if (!m) return 0;
    return (+(m[1] || 0)) * 3600 + (+(m[2] || 0)) * 60 + (+(m[3] || 0));
  }

  const providers = [
    /* ---------------------------------------------------------------- video */
    {
      type: 'video',
      test: (u) => on(u, 'youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com', 'youtu.be'),
      src(u) {
        const parts = seg(u);
        let id = '';
        if (host(u) === 'youtu.be') id = parts[0] || '';
        else if (u.pathname === '/watch') id = u.searchParams.get('v') || '';
        else if (['embed', 'shorts', 'live', 'v'].indexOf(parts[0]) !== -1) id = parts[1] || '';
        if (!ID.test(id)) return null;

        const q = new URLSearchParams({rel: '0'});
        const t = toSeconds(u.searchParams.get('t') || u.searchParams.get('start'));
        if (t) q.set('start', String(t));
        const list = u.searchParams.get('list');
        if (list && ID.test(list)) q.set('list', list);
        return 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(id) + '?' + q;
      },
    },
    {
      type: 'video',
      test: (u) => on(u, 'vimeo.com', 'player.vimeo.com'),
      src(u) {
        const parts = seg(u);
        const i = parts[0] === 'video' ? 1 : 0;          // player.vimeo.com/video/<id>
        const id = parts[i];
        if (!/^\d+$/.test(id || '')) return null;
        /* Unlisted videos carry a hash, either as /<id>/<hash> or ?h=<hash>. */
        const hash = (ID.test(parts[i + 1] || '') && parts[i + 1]) || u.searchParams.get('h') || '';
        return 'https://player.vimeo.com/video/' + id + (ID.test(hash) ? '?h=' + hash : '');
      },
    },
    {
      type: 'video',
      test: (u) => on(u, 'dailymotion.com', 'dai.ly'),
      src(u) {
        const parts = seg(u);
        const id = host(u) === 'dai.ly' ? parts[0] : (parts[0] === 'video' ? parts[1] : '');
        return ID.test(id || '') ? 'https://www.dailymotion.com/embed/video/' + id : null;
      },
    },
    {
      type: 'video',
      test: (u) => on(u, 'twitch.tv', 'clips.twitch.tv'),
      src(u) {
        const parts = seg(u);
        const parent = '&parent=' + encodeURIComponent(location.hostname);
        if (host(u) === 'clips.twitch.tv' && ID.test(parts[0] || '')) {
          return 'https://clips.twitch.tv/embed?clip=' + encodeURIComponent(parts[0]) + parent;
        }
        if (parts[0] === 'videos' && /^\d+$/.test(parts[1] || '')) {
          return 'https://player.twitch.tv/?video=' + parts[1] + parent;
        }
        if (parts.length === 1 && ID.test(parts[0])) {
          return 'https://player.twitch.tv/?channel=' + encodeURIComponent(parts[0]) + parent;
        }
        return null;
      },
    },
    {
      type: 'video',
      test: (u) => on(u, 'loom.com'),
      src(u) {
        const parts = seg(u);
        return (parts[0] === 'share' && ID.test(parts[1] || ''))
          ? 'https://www.loom.com/embed/' + parts[1] : null;
      },
    },
    {
      type: 'video',
      test: (u) => on(u, 'streamable.com'),
      src(u) {
        const parts = seg(u);
        const id = parts[0] === 'e' ? parts[1] : parts[0];
        return ID.test(id || '') ? 'https://streamable.com/e/' + id : null;
      },
    },

    /* ---------------------------------------------------------------- audio */
    {
      type: 'audio',
      test: (u) => on(u, 'open.spotify.com', 'spotify.com'),
      src(u) {
        const parts = seg(u).filter((s) => !/^intl-/i.test(s));   // /intl-de/track/<id>
        const kinds = ['track', 'album', 'playlist', 'episode', 'show', 'artist'];
        if (kinds.indexOf(parts[0]) === -1 || !ID.test(parts[1] || '')) return null;
        return 'https://open.spotify.com/embed/' + parts[0] + '/' + parts[1];
      },
    },
    {
      type: 'audio',
      test: (u) => on(u, 'soundcloud.com', 'm.soundcloud.com'),
      src(u) {
        if (seg(u).length < 2) return null;
        /* Private shares carry ?secret_token=… ; dropping it 404s the widget. */
        const token = u.searchParams.get('secret_token');
        const target = 'https://soundcloud.com' + u.pathname + (token ? '?secret_token=' + token : '');
        return 'https://w.soundcloud.com/player/?url=' + encodeURIComponent(target) +
          '&color=%23ff5500&auto_play=false&hide_related=true&show_comments=false' +
          '&show_user=true&show_reposts=false&show_teaser=false';
      },
    },

    /* ----------------------------------------------------------------- code */
    {
      type: 'code',
      test: (u) => on(u, 'codepen.io'),
      src(u) {
        const parts = seg(u);
        const kinds = ['pen', 'full', 'details', 'embed', 'live'];
        const i = parts.findIndex((s) => kinds.indexOf(s) !== -1);
        if (i < 1) return null;
        const user = parts[i - 1];
        const id = parts[i + 1];
        if (!ID.test(user || '') || !ID.test(id || '')) return null;
        return 'https://codepen.io/' + user + '/embed/' + id +
          '?default-tab=html%2Cresult&theme-id=dark';
      },
    },
    {
      type: 'code',
      test: (u) => on(u, 'codesandbox.io'),
      src(u) {
        const parts = seg(u);
        const id = (parts[0] === 's' || parts[0] === 'embed') ? parts[1]
          : (parts[0] === 'p' && parts[1] === 'sandbox' ? parts[2] : '');
        return ID.test(id || '') ? 'https://codesandbox.io/embed/' + id : null;
      },
    },
    {
      type: 'code',
      test: (u) => on(u, 'stackblitz.com'),
      src(u) {
        const parts = seg(u);
        return (parts[0] === 'edit' && ID.test(parts[1] || ''))
          ? 'https://stackblitz.com/edit/' + parts[1] + '?embed=1' : null;
      },
    },
    {
      type: 'code',
      test: (u) => on(u, 'jsfiddle.net'),
      src(u) {
        const parts = seg(u).filter((s) => s !== 'embedded' && s !== 'embed');
        if (!parts.length || !parts.every((s) => ID.test(s))) return null;
        return 'https://jsfiddle.net/' + parts.slice(0, 2).join('/') +
          '/embedded/result,js,html,css/';
      },
    },
    {
      type: 'code',
      test: (u) => on(u, 'gist.github.com'),
      /* A gist has no iframe endpoint - its <script> tag is hosted in a srcdoc
         document. srcdoc is assigned as a property, so the quotes in the markup
         can never break out of an attribute (the previous data:text/html embed
         produced invalid HTML for exactly that reason). */
      srcdoc(u) {
        const parts = seg(u).slice(0, 2);
        if (!parts.length || !parts.every((s) => ID.test(s))) return null;
        const url = 'https://gist.github.com/' + parts.join('/') + '.js';
        return '<!doctype html><meta charset="utf-8">' +
          '<style>body{margin:0;font:13px/1.5 system-ui,sans-serif}</style>' +
          '<script src="' + url + '"><\/script>';
      },
    },

    /* -------------------------------------------------------- maps & design */
    {
      type: 'code',
      test: (u) => on(u, 'figma.com'),
      src(u) {
        const parts = seg(u);
        if (['file', 'design', 'proto', 'board', 'slides'].indexOf(parts[0]) === -1) return null;
        if (!ID.test(parts[1] || '')) return null;
        return 'https://www.figma.com/embed?embed_host=bookstack&url=' + encodeURIComponent(u.href);
      },
    },
    {
      type: 'video',
      test: (u) => on(u, 'google.com', 'maps.google.com') && /^\/maps\/embed/.test(u.pathname),
      src: (u) => u.href,
    },
  ];

  function embedFor(url) {
    let u;
    try {
      u = new URL(url, location.href);
    } catch (e) {
      return null;
    }
    if (u.protocol !== 'http:' && u.protocol !== 'https:') return null;

    for (const p of providers) {
      if (!p.test(u)) continue;
      const src = p.src ? p.src(u) : null;
      const srcdoc = p.srcdoc ? p.srcdoc(u) : null;
      if (src || srcdoc) return {src, srcdoc, type: p.type};
    }
    return null;
  }

  /* ------------------------------------------------------------ page scope */

  /* A page view renders its body in .page-content and shows none of the
     overview containers. Editors live in their own document (the TinyMCE and
     markdown-preview iframes) or carry an editor class, and are filtered per
     element below. */
  function isPageView() {
    if (!cfg.pageViewOnly) return true;
    if (document.querySelector(OVERVIEW_SEL)) return false;
    return !!document.querySelector(cfg.scope);
  }

  /* ------------------------------------------------------------- rendering */

  function buildEmbed(info, label) {
    const wrap = document.createElement('div');
    wrap.className = 'auto-embed ' + info.type;

    const frame = document.createElement('iframe');
    frame.title = label;
    frame.loading = 'lazy';
    frame.referrerPolicy = 'strict-origin-when-cross-origin';
    frame.setAttribute('frameborder', '0');

    if (info.srcdoc) {
      /* An about:srcdoc document INHERITS the embedder's origin, so the third
         party script inside it would otherwise run same-origin with BookStack
         - reading session cookies, the CSRF meta and parent.document. Sandbox
         without allow-same-origin forces an opaque origin; the script still
         runs and renders, it just cannot reach out. */
      frame.setAttribute('sandbox', 'allow-scripts allow-popups');
      frame.srcdoc = info.srcdoc;
    } else {
      frame.src = info.src;
      /* A pen, a fiddle, a sandbox: somebody else's page, running somebody
         else's script. It may do everything an embed needs and nothing to the
         page around it - in particular it may not navigate the wiki away,
         which a non-sandboxed frame is allowed to do on the first click. */
      frame.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-forms allow-popups allow-presentation');
      frame.setAttribute('allowfullscreen', '');
      frame.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share; fullscreen');
    }

    wrap.appendChild(frame);
    return wrap;
  }

  /**
   * The URL a paragraph offers up for embedding, or null.
   * With linksOnly (the default) the paragraph must hold exactly one <a href>
   * and no other visible content, and that anchor's own text must be the whole
   * paragraph - a link inside a sentence is left alone.
   */
  function candidateUrl(p) {
    const text = (p.textContent || '').trim();
    if (!text) return null;

    if (!cfg.linksOnly) {
      return /^https?:\/\/\S+$/i.test(text) ? text : null;
    }

    const kids = Array.prototype.filter.call(p.children, (el) => el.tagName !== 'BR');
    if (kids.length !== 1) return null;

    const a = kids[0];
    if (a.tagName !== 'A' || !a.hasAttribute('href')) return null;
    if ((a.textContent || '').trim() !== text) return null;
    return a.href || null;
  }

  function run() {
    if (!isPageView()) return;

    document.querySelectorAll(cfg.scope).forEach((scope) => {
      if (cfg.exclude && scope.closest(cfg.exclude)) return;

      /* Static snapshot: each <p> is replaced while iterating. */
      Array.prototype.slice.call(scope.querySelectorAll('p')).forEach((p) => {
        if (!p.isConnected || p.closest('.auto-embed')) return;
        if (cfg.exclude && p.closest(cfg.exclude)) return;

        const url = candidateUrl(p);
        if (!url) return;

        const info = embedFor(url);
        if (!info) return;

        p.replaceWith(buildEmbed(info, 'Embedded content: ' + url));
      });
    });
  }

  /* The loader injects this file dynamically, so DOMContentLoaded may already
     have fired by the time it runs. */
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
  else run();
  window.addEventListener('bookstack-dom-change', run);
})();
