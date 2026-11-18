// Phase 14, work package 7 (docs/PHASE14.md 4.2): the proof of the passkeys in a real browser. Chrome (or Edge) runs headless with a virtual
// authenticator, a real WebAuthn implementation without hardware, and drives the web interface through its own pages: logging in with the
// password, adding a passkey, logging in with it, a second key, a passkey instead of the code of the second factor, removing a key, a page that
// cannot bind passkeys (an IP address), and the accessibility audit of the pages. `localhost` is a secure context, so no certificate is needed.
//
//   node tests/stack/passkey-browser.mjs --url=http://localhost:8390 --password=PASSWORD    against a running web interface (the stack, phase 4h of run.sh)
//   node tests/stack/passkey-browser.mjs                                                    on its own: PHP's built-in server over a throw-away data directory
//
// Options: --php=PATH --browser=PATH --shot=FILE. Exit status 0 only if every check passed. It leaves the installation as it found it: no passkey
// and no second factor.
import { spawn, spawnSync } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { mkdtempSync, writeFileSync, readFileSync, existsSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

const args = Object.fromEntries(process.argv.slice(2).map((a) => a.replace(/^--/, '').split(/=(.*)/s).slice(0, 2)));
const root = resolve(import.meta.dirname, '..', '..');
const browser = [args.browser, 'C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'].filter(Boolean).find((p) => existsSync(p));
if (!browser) { console.error('No Chrome or Edge found; pass --browser=PATH'); process.exit(2); }

const cleanup = [];
let base = args.url;
let password = args.password ?? 'correct horse battery';
let server = null;
if (!base) {
  // on its own: a throw-away installation behind PHP's built-in server
  const php = args.php ?? 'php';
  const data = mkdtempSync(join(tmpdir(), 'ts-pk-data-'));
  cleanup.push(data);
  const passwordFile = join(data, 'pw.txt');
  writeFileSync(passwordFile, password + '\n');
  const env = { ...process.env, THREADSAFE_DATA: join(data, 'data') };
  for (const cmd of [['admin', 'password', `--password-file=${passwordFile}`], ['admin', 'enable']]) {
    const r = spawnSync(php, [join(root, 'bin', 'threadsafe'), ...cmd], { env, encoding: 'utf8' });
    if (r.status !== 0) { console.error(r.stdout, r.stderr); process.exit(3); }
  }
  const port = 8300 + Math.floor(Math.random() * 500);
  server = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', join(root, 'public'), join(root, 'public', 'index.php')], { env, stdio: 'ignore' });
  base = `http://localhost:${port}`;
}
base = base.replace(/\/$/, '');
const url = new URL(base);
const profile = mkdtempSync(join(tmpdir(), 'ts-pk-chrome-'));
cleanup.push(profile);
const debugPort = 9300 + Math.floor(Math.random() * 500);
const child = spawn(browser, ['--headless=new', `--remote-debugging-port=${debugPort}`, `--user-data-dir=${profile}`, '--no-first-run', '--window-size=1100,1400', 'about:blank'], { stdio: 'ignore' });
const stop = () => { try { child.kill(); } catch {} try { server?.kill(); } catch {} setTimeout(() => { for (const d of cleanup) { try { rmSync(d, { recursive: true, force: true }); } catch {} } }, 1500); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function target() {
  for (let i = 0; i < 60; i++) {
    try { const l = await (await fetch(`http://127.0.0.1:${debugPort}/json/list`)).json(); const p = l.find((t) => t.type === 'page'); if (p) return p.webSocketDebuggerUrl; } catch {}
    await sleep(200);
  }
  throw new Error('the browser did not open its debugging port');
}
const ws = new WebSocket(await target());
await new Promise((r) => ws.addEventListener('open', r, { once: true }));
let seq = 0; const waiting = new Map();
ws.addEventListener('message', (e) => { const m = JSON.parse(e.data); if (m.id && waiting.has(m.id)) { waiting.get(m.id)(m); waiting.delete(m.id); } });
const cdp = (method, params = {}) => new Promise((resolveCall, reject) => { const id = ++seq; waiting.set(id, (m) => (m.error ? reject(new Error(`${method}: ${m.error.message}`)) : resolveCall(m.result))); ws.send(JSON.stringify({ id, method, params })); });
// a page that is being replaced answers with an error for a moment: that counts as "not yet"
const run = async (expression) => { const r = await cdp('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true, userGesture: true }); if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description ?? JSON.stringify(r.exceptionDetails)); return r.result.value; };
const waitFor = async (expression, what, ms = 15000) => {
  const end = Date.now() + ms; let last = '';
  while (Date.now() < end) { try { const v = await run(expression); if (v) return v; last = JSON.stringify(v); } catch (e) { last = String(e.message).split('\n')[0]; } await sleep(150); }
  throw new Error(`timed out waiting for: ${what} (${last})`);
};
const go = async (path, origin = base) => { await cdp('Page.navigate', { url: origin + path }); await waitFor(`document.readyState === 'complete'`, 'the page ' + path); await sleep(150); };
const text = () => run(`document.body.innerText`);
const fill = (selector, value) => run(`(() => { const e = document.querySelector(${JSON.stringify(selector)}); if (!e) { throw new Error('no ${selector.replace(/'/g, '')}'); } e.value = ${JSON.stringify(value)}; return true; })()`);
const click = (selector) => run(`(() => { const e = document.querySelector(${JSON.stringify(selector)}); if (!e) { throw new Error('no ${selector.replace(/'/g, '')}'); } e.click(); return true; })()`);

// RFC 6238, with the library of node and nothing of ours
const base32 = (s) => { const a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; let bits = ''; for (const c of s.replace(/[ =]/g, '')) { bits += a.indexOf(c).toString(2).padStart(5, '0'); } const out = []; for (let i = 0; i + 8 <= bits.length; i += 8) { out.push(parseInt(bits.slice(i, i + 8), 2)); } return Buffer.from(out); };
const totp = (secret, at = Date.now()) => { const counter = Buffer.alloc(8); counter.writeBigUInt64BE(BigInt(Math.floor(at / 30000))); const h = createHmac('sha1', base32(secret)).update(counter).digest(); const o = h[h.length - 1] & 15; return String(((h.readUInt32BE(o) & 0x7fffffff) % 1000000)).padStart(6, '0'); };

let failed = 0;
const check = (name, ok, detail = '') => { console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${detail ? ' - ' + detail : ''}`); if (!ok) { failed++; } };
const audit = async (label) => {
  const out = await run(readFileSync(join(root, 'tools', 'a11y-audit.js'), 'utf8'));
  const dirty = ['light', 'dark'].flatMap((scheme) => ['text', 'ui', 'structure'].flatMap((k) => (out[scheme][k].length ? [`${scheme} ${k}: ${JSON.stringify(out[scheme][k])}`] : [])).concat(out[scheme].scrollX ? [`${scheme} scrolls sideways`] : []));
  check(`the a11y audit of ${label} finds nothing`, dirty.length === 0, dirty.join('; '));
};
const logout = async () => { await run(`document.querySelector('form[action$="/logout"]').submit();`); await waitFor(`location.pathname.endsWith('/login')`, 'the login page after logging out'); };
const passwordLogin = async (origin = base) => {
  await go('/login', origin);
  await fill('input[name=password]', password);
  await run(`(() => { const p = document.querySelector('input[name=plain]'); if (p) { p.checked = true; } document.querySelector('form[action$="/login"]').submit(); })()`);
  await waitFor(`!location.pathname.endsWith('/login')`, 'a page after the password');
};
const addPasskey = async (name) => {
  await go('/security');
  await fill('#passkey-add input[name=passkey_name]', name);
  await fill('#passkey-add input[name=passkey_password]', password);
  await click('#passkey-add button[type=submit]');
  await waitFor(`document.body.innerText.includes('The passkey is added.')`, 'the passkey to be added');
};
const rows = () => run(`[...document.querySelectorAll('table tbody tr')].filter((r) => r.querySelector('form[action$="/security/passkey/remove"]')).map((r) => r.cells[0].innerText.split('\\n')[0].trim())`);

let exitCode = 1;
try {
  await cdp('Page.enable');
  await cdp('WebAuthn.enable', { enableUI: false });
  const options = { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true };
  let { authenticatorId } = await cdp('WebAuthn.addVirtualAuthenticator', { options });
  const held = async () => (await cdp('WebAuthn.getCredentials', { authenticatorId })).credentials;

  // 1. the first passkey, through the page
  await passwordLogin();
  await go('/security');
  check('the Security page has the form for a passkey', await run(`!!document.getElementById('passkey-add') && getComputedStyle(document.getElementById('passkey-add')).display !== 'none'`));
  await addPasskey('Key one');
  check('the passkey is listed and the device holds it for this host', (await rows()).join() === 'Key one' && (await held()).length === 1 && (await held())[0].rpId === url.hostname);

  // a wrong password and the same device again
  await go('/security');
  await fill('#passkey-add input[name=passkey_password]', 'wrong wrong wrong');
  await click('#passkey-add button[type=submit]');
  await waitFor(`/password is wrong/i.test(document.getElementById('passkey-message').textContent)`, 'the message for a wrong password');
  await fill('#passkey-add input[name=passkey_password]', password);
  await click('#passkey-add button[type=submit]');
  await waitFor(`/already/i.test(document.getElementById('passkey-message').textContent)`, 'the message that the device has one');
  check('a wrong password and a second registration of the same device are refused', true);

  // 2. the login with the passkey
  await logout();
  check('the login page offers the button', await run(`!!document.getElementById('passkey-login') && getComputedStyle(document.getElementById('passkey-login')).display !== 'none'`));
  await audit('the login page');
  await click('#passkey-login');
  await waitFor(`location.pathname === '${url.pathname.replace(/\/$/, '')}/' || location.pathname === '/'`, 'the dashboard after the passkey');
  const used = await run(`fetch('${base}/security', { credentials: 'same-origin' }).then((r) => r.text())`);
  check('the passkey logs in and the list shows that it was used', !/not used yet/.test(used) && /Last used/.test(used));

  // 3. a second key on another device (the first device is taken away, so the browser does not refuse the registration)
  await cdp('WebAuthn.removeVirtualAuthenticator', { authenticatorId });
  ({ authenticatorId } = await cdp('WebAuthn.addVirtualAuthenticator', { options }));
  await addPasskey('Key two');
  check('a second key from another device is listed beside the first', (await rows()).join() === 'Key one,Key two');
  await audit('the Security page with two passkeys');
  await logout();
  await click('#passkey-login');
  await waitFor(`!location.pathname.endsWith('/login')`, 'the dashboard with the second key');
  check('the second key logs in', true);

  // 4. a passkey instead of the code of the second factor
  await go('/security');
  await fill('form[action$="/security/start"] input[name=password]', password);
  await click('form[action$="/security/start"] button[type=submit]');
  await waitFor(`!!document.getElementById('totp-key')`, 'the key of the second factor');
  const secret = (await run(`document.getElementById('totp-key').textContent`)).replace(/ /g, '');
  await fill('form[action$="/security/confirm"] input[name=code]', totp(secret));
  await click('form[action$="/security/confirm"] button[type=submit]');
  await waitFor(`!!document.getElementById('recovery-codes')`, 'the recovery codes');
  await go('/security');
  await logout();
  await passwordLogin();
  check('the password alone leads to the page of the code, which offers a passkey', (await run(`location.pathname`)).endsWith('/login/code') && await run(`!!document.getElementById('passkey-login')`));
  await audit('the page of the code');
  await click('#passkey-login');
  await waitFor(`!location.pathname.endsWith('/login/code')`, 'the dashboard after a passkey at the code step');
  const dashboard = await run(`fetch('${base}/profiles', { credentials: 'same-origin', redirect: 'manual' }).then((r) => r.status)`);
  check('the session of that login is a full one', dashboard === 200, String(dashboard));

  // 5. switching the second factor off again (a code of the next step, as the window of one step accepts)
  await go('/security');
  await fill('form[action$="/security/disable"] input[name=off_password]', password);
  await fill('form[action$="/security/disable"] input[name=off_code]', totp(secret, Date.now() + 30000));
  await click('form[action$="/security/disable"] button[type=submit]');
  await waitFor(`document.body.innerText.includes('The second factor is off.')`, 'the second factor to be off');

  // 6. removing a key: the page forgets it, and the device that holds it is no longer let in
  await go('/security');
  await run(`(() => { const row = [...document.querySelectorAll('table tbody tr')].find((r) => r.cells[0].innerText.startsWith('Key two')); row.querySelector('input[name=remove_password]').value = ${JSON.stringify(password)}; row.querySelector('form[action$="/security/passkey/remove"] button').click(); })()`);
  await waitFor(`document.body.innerText.includes('The passkey is removed.')`, 'the passkey to be removed');
  check('the removed key is gone from the list', (await rows()).join() === 'Key one');
  await logout();
  await click('#passkey-login');
  await waitFor(`!document.getElementById('passkey-login-message').hidden`, 'the refusal of the removed key');
  check('a device that holds a removed key is refused', /not accepted/i.test(await run(`document.getElementById('passkey-login-message').textContent`)));

  // 7. a page that cannot bind passkeys: an IP address
  if (/^(localhost|127\.0\.0\.1)$/.test(url.hostname)) {
    const ip = `${url.protocol}//127.0.0.1${url.port ? ':' + url.port : ''}`;
    await passwordLogin(ip);
    await go('/security', ip);
    const page = await text();
    check('an IP address is refused, with the reason and no form', /IP address/.test(page) && (await run(`!document.getElementById('passkey-add')`)));
    await run(`document.querySelector('form[action$="/logout"]').submit();`);
    await sleep(1000);
  }

  // 8. the System page, then the installation as it was found
  await passwordLogin();
  await go('/system');
  check('the System page has the row of the passkeys', (await text()).includes('Passkeys'));
  await audit('the System page');
  await go('/security');
  await run(`(() => { const row = document.querySelector('table tbody tr'); row.querySelector('input[name=remove_password]').value = ${JSON.stringify(password)}; row.querySelector('form[action$="/security/passkey/remove"] button').click(); })()`);
  await waitFor(`document.body.innerText.includes('The passkey is removed.')`, 'the last passkey to be removed');
  check('no passkey and no second factor are left', (await rows()).length === 0 && (await text()).includes('No passkey yet.'));
  await audit('the Security page without passkeys');

  if (args.shot) {
    const shot = await cdp('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
    writeFileSync(args.shot, Buffer.from(shot.data, 'base64'));
  }
  exitCode = failed === 0 ? 0 : 1;
} catch (e) {
  console.log(`FAIL ${e.message}`);
  failed++;
} finally { stop(); }
console.log(failed === 0 ? 'all checks passed' : `${failed} check(s) failed`);
process.exit(exitCode);
