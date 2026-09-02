(function () {
  'use strict';

  const cfg = Object.assign(
    {newTab: true, icon: true, fontAwesome: false, ignoreHosts: []},
    (window.MILELO_CONFIG && window.MILELO_CONFIG.externalLinks) || {},
  );

  const SCOPE = '.page-content, .comment-body, .description, .markdown-display';
  const internal = new Set([location.hostname].concat(cfg.ignoreHosts || []));

  function isExternal(a) {
    if (!a.hasAttribute('href')) return false;
    const href = a.getAttribute('href');
    if (/^(#|mailto:|tel:|javascript:)/i.test(href)) return false;
    /* Equality, not startsWith: a.protocol is the *page's* scheme for a
       relative href, and any scheme merely beginning with "http" would pass. */
    return (a.protocol === 'http:' || a.protocol === 'https:')
      && !!a.hostname && !internal.has(a.hostname);
  }

  function decorate(root) {
    (root || document).querySelectorAll(SCOPE).forEach((scope) => {
      scope.querySelectorAll('a[href]:not([data-external-checked])').forEach((a) => {
        a.dataset.externalChecked = '1';
        if (!isExternal(a)) return;

        /* A link wrapping media still wants the safe target/rel treatment - it
           just must not get a text icon appended after the image. */
        const hasMedia = !!a.querySelector('img, picture, svg, video, .auto-embed');

        a.classList.add('external-link');
        if (cfg.newTab) {
          a.target = '_blank';
          /* Rebuild rather than append: re-decoration (or an author-supplied
             rel) used to produce "noopener noreferrer noopener noreferrer". */
          const rel = (a.rel || '').split(/\s+/).filter(Boolean);
          ['noopener', 'noreferrer'].forEach((t) => {
            if (rel.indexOf(t) === -1) rel.push(t);
          });
          a.rel = rel.join(' ');
        }
        if (!a.title) a.title = 'Opens ' + a.hostname + (cfg.newTab ? ' in a new tab' : '');
        if (hasMedia) return;

        if (cfg.icon && cfg.fontAwesome) {
          const i = document.createElement('i');
          i.className = 'fa-solid fa-arrow-up-right-from-square external-link-icon';
          i.setAttribute('aria-hidden', 'true');
          a.appendChild(document.createTextNode('\u2009'));
          a.appendChild(i);
        } else if (cfg.icon) {
          a.classList.add('external-link--css-icon');
        }
      });
    });
  }

  const run = () => decorate(document);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
  else run();
  window.addEventListener('bookstack-dom-change', run);
})();