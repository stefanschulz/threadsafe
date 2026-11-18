// Spike of phase 14, work package 0 (docs/PHASE14.md 5): does a real browser make WebAuthn answers that we can drive from a script,
// and which algorithms does its virtual authenticator offer? It starts Chrome (or Edge) headless with a debugging port, opens
// http://localhost:PORT/ (localhost is a secure context, so no certificate), adds a virtual authenticator over the DevTools protocol and
// runs navigator.credentials.create() and .get() for each algorithm. The answers (clientDataJSON, attestationObject, authenticatorData,
// signature, ...) are written as JSON: they are real vectors for the unit tests (made by a real WebAuthn implementation, not by our own code).
//
//   node tools/spikes/webauthn-virtual.mjs [--browser=PATH] [--out=FILE]
//
// Nothing here is part of the product; the output holds throw-away keys of a virtual device only.
import { spawn } from 'node:child_process';
import { createServer } from 'node:http';
import { mkdtempSync, writeFileSync, existsSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const args = Object.fromEntries(process.argv.slice(2).map((a) => a.replace(/^--/, '').split('=')));
const candidates = [
  args.browser,
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser',
].filter(Boolean);
const browser = candidates.find((p) => existsSync(p));
if (!browser) { console.error('No Chrome or Edge found; pass --browser=PATH'); process.exit(2); }
const out = args.out ?? join(tmpdir(), 'webauthn-vectors.json');

const server = createServer((req, res) => { res.setHeader('content-type', 'text/html'); res.end('<!doctype html><title>spike</title><p>spike'); });
await new Promise((r) => server.listen(0, '127.0.0.1', r));
const port = server.address().port;
const origin = `http://localhost:${port}`;

const profile = mkdtempSync(join(tmpdir(), 'ts-webauthn-'));
const debugPort = 9300 + Math.floor(Math.random() * 500);
const child = spawn(browser, ['--headless=new', `--remote-debugging-port=${debugPort}`, `--user-data-dir=${profile}`, '--no-first-run', '--no-default-browser-check', 'about:blank'], { stdio: 'ignore' });
const stop = () => { try { child.kill(); } catch {} server.close(); setTimeout(() => { try { rmSync(profile, { recursive: true, force: true }); } catch {} }, 1500); };

async function wsUrl() {
  for (let i = 0; i < 50; i++) {
    try {
      const list = await (await fetch(`http://127.0.0.1:${debugPort}/json/list`)).json();
      const page = list.find((t) => t.type === 'page');
      if (page) return page.webSocketDebuggerUrl;
    } catch {}
    await new Promise((r) => setTimeout(r, 200));
  }
  throw new Error('the browser did not open its debugging port');
}

const ws = new WebSocket(await wsUrl());
await new Promise((r) => ws.addEventListener('open', r, { once: true }));
let seq = 0; const waiting = new Map();
ws.addEventListener('message', (e) => { const m = JSON.parse(e.data); if (m.id && waiting.has(m.id)) { waiting.get(m.id)(m); waiting.delete(m.id); } });
const cdp = (method, params = {}) => new Promise((resolve, reject) => {
  const id = ++seq; waiting.set(id, (m) => (m.error ? reject(new Error(`${method}: ${m.error.message}`)) : resolve(m.result)));
  ws.send(JSON.stringify({ id, method, params }));
});
const run = async (expression) => {
  const r = await cdp('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true, userGesture: true });
  if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description ?? JSON.stringify(r.exceptionDetails));
  return r.result.value;
};

const result = { browser: null, origin, rpId: 'localhost', cases: [] };
try {
  await cdp('Page.enable');
  await cdp('Page.navigate', { url: `${origin}/` });
  await new Promise((r) => setTimeout(r, 800));
  result.browser = (await (await fetch(`http://127.0.0.1:${debugPort}/json/version`)).json()).Browser;
  await cdp('WebAuthn.enable', { enableUI: false });
  const { authenticatorId } = await cdp('WebAuthn.addVirtualAuthenticator', { options: {
    protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true,
  } });
  result.authenticatorId = authenticatorId;

  const helper = `
    const b64 = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\\+/g,'-').replace(/\\//g,'_').replace(/=+$/,'');
    const unb = (s) => Uint8Array.from(atob(s.replace(/-/g,'+').replace(/_/g,'/')), c => c.charCodeAt(0));
    async function ceremony(algs) {
      const challenge = crypto.getRandomValues(new Uint8Array(32));
      const userId = crypto.getRandomValues(new Uint8Array(64));
      const created = await navigator.credentials.create({ publicKey: {
        challenge, rp: { id: 'localhost', name: 'ThreadSafe' },
        user: { id: userId, name: 'admin', displayName: 'admin' },
        pubKeyCredParams: algs.map((alg) => ({ type: 'public-key', alg })),
        authenticatorSelection: { residentKey: 'required', userVerification: 'required' },
        attestation: 'none', timeout: 20000 } });
      const c2 = crypto.getRandomValues(new Uint8Array(32));
      const got = await navigator.credentials.get({ publicKey: { challenge: c2, rpId: 'localhost', userVerification: 'required', timeout: 20000 } });
      return {
        origin: location.origin,
        registration: { challenge: b64(challenge), userId: b64(userId), id: b64(created.rawId), clientDataJSON: b64(created.response.clientDataJSON),
          attestationObject: b64(created.response.attestationObject), transports: created.response.getTransports ? created.response.getTransports() : null,
          publicKeyAlgorithm: created.response.getPublicKeyAlgorithm ? created.response.getPublicKeyAlgorithm() : null },
        assertion: { challenge: b64(c2), id: b64(got.rawId), clientDataJSON: b64(got.response.clientDataJSON), authenticatorData: b64(got.response.authenticatorData),
          signature: b64(got.response.signature), userHandle: got.response.userHandle ? b64(got.response.userHandle) : null },
      };
    }`;
  for (const algs of [[-7], [-8], [-257], [-7, -8, -257]]) {
    const entry = { offered: algs };
    try {
      entry.answer = await run(`(async () => { ${helper}; return await ceremony(${JSON.stringify(algs)}); })()`);
      entry.ok = true;
    } catch (e) { entry.ok = false; entry.error = String(e.message).split('\n')[0]; }
    // each case starts from an empty authenticator, so that one key does not exclude the next
    await cdp('WebAuthn.clearCredentials', { authenticatorId });
    result.cases.push(entry);
  }
  writeFileSync(out, JSON.stringify(result, null, 1));
  console.log(`${result.browser}: ` + result.cases.map((c) => `${JSON.stringify(c.offered)} ${c.ok ? 'ok (alg ' + c.answer.registration.publicKeyAlgorithm + ')' : 'FAILED: ' + c.error}`).join('; '));
  console.log(`written to ${out}`);
} finally { stop(); }
