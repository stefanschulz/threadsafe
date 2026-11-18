// Phase 14, first cut of the browser proof (docs/PHASE14.md 4.2): the page *Security* in a real Chrome with a virtual authenticator.
// It starts the web interface with PHP's built-in server on http://localhost:PORT (localhost is a secure context) over a throw-away data
// directory, logs in, adds a passkey through the page (the script of public/app.js does the ceremony), and reads the result from the page.
// Package 7 grows this into the proof of the stack (Apache with the limits, all three database servers).
//
//   node tools/spikes/passkey-page.mjs [--php=PATH] [--browser=PATH] [--shot=FILE]
import { spawn, spawnSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, readFileSync, existsSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

const args = Object.fromEntries(process.argv.slice(2).map((a) => a.replace(/^--/, '').split('=')));
const root = resolve(import.meta.dirname, '..', '..');
const php = args.php ?? 'php';
const browser = [args.browser, 'C:/Program Files/Google/Chrome/Application/chrome.exe', '/usr/bin/google-chrome', '/usr/bin/chromium'].filter(Boolean).find((p) => existsSync(p));
if (!browser) { console.error('No Chrome found'); process.exit(2); }

const data = mkdtempSync(join(tmpdir(), 'ts-pk-data-'));
const profile = mkdtempSync(join(tmpdir(), 'ts-pk-chrome-'));
const passwordFile = join(data, 'pw.txt');
writeFileSync(passwordFile, 'correct horse battery\n');
const env = { ...process.env, THREADSAFE_DATA: join(data, 'data') };
for (const cmd of [['admin', 'password', `--password-file=${passwordFile}`], ['admin', 'enable']]) {
  const r = spawnSync(php, [join(root, 'bin', 'threadsafe'), ...cmd], { env, encoding: 'utf8' });
  if (r.status !== 0) { console.error(r.stdout, r.stderr); process.exit(3); }
}

const port = 8300 + Math.floor(Math.random() * 500);
const server = spawn(php, ['-S', `localhost:${port}`, '-t', join(root, 'public'), join(root, 'public', 'index.php')], { env, stdio: 'ignore' });
const debugPort = 9300 + Math.floor(Math.random() * 500);
const child = spawn(browser, ['--headless=new', `--remote-debugging-port=${debugPort}`, `--user-data-dir=${profile}`, '--no-first-run', '--window-size=1100,1400', 'about:blank'], { stdio: 'ignore' });
const stop = () => { try { child.kill(); } catch {} try { server.kill(); } catch {} setTimeout(() => { for (const d of [data, profile]) { try { rmSync(d, { recursive: true, force: true }); } catch {} } }, 1500); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function target() {
  for (let i = 0; i < 50; i++) {
    try { const l = await (await fetch(`http://127.0.0.1:${debugPort}/json/list`)).json(); const p = l.find((t) => t.type === 'page'); if (p) return p.webSocketDebuggerUrl; } catch {}
    await sleep(200);
  }
  throw new Error('no debugging port');
}
const ws = new WebSocket(await target());
await new Promise((r) => ws.addEventListener('open', r, { once: true }));
let seq = 0; const waiting = new Map();
ws.addEventListener('message', (e) => { const m = JSON.parse(e.data); if (m.id && waiting.has(m.id)) { waiting.get(m.id)(m); waiting.delete(m.id); } });
const cdp = (method, params = {}) => new Promise((resolveCall, reject) => { const id = ++seq; waiting.set(id, (m) => (m.error ? reject(new Error(`${method}: ${m.error.message}`)) : resolveCall(m.result))); ws.send(JSON.stringify({ id, method, params })); });
const run = async (expression) => { const r = await cdp('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true, userGesture: true }); if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description ?? JSON.stringify(r.exceptionDetails)); return r.result.value; };
const go = async (path) => { await cdp('Page.navigate', { url: `http://localhost:${port}${path}` }); await sleep(1200); };

let failed = 0;
const check = (name, ok, detail = '') => { console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${detail ? ' - ' + detail : ''}`); if (!ok) failed++; };
try {
  await cdp('Page.enable');
  await cdp('WebAuthn.enable', { enableUI: false });
  const { authenticatorId } = await cdp('WebAuthn.addVirtualAuthenticator', { options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true } });

  await go('/login');
  await run(`document.querySelector('input[name=user]').value='admin'; document.querySelector('input[name=password]').value='correct horse battery'; document.querySelector('form').submit();`);
  await sleep(1500);
  await go('/security');
  check('the page shows the section and the form', await run(`!!document.getElementById('passkey-add') && document.body.innerText.includes('Passkeys')`));
  check('the form is visible with a script', await run(`getComputedStyle(document.getElementById('passkey-add')).display !== 'none'`));

  await run(`document.querySelector('#passkey-add input[name=passkey_name]').value='Virtual key'; document.querySelector('#passkey-add input[name=passkey_password]').value='correct horse battery'; document.querySelector('#passkey-add button[type=submit]').click();`);
  await sleep(2500);
  const text = await run(`document.body.innerText`);
  check('the passkey is listed after the registration', text.includes('Virtual key') && text.includes('The passkey is added.'), text.includes('Virtual key') ? '' : text.slice(0, 300));
  const creds = await cdp('WebAuthn.getCredentials', { authenticatorId });
  check('the virtual device holds one credential for localhost', creds.credentials.length === 1 && creds.credentials[0].rpId === 'localhost');

  // a wrong password: the page says so and makes nothing
  await go('/security');
  await run(`document.querySelector('#passkey-add input[name=passkey_password]').value='wrong wrong wrong'; document.querySelector('#passkey-add button[type=submit]').click();`);
  await sleep(1500);
  const refused = await run(`document.getElementById('passkey-message').textContent`);
  check('a wrong password is refused with a message', /password is wrong/i.test(refused), refused);

  // the same device again: the browser refuses (excludeCredentials)
  await run(`document.querySelector('#passkey-add input[name=passkey_password]').value='correct horse battery'; document.querySelector('#passkey-add button[type=submit]').click();`);
  await sleep(2000);
  const again = await run(`document.getElementById('passkey-message').textContent`);
  check('the same device is not added twice', /already/i.test(again), again);

  // the contrast and the structure of the page in both schemes (docs/DESIGN.md, "Accessibility")
  await go('/security');
  const audit = await run(readFileSync(join(root, 'tools', 'a11y-audit.js'), 'utf8'));
  const dirty = ['light', 'dark'].flatMap((scheme) => ['text', 'ui', 'structure'].flatMap((k) => (audit[scheme][k].length ? [`${scheme} ${k}: ${JSON.stringify(audit[scheme][k])}`] : [])).concat(audit[scheme].scrollX ? [`${scheme} scrolls sideways`] : []));
  check('the audit finds no contrast or structure problem on the page', dirty.length === 0, dirty.join('; ') || `${audit.light.checked} texts checked`);

  if (args.shot) {
    const shot = await cdp('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
    writeFileSync(args.shot, Buffer.from(shot.data, 'base64'));
  }
} finally { stop(); }
console.log(failed === 0 ? 'all checks passed' : `${failed} check(s) failed`);
process.exit(failed === 0 ? 0 : 1);
