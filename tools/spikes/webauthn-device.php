<?php

/**
 * Spike of phase 14, work package 0, parts (c) and (d) (docs/PHASE14.md 5): what does a real host and a real device say?
 *
 * One file for a throw-away page. Upload it to the host whose web interface will get passkeys (it must be reached over https, or as
 * localhost), open it in the browser, press "Make a passkey" and then "Use the passkey" on each device you want to know about (a phone, Windows
 * Hello, a security key, a password manager), and send back the text under "Report". Delete the file afterwards.
 *
 * It shows what the SERVER sees (PHP version, openssl, sodium, scheme, host, port, proxy headers) and what the DEVICE sends (algorithm,
 * the flags user present, user verified, backup eligible and backup state, the counter, transports, whether a user handle comes back).
 * It stores nothing, checks nothing and keeps no key: the passkey it makes stays on the device and is of no use to anybody (a throw-away
 * name `threadsafe-spike`; remove it from the device's list of passkeys when done).
 */

declare(strict_types=1);

header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; base-uri 'none'; form-action 'none'");

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$proxy = [];
foreach (['HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_PORT', 'HTTP_FORWARDED', 'HTTP_CF_VISITOR', 'HTTP_X_REAL_IP'] as $h) {
    if (isset($_SERVER[$h])) {
        $proxy[$h] = (string) $_SERVER[$h];
    }
}
$server = [
    'php' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'openssl' => extension_loaded('openssl') ? OPENSSL_VERSION_TEXT : 'MISSING',
    'openssl_ec_p256' => extension_loaded('openssl') ? (in_array('prime256v1', openssl_get_curve_names() ?: [], true) ? 'yes' : 'NO') : '-',
    'sodium' => extension_loaded('sodium') ? (defined('SODIUM_LIBRARY_VERSION') ? SODIUM_LIBRARY_VERSION : 'yes') : 'MISSING',
    'mbstring' => extension_loaded('mbstring') ? 'yes' : 'MISSING',
    'HTTPS' => $https ? 'on' : 'off',
    'HTTP_HOST' => (string) ($_SERVER['HTTP_HOST'] ?? ''),
    'SERVER_NAME' => (string) ($_SERVER['SERVER_NAME'] ?? ''),
    'SERVER_PORT' => (string) ($_SERVER['SERVER_PORT'] ?? ''),
    'REQUEST_SCHEME' => (string) ($_SERVER['REQUEST_SCHEME'] ?? ''),
    'proxy headers' => $proxy === [] ? 'none' : $proxy,
];
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Passkey spike</title>
<style>body{font:16px/1.5 system-ui,sans-serif;max-width:46rem;margin:2rem auto;padding:0 1rem}button{font:inherit;padding:.5rem 1rem;margin:.25rem .5rem .25rem 0}pre{background:#f2f2f2;color:#111;padding:1rem;overflow:auto;white-space:pre-wrap}</style></head>
<body>
<h1>Passkey spike</h1>
<p>A throw-away page. It stores nothing. Delete the file when you are done.</p>
<p><button id="make">Make a passkey</button><button id="use">Use the passkey</button></p>
<h2>Report</h2>
<pre id="report"></pre>
<script>
const server = <?= json_encode($server, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) ?>;
const out = document.getElementById('report');
const lines = ['SERVER (what PHP sees)', JSON.stringify(server, null, 1),
  '', 'BROWSER', 'location.origin = ' + location.origin, 'isSecureContext = ' + window.isSecureContext,
  'PublicKeyCredential = ' + (typeof PublicKeyCredential !== 'undefined'),
  'userAgent = ' + navigator.userAgent];
const show = () => { out.textContent = lines.join('\n'); };
const b64 = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const flagText = (f) => 'UP=' + (f & 1) + ' UV=' + ((f >> 2) & 1) + ' BE=' + ((f >> 3) & 1) + ' BS=' + ((f >> 4) & 1) + ' AT=' + ((f >> 6) & 1) + ' ED=' + ((f >> 7) & 1);
const rp = location.hostname;
let credId = null;
async function make() {
  lines.push('', 'MAKE (rp.id = ' + rp + ')');
  try {
    const c = await navigator.credentials.create({ publicKey: {
      challenge: crypto.getRandomValues(new Uint8Array(32)), rp: { id: rp, name: 'ThreadSafe spike' },
      user: { id: crypto.getRandomValues(new Uint8Array(64)), name: 'threadsafe-spike', displayName: 'threadsafe-spike' },
      pubKeyCredParams: [-7, -8, -257].map((alg) => ({ type: 'public-key', alg })),
      authenticatorSelection: { residentKey: 'required', userVerification: 'required' }, attestation: 'none', timeout: 120000 } });
    credId = c.rawId;
    const r = c.response;
    const ao = new Uint8Array(r.getAuthenticatorData ? r.getAuthenticatorData() : new ArrayBuffer(0));
    lines.push('ok; credential id length = ' + c.rawId.byteLength, 'attachment = ' + c.authenticatorAttachment,
      'algorithm = ' + (r.getPublicKeyAlgorithm ? r.getPublicKeyAlgorithm() : 'n/a'),
      'transports = ' + JSON.stringify(r.getTransports ? r.getTransports() : null),
      'flags = ' + (ao.length > 32 ? flagText(ao[32]) : 'n/a'),
      'aaguid = ' + (ao.length >= 53 ? b64(ao.slice(37, 53)) : 'n/a'),
      'client data = ' + new TextDecoder().decode(r.clientDataJSON));
  } catch (e) { lines.push('FAILED: ' + e.name + ': ' + e.message); }
  show();
}
async function use() {
  lines.push('', 'USE (no allowCredentials, user verification required)');
  try {
    const g = await navigator.credentials.get({ publicKey: { challenge: crypto.getRandomValues(new Uint8Array(32)), rpId: rp, userVerification: 'required', timeout: 120000 } });
    const ad = new Uint8Array(g.response.authenticatorData);
    const counter = new DataView(ad.buffer, ad.byteOffset + 33, 4).getUint32(0);
    lines.push('ok; attachment = ' + g.authenticatorAttachment, 'flags = ' + flagText(ad[32]), 'counter = ' + counter,
      'user handle = ' + (g.response.userHandle ? g.response.userHandle.byteLength + ' bytes' : 'none'),
      'signature length = ' + g.response.signature.byteLength,
      'client data = ' + new TextDecoder().decode(g.response.clientDataJSON));
  } catch (e) { lines.push('FAILED: ' + e.name + ': ' + e.message); }
  show();
}
document.getElementById('make').onclick = make;
document.getElementById('use').onclick = use;
show();
</script>
</body></html>
