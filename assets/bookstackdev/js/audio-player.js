/**
 * audio-player.js - turn an audio file link into an inline <audio> player.
 *
 * The link is recognised by the extension on its href, falling back to its
 * text (BookStack attachment links point at /attachments/<id> but are labelled
 * with the file name). Matching on the href first also survives
 * external-links.js appending its icon element inside the anchor, which
 * changes the anchor's textContent.
 */
(function () {
  'use strict';

  const cfg = Object.assign({
    scope: '.page-content, .comment-body, .description, .markdown-display',
    extensions: ['mp3', 'm4a'],
  }, (window.MILELO_CONFIG && window.MILELO_CONFIG.audioPlayer) || {});

  const MIME = {
    mp3: 'audio/mpeg',
    m4a: 'audio/mp4',
    ogg: 'audio/ogg',
    oga: 'audio/ogg',
    wav: 'audio/wav',
    flac: 'audio/flac',
    aac: 'audio/aac',
    opus: 'audio/ogg',
  };

  const exts = (Array.isArray(cfg.extensions) ? cfg.extensions : ['mp3', 'm4a'])
    .map((e) => String(e).replace(/^\./, '').toLowerCase())
    .filter(Boolean);

  const extOf = (s) => {
    const m = /\.([a-z0-9]+)$/i.exec(String(s || '').trim());
    return m ? m[1].toLowerCase() : '';
  };

  function audioExt(a) {
    let u;
    try {
      u = new URL(a.href, location.href);
    } catch (e) {
      return '';
    }
    /* mailto:, tel: and the like can never be played. */
    if (u.protocol !== 'http:' && u.protocol !== 'https:') return '';

    const fromHref = extOf(u.pathname);
    if (exts.indexOf(fromHref) !== -1) return fromHref;

    /* Text fallback for BookStack attachments, whose href is /attachments/<id>
       while the label carries the file name. Restricted to that shape: a plain
       navigation link that merely happens to be *titled* "notes.mp3" is a link,
       not a player, and replacing it used to remove it from the page. */
    if (!/\/attachments\/\d+/.test(u.pathname)) return '';
    const fromText = extOf(a.textContent);
    return exts.indexOf(fromText) !== -1 ? fromText : '';
  }

  function build(a, ext) {
    const label = (a.textContent || '').trim() || a.getAttribute('href') || 'Audio';
    const emoji = ext === 'mp3' ? '🎶' : '🎵';

    const wrapper = document.createElement('div');
    wrapper.className = 'audio-player-wrapper';

    const container = document.createElement('div');
    container.className = 'audio-player-container';

    const title = document.createElement('span');
    title.className = 'audio-player-title';
    title.textContent = emoji + ' ' + label;

    const audio = document.createElement('audio');
    audio.controls = true;
    audio.preload = 'metadata';
    audio.className = 'audio-element';

    const source = document.createElement('source');
    source.src = a.href;
    source.type = MIME[ext] || '';

    /* Content *inside* <audio> only renders where the element is unsupported -
       it is invisible when the element works but the source 404s, which left a
       dead player and no way to reach the file. Kept as a sibling instead and
       revealed on error. */
    const fallback = document.createElement('a');
    fallback.href = a.href;
    fallback.className = 'audio-player-fallback';
    fallback.textContent = 'Download ' + label;
    fallback.hidden = true;

    const reveal = () => {
      fallback.hidden = false;
    };
    audio.addEventListener('error', reveal);
    source.addEventListener('error', reveal);

    audio.appendChild(source);
    container.append(title, audio, fallback);
    wrapper.appendChild(container);
    return wrapper;
  }

  function run() {
    if (!exts.length) return;

    document.querySelectorAll(cfg.scope).forEach((scope) => {
      scope.querySelectorAll('a[href]:not([data-audio-checked])').forEach((a) => {
        a.dataset.audioChecked = '1';
        if (!a.href || a.closest('.audio-player-wrapper')) return;
        /* An anchor wrapping an image or an embed is a thumbnail, not a
           download link. */
        if (a.querySelector('img, picture, svg, video, .auto-embed, audio')) return;

        const ext = audioExt(a);
        if (!ext) return;

        const player = build(a, ext);
        if (a.parentNode) a.parentNode.replaceChild(player, a);
      });
    });
  }

  /* The loader injects this file dynamically. A dynamically inserted script is
     never a "defer" script no matter what the attribute says, so it can easily
     execute after DOMContentLoaded has already fired - registering only a
     DOMContentLoaded listener (the old behaviour) meant the players silently
     never appeared. */
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
  else run();
  window.addEventListener('bookstack-dom-change', run);
})();
