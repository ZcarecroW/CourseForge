/**
 * milelo.de_bookstack.js - single entry point for all BookStack customisations.
 *
 * Two ways to run it, and the same file for both:
 *
 *   Standalone. Host this folder (the loader plus css/ and js/) anywhere over
 *   HTTPS, edit CONFIG below, and paste one line into BookStack -> Settings ->
 *   Customization -> Custom HTML head:
 *     <script src="https://your-host/bs/js/milelo.de_bookstack.js"></script>
 *
 *   From CourseForge. CourseForge serves this file from its bs.php endpoint with
 *   the settings of a BookStackDev profile written into window.__cfBookStackDev
 *   just above it, and every sibling asset is fetched back through the same
 *   endpoint - which is what lets that endpoint refuse any origin the profile
 *   does not allow. When the boot object is there it wins over CONFIG, and the
 *   asset URLs carry the profile key instead of being resolved off this
 *   script's own address.
 *
 * Every value in CONFIG is documented in docs.md. Every module below reads its
 * own slice of it from window.MILELO_CONFIG once, at load time.
 */
(function () {
  'use strict';

  if (window.__mileloLoaded) return;
  window.__mileloLoaded = true;

  /* ======================= CONFIGURATION ======================= */
  const CONFIG = {
    // Root of the asset folder; null = derived from this script's URL ("<base>/js/..").
    baseUrl: null,

    theme: {
      toggleButton: true,                       // floating light/dark button
      position: 'bottom-left',                  // bottom-left | bottom-right | top-left | top-right
      offset: 20,                               // px from the two nearest edges
      size: 44,                                 // px, the button's diameter
      storageKey: 'bookstack-guest-dark-mode',  // guest preference key
    },

    page: {
      enabled: true,                            // base.css: zoom, colours, background
      zoom: 1.15,                               // .page-content zoom
      headingZoom: 0.9,                         // headings scaled back down inside it
      tintText: true,                           // slightly softer text and heading colours
      backgroundImage: '',                      // a URL, or '' for none
      backgroundOpacity: 0.05,                  // of that image, 0..1
    },

    markdown: {
      singleLineBreaks: true,                   // the editor preview treats a single \n as <br>
    },

    math: {
      enabled: true,
      url: 'https://cdn.jsdelivr.net/npm/mathjax@4/tex-mml-chtml.js',
      inlineMath: [['\\(', '\\)']],             // $ stays literal on purpose
      displayMath: [['$$', '$$']],
    },

    mermaid: {
      enabled: true,
      themeLight: 'default',                    // default | neutral | forest | base | dark
      themeDark: 'dark',
      url: 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.esm.min.mjs',
    },

    autoEmbed: {
      enabled: true,                            // YouTube/Spotify/CodePen ... iframes
      pageViewOnly: true,                       // never on a book/chapter/shelf overview
      linksOnly: true,                          // a real <a href>, never a plain-text URL
      scope: '.page-content',                   // where an embed may appear
    },

    audioPlayer: {
      enabled: true,                            // .mp3/.m4a links -> <audio>
      scope: '.page-content, .comment-body, .description, .markdown-display',
      extensions: ['mp3', 'm4a'],               // ogg/wav/flac/aac/opus also known
    },

    externalLinks: {
      enabled: true,
      newTab: true,
      icon: true,
      fontAwesome: false,                       // true = load the FA7 CDN stylesheet, use its glyph
      ignoreHosts: [],                          // extra hosts treated as internal
    },

    codeBlocks: {
      enabled: true,
      disableCodeMirror: true,                  // replace CM6 *viewers* with the highlighter
      // Read-only content areas. Used both by the highlighter (what may be
      // highlighted) and by the CM6 kill switch (what is a display context
      // rather than an editor) - the two used to disagree.
      containers: '.page-content, .page-revision, .comment-container, .comment-body,' +
        '.description, .book-content, .chapter-content, .markdown-display',
      themes: {light: 'one-light', dark: 'one-dark-pro'},
      wrap: true,                               // default soft wrap
      lineNumbers: true,                        // default gutter state
      collapseHeight: 560,                      // px, 0 = never collapse
      lazy: false,                              // highlight blocks near viewport first
      detect: true,                             // highlight.js auto-detection
      detectMinRelevance: 5,
      showDetectedHint: true,                   // "auto" badge
      skipLanguages: ['mermaid', 'mmd'],
      fallbackLang: 'plaintext',
      detectSubset: null,                       // null = hljs list minus low-trust
      shikiUrl: 'https://esm.sh/shiki@4',       // first entry of the CDN chain
      hljsUrl: 'https://esm.sh/highlight.js@11',
      debug: false,
    },
  };

  /* ================= configuration handed in by CourseForge ================= */
  /* A boot object carries a complete configuration, but a partial one must not
     break a module that reads a key CONFIG would have had - so it is merged one
     level deep over the defaults above rather than replacing them. */
  const boot = (window.__cfBookStackDev && typeof window.__cfBookStackDev === 'object')
    ? window.__cfBookStackDev : null;
  if (boot && boot.config && typeof boot.config === 'object') {
    Object.keys(boot.config).forEach((group) => {
      const given = boot.config[group];
      if (given && typeof given === 'object' && !Array.isArray(given)
        && CONFIG[group] && typeof CONFIG[group] === 'object') {
        CONFIG[group] = Object.assign({}, CONFIG[group], given);
      } else {
        CONFIG[group] = given;
      }
    });
  }
  window.MILELO_CONFIG = CONFIG;

  /* ======================= paths ======================= */
  let asset;
  if (boot && boot.base && boot.key) {
    /* Every sibling goes back through the endpoint that served this file, with
       the profile key and a version stamp, so a CourseForge update is a new
       URL rather than a stale cache. */
    const join = String(boot.base).indexOf('?') === -1 ? '?' : '&';
    const stamp = boot.version ? '&v=' + encodeURIComponent(String(boot.version)) : '';
    asset = (p) => String(boot.base) + join + 'k=' + encodeURIComponent(String(boot.key))
      + '&f=' + encodeURIComponent(p) + stamp;
  } else {
    const script = document.currentScript;
    /* A baseUrl of "https://cdn.example/bs" (no trailing slash) resolves as a
       *file* name, so "css/base.css" would land at "https://cdn.example/css/…"
       and every asset 404s silently. Force the trailing slash. */
    const baseRef = CONFIG.baseUrl ? String(CONFIG.baseUrl).replace(/\/?$/, '/') : '../';
    const base = new URL(baseRef, (script && script.src) || location.href);
    asset = (p) => new URL(p, base).href;
  }

  /* Our own assets are fetched with crossorigin="anonymous" when CourseForge
     serves them: that is what makes the browser send an Origin header, which is
     what the endpoint checks the link against. Third-party CDN files are left
     alone - they answer everybody and need no such thing. */
  const locked = !!(boot && boot.base && boot.key);
  const head = document.head || document.documentElement;

  const css = (href, id, own) => {
    if (id && document.getElementById(id)) return;
    const l = document.createElement('link');
    if (id) l.id = id;
    l.rel = 'stylesheet';
    l.href = href;
    if (own && locked) l.crossOrigin = 'anonymous';
    l.onerror = () => console.error('[milelo] stylesheet failed to load:', href);
    head.appendChild(l);
  };
  /* A dynamically inserted <script> is "async" by default and `defer` is
     ignored on it entirely - async=false is what actually restores insertion
     order. Note that such a script does NOT delay DOMContentLoaded either:
     every injected file must therefore cope with DOMContentLoaded having
     already fired, which is why each of them checks document.readyState rather
     than only registering a listener. */
  const js = (src, opt) => {
    const s = document.createElement('script');
    s.src = src;
    if (opt && opt.module) {
      s.type = 'module';
      /* Left async: the two modules are independent, and chaining them would
         make the code highlighter wait for Mermaid's CDN download on every
         page, including the ones with no diagrams. */
    } else {
      /* Classic files must keep their order - audio-player.js has to replace a
         link before external-links.js decorates it. */
      s.async = !!(opt && opt.async);
    }
    if (opt && opt.own && locked) s.crossOrigin = 'anonymous';
    s.onerror = () => console.error('[milelo] script failed to load:', src);
    head.appendChild(s);
  };

  /* =============== guest theme, applied before first paint =============== */
  /* Recorded BEFORE the guest value is applied: this is the theme BookStack
     itself rendered, and it is the only chance to capture it. theme-toggle.js
     needs it to undo a stale guest override for a signed-in user - reading the
     class later would just read back the override. */
  window.__mileloServerDark = document.documentElement.classList.contains('dark-mode');

  try {
    const pref = localStorage.getItem(CONFIG.theme.storageKey);
    /* Only 'true'/'false' are ours. Any other value used to be read as "force
       light", which silently overrode BookStack's own server-side preference;
       drop it instead so the install self-heals. theme-toggle.js applies the
       same rule. */
    if (pref === 'true' || pref === 'false') {
      document.documentElement.classList.toggle('dark-mode', pref === 'true');
    } else if (pref !== null) {
      localStorage.removeItem(CONFIG.theme.storageKey);
    }
  } catch (e) { /* storage blocked */
  }
  window.BOOKSTACK_THEME_STORAGE_KEY = CONFIG.theme.storageKey;

  /* =============== page styling, before base.css lands =============== */
  /* The knobs base.css and shiki-highlight.css read: set on the root before
     either stylesheet arrives, so the first paint is already the configured
     one. A number that is not a number keeps the stylesheet's own default. */
  const page = CONFIG.page || {};
  if (page.enabled !== false) {
    const root = document.documentElement.style;
    const num = (v) => (typeof v === 'number' && isFinite(v) && v > 0 ? v : null);
    const zoom = num(page.zoom);
    const headingZoom = num(page.headingZoom);
    if (zoom !== null) root.setProperty('--milelo-zoom', String(zoom));
    if (headingZoom !== null) root.setProperty('--milelo-heading-zoom', String(headingZoom));
    if (typeof page.backgroundImage === 'string' && page.backgroundImage.trim() !== '') {
      /* Built with the DOM's own escaping rather than pasted into a string:
         a quote in the address must not end the url() early. */
      root.setProperty('--milelo-bg-image', 'url("' + page.backgroundImage.trim().replace(/["\\]/g, '') + '")');
    }
    const opacity = (typeof page.backgroundOpacity === 'number' && isFinite(page.backgroundOpacity))
      ? Math.min(1, Math.max(0, page.backgroundOpacity)) : null;
    if (opacity !== null) root.setProperty('--milelo-bg-opacity', String(opacity));
    if (page.tintText !== false) document.documentElement.classList.add('milelo-tint');
  }

  /* ============ code language snapshot + CodeMirror 6 kill switch ==========
     Both must be set up synchronously, and neither has any consumer unless the
     kill switch is actually going to run: the snapshot Map exists solely to
     feed it, so gating the whole section stops an unused document-wide
     MutationObserver from retaining a copy of every code block on the page.

     BookStack removes the original <pre><code class="language-x"> when it
     mounts a CodeMirror viewer, so by the time 'library-cm6::post-init' fires
     the language hint no longer exists in the DOM. It is therefore recorded
     here, keyed by the code text, while the markup is still intact. The
     MutationObserver starts during HTML parsing - i.e. before BookStack's own
     DOMContentLoaded handlers run.

     `enabled` is part of the condition because docs.md promises that turning
     the highlighter off restores BookStack's own viewer; tearing CodeMirror
     down with no highlighter loaded left every code block unstyled instead. */
  if (CONFIG.codeBlocks.enabled && CONFIG.codeBlocks.disableCodeMirror) {
    const langByCode = new Map();
    const codeKey = (s) => String(s == null ? '' : s).replace(/\r\n?/g, '\n').replace(/\s+$/, '');

    const MERMAID_RE = /^\s*(?:%%\{[\s\S]*?\}%%\s*)?(?:graph\s+(?:TB|TD|BT|RL|LR)\b|flowchart(?:-elk)?\s+(?:TB|TD|BT|RL|LR)\b|sequenceDiagram|classDiagram(?:-v2)?|stateDiagram(?:-v2)?|erDiagram|journey|gantt|pie\b|requirementDiagram|gitGraph|mindmap|timeline|quadrantChart|zenuml|kanban|C4(?:Context|Container|Component|Dynamic|Deployment)|(?:sankey|xychart|block|packet|architecture|radar|treemap)-beta)\b/;

    function langOf(el) {
      if (!el) return '';
      const cls = (el.classList && el.classList.length)
        ? Array.prototype.join.call(el.classList, ' ')
        : (typeof el.className === 'string' ? el.className : '');
      const m = /(?:^|\s)(?:language|lang|highlight-source)-([\w+#.-]+)/i.exec(cls);
      const attr = el.getAttribute
        ? (el.getAttribute('data-lang') || el.getAttribute('data-language') || el.getAttribute('data-mode') || '')
        : '';
      return String(attr || (m && m[1]) || '').trim().toLowerCase();
    }

    function snapshotPre(pre) {
      if (!pre || !pre.querySelector) return;
      const codeEl = pre.querySelector('code') || pre;
      const lang = langOf(codeEl) || langOf(pre) || langOf(pre.parentElement);
      if (!lang) return;
      const key = codeKey(codeEl.textContent);
      if (key && !langByCode.has(key)) langByCode.set(key, lang);
    }

    function snapshotAll(root) {
      (root || document).querySelectorAll('pre').forEach(snapshotPre);
    }

    const snapshotObserver = new MutationObserver((muts) => {
      for (const mut of muts) {
        for (const node of mut.addedNodes) {
          if (node.nodeType !== 1) continue;
          if (node.tagName === 'PRE') snapshotPre(node);
          else if (node.querySelectorAll) node.querySelectorAll('pre').forEach(snapshotPre);
        }
      }
    });
    snapshotObserver.observe(document.documentElement, {childList: true, subtree: true});

    if (document.readyState !== 'loading') snapshotAll(document);
    else document.addEventListener('DOMContentLoaded', () => snapshotAll(document));

    /* Every CM6 viewer has mounted by 'load'; from then on the snapshot is dead
       weight that would otherwise grow for the lifetime of the page. */
    window.addEventListener('load', () => {
      snapshotObserver.disconnect();
      setTimeout(() => langByCode.clear(), 2000);
    });

    window.addEventListener('library-cm6::post-init', (event) => {
      let d;
      try {
        d = event.detail || {};
      } catch (err) {
        return;
      }
      /* BookStack has published the instance under two names over the years. */
      const view = d.editorView || d.editorViewInstance;
      if (!view || !view.dom || !view.state) return;

      const dom = view.dom;
      const parent = dom.parentElement;
      if (!parent) return;

      /* Only a read-only viewer is replaced. An editor - the markdown editor,
         the code-block dialog of the WYSIWYG editor, a settings field - keeps
         its CodeMirror: BookStack reads what was typed back out of that very
         instance when the dialog is saved, so swapping it for a textarea used
         to make the edit look accepted and then save the old text. */
      if (d.usage === 'markdown-editor' || d.usage === 'code-editor' || !dom.closest(CONFIG.codeBlocks.containers)) {
        return;
      }

      const code = view.state.doc.toString();

      /* 1) live DOM, 2) pre-CodeMirror snapshot, 3) mermaid content sniff. */
      const lang = langOf(parent) || langOf(dom)
        || langByCode.get(codeKey(code))
        || (MERMAID_RE.test(code) ? 'mermaid' : '');

      /* Feed the highlighter (or mermaid-render.js) instead of a dead viewer. */
      const node = document.createElement('pre');
      const c = document.createElement('code');
      if (lang) c.className = 'language-' + lang;
      c.textContent = code;
      node.appendChild(c);
      if (lang === 'mermaid' || lang === 'mmd') {
        node.className = 'mermaid-source';
        c.className = 'language-mermaid';
      }

      /* Insert first: view.destroy() detaches view.dom, after which
         parent.replaceChild(node, dom) would throw NotFoundError. */
      try {
        parent.insertBefore(node, dom);
        view.destroy();
        if (dom.parentNode) dom.parentNode.removeChild(dom);
      } catch (err) {
        console.warn('[milelo] CodeMirror replacement failed', err);
      }
    });
  }

  /* =============== markdown-it =============== */
  /* Only the Markdown editor's live preview reads this: BookStack renders a
     page saved through its API on the server, with its own rules. */
  if (CONFIG.markdown.singleLineBreaks) {
    window.addEventListener('editor-markdown::setup', (e) => {
      try {
        e.detail.markdownIt.set({breaks: true});
      } catch (err) { /* an editor build without markdown-it */
      }
    });
  }

  /* =============== MathJax =============== */
  if (CONFIG.math.enabled) {
    window.MathJax = {
      tex: {inlineMath: CONFIG.math.inlineMath, displayMath: CONFIG.math.displayMath},
      options: {
        /* Code the highlighter has not rendered yet sits in a <div>, not a
           <pre>, and a formula-shaped comment in it must not be typeset. */
        ignoreHtmlClass: 'shiki-block|mermaid|mermaid-source|cm-editor|auto-embed|tex2jax_ignore',
      },
      startup: {
        ready() {
          window.MathJax.startup.defaultReady();
          /* Content that arrives later - a page section loaded over AJAX, a
             CodeMirror viewer swapped out above - is typeset as well. */
          window.addEventListener('bookstack-dom-change', () => {
            window.MathJax.typesetPromise().catch((err) => console.warn('[milelo] MathJax typeset failed', err));
          });
        },
      },
    };
    /* MathJax is large and needs no ordering relative to our own files - left
       in-order it would hold every later script behind its download. */
    js(CONFIG.math.url, {async: true});
  }

  /* =============== assets =============== */
  if (page.enabled !== false) {
    css(asset('css/base.css'), 'milelo-base', true);
  }

  if (CONFIG.theme.toggleButton) {
    css(asset('css/theme-toggle.css'), 'milelo-theme-toggle', true);
    js(asset('js/theme-toggle.js'), {own: true});
  }

  if (CONFIG.mermaid.enabled) {
    css(asset('css/mermaid.css'), 'milelo-mermaid', true);
    js(asset('js/mermaid-render.js'), {module: true, own: true});
  }

  if (CONFIG.autoEmbed.enabled) {
    css(asset('css/auto-embed.css'), 'milelo-auto-embed', true);
    js(asset('js/auto-embed.js'), {own: true});
  }

  if (CONFIG.audioPlayer.enabled) {
    css(asset('css/audio-player.css'), 'milelo-audio-player', true);
    js(asset('js/audio-player.js'), {own: true});
  }

  if (CONFIG.externalLinks.enabled) {
    if (CONFIG.externalLinks.fontAwesome) {
      css('https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@7/css/all.min.css', 'milelo-fa', false);
    }
    css(asset('css/external-links.css'), 'milelo-external-links', true);
    js(asset('js/external-links.js'), {own: true});
  }

  if (CONFIG.codeBlocks.enabled) {
    window.SHIKI_CODE_CONFIG = CONFIG.codeBlocks;
    css(asset('css/shiki-highlight.css'), 'shiki-code-css', true);
    js(asset('js/shiki/index.js'), {module: true, own: true});
  }
})();
