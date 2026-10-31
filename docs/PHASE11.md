# Phase 11 – two-factor authentication for the web interface

**Status:** **in progress** (planned 2026-10-31; the decisions of section 7.2 were settled with the owner the same day, all as recommended; work package 1 is built, see section 8).
**Context:** [WEB.md](WEB.md) ("Logging in", "Security in short") is the login as it works today; [PHASE4.md](PHASE4.md) 3.2 is its design (sessions in files, the throttle, the audit log). The login stays the password; this phase adds a second step after it.

## 1. Goal and scope

**What exists today:** one administrator (`data/admin.json`, the password as an Argon2id or bcrypt hash), a throttle for wrong passwords (global, 5 s doubling to 5 minutes), a new session at every login (files in `data/sessions/`, idle 2 hours, 12 hours at most, cookie `HttpOnly`, `SameSite=Strict`), every change a `POST` with a session token. `WEB.md` says it plainly: **a compromised interface can read every site on the server** - a backup holds the files and the database of a shop, and the profiles hold the database passwords. One password is then the only thing between the internet and all of it.

**What is missing:** a second factor. A leaked, reused or guessed password (the throttle slows guessing, it does not stop a patient one) is enough today.

| In scope | Out of scope (later, or never) |
|---|---|
| **TOTP** (RFC 6238: six digits, 30 seconds, SHA-1 - what every authenticator app does) as a second step of the login | passkeys / WebAuthn (decision 1), SMS or e-mail codes, push approval |
| a page **Security** (menu): switch on (password again, scan a code, confirm with one code), switch off, new recovery codes | a page to change the password in the browser (today only `admin password`; the Security page is where it would go) |
| **recovery codes** (10, shown once, each works once) | several users, roles, a second factor per user policy |
| the way back when the phone is lost: a recovery code, `admin two-factor --disable`, or deleting one file | asking for a code again before dangerous actions (download, key, update): the session is the unit |
| a **QR code** drawn by the program (plain PHP, an inline SVG) | "remember this browser for 30 days" (decision 5) |
| a setting `web.requireTwoFactor` and a warning on the System page while the factor is off | a second factor for the command line, the trigger address or `threadsafe-restore.php`: they are not the login (see below) |

**What the factor does not cover, and says so:** the command line (whoever has a shell has the data), the trigger address `/cron/<secret>` (a secret that starts backups and nothing else), `threadsafe-restore.php` (its own access key). An HTTP authentication in front of the interface, which `WEB.md` suggests, stays a good idea.

**No migration.** A data directory without the new file works as before: no factor, the login as today.

## 2. Deliverables

1. `Auth\Base32` and `Auth\Totp` (new, no extension needed: `hash_hmac`): the code for a moment, the check with a window and the replay rule, the `otpauth://` address.
2. `Auth\TwoFactorStore` (new): `data/two-factor.json` (mode 0600), the secret, the last accepted step, the recovery codes (only as hashes); `Auth\RecoveryCodes`.
3. `Admin\AuthController`: the second step of the login (`/login/code`), the pending state, its limits; `Admin\Kernel` and `Web\Sessions`: the changes that keep a half-logged-in person out of every page (3.3).
4. `Admin\SecurityController` (new): the page *Security* - status, switching on in three steps, recovery codes, switching off.
5. `Auth\QrCode` (new): a QR encoder (byte mode, error correction M) and the SVG; the page always also shows the key as text.
6. `Cli\AdminCommand`: `admin two-factor status|disable`; `admin status` names it; `Config`: `web.requireTwoFactor`; `SystemChecks`: the warning.
7. Tests (section 4) and the documentation: `WEB.md`, `README.md`, `CHANGELOG.md`, `AGENTS.md`, this plan's "As built".

## 3. Design

### 3.1 The factor

TOTP: a secret of 20 random bytes (`random_bytes`, shown as 32 Base32 characters), the code is `HOTP(secret, floor(time / 30))` with HMAC-SHA1, six digits - the combination that Google Authenticator, Microsoft Authenticator, Aegis, 2FAS, FreeOTP, 1Password and the others all read. The address for the app is `otpauth://totp/ThreadSafe%20%28host%29:admin?secret=...&issuer=ThreadSafe%20%28host%29&algorithm=SHA1&digits=6&period=30` (the label and the `issuer` say the same, as the apps want); the host name only tells several installations apart in the app.

**Check.** The steps of the previous, the current and the next 30 seconds are accepted (a clock that runs a little wrong, a code typed slowly); the comparison is `hash_equals`. **A step is accepted once:** `lastStep` is saved, a code of the same or an older step is refused, so that a code seen over a shoulder or in a log does not log in a second time. The time is `Services::$clock`, so the tests set it.

**Where the secret lives.** It cannot be a hash (the program has to compute the code), so `data/two-factor.json` holds it in the clear, like the profiles hold database passwords: mode 0600 in the data directory that the web server does not serve. Encrypting it with a key from the same directory would be for show only. The file has the list form of `admin.json` (`{"users": [{"name": "admin", "secret": "...", "enrolled": "...", "lastStep": 0, "recovery": ["sha256..."]}]}`) so that a second user would not need a new format.

It is a **separate file** on purpose: `AdminStore::setPassword()` writes the user list new from the fields it knows, so a field added there would be lost at the next password change - and a change of the password must never switch the factor off.

### 3.2 Recovery codes

Ten codes of 12 characters (Base32 without look-alikes, 60 bits, written `XXXX-XXXX-XXXX`), made when the factor is switched on and shown **once** with a button to copy and a note to print or store them in a password manager. Only `sha256` of each is stored (the codes are random with enough entropy that a plain hash is right; Argon2id for ten codes per login try would only cost time). A code works at the second step in place of the six digits, is struck out when it is used, and the audit log says how many are left. "New recovery codes" (password and a code) replaces all ten.

### 3.3 The login in two steps

```
POST /login   password right, no factor        -> a full session, as today
POST /login   password right, factor switched on -> a *pending* session, redirect to /login/code
POST /login/code   code (or recovery code) right -> the pending session is destroyed, a full one made
```

The pending state is a session file like any other, with `pending = {user, since}` and **no `user`**. The trap is the places that read "a session exists" as "someone is logged in": `Kernel::run()` (`$context->session === null`) and `Kernel::loggedIn()` (the error pages show their message to a logged-in person). Both change to ask for `Context::user()`; a test fails if any route outside the public ones answers a pending session. (`Context::user()` already returns null when `user` is not set.)

Limits of the pending state: it ends after **5 minutes** and after **5 wrong codes** (then the password is asked again); the codes have their **own throttle** (`throttle/login-code.json`, the rule of the password throttle: three free tries, then 5 s doubling to 5 minutes, global, so that an attacker who changes addresses gets nowhere and the owner waits a few minutes at worst). With three accepted steps of a million, ten tries an hour are one chance in 30,000 an hour for someone who already knows the password. `POST /login/code` is a public route like `/login` (no session token yet): `SameSite=Strict` and `Csrf::sameOrigin` protect it; the plain-http confirmation of the login form applies as before.

After success: a new session (never the id that went through the form), `mfa` noted in it, `login` in the audit log with `"mfa": "totp"` or `"recovery"`. Failures: `login.code.failed`; a used recovery code: `login.recovery`.

### 3.4 Switching on, off and the other sessions

The page *Security* (menu, below *Schedule*), three steps, each a `POST` with the session token:

1. **Password again** (a stolen session must not be able to switch the factor on and lock the owner out, or off and take the protection away). The program makes a secret and keeps it in the session, **not in the file yet**.
2. **Scan** the QR code (or type the key) and enter the code the app shows now. A wrong code changes nothing.
3. **Recovery codes** appear once; the factor is on only when this page is left with "I have stored them" (the codes are in the file from step 2, so a closed tab does not leave the person without; the page shows them again only until it has been left once).

**Switching off:** password and a current code (or a recovery code). **When the factor is switched on or off, every other session ends** (`Sessions::destroyExcept()`: the directory is scanned for files with the user; a session that an attacker may hold dies with the change). Each step is in the audit log (`twofactor.enabled`, `.disabled`, `.recovery.made`); no secret and no code is written, in line with `AuditLog`'s rule.

### 3.5 The way back

In the order a person tries them: (1) a recovery code at the second step; (2) with a shell: `php bin/threadsafe admin two-factor --disable` (audit entry, source "command line"); (3) without a shell, with FTP or the file manager: **delete `data/two-factor.json`**. Whoever can do (2) or (3) has the data directory anyway, so they add no weakness; `WEB.md` says all three where the person reads about the factor, and the Security page says them before they are needed.

`admin two-factor` has `status` (on or off, since when, recovery codes left) and `--disable`. Switching **on** is done in the browser only; that keeps the secret off terminals and logs.

### 3.6 The QR code

The only sizeable new piece. An encoder for byte mode, error correction level M, versions 1 to 6 (an `otpauth://` address is 100 to 150 bytes), the eight masks with the penalty rule, drawn as an **inline `<svg>`** (the content security policy has `img-src 'self'`, and an inline element needs no source) with a white quiet zone of four modules and black modules **in both colour schemes** (a scanner needs the contrast, not the page's theme). No dependency: `composer.json` requires none, and the program works on a host where nothing else is installed. The proof is not our own decoder: known vectors from ISO/IEC 18004 for the data and format bits, and **`zbarimg` reading the SVG's rendering** in the stack (section 4.2). The key as text is always shown next to it, so a wrong picture costs a typing, not the access.

### 3.7 The setting and the warning

`web.requireTwoFactor` (bool, default `false`): when true, a person who logs in without a factor reaches the Security page and nothing else until it is on (the same check as the pending state, 3.3). Set with `admin set web.requireTwoFactor true` and on the System page. While the factor is off, the System page shows a warning in the colour of the plain-http warning ("The login has only a password. Set up a second factor under Security"); it is only a warning, because the first thing a new installation needs is a login, and a host without a working clock must not be locked out by default.

### 3.8 Time

A TOTP needs a right clock on the server. The Security page shows the server time (UTC) next to the code field; the second step says, after a wrong code, "check that the clocks of the phone and the server agree" (the server time is in the answer; nothing is revealed that the person has not already proven by the password).

### 3.9 Updating and rolling back

The new file is read by the new release only. A program rolled back to a release without it **logs in without the factor** and says nothing. `WEB.md` "Updating" and the release notes say so, and the roll-back on the update page names it when `data/two-factor.json` exists.

## 4. Test strategy

### 4.1 Unit tests (no database)

- `Base32`: the RFC 4648 vectors, lower case and padding accepted on input, a wrong character refused.
- `Totp`: the **RFC 6238 appendix B vectors** (secret `12345678901234567890`, times 59, 1111111109, 1111111111, 1234567890, 2000000000, 20000000000), six digits, a step earlier and later accepted, two earlier refused, the same step refused the second time, `hash_equals` in use, the `otpauth://` address (escaping of host and name).
- `TwoFactorStore`: the file form and mode 0600, a damaged file reads as "off" **and the login refuses** (a file that exists but cannot be read must not switch the factor off silently - decision of the code, tested), a password change leaves the factor alone, recovery codes: ten, shown once, stored as hashes, each works once, the count.
- The login: no factor = as today; with the factor the password alone gives **no page and no download** (every route of `Kernel::router()` against a pending session: a test that walks the table), 5 wrong codes end the pending state, 5 minutes end it, the code throttle (waits, not counted per address), a recovery code, a replayed code, a new session id after success, the audit entries without any secret.
- *Security*: the three steps, a wrong password or code changes nothing, the other sessions end, switching off needs password and code, a CSRF token is needed, `requireTwoFactor` closes every other page.
- `QrCode`: the data and format bits against ISO 18004's example, the size for versions 1 to 6, the mask choice, the SVG has the quiet zone and no external reference.
- The command: `status`, `--disable`, `admin status`; the text of the roll-back note.

### 4.2 Integration and the browser

- Phase 4b of `tests/stack/run.sh` (the real Apache with 5 s / 64 MB): switch the factor on through the pages and log in with **`oathtool --totp -b KEY`** (oath-toolkit, added to the tools image: an implementation that is not ours), then log out and in again with a recovery code. The SVG of the QR code is rendered to PNG and read with **`zbarimg`** (added to the tools image); what it reads must be the `otpauth://` address.
- `tools/a11y-audit.js` on the Security page (all three steps) and the code step of the login, in both colour schemes; seen in the browser at phone width, with the autofill of a password manager (`autocomplete="one-time-code"`, `inputmode="numeric"`, `autofocus`).

## 5. Work packages

| # | Package | Needs | Proof |
|---|---|---|---|
| 1 | `Base32`, `Totp` (check, window, replay rule, address) | - | unit tests of 4.1 (RFC vectors) |
| 2 | `TwoFactorStore`, `RecoveryCodes` | 1 | unit tests |
| 3 | The login in two steps: pending state, `Kernel` and `Sessions` changes, `/login/code`, throttle, audit | 1, 2 | unit tests (the walk over all routes) |
| 4 | The page *Security*: switching on and off, recovery codes, other sessions end, the roll-back note | 3 | controller tests, browser |
| 5 | `QrCode` and the SVG | - | unit tests; `zbarimg` in the stack |
| 6 | `admin two-factor`, `web.requireTwoFactor`, the System warning | 3, 4 | command and page tests |
| 7 | Integration in phase 4b, `oathtool` and `zbar` in the tools image, the a11y audit, the look in both schemes | 3 to 6 | `run.sh` phase 4b, matrix, audit |
| 8 | Documentation (`WEB.md`, `README.md`, `CHANGELOG.md`, `AGENTS.md`, "As built") | all | read through |
| 9 | **Field test** by the owner: an authenticator app on the phone, the customer server, a recovery code, `--disable`, the roll-back note | 8 | the findings |

Packages 1, 2 and 5 are pure logic and independent (5 is the largest and the only one that could be cut: decision 2). One commit per package (`Phase 11, work package N: ...`).

## 6. Acceptance criteria

1. With the factor on, the right password alone opens **no** page, no download and no JSON status call; the wrong code, a replayed code and an expired pending state do not either.
2. An authenticator app (the ones named in 3.1) scans the QR code, and its codes log in; with the key typed by hand the same.
3. A recovery code logs in once and not twice; the count is shown; new codes replace all ten.
4. A lost phone has three ways back (3.5), each tried in the field test, and the page names them.
5. Switching the factor on or off ends the other sessions; changing the password with `admin password` leaves the factor on.
6. A data directory without `two-factor.json` behaves as before; a damaged one refuses the login instead of opening it.
7. No secret, code or recovery code in any log, audit entry, report, URL or the sidecar; the audit entries name only the events.
8. `composer check` and all three database servers stay green; the pages pass the contrast audit in both colour schemes.

## 7. Risks and decisions

### 7.1 Risks

| Risk | Handling |
|---|---|
| **Lock-out** by a lost phone | recovery codes shown once with a prompt to store them, `admin two-factor --disable`, deleting the file; all three named on the Security page and in `WEB.md` |
| A half-logged-in session read as a login (the worst error in this phase) | the check moves to `Context::user()` in `Kernel::run()` and `Kernel::loggedIn()`; the test walks the whole route table against a pending session |
| A wrong clock on the server or the phone | the window of one step, the server time on the page and in the message after a wrong code |
| Guessing six digits | pending state ends after 5 tries, own throttle, replay rule; 3.3 gives the odds |
| The throttle is global, so an attacker can make the owner wait | as for the password today (accepted in phase 4); the wait is at most 5 minutes, recovery from the shell is not affected |
| A password change or an update drops the factor | its own file (3.1); a test that changes the password; 3.9 for the roll-back |
| A wrong QR code | known vectors and `zbarimg` (4.2); the key as text beside it |
| The secret is readable by anyone with the data directory | the same trust as the database passwords in the profiles; said in `WEB.md`; 0600 |
| A host without `hash_hmac` or `random_bytes` | both are PHP core; `threadsafe doctor` already needs a modern PHP 8.4 |
| A person who switches the factor on in a session an attacker holds | step 1 asks for the password again; other sessions end; the attacker would also need the password |

### 7.2 Decisions (owner)

Each with the recommendation. **Settled on 2026-10-31: every decision as recommended, and decision 10 as "start now"** (work package 1 was built the same day; the rest follows package by package).

1. **Method.** TOTP first **(recommended)**: works with every app, no https requirement, plain PHP. Passkeys (WebAuthn) later as a second package of its own: they need https and a registered host name (not every customer server has one), CBOR and COSE parsing and a signature check - a phase of its own, and a better factor against phishing, which TOTP is not.
2. **QR code.** Build our own encoder (3.6) **(recommended)**: a phone is the usual second device, and typing 32 characters is what people give up on. The fallback is the first release with the key as text only and the QR code as a later package; 1 to 4 and 6 do not depend on it.
3. **Place.** An own page *Security* in the menu **(recommended)**: it is a person's account, the System page is the installation's settings; the page is also where a password change in the browser would go. Or a section of the System page.
4. **Optional or required.** Optional, with the warning on the System page and the setting `web.requireTwoFactor` **(recommended)**; or required for every login from this release on (an update would then lock the owner out of an installation that has no factor yet, so not without a way to set it up first).
5. **Remember this browser.** Not in this version **(recommended)**: the session already lasts 2 hours idle / 12 hours, and a long-lived "trusted" cookie is a second login that skips the factor. If it is wanted: a setting, 30 days, a token bound to the user agent, listed and revocable on the Security page.
6. **Recovery codes.** Ten, 12 characters, hashed with `sha256`, shown once **(recommended)**.
7. **Where the secret lives.** A separate file `data/two-factor.json` **(recommended)**, not a field of `admin.json` (3.1).
8. **Setting it up from the command line.** No: only `status` and `--disable` **(recommended)**; or `admin two-factor enable` printing the key for a host that can only be reached by SSH (the key would then stand on a terminal).
9. **Step-up before dangerous actions** (a code again before a download or the update): not now **(recommended)**; the session is the unit.
10. **Order of work.** After the field test of 0.9.0-beta.3 on the customer server and the decisions of phase 10; packages 1, 2 and 5 are pure logic and can start earlier. *Settled: start now.*

## 8. As built

**Work package 1 (2026-10-31): `Auth\Base32` and `Auth\Totp`** in `src/Auth/`, tests in `tests/Unit/Auth/`.

- `Base32`: RFC 4648 without padding when writing; reading accepts lower case, spaces, hyphens and padding, and refuses any other character with an `InvalidArgumentException`.
- `Totp`: `newSecret()` (20 random bytes as 32 Base32 characters), `step($time)`, `code($secret, $step, $digits = 6)`, `verify($secret, $typed, $time, $lastStep = 0)` and `uri($secret, $account, $host = '')`. `verify()` returns the step that matched (or null); the caller keeps it as `lastStep`, and a code of that step or an older one is refused. All three steps of the window are compared whether or not an earlier one matched. The typed code is 6 digits, spaces and hyphens ignored.
- Proof: the SHA-1 vectors of RFC 6238 appendix B (all six times) and the vectors of RFC 4648 section 10; `composer lint` and `composer stan` clean. The checks against a real tool (`oathtool`) wait for package 7.
- Differs from the plan: the `issuer` is `ThreadSafe (host)`, the same as the label (3.1 now says so).
