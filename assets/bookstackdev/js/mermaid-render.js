/* This module renders diagrams and nothing else. It used to also write the
   guest dark-mode preference into localStorage on every page load, which meant
   a signed-in user's server-side theme was captured into the guest key and then
   re-applied before first paint on the next visit - overriding whatever the
   server had actually sent. theme-toggle.js is the only owner of that key. */

const cfg = Object.assign({
  themeLight: "default",
  themeDark: "dark",
  url: "https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.esm.min.mjs",
}, (window.MILELO_CONFIG && window.MILELO_CONFIG.mermaid) || {});

/* The library comes from a CDN, with the configured address first and two
   fallbacks behind it - the same chain the code highlighter uses, and for the
   same reason: one unreachable host must not mean no diagrams. A static import
   could name only one address and could not be configured at all. */
const uniq = (list) => list.filter((v, i, a) => typeof v === "string" && v && a.indexOf(v) === i);
const URLS = uniq([
  cfg.url,
  "https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.esm.min.mjs",
  "https://esm.sh/mermaid@11",
  "https://unpkg.com/mermaid@11/dist/mermaid.esm.min.mjs",
]);

async function loadMermaid() {
  let lastErr = null;
  for (const url of URLS) {
    try {
      const mod = await import(/* @vite-ignore */ url);
      const lib = (mod && (mod.default || mod)) || null;
      if (lib && typeof lib.run === "function" && typeof lib.initialize === "function") return lib;
      lastErr = new Error("Unexpected Mermaid module shape from " + url);
    } catch (err) {
      lastErr = err;
      console.warn("[milelo] Mermaid CDN failed:", url, err);
    }
  }
  throw lastErr || new Error("Mermaid could not be loaded");
}

const mermaid = await loadMermaid();

function isBookStackDarkMode() {
  return document.documentElement.classList.contains("dark-mode");
}

/* Only the themes Mermaid ships; anything else would throw inside render(). */
const THEMES = ["default", "dark", "neutral", "forest", "base"];
const theme = (name, fallback) => (THEMES.indexOf(name) === -1 ? fallback : name);

function getMermaidTheme() {
  return isBookStackDarkMode() ? theme(cfg.themeDark, "dark") : theme(cfg.themeLight, "default");
}

const BASE_CONFIG = {
  startOnLoad: false,
  securityLevel: "strict",
  flowchart: {
    useMaxWidth: false,
    htmlLabels: true,
    nodeSpacing: 30,
    rankSpacing: 50
  }
};

let currentTheme = getMermaidTheme();
mermaid.initialize({...BASE_CONFIG, theme: currentTheme});

// Collect all supported Mermaid code block variants
function findMermaidSources() {
  const selectors = [
    "pre.mermaid",
    "pre > code.language-mermaid",
    "pre > code.lang-mermaid",
    "code.language-mermaid"
  ];
  const nodes = Array.from(document.querySelectorAll(selectors.join(",")))
    .filter(n => !n.closest(".mermaid[data-original-code]"));
  /* A <pre class="mermaid"> holding a <code class="language-mermaid"> matches
     twice and both hits resolve to the same <pre>; replacing it twice left the
     first container detached and the diagram missing. */
  return Array.from(new Set(nodes.map(n =>
    (n.tagName.toLowerCase() === "code" && n.parentElement?.tagName.toLowerCase() === "pre")
      ? n.parentElement
      : n
  )));
}

function extractCodeFromNode(node) {
  const codeEl = node.tagName.toLowerCase() === "pre"
    ? (node.querySelector("code") || node)
    : node;
  return (codeEl.textContent || "").trim();
}

function replaceWithContainer(originalNode, code) {
  const container = document.createElement("div");
  container.className = "mermaid";
  container.textContent = code;
  container.style.visibility = "hidden";
  originalNode.replaceWith(container);
  return container;
}

// Ensure layout is fully settled before rendering
async function waitForPageReady() {
  await new Promise(resolve => {
    if (document.readyState === "complete") return resolve();
    window.addEventListener("load", () => resolve(), {once: true});
  });
  /* requestAnimationFrame does not fire in a background tab, which stalled the
     serialised queue until the tab was focused - and every later pass with it.
     The setTimeout below already provides the settle delay. */
  if (document.visibilityState === "visible") {
    await Promise.race([
      new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r))),
      new Promise(r => setTimeout(r, 300)),
    ]);
  }
  await new Promise(r => setTimeout(r, 150));
}

async function renderAllMermaid() {
  if (!findMermaidSources().length) return;

  /* Settle the layout FIRST. Detaching the sources up front (the old order)
     replaced readable code with hidden empty containers and left a blank gap
     for as long as the wait took. */
  await waitForPageReady();

  const containers = [];
  for (const src of findMermaidSources()) {
    if (!src.parentNode) continue;
    const code = extractCodeFromNode(src);
    if (!code) continue;
    const container = replaceWithContainer(src, code);
    container.setAttribute("data-original-code", code);
    containers.push(container);
  }
  if (!containers.length) return;

  for (const el of containers) {
    try {
      await mermaid.run({nodes: [el]});
    } catch (e) {
      console.error("Mermaid render failed for element:", el, e);
    }
    el.style.visibility = "visible";
  }
}

/* A diagram keeps whatever theme it was rendered with, so a light/dark switch
   has to redraw it from the source text kept in data-original-code. */
async function retheme() {
  const next = getMermaidTheme();
  if (next === currentTheme) return;
  currentTheme = next;

  /* Reconfigure before the early return: currentTheme is already committed
     above, so bailing out first would leave the library on the old theme while
     this module believed otherwise - and a diagram arriving later on the same
     page would then render in the wrong one. */
  mermaid.initialize({...BASE_CONFIG, theme: currentTheme});

  const rendered = Array.from(document.querySelectorAll(".mermaid[data-original-code]"));
  if (!rendered.length) return;

  for (const el of rendered) {
    el.removeAttribute("data-processed");
    el.innerHTML = "";
    el.textContent = el.getAttribute("data-original-code") || "";
    try {
      await mermaid.run({nodes: [el]});
    } catch (e) {
      console.error("Mermaid re-render failed for element:", el, e);
    }
    el.style.visibility = "visible";
  }
}

/* Diagrams can appear late: the CodeMirror kill switch rebuilds
   <pre><code class="language-mermaid"> only after 'library-cm6::post-init'.
   Runs are serialised so two passes can never render the same node twice. */
let queue = Promise.resolve();
let rescanTimer = null;

function enqueue(task) {
  queue = queue.then(task).catch(e => console.error("Mermaid pass failed:", e));
  return queue;
}

const scheduleRender = () => enqueue(renderAllMermaid);

function debouncedRender() {
  clearTimeout(rescanTimer);
  rescanTimer = setTimeout(scheduleRender, 60);
}

scheduleRender();

window.addEventListener("load", debouncedRender);
window.addEventListener("bookstack-dom-change", debouncedRender);
window.addEventListener("milelo-theme-change", () => enqueue(retheme));

const WATCH = "pre.mermaid, code.language-mermaid, code.lang-mermaid";
new MutationObserver(muts => {
  for (const m of muts) {
    for (const n of m.addedNodes) {
      if (n.nodeType !== 1) continue;
      if ((n.matches && n.matches(WATCH)) || (n.querySelector && n.querySelector(WATCH))) {
        debouncedRender();
        return;
      }
    }
  }
}).observe(document.documentElement, {childList: true, subtree: true});

window.MermaidRender = {render: scheduleRender, retheme: () => enqueue(retheme)};
