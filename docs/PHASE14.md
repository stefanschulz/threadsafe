# Phase 14 – passkeys for the web interface

Status: **planned, decisions settled, work started on the branch `phase-14-passkeys`** (2026-11-18, asked for by the owner). The decisions for the owner are in section 7.2; the recommendations are marked, and 7.2 says what was settled. Phase 11 left passkeys out on purpose ([PHASE11.md](PHASE11.md) 7.2, decision 1: "TOTP first, passkeys as a package of their own"); this is that package. It is a new feature, so it belongs to the minor after 1.1.0 (**1.2.0**); it is developed on its own branch and is merged only after 1.1.0 is out, so that it does not touch the release that the field test of phase 13 is preparing.

## 1. Goal and scope

Today the web interface logs in with one administrator's password and, if switched on, a TOTP code ([PHASE11.md](PHASE11.md)). A password and a code can be phished: a fake page asks for both and passes them on at once. A **passkey** (WebAuthn) cannot be passed on, because the browser signs the request together with the real address of the page. This phase lets the administrator register passkeys (a phone, a Windows Hello PIN or fingerprint, a macOS or Android device, a security key such as a YubiKey, a password manager that stores passkeys) and log in with one.

| In scope | Out of scope |
|---|---|
| Registering, naming, listing and removing passkeys on the page *Security* | More than one administrator (there is one, as today) |
| A button **Log in with a passkey** on the login page (discoverable credential, user verification required) | Logging in **without** a password being possible at all: the password stays the way back (3.6) |
| Passkeys count as the second factor wherever `web.requireTwoFactor` asks for one | Checking the *attestation* (which make and model of device made the key) and the FIDO metadata service (3.2) |
| ES256, EdDSA and RS256 keys | Conditional UI (the passkey offered in the field's autofill list), "remember this browser", related origins |
| `admin passkey` on the command line: list, remove, remove all (the way back) | Passkeys in `threadsafe-restore.php`, the installer or the backup tool (they have an access key, not a login; `src/Auth` is left out of them already) |
| A check on the System page: HTTPS or `localhost`, `ext-openssl`, the host name the passkeys are bound to | Passkeys for the agent API of phase 13 (signed requests with a secret; a different job) |

**What a passkey does not cover:** an attacker who has the unlocked device, or who has the administrator's session cookie (as with TOTP, the cookie is the login). A *synced* passkey (iCloud Keychain, Google Password Manager, a password manager) is as safe as the account that syncs it. The page says which kind a key is (3.4).

## 2. Deliverables

1. `Auth\Cbor` (a small, strict decoder), `Auth\Cose` (reads a COSE public key into a PEM for `openssl`, or the raw key for `sodium`), `Auth\AuthenticatorData`, `Auth\ClientData`: the parsing, with the checks of section 3.2.
2. `Auth\Passkeys` (the relying party: makes the options, verifies a registration and an assertion), `Auth\PasskeyStore` (`data/passkeys.json`: its own file, locked read-and-write, like `two-factor.json`).
3. The login in the web interface: `POST /login/passkey/options` and `POST /login/passkey` in `AuthController`; the same pending-session rules as the second factor; a throttle of its own.
4. The page *Security*: a section **Passkeys** with the list (name, kind, made, last used), *Add a passkey*, *Rename*, *Remove*; the password again before adding or removing (as for TOTP). `SecurityController` and `public/app.js` (the browser side: `navigator.credentials.create` and `.get`, base64url, an error text for each way it fails).
5. `admin passkey [list | remove NAME | remove-all]` (the way back, 3.6) in `Cli\AdminCommand`.
6. The System page: a row *Passkeys* (how many, bound to which host, usable or not and why not); the row of the second factor knows about passkeys; the setting `web.passkeyOrigin` (also in the settings form and for `admin set`).
7. Tests (section 4), the documentation ([WEB.md](WEB.md) "Security", [DESIGN.md](DESIGN.md) for the new elements), `CHANGELOG.md`, `AGENTS.md`.

## 3. Design

### 3.1 What WebAuthn needs, and what the code must do

Three facts shape the design:

- **A secure context.** Browsers offer WebAuthn only on `https://` pages and on `localhost`. A ThreadSafe on a plain `http://` address (a customer host without a certificate) cannot use passkeys; the page says so and TOTP stays. No workaround: this is the browser's rule, not ours.
- **A relying party id (`rpId`).** A passkey is bound to a registrable domain (`admin.example.com` or `example.com`). If the installation later moves to another domain, the passkeys stop working (the password still does, 3.6). The `rpId` is **fixed when the first passkey is made**: the host name of the request, stored in `passkeys.json`; a later registration under another host is refused with a clear text. The expected **origin** (scheme, host, port) is checked against that, not against a header that a proxy may set: the code reads the host from the request as the web interface already does everywhere else, and an optional setting `web.passkeyOrigin` names it explicitly for a site behind a reverse proxy. `X-Forwarded-*` headers are not trusted.
- **Binary data in a browser.** The options and the answers carry `ArrayBuffer`s; on the wire they are base64url strings in JSON. The browser side is about 80 lines of plain JavaScript in `public/app.js` (the content security policy allows scripts from the same origin and `fetch` to itself only; it needs no change).

### 3.2 Verification (the part that must be exact)

**Registration** (`navigator.credentials.create`): the server makes 32 random bytes as the challenge, stores them in the session (single use, valid 5 minutes) and sends the options: the `rpId` and the name `ThreadSafe`, a random 64-byte user handle (the same for every passkey of the administrator, kept in the store), `pubKeyCredParams` (ES256 -7, EdDSA -8, RS256 -257, in that order), `authenticatorSelection` with `residentKey: required` and `userVerification: required`, `attestation: none`, `excludeCredentials` (the keys already registered, so that one device does not make two). The answer is checked:

1. `clientDataJSON`: `type` is `webauthn.create`, `challenge` is the one issued, `origin` is the expected origin, `crossOrigin` is not true.
2. `attestationObject` (CBOR): `authData` is read; its `rpIdHash` is the SHA-256 of the `rpId`; the flags have *user present* and *user verified*, and *attested credential data*; the algorithm of the COSE key is one that was offered.
3. The credential id is not known yet. The key is stored (as a COSE key, so that nothing is lost), with the sign counter, the flags *backup eligible* and *backup state* (these say "synced passkey"), the transports and the AAGUID.

**The attestation statement is not checked** (`attestation: none` asks the browser to leave it empty or "none"). Checking it would prove which device made the key, which a single administrator does not need; it would also need certificate chains and the FIDO metadata. The decision is written down so that nobody adds half of it later.

**Login** (`navigator.credentials.get`, no `allowCredentials`: the passkey is *discoverable*, so the browser offers the keys of this site): a fresh challenge; the answer is checked: `clientDataJSON` (`webauthn.get`, challenge, origin), `rpIdHash`, *user present* and *user verified*, the credential id is one of ours, the user handle (if the device sends one) is ours, and the **signature** over `authenticatorData || SHA-256(clientDataJSON)` with the stored key (`openssl_verify` for ES256 and RS256, `sodium_crypto_sign_verify_detached` for EdDSA). The sign counter follows WebAuthn level 3 (7.2, step 21): if the new or the stored value is not zero, the new one must be larger; otherwise (a smaller or equal value, or a fall back to 0 after a counter was running) the key may be cloned, the login is refused and an audit event `login.passkey.clone` is written. Both 0, as synced passkeys always say, is allowed.

**The CBOR reader** is written for this one job and nothing else: definite lengths only, the types that WebAuthn uses (unsigned and negative integers, byte and text strings, arrays, maps; `true`, `false`, `null`), a depth limit, a limit on the size of the whole input (64 KiB), no duplicate map keys, and no byte left over after the last item. Any other input is an error with a message that names the position. It has no dependency (the project has none for this kind of job: Base32, Totp and the QR code of phase 11 were written the same way).

**PHP needs `ext-openssl`** for ES256 and RS256 (the two algorithms that every device offers; EdDSA comes from `ext-sodium`, which the age format already suggests). It is not in `composer.json` as required, because the program works without it: the passkey section says "this server's PHP has no `openssl` extension" and stays off, like encryption without `sodium`. The System page names them in its row *Passkeys* (the list of `Core\Environment` and `threadsafe doctor` are not changed: the extensions are optional and only the web interface uses them).

### 3.3 The login in two ways

The login page gets a second button, **Log in with a passkey**, above the password form. It needs JavaScript; without it (or without WebAuthn in the browser) the button is not shown and the password form works as today.

- **With a passkey:** the browser shows its own chooser, the device asks for a fingerprint, face or PIN (*user verification*: this is why a passkey login counts as **two factors** and does not ask for a TOTP code). The session is made at once.
- **With the password:** exactly as today, including the TOTP step when it is on. A passkey may be offered at the code step too (*Use a passkey instead of the code*): that is the phishing-resistant way to do the second step, and it only needs the same two endpoints.

The pending-session rule of phase 11 holds: a session between steps has no `user`, and `Kernel` asks `Context::user()`, never "is there a session". The passkey endpoints are reachable without a login (that is their job). **They wait and count nowhere**: an answer cannot be guessed (it needs a signature of the device), and a wait that a stranger can start would shut the door for the owner, which is the lesson of 9.11 in phase 13. A failure is logged as `login.passkey.failed` (a copied key as `login.passkey.clone`) with the name of the check and without the data of the answer; the audit log has a size limit, so a flood cannot fill the disk; the audit never holds a challenge, a signature or a key.

### 3.4 The page *Security*

A section **Passkeys** under the one for the second factor: the list (name given when it was made, the kind: *synced* when the backup-eligible flag is set, else *only on this device*; the dates), **Add a passkey** (the password again; then the browser's dialog; then the name), **Rename**, **Remove** (the password again; the last passkey may be removed, nothing else depends on it). After adding or removing, the other sessions end (`Sessions::destroyExcept()`), as for TOTP. The page warns once, in plain words, that a key stored only on one device is lost with the device, and that the password stays the way in.

### 3.5 `web.requireTwoFactor`

The setting asks for a second factor of every login. A passkey login **satisfies** it (two factors in one step), and so does a passkey that is made in the session while the setting is on (the registration was verified on the device and the password was asked again; the session then carries `mfa = passkey`). A **password alone does not**, however many passkeys exist: the requirement would be empty if registering a passkey let a password through. A login that does not meet it reaches the Security page (including the two routes that make a passkey), the way out and nothing else. At the code step a passkey may stand in for the code. The warning of the System page stays as honest as before: with a passkey and no authenticator app it says that the password alone still logs in, until the app is on or the requirement stops it.

### 3.6 The way back

A passkey is lost with a device. Therefore the **password login stays** with every setting, and a passkey never replaces it: a passkey-only mode is out of scope on purpose, because the way back from it (a recovery on the server) is what a customer's host makes hard. Losing every passkey costs the convenience, not the access.

- The password (and the TOTP code or a recovery code, if TOTP is on) logs in as before.
- `php bin/threadsafe admin passkey list` shows the keys by name; `remove NAME` (or the start of the id that `list` shows) and `remove-all` delete them; deleting `data/passkeys.json` does the same. The key file holds public keys only: leaking it is no breach.
- An installation that moves to another domain loses its passkeys (3.1): the page says so before the first registration and the update notes of a move should. `admin passkey remove-all` clears them; new ones are made at the new address.

### 3.7 Updating and rolling back

`data/passkeys.json` is a new file; nothing existing changes shape, so no migration is needed. An older release does not know the file and logs in with password (and TOTP) as before: a roll-back loses nothing and opens nothing.

## 4. Test strategy

### 4.1 Unit tests (no database)

- **CBOR:** the examples of RFC 8949 appendix A, plus every refusal (indefinite length, duplicate key, trailing byte, depth, size, truncated).
- **COSE and signatures:** keys and signatures made in the test with `openssl` and `sodium` for each of the three algorithms, plus **real vectors** from the specifications and from an open library's test data (to be found in the spike, 5, package 0), so that the checks do not only agree with the code that wrote them.
- **A software authenticator** in `tests/Support` (it makes the key, the `authData`, the client data JSON, the signature and the attestation object, as a device does) drives full registrations and logins through `Passkeys` and through the controllers: the good path, and a test for each refusal: wrong challenge, challenge used twice or expired, wrong origin, wrong `rpId` hash, *user verified* missing, unknown credential id, a bad signature, a counter that goes back, an algorithm that was not offered, a key under another `rpId`, `crossOrigin: true`.
- **The session:** a walk over every route with a session that waits at the code step or has a passkey challenge only; none is open (the test of phase 11 gets the new routes).
- **The store:** a damaged file refuses the passkey login and logs in with the password (as `two-factor.json` does); concurrent writes; the secret-free audit lines.

### 4.2 Integration and the browser

A real browser is the oracle: **Chrome's virtual authenticator** (the DevTools protocol, `WebAuthn.addVirtualAuthenticator`; it is a real browser doing the real WebAuthn ceremony, with no hardware) registers and logs in against the web interface behind the real Apache of phase 4b (`localhost` is a secure context, so no certificate is needed). Which tool drives it from the stack (the browser pane of the desktop app, a headless Chrome in the tools image, Playwright) is **settled in package 0**, not guessed here. The proof covers: registering, logging in with the passkey, a login at the code step, removing, a second key, a key made under another host name refused, and the three algorithms (the virtual authenticator can be set to each).

### 4.3 What only the owner can try

A real phone (a passkey made on the phone, used from a computer through the QR code of the browser's own dialog), Windows Hello or a security key, on the live system over https; a *synced* passkey (a password manager). The report goes into section 8.

## 5. Work packages

| # | Package | Depends on | Proof |
|---|---|---|---|
| 0 | **Spike:** (a) which tool drives a virtual authenticator from the stack; (b) real test vectors for WebAuthn (registration and assertion) from the specification or an open test suite; (c) what the live host's PHP has (`openssl`, `sodium`) and which host name and scheme the web interface sees there (proxy or not); (d) what the browsers send for `userHandle`, flags and counters on the owner's devices (a throw-away page). Findings into section 8 of this file | – | the findings; the plan is corrected before more is built |
| 1 | `Auth\Cbor`, `Auth\Cose` | – | RFC 8949 examples and the refusals; keys read for all three algorithms |
| 2 | `Auth\AuthenticatorData`, `Auth\ClientData`, `Auth\Passkeys` (options, registration, assertion) and the software authenticator | 1 | every refusal of 3.2 has a test; the real vectors of package 0 pass |
| 3 | `Auth\PasskeyStore`, `admin passkey` | – (2 for the shape) | locked writes, the damaged file, the commands |
| 4 | The page *Security*: the section, the endpoints, `public/app.js`; the password again; the other sessions end | 2, 3 | controller tests with the software authenticator; the contrast audit of the page in both schemes |
| 5 | The login: the button, the two endpoints, the code step, the throttle, the audit; `Kernel` and the pending-session test | 2, 3 | tests of 4.1; **the new routes in the walk of all routes** |
| 6 | `web.requireTwoFactor`, the System page row, `Core\Environment` checks, `web.passkeyOrigin` | 4, 5 | unit tests; the page with and without `openssl` |
| 7 | The proof in a real browser: a new group in phase 4b of `run.sh`, all three database servers | 5, 6 | the virtual authenticator walks 4.2 |
| 8 | Documentation: [WEB.md](WEB.md) ("Logging in", "Security", "Security in short": what a passkey is, `https`, the host name, the way back), [DESIGN.md](DESIGN.md), `CHANGELOG.md`, `README.md`, `AGENTS.md`; "As built" here | 1–7 | reading it once as someone who has never seen the feature |
| 9 | The field test by the owner (4.3); findings into section 8 | 8 | the report |

One commit per package: `Phase 14, work package N: ...`. Package 0 comes first and may change the packages after it (as the spikes of phases 2 and 9 did). Packages 1 and 3 are independent of each other.

## 6. Acceptance criteria

1. A passkey made in the browser is listed on the page *Security*, and **Log in with a passkey** logs in with it, with no code asked for; the password login works as before.
2. A registration or a login that fails any check of 3.2 is refused with a message that does not tell an attacker which check failed, and writes an audit line without data of the answer.
3. No page of the interface opens with a challenge or a password alone: the pending-session walk of phase 11 passes with the new routes.
4. A damaged or missing `passkeys.json` never blocks the password login; the way back of 3.6 works on a server with nothing but a shell.
5. On a plain `http://` address (not `localhost`) and on a PHP without `openssl` the page says why passkeys are off, and nothing else changes.
6. No key, challenge, signature or user handle appears in a log, the audit, a report or a page source beyond what the browser needs for the ceremony.
7. The virtual-authenticator proof is green on MariaDB 10.11, MySQL 8.4 and MariaDB 11.8; `composer check` is green; every new text is at least 4.5:1 in both schemes.
8. The owner has made, used and removed a passkey on a real device on the live system (package 9), and says so.

## 7. Risks and decisions

### 7.1 Risks

| Risk | Handling |
|---|---|
| **The domain changes** (a migration, a new host name) and the passkeys die | The `rpId` is shown on the page; the text before the first registration says it; the password stays; `admin passkey remove-all` clears them |
| **A reverse proxy** changes the host or the scheme that PHP sees, so the origin check fails for everybody | The origin comes from the request, with `web.passkeyOrigin` to name it; no `X-Forwarded-*`; the failure text on the page names the origin that was expected and the one that arrived (public information in the browser's own request) |
| A subtle error in the signature or the flag checks lets a forged answer in | Real vectors, a test for each refusal, a software authenticator **and** a real browser as oracles; the code is short and has no clever parts; a review by reading before the release (as 9.11 of phase 13) |
| A homemade CBOR reader is a parser of untrusted input | Strict and small (3.2), limits on size and depth, no recursion without the limit, fuzzed in a unit test with random and mutated input (it must only ever throw its own exception) |
| The sign counter of synced passkeys (always 0) is read as a clone | Zero on both sides is allowed (3.2) |
| The owner is locked out | The password stays (3.6); the CLI works on the server |
| `openssl` is missing or too old on a customer's host | The feature says so and stays off; TOTP is not affected |
| Browsers differ (what they send for `userHandle`, transports, flags) | Package 0 (d) looks at the owner's devices; the checks accept what the specification allows |

### 7.2 Decisions (owner)

1. **What does a passkey do?** *Recommended:* it is a **login of its own that counts as two factors** (button on the login page), and it may stand in for the TOTP code at the code step; the password login stays. The alternatives: only a second factor after the password (simpler, but the point of a passkey, no password to type or to phish, is lost); or passkey-only (not recommended, 3.6).
2. **User verification.** *Recommended:* **required** (a fingerprint, face or PIN every time). Without it a passkey is only "something you have", and it could not stand in for two factors.
3. **The attestation.** *Recommended:* **none** (3.2). Checking it needs certificate chains and the FIDO metadata and answers a question a single administrator does not ask.
4. **Algorithms.** *Recommended:* ES256, EdDSA and RS256 (what browsers and Windows Hello offer). Without RS256 some Windows TPMs could not register.
5. **The host name.** *Recommended:* fixed at the first registration and shown (3.1), with `web.passkeyOrigin` for proxies; no automatic following of a changed host.
6. **How many passkeys.** *Recommended:* up to **10**; the number is no secret and bounds the file.
7. **Conditional UI** (the passkey in the autofill list of the user name field). *Recommended:* **leave it out**; the button is clear, and the autofill needs a user name field that this one-administrator login does not have.
8. **Timing and version.** *Recommended:* after 1.1.0 is out and the week of phase 13 is done, as **1.2.0**; start with package 0. **The owner chose differently:** start at once and develop on a branch (`phase-14-passkeys`); the branch is merged into `main` after 1.1.0 is released (the release workflow builds from the tag, so a branch changes nothing there). Fixes for 1.1.0 stay on `main`; the branch takes them over by merging `main` into it now and then.
9. **Recovery codes of the second factor.** *Recommended:* unchanged; they belong to TOTP. A passkey needs none, because the password is the way back.

**Settled on 2026-11-18:** decisions 1 to 7 and 9 as recommended (a passkey is a login of its own that counts as two factors and may stand in for the TOTP code; user verification required; attestation none; ES256, EdDSA and RS256; the host name fixed at the first registration, with `web.passkeyOrigin`; up to 10 passkeys; no conditional UI; the recovery codes unchanged). Decision 8 as the owner chose: start now, on a branch.

## 8. As built

### 8.1 Work package 0, the spike (2026-11-18)

Scripts: `tools/spikes/webauthn-virtual.mjs` (drives a real browser), `tools/spikes/webauthn-verify.php` (checks its answers with plain PHP), `tools/spikes/webauthn-device.php` (a throw-away page for the owner's host and devices). Parts (a) to (d) are done; the open rest of (d) is named below.

**(a) The browser can be driven without a download.** Chrome (157 here; Edge is the same engine) is started headless with `--remote-debugging-port`, and `node` (24, with its own `WebSocket`) talks the DevTools protocol to it: `WebAuthn.addVirtualAuthenticator` with `ctap2`, `internal`, resident keys, user verification on and presence simulated, then `navigator.credentials.create()` and `.get()` on `http://localhost:PORT/`. **`localhost` is a secure context, so WebAuthn works without a certificate** (finding for the stack proof: phase 4b's Apache on `localhost` is enough). The tools image has no browser and no `node`; the proof of package 7 therefore runs the script **on the host machine** against the published port of the stack (or the image gets a browser then; settled in package 7).

**(b) Real vectors, from a real implementation, without a download.** The virtual authenticator makes **ES256, EdDSA and RS256** keys (all three offered one by one; with all three offered, Chrome takes ES256, the first). The script writes the answers as JSON; they are the test vectors (the unit tests get a copy made small: one registration and one assertion per algorithm, with the throw-away keys of a virtual device, nothing else). What the answers show:
- `clientDataJSON` has `type`, `challenge`, `origin` and `crossOrigin`, as in the specification; the registration's flags are UP, UV and AT; **BE and BS are 0** (a virtual device is not synced).
- `attestation: none` gives an attestation object with `fmt: "none"` and an empty statement; `authData` carries the credential id and the COSE key.
- A plain-PHP check works for all three: a **70-line CBOR reader**, the COSE key turned into a public key (ES256: the fixed 26-byte header plus `04||x||y`; RS256: the DER of `n` and `e`; EdDSA: the raw 32 bytes), `openssl_verify(..., SHA256)` for ES256 and RS256 and `sodium_crypto_sign_verify_detached` for EdDSA. **Every signature verified, and a changed byte was refused, in all three.** The assertion has UP and UV set and the **counter counts** (2 here); the user handle that comes back is the one given at registration. The ES256 signature is DER, as `openssl_verify` wants it.
- The transports the device reports are `["internal"]`: stored as a hint only.

What this changes in the plan: nothing in the design; **the CBOR reader and the COSE conversion are small enough to be written strictly (package 1)**, and the real vectors above replace the search for an outside test suite (package 1 and 2 use them, and the unit tests add the software authenticator and the refusals).

**(c) The live host, answered (the owner ran `webauthn-device.php` on 2026-11-18, host `www.n8mail.de`).** PHP 8.4.27 as `fpm-fcgi`; **`openssl` 3.0.13 with `prime256v1`, `sodium` 1.0.18** and `mbstring` are there, so ES256, RS256 and EdDSA all work. PHP sees `HTTPS` on, `HTTP_HOST` and `SERVER_NAME` the real name, port 443 and `REQUEST_SCHEME` https: **the origin can be made from the request itself**; the only proxy header is `X-Forwarded-For` (a proxy that ends no TLS), which the design does not use. On this host `web.passkeyOrigin` is not needed. The browser took `rpId` = the host name (`www.n8mail.de`), a secure context.

**(d) Two real devices (Chrome 157 on Windows).**

| | Passkey in the browser's password manager | Windows Hello (platform key) |
|---|---|---|
| Algorithm | -7 (ES256) | -7 (ES256) |
| Flags at registration | UP, UV, AT, **BE=1, BS=1** (synced) | UP, UV, AT, **BE=0, BS=0** (this device only) |
| Transports | `hybrid`, `internal` | `internal` |
| Credential id | 16 bytes | 32 bytes |
| AAGUID | `ea9b8d66-4d01-1d21-3ce4-b6b48cb575d4` (that of Google Password Manager, from the base64url `6puNZk0B...`) | `9ddd1817-af5a-4672-a2b9-3e3dd95000a9` (a Windows Hello software authenticator, from `nd0YF69a...`) |
| Assertion | UP, UV, BE=1, BS=1, **counter 0**, user handle 64 bytes, signature 71 bytes | not run (the report ends after the second registration) |

What this means for the design:
1. **The "synced" label of 3.4 works as planned**: the one key says BE=1, BS=1, the other BE=0, BS=0. The page shows what the flags say.
2. **A counter of 0 is real** (the synced passkey): the rule of 3.2 (0 on both sides is allowed) is needed, not theoretical. The counter of Windows Hello is still unknown.
3. **The user handle comes back** for a discoverable login (64 bytes, the one given at registration), so it can be checked against the stored one.
4. **The client data JSON of Windows Hello has an extra member** (`other_keys_can_be_added_here`, "do not compare clientDataJSON against a template"): `ClientData` must read the JSON and check the members it knows, **never compare the text with a template**, and must ignore unknown members. A test with that member is added in package 2.
5. **Credential ids are 16 to 32 bytes**; the store keeps them as base64url and accepts up to 1023 bytes (the specification's limit), not a fixed length.
6. Both keys are ES256, so on these devices RS256 and EdDSA are not used; they stay because other devices (some TPMs, security keys, other managers) need them, and are proven by the virtual authenticator.

Still open from (d): the assertion with Windows Hello (its counter), and a phone and a hardware key if the owner uses them. Neither can change the design; they are looked at in the field test (package 9). The file `webauthn-device.php` can be deleted from the host, and the two `threadsafe-spike` passkeys removed from the devices.

### 8.2 Work package 1, the CBOR reader and the COSE key (2026-11-18)

`Auth\Cbor` (strict reader: integers as far as PHP holds them, strings, arrays, maps, false, true, null; no indefinite lengths, tags, floats, undefined; no map key twice, and a text key that PHP turns into a number counts as the same key; 64 KiB, depth 8, 4096 items; a length that claims more than the input holds is refused before anything is allocated), `Auth\CoseKey` (ES256, EdDSA, RS256 from 2048 bits; keeps the key's bytes; `verify()` returns false for any signature that is wrong, short or odd; OpenSSL refuses a point off the curve), `Auth\PasskeyException`. Tests: the examples of RFC 8949, every refusal, 3000 damaged inputs, and the answers of Chrome in `tests/fixtures/webauthn/chrome-virtual.json`. The full unit suite and PHPStan were green after it (4273 tests).

### 8.3 Work package 2, the checks (2026-11-18)

`Auth\Base64Url` (strict: no padding, no stray bits), `Auth\AuthenticatorData` (flags, counter, the data of a new credential, extensions; nothing may be left over), `Auth\ClientData` (read as JSON; unknown members are ignored, never compared with a template), `Auth\PasskeyCredential` (public data of a registered passkey: id, key, counter, the flags for a synced key, AAGUID, transports), `Auth\Passkeys` (the relying party: the options for both ceremonies, `verifyRegistration()`, `verifyAssertion()`; it keeps no state), `Auth\PasskeyClonedException` (a counter that does not go up). The software device for the tests is `tests/Support/SoftwareAuthenticator.php`; it builds answers and can spoil each one in one place.

What differs from the plan or is worth knowing:
1. **The counter rule is the specification's** (3.2 is corrected above): a counter that falls back to 0 after it was running is also a clone, not only a smaller non-zero value.
2. **The attestation object is read for its shape only** (a text `fmt`, a map `attStmt`, the bytes `authData`) and any `fmt` is accepted, because a statement that is not checked can neither help nor hurt; the browser is asked for "none" and sends it.
3. **A device that changes its eligibility for sync** between registration and login (the flag BE) is refused; the state of the backup (BS) may change and is stored with the new counter.
4. **The user handle is checked when the device sends it**; a device that leaves it out is found by the credential id alone.
5. **The transports are a hint**: only the six names of the specification are kept, each once.
6. A flake in the tests was found and fixed on the way: OpenSSL gives the coordinates of a key without the leading zero in about 1 of 128 keys, and the test device must pad them to 32 bytes (the product code reads what a device sends, which has them padded).

Tests: 84 for the checks (every refusal of 3.2 has one, three algorithms, a synced device, the extra member of the client data, a counter of 0, a clone, the answers of the real browser for all three algorithms) and the small readers; `composer stan` is clean. Not done yet: the store and the command line (package 3), and everything that talks to a browser (packages 4 and 5).

### 8.4 Work package 3, the store and the command (2026-11-18)

`Auth\PasskeyStore` (`data/passkeys.json`, mode 0600, written whole through a temporary file and a lock on `passkeys.json.lock`, like `two-factor.json`), `Auth\StoredPasskey` (a passkey with its name and dates; `kind()` says "synced" or "only on this device" from the flag BE), `Auth\PasskeyStoreException` (a file that exists and cannot be read; it is a `PasskeyException`, so the login can treat it as "no passkey login now" and never touches the password). `admin passkey` in `Cli\AdminCommand`.

What differs from the plan or is worth knowing:
1. **The command takes words, not options**: `admin passkey [list | remove NAME | remove-all]`, because `--remove` is already a flag of `cron-secret` and an option is global to the command. `remove` takes the name or the start of the id (at least 4 characters) that `list` shows, and refuses a name that fits more than one.
2. **The file holds public data only** (ids, public keys, counters, flags, names, dates) plus the user handle that all passkeys were made with and the host name they are bound to; the first passkey fixes both, and `add()` refuses another host name or handle.
3. **Ten at most** (decision 6), a duplicate id is refused, a name is cleaned (control characters, 64 characters, "Passkey N" if empty).
4. **The last passkey removed takes the file with it**, so "none" and "never had one" look the same. `remove-all` also removes a file that cannot be read, as `two-factor --disable` does; `list` shows such a file as DAMAGED and says that the password login is not affected.
5. The user handle is made at the first registration (`userHandle()` returns the stored one, or new random bytes that `add()` then stores).

Tests: `PasskeyStoreTest` (what is stored and read back, privacy of the file, the host name and handle that stay, the limit, the names, a login's counter, rename and remove, a damaged file never reads as "no passkeys", a key that does not parse, two stores writing in turn) and four tests of `AdminCommandTest`. Lint and PHPStan are clean.

### 8.5 Work package 4, the page *Security* (2026-11-18)

`Admin\PasskeyHost` (the host name and origin of the request, with the reason when passkeys cannot be bound there), `Admin\SecurityController` (four routes: `POST /security/passkey/options`, `/register`, `/rename`, `/remove`; the section *Passkeys* on the page), `Admin\Services::$passkeys`, the script in `public/app.js`, three small rules in `public/app.css` (`.needs-js`, `.no-js-only`).

How it works: the form asks for a name and the password again; the script posts them to `passkey/options` (the password is checked in the throttle of the login; the challenge, the user handle and the name wait in the session for 5 minutes and are used once), lets the browser make the passkey with the options, and posts the answer as JSON to `passkey/register` (the token of the session in the header, as the tick loop does). A good answer is stored, the other sessions end, the audit gets `passkey.added`; a bad one gets `passkey.register.failed` with the name of the failed check and no data of the answer, and the person sees one general text. Without JavaScript the form is hidden and the page says so (a form that posted the password in the address would be worse than none).

What differs from the plan or is worth knowing:
1. **Where passkeys are offered** (3.1, made concrete): the host name that the browser asked for must be a name (not an IP address, not IPv6), and the page must be `https` or `localhost`/`*.localhost`; the scheme comes from `$request->https` and the port from the `Host` header, **never from `X-Forwarded-*`**. Otherwise the page says why and offers no form. The setting `web.passkeyOrigin` for a proxy that ends TLS is work package 6.
2. **The host name is fixed by the first passkey**: another name shows the host that the passkeys are bound to and offers no form (and `options` answers 409).
3. **The field names of the form are `passkey_name` and `passkey_password`**: the first version used `password`, which made a second `id="f-password"` next to the form of the second factor; the audit of the page found it.
4. **A damaged file of the passkeys** shows its message and the way back on the page and nothing else changes: the rest of the interface and the password login work.
5. **What the page promises is ahead of the program**: it says that a passkey logs in, which package 5 makes true. The branch is not released; the wording stays.
6. **`requireTwoFactor`** does not yet count a passkey and does not yet let the routes of the passkeys through while a second factor is required but missing (work package 6).

Proof: `SecurityPasskeysTest` (29 tests with the software device: the form only where it can work, the password asked again and throttled, the challenge used once and valid 5 minutes, every check of 3.2 refused with a general answer and an audit line, three algorithms, the host name that stays, ten at most, rename and remove with the password, the other logins ended, the token of the session needed, a damaged file) and `tests/stack/passkey-browser.mjs` (then **a first cut of the proof of package 7**, see 8.8): a real Chrome with a virtual authenticator logs in to the real page (PHP's built-in server on `localhost`), adds a passkey through the script of `app.js`, sees it listed, is refused a wrong password and a second registration of the same device, and runs `tools/a11y-audit.js` on the page in both schemes (no contrast or structure problem; 48 texts). Lint and PHPStan are clean.

### 8.6 Work package 5, the login with a passkey (2026-11-18)

`Admin\AuthController::passkeyOptions()` and `::passkeyLogin()` (the two public routes `POST /login/passkey/options` and `POST /login/passkey`), the button on the login page and on the page of the code, the script in `public/app.js`, `PasskeyStore::user()`.

How it works: the button asks for a challenge. It is kept in a **session that has no user**, so it is no login; a visitor who has no session gets one (with its cookie), a visitor whose session waits for the code of the second factor keeps that session and its wait. The device signs the challenge; the answer goes to `passkey` as JSON. The challenge is taken out of the session first, so it is used once whatever happens, and it is good for five minutes. A good answer makes a **new session** (as after a password: the old token is destroyed) with `user` and `mfa = passkey`, writes the counter, the state of the backup and the time into the file of the passkeys, and the audit gets `login` with `mfa: passkey`. A passkey login stands in for both factors and is not asked for a code even when the second factor is on.

What differs from the plan or is worth knowing:
1. **Nothing waits or counts** (the plan said "throttled in a file of their own"; 3.3 is corrected above): see the reason there.
2. **The button is shown only where it can work**: passkeys exist and can be read, the page is the host name that they are bound to on a secure page, and PHP can check them; otherwise the page shows nothing of passkeys. This tells a stranger that a passkey exists, which is not a secret worth a button that does not work.
3. **If the counter cannot be written** after a good answer, the login still succeeds and the audit gets `login.passkey.unwritten`: the check for a copied key is weaker until the file can be written again.
4. **A session that holds a challenge is not a login.** The test that walks every route behind the login with a session that waits for a code now also walks it with a session that holds a challenge (`TwoFactorLoginTest::walkRoutesBehindTheLogin()`, used by `PasskeyLoginTest`); the test that lists the public routes names the two new ones on purpose.
5. Challenges are kept in session files, and asking for one makes a file when the visitor has no session. Sessions of the kind are collected with the idle ones after two hours; a flood of such requests would make many small files. Not handled (a stranger can make session files on any site that sets a cookie to a first visit); noted.

Proof: `PasskeyLoginTest` (the button where it can work, three algorithms, a new session and the counter written, single use, five minutes, eight spoiled answers refused with one general text and an audit line, another device's signature, a clone, twelve failures from strangers without the owner waiting, junk, no passkeys no challenge, a logged-in visitor, a damaged file, the walk of every route, the page of the code and the wait that survives asking for a challenge: 20 tests) and the browser run of `tests/stack/passkey-browser.mjs` (a real Chrome with a virtual authenticator logs out, finds the button, logs in with the passkey, lands on the dashboard, and the list shows the use; the audit of the login page is clean in both schemes). Lint and PHPStan are clean.

### 8.7 Work package 6, the requirement, the System page and the origin (2026-11-18)

`Admin\Config` (the setting `web.passkeyOrigin`, type `origin`), `Admin\PasskeyHost::fromOrigin()` and `Services::passkeyHost()` (every place that asks "where are passkeys bound?" goes through it), `Admin\Kernel::secondFactorRequired()`, `Admin\SystemChecks` (the rows *Passkeys* and *Second factor*), `Admin\SystemController` (the field in the settings form).

What differs from the plan or is worth knowing:
1. **A password alone is not a second factor, even with passkeys** (3.5 is corrected above). The plan said that the System page warning stays quiet when a passkey is registered; it does not, because the password still logs in alone then. The row says so and names the two ways out (an authenticator app, or the requirement).
2. **A passkey made in the session satisfies the requirement** for that session; its routes (`POST /security/passkey/options` and `/register`) are open to a login that waits for its second factor. Renaming and removing are not.
3. **`web.passkeyOrigin`** is lower-cased, takes scheme, host name and an optional port and nothing else (no path, no user, no query), must be https (or `localhost`) and a name (not an IP address). With it set, the host name and the scheme do not come from the request at all, so a proxy that ends https works; the browser still enforces that the page really is at that origin. The setting is in the System settings and works with `admin set web.passkeyOrigin ...`.
4. **The System page** gets a row *Passkeys*: none (a note), N bound to the host (ok), a file that cannot be read (a warning, never a failure, the password login is not affected), PHP without openssl or sodium (a warning, and names the extension whose kind of key cannot be used).
5. `Core\Environment` and `threadsafe doctor` are **not** changed (the plan said so): the extensions are optional, and only the web interface uses them.

Proof: `PasskeyRequirementTest` (24 tests: a password login reaches only Security, a passkey made in the session meets the requirement, rename and remove stay shut, passkeys that exist do not make a password enough, a passkey login meets it, the rows of the System page, a damaged file is a warning, the setting saved and refused, bad origins, a proxy that ends https for the form and the login button, `fromOrigin()` cases) and the browser run (the System page and its settings are clean in the audit in both schemes, the row is there). Lint and PHPStan are clean.

### 8.8 Work package 7, the proof in a real browser (2026-11-18)

`tests/stack/passkey-browser.mjs` (the proof; it grew out of the first cut `tools/spikes/passkey-page.mjs`, moved here), `tests/stack/passkeys.sh` (makes the installation of the web service empty, gives it an administrator and runs the proof against it), phase 4h of `tests/stack/run.sh`.

How it works: Chrome (or Edge) runs headless with a virtual authenticator (`WebAuthn.addVirtualAuthenticator`: ctap2, internal, resident keys, user verification), driven over the DevTools protocol by a node script that uses nothing of ours but the pages of the interface. **`localhost` is a secure context, so the real Apache of the service `web` (limits of 5 s and 64 MB) needs no certificate.** The script runs on the machine that runs `run.sh` (node and Chrome are not in the tools image); without node or Chrome the phase says so and is skipped, because no other phase depends on it. On its own (no `--url`) it starts PHP's built-in server over a throw-away installation, which is how it is tried during development.

What it proves (all in one journey, 19 checks): the form is there; a passkey is added through the script of the page and the device holds it for this host; a wrong password and a second registration of the same device are refused; the login page offers the button and a passkey logs in, and the list shows that it was used; a second key from another device is added and logs in; with the second factor on, the password alone leads to the page of the code, which offers a passkey, and the passkey makes a full session (TOTP is computed in the script from the key of the page, RFC 6238 with the library of node, and the code of the next step switches it off again); a key that was removed on the page is gone from the list and the device that still holds it is refused; an IP address is refused with its reason and no form; the System page has the row; the a11y audit (`tools/a11y-audit.js`, both schemes) finds nothing on the login page, the page of the code, the Security page with and without passkeys and the System page; the installation is left with no passkey and no second factor.

What differs from the plan or is worth knowing:
1. **ES256 only through the page.** The server offers ES256, EdDSA and RS256 and Chrome's virtual authenticator takes the first, so the page makes ES256 keys. EdDSA and RS256 are proven with real answers of Chrome in the unit tests (`tests/fixtures/webauthn/chrome-virtual.json`, `webauthn-virtual.mjs`, which asks for each algorithm by itself). Making the page ask for another algorithm would need a build of the page that is not the product's.
2. **"A key under another host name" is proven with an IP address** (the page refuses and says why), and the binding to the first host name by the unit tests (`SecurityPasskeysTest`, `PasskeyStoreTest`): the stack has one name for the service, `localhost`.
3. **The authenticator is taken away to make a second key**, because the browser refuses to register a device that already holds one of the listed passkeys (`excludeCredentials`), which is the behaviour that the first check shows.
4. **Plain http**: the Docker port forward comes from another address than 127.0.0.1, so the login page asks to accept the unencrypted connection; the script ticks the box, as a person would. Passkeys work on `localhost` over http.

Proof: `tests/stack/passkeys.sh` is green on MariaDB 10.11, MySQL 8.4 and MariaDB 11.8 (the interface does not touch the database for any of this; the three runs follow the rule that every phase runs on all three servers), the standalone mode is green, and the whole journey is repeatable (it empties the installation first).

### 8.9 The other work packages

Not started.
