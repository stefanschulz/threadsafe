// SPIKE (phase 4, WP 1f): the browser asks for a probe file that must NOT be served; 200 with the right content = exposed.
const probes = ['spike-data-open/probe.txt', 'spike-data-denied/probe.txt', 'spike-data-missing/probe.txt'];
const out = document.getElementById('out');
out.textContent = '';
Promise.all(probes.map(async (p) => {
  try {
    const r = await fetch(p, { cache: 'no-store', credentials: 'omit' });
    const t = r.ok ? await r.text() : '';
    return p + ' -> ' + r.status + (t.trim() === 'threadsafe-probe-1234' ? ' CONTENT VISIBLE: EXPOSED' : ' not exposed');
  } catch (e) {
    return p + ' -> error ' + e.message;
  }
})).then((lines) => { out.textContent = lines.join('\n'); });
