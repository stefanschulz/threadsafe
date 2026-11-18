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
5. `admin passkey --list | --remove=ID | --remove-all` (the way back, 3.6) in `Cli\AdminCommand`.
6. The System page: a row *Passkeys* (usable or not, and why not); the warning of `web.requireTwoFactor` also counts passkeys.
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

**PHP needs `ext-openssl`** for ES256 and RS256 (the two algorithms that every device offers; EdDSA comes from `ext-sodium`, which the age format already suggests). It is not in `composer.json` as required, because the program works without it: the passkey section says "this server's PHP has no `openssl` extension" and stays off, like encryption without `sodium`. Both are in the list of checks of `Core\Environment` so that `threadsafe doctor` and the System page name them.

### 3.3 The login in two ways

The login page gets a second button, **Log in with a passkey**, above the password form. It needs JavaScript; without it (or without WebAuthn in the browser) the button is not shown and the password form works as today.

- **With a passkey:** the browser shows its own chooser, the device asks for a fingerprint, face or PIN (*user verification*: this is why a passkey login counts as **two factors** and does not ask for a TOTP code). The session is made at once.
- **With the password:** exactly as today, including the TOTP step when it is on. A passkey may be offered at the code step too (*Use a passkey instead of the code*): that is the phishing-resistant way to do the second step, and it only needs the same two endpoints.

The pending-session rule of phase 11 holds: a session between steps has no `user`, and `Kernel` asks `Context::user()`, never "is there a session". The passkey endpoints are reachable without a login (that is their job) and are throttled in a file of their own, as `/login/code` is; a failure is logged as `login.passkey.failed` without the data of the answer; the audit never holds a challenge, a signature or a key.

### 3.4 The page *Security*

A section **Passkeys** under the one for the second factor: the list (name given when it was made, the kind: *synced* when the backup-eligible flag is set, else *only on this device*; the dates), **Add a passkey** (the password again; then the browser's dialog; then the name), **Rename**, **Remove** (the password again; the last passkey may be removed, nothing else depends on it). After adding or removing, the other sessions end (`Sessions::destroyExcept()`), as for TOTP. The page warns once, in plain words, that a key stored only on one device is lost with the device, and that the password stays the way in.

### 3.5 `web.requireTwoFactor`

The setting asks for a second factor of every login. A passkey login **satisfies** it (it is two factors in one step). If a passkey is registered and the setting is on, TOTP is not asked for when the password is typed and a passkey is used at the code step. The warning on the System page ("the second factor is not switched on") stays quiet when a passkey is registered.

### 3.6 The way back

A passkey is lost with a device. Therefore the **password login stays** with every setting, and a passkey never replaces it: a passkey-only mode is out of scope on purpose, because the way back from it (a recovery on the server) is what a customer's host makes hard. Losing every passkey costs the convenience, not the access.

- The password (and the TOTP code or a recovery code, if TOTP is on) logs in as before.
- `php bin/threadsafe admin passkey --list` shows the keys by name; `--remove=ID` and `--remove-all` delete them; deleting `data/passkeys.json` does the same. The key file holds public keys only: leaking it is no breach.
- An installation that moves to another domain loses its passkeys (3.1): the page says so before the first registration and the update notes of a move should. The command above clears them; new ones are made at the new address.

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
| **The domain changes** (a migration, a new host name) and the passkeys die | The `rpId` is shown on the page; the text before the first registration says it; the password stays; `admin passkey --remove-all` clears them |
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

### 8.4 The other work packages

Not started.
