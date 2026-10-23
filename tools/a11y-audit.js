// Accessibility audit of a page of the web interface, for the browser (docs/DESIGN.md, "Accessibility").
// Paste it into the console of a logged-in page, or inject it as a script that is served from the same origin (the content security policy
// allows nothing else). It returns, for the light and for the dark scheme (it sets data-theme and puts it back): the text that falls below
// 4.5:1 (3:1 for large text) against the background it really has (layers and alpha composed), the controls whose edge falls below 3:1, and
// the structure: names of buttons and links, labels of fields, duplicate ids, the headings, the landmarks, the language, the skip link,
// and whether the page scrolls sideways. Empty lists are what you want. The wizard has no data-theme: run it twice under each system scheme.
(() => {
  const parse = (c) => {
    let m = c.match(/^rgba?\(([^)]+)\)/);
    if (m) { const p = m[1].split(/[ ,\/]+/).filter(Boolean).map(Number); return [p[0], p[1], p[2], p.length > 3 ? p[3] : 1]; }
    m = c.match(/^color\(srgb ([^)]+)\)/);
    if (m) { const p = m[1].split(/[ \/]+/).filter(Boolean).map(Number); return [p[0] * 255, p[1] * 255, p[2] * 255, p.length > 3 ? p[3] : 1]; }
    return [0, 0, 0, 0];
  };
  const over = (t, b) => { const a = t[3] + b[3] * (1 - t[3]); return a === 0 ? [0, 0, 0, 0] : [0, 1, 2].map(i => (t[i] * t[3] + b[i] * b[3] * (1 - t[3])) / a).concat(a); };
  const lum = (c) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]); };
  const ratio = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05); };
  const bgOf = (el) => { const layers = []; for (let e = el; e; e = e.parentElement) { const c = parse(getComputedStyle(e).backgroundColor); if (c[3] > 0) { layers.push(c); if (c[3] >= 1) break; } } let r = [255, 255, 255, 1]; for (let i = layers.length - 1; i >= 0; i--) { r = over(layers[i], r); } return r; };
  const visible = (el) => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return s.visibility !== 'hidden' && s.display !== 'none' && r.width > 2 && r.height > 2; };
  const label = (el) => (el.getAttribute('aria-label') || el.textContent || el.getAttribute('title') || '').trim();
  const audit = (theme) => {
    const root = document.documentElement;
    if (theme === 'auto') { root.removeAttribute('data-theme'); } else { root.setAttribute('data-theme', theme); }
    const text = [];
    let checked = 0;
    document.body.querySelectorAll('*').forEach((el) => {
      if (['SCRIPT', 'STYLE', 'OPTION', 'svg', 'path'].includes(el.tagName)) { return; }
      const own = [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim().length > 0);
      const isField = el.matches('input:not([type=hidden]):not([type=checkbox]),textarea,select');
      if ((!own && !isField) || !visible(el)) { return; }
      const s = getComputedStyle(el);
      const bg = bgOf(el);
      const fg = over(parse(s.color), bg);
      const size = parseFloat(s.fontSize);
      const large = size >= 24 || (size >= 18.66 && parseInt(s.fontWeight, 10) >= 700);
      const need = large ? 3 : 4.5;
      const r = ratio(fg, bg);
      checked++;
      if (r < need) { text.push(`${el.tagName.toLowerCase()}.${(el.className || '').toString().split(' ')[0]} "${(el.textContent || el.value || '').trim().slice(0, 28)}" ${r.toFixed(2)} (need ${need})`); }
      if (isField && el.placeholder) { const pc = over(parse(getComputedStyle(el, '::placeholder').color), bg); const pr = ratio(pc, bg); if (pr < 4.5) { text.push(`placeholder of ${el.name} ${pr.toFixed(2)}`); } }
    });
    // boundaries of controls against what is around them (3:1)
    const ui = [];
    document.body.querySelectorAll('input:not([type=hidden]):not([type=checkbox]),textarea,select,button.secondary,.button.secondary,.theme').forEach((el) => {
      if (!visible(el)) { return; }
      const s = getComputedStyle(el);
      const edge = parse(s.borderTopColor);
      const around = bgOf(el.parentElement);
      const r = ratio(over(edge, around), around);
      if (r < 3) { ui.push(`${el.tagName.toLowerCase()}.${(el.className || '').toString().split(' ')[0]} edge ${r.toFixed(2)}`); }
    });
    // the structure
    const structure = [];
    document.body.querySelectorAll('button,a[href],[role=button]').forEach((el) => { if (!label(el)) { structure.push('no name: ' + el.outerHTML.slice(0, 60)); } });
    document.body.querySelectorAll('input:not([type=hidden]),textarea,select').forEach((el) => { if (!(el.labels && el.labels.length) && !el.getAttribute('aria-label') && !el.getAttribute('aria-labelledby')) { structure.push('no label: ' + el.name); } });
    const ids = {}; document.querySelectorAll('[id]').forEach((e) => { ids[e.id] = (ids[e.id] || 0) + 1; });
    Object.keys(ids).filter((k) => ids[k] > 1).forEach((k) => structure.push('duplicate id ' + k));
    const h = [...document.querySelectorAll('h1,h2,h3,h4')].map((e) => +e.tagName[1]);
    if (h.filter((x) => x === 1).length !== 1) { structure.push('h1 count ' + h.filter((x) => x === 1).length); }
    h.forEach((x, i) => { if (i > 0 && x - h[i - 1] > 1) { structure.push(`heading jumps ${h[i - 1]} -> ${x}`); } });
    const lm = { main: document.querySelectorAll('main').length, nav: [...document.querySelectorAll('nav')].filter((n) => !n.getAttribute('aria-label')).length, asideNoName: [...document.querySelectorAll('aside')].filter((n) => !n.getAttribute('aria-label')).length };
    if (lm.main !== 1) { structure.push('main ' + lm.main); }
    if (lm.nav) { structure.push('nav without a name'); }
    if (lm.asideNoName) { structure.push('aside without a name'); }
    if (!root.getAttribute('lang')) { structure.push('no lang'); }
    if (document.querySelector('[tabindex]:not([tabindex="0"]):not([tabindex="-1"])')) { structure.push('positive tabindex'); }
    if (!document.querySelector('a.skip') && !document.querySelector('.steps')) { structure.push('no skip link'); }
    return { checked, text: text.slice(0, 12), textTotal: text.length, ui, structure, scrollX: document.documentElement.scrollWidth > window.innerWidth };
  };
  const out = { page: location.pathname, light: audit('light'), dark: audit('dark') };
  audit('auto');
  return out;
})()
