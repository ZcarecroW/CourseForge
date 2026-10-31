/* CourseForge documentation - the little a static page needs: the theme
   switch, the navigation drawer, copy buttons, the "on this page" highlight
   and search. Search loads its index the first time it is opened, so a page
   that is only read never fetches it. */
(() => {
  const root = document.documentElement;
  const base = document.body.dataset.root || './';

  /* ---------------------------------------------------------- theme */
  const themeButtons = document.querySelectorAll('[data-theme-set]');
  const showTheme = () => {
    const current = root.dataset.theme || 'auto';
    themeButtons.forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.themeSet === current)));
  };
  themeButtons.forEach((b) => b.addEventListener('click', () => {
    const value = b.dataset.themeSet;
    if (value === 'auto') delete root.dataset.theme; else root.dataset.theme = value;
    try { value === 'auto' ? localStorage.removeItem('cf-docs-theme') : localStorage.setItem('cf-docs-theme', value); } catch { /* private mode */ }
    showTheme();
  }));
  showTheme();

  /* ------------------------------------------------------ nav drawer */
  const menu = document.querySelector('.topbar__menu');
  const scrim = document.querySelector('.nav-scrim');
  const setNav = (open) => {
    document.body.classList.toggle('nav-open', open);
    menu?.setAttribute('aria-expanded', String(open));
    if (scrim) scrim.hidden = !open;
  };
  menu?.addEventListener('click', () => setNav(!document.body.classList.contains('nav-open')));
  scrim?.addEventListener('click', () => setNav(false));
  document.querySelector('.nav a[aria-current="page"]')?.scrollIntoView({ block: 'center' });

  /* ---------------------------------------------------- copy buttons */
  document.addEventListener('click', async (e) => {
    const button = e.target.closest('.code__copy');
    if (!button) return;
    const text = button.closest('.code').querySelector('pre').innerText.replace(/\n$/, '');
    try {
      await navigator.clipboard.writeText(text);
      button.classList.add('is-done');
      button.querySelector('span').textContent = 'Copied';
      setTimeout(() => { button.classList.remove('is-done'); button.querySelector('span').textContent = 'Copy'; }, 1600);
    } catch { /* clipboard refused: the text is still selectable */ }
  });

  /* ------------------------------------------------- on this page */
  const tocLinks = new Map([...document.querySelectorAll('.toc a')].map((a) => [a.hash.slice(1), a]));
  if (tocLinks.size && 'IntersectionObserver' in window) {
    const visible = new Set();
    const headings = [...tocLinks.keys()].map((id) => document.getElementById(id)).filter(Boolean);
    const mark = () => {
      const first = headings.find((h) => visible.has(h)) ?? headings.filter((h) => h.getBoundingClientRect().top < 120).pop();
      tocLinks.forEach((a) => a.removeAttribute('aria-current'));
      if (first) tocLinks.get(first.id)?.setAttribute('aria-current', 'true');
    };
    const io = new IntersectionObserver((entries) => {
      entries.forEach((en) => (en.isIntersecting ? visible.add(en.target) : visible.delete(en.target)));
      mark();
    }, { rootMargin: '-60px 0px -60% 0px' });
    headings.forEach((h) => io.observe(h));
  }

  /* ----------------------------------------------------------- search */
  const dialog = document.querySelector('dialog.search');
  const input = dialog.querySelector('input');
  const list = dialog.querySelector('.search__results');
  let index = null;
  let loading = null;
  let selected = 0;

  const load = () => loading ??= fetch(base + 'search.json').then((r) => r.json()).then((data) => {
    index = data.map((e) => ({ ...e, lp: e.p.toLowerCase(), lh: e.h.toLowerCase(), lt: e.t.toLowerCase() }));
  }).catch(() => { index = []; });

  const escapeHtml = (s) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const highlight = (text, terms) => {
    let html = escapeHtml(text);
    for (const t of terms) {
      const safe = escapeHtml(t).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      html = html.replace(new RegExp(`(${safe})`, 'gi'), '<mark>$1</mark>');
    }
    return html;
  };
  const snippet = (entry, terms) => {
    const at = Math.max(0, ...terms.map((t) => entry.lt.indexOf(t)).filter((i) => i >= 0).slice(0, 1));
    const start = Math.max(0, at - 60);
    const text = (start ? '…' : '') + entry.t.slice(start, start + 170) + (entry.t.length > start + 170 ? '…' : '');
    return highlight(text, terms);
  };

  const search = (query) => {
    const terms = query.toLowerCase().split(/\s+/).filter(Boolean);
    if (!terms.length || !index) return [];
    const results = [];
    for (const e of index) {
      let score = 0;
      for (const t of terms) {
        const inHead = e.lh.includes(t);
        const inPage = e.lp.includes(t);
        const at = e.lt.indexOf(t);
        if (!inHead && !inPage && at < 0) { score = -1; break; }
        if (inHead) score += e.lh === t ? 30 : (new RegExp(`\\b${t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`).test(e.lh) ? 14 : 8);
        if (inPage) score += 6;
        if (at >= 0) score += 1 + Math.min(4, e.lt.split(t).length - 1) * 0.4;
      }
      if (score <= 0) continue;
      // The words in the order they were typed outrank the same words scattered.
      const phrase = terms.join(' ');
      if (terms.length > 1) score += (e.lh.includes(phrase) ? 20 : 0) + (e.lt.includes(phrase) ? 12 : 0);
      results.push({ e, score: score + (e.h ? 0 : 2) });
    }
    return results.sort((a, b) => b.score - a.score).slice(0, 30).map((r) => r.e);
  };

  const render = () => {
    const q = input.value.trim();
    const terms = q.toLowerCase().split(/\s+/).filter(Boolean);
    if (!q) { list.innerHTML = ''; return; }
    const hits = search(q);
    selected = 0;
    list.innerHTML = hits.length
      ? hits.map((e, i) => `<li><a href="${base}${e.u}" role="option" aria-selected="${i === 0}"><span class="search__where">${escapeHtml(e.s)} › ${escapeHtml(e.p)}</span><span class="search__head">${highlight(e.h || e.p, terms)}</span><span class="search__snip">${snippet(e, terms)}</span></a></li>`).join('')
      : `<li class="search__empty">Nothing matches “${escapeHtml(q)}”.</li>`;
  };
  const move = (step) => {
    const links = [...list.querySelectorAll('a')];
    if (!links.length) return;
    selected = (selected + step + links.length) % links.length;
    links.forEach((a, i) => a.setAttribute('aria-selected', String(i === selected)));
    links[selected].scrollIntoView({ block: 'nearest' });
  };

  const open = () => {
    if (dialog.open) return;
    setNav(false);
    dialog.showModal();
    input.select();
    load().then(render);
  };
  document.querySelector('.search-button')?.addEventListener('click', open);
  document.addEventListener('keydown', (e) => {
    if ((e.key === 'k' && (e.ctrlKey || e.metaKey)) || (e.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName))) {
      e.preventDefault();
      open();
    } else if (e.key === 'Escape' && document.body.classList.contains('nav-open')) {
      setNav(false);
    }
  });
  input.addEventListener('input', render);
  input.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
    else if (e.key === 'Enter') {
      const a = list.querySelectorAll('a')[selected];
      if (a) { e.preventDefault(); location.href = a.href; dialog.close(); }
    }
  });
  list.addEventListener('click', (e) => { if (e.target.closest('a')) dialog.close(); });
  dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });
})();
