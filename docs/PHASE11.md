# Phase 11 – two-factor authentication for the web interface

**Status:** **done** (2026-10-31): built and proven in Docker, released as 0.9.0-beta.4, and tried by the owner with a real authenticator app on the local test installation and on the live system, successfully (work package 9) (planned 2026-10-31; the decisions of section 7.2 were settled with the owner the same day, all as recommended; work packages 1 to 8 are done, see section 8). User documentation: [WEB.md](WEB.md) "Security".
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

The only sizeable new piece. An encoder for byte mode, error correction level M, versions 1 to 10 (an `otpauth://` address is 130 to 200 bytes; version 10 holds 213), the eight masks with the penalty rule, drawn as an **inline `<svg>`** (the content security policy has `img-src 'self'`, and an inline element needs no source) with a white quiet zone of four modules and black modules **in both colour schemes** (a scanner needs the contrast, not the page's theme). No dependency: `composer.json` requires none, and the program works on a host where nothing else is installed. The proof is not our own decoder: known vectors from ISO/IEC 18004 for the data and format bits, and **`zbarimg` reading the SVG's rendering** in the stack (section 4.2). The key as text is always shown next to it, so a wrong picture costs a typing, not the access.

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

**Work package 2 (2026-10-31): `Auth\TwoFactorStore`, `Auth\RecoveryCodes`, `Auth\TwoFactorException`** in `src/Auth/`.

- `TwoFactorStore` keeps `data/two-factor.json` (0600, the directory 0700, the list form `{"users": [{"name", "secret", "enrolled", "lastStep", "recovery": [sha256...]}]}`). Methods: `fileExists()`, `isOn()`, `secret()`, `enrolled()`, `recoveryLeft()`, `enable()` (also replaces: new secret, `lastStep` 0), `replaceRecoveryCodes()`, `acceptCode()`, `useRecoveryCode()`, `disable()` (removes the file when nobody keeps a factor).
- `acceptCode()` does the check **and** keeps the step in one locked piece of work, and `useRecoveryCode()` strikes the code out in the same way (`two-factor.json.lock`, `flock`), so that two requests with the same code cannot both succeed. The lock file stays behind, empty and harmless; deleting `two-factor.json` alone still switches the factor off (3.5).
- A file that exists and cannot be read, is not JSON, or holds a user without a name or a Base32 secret makes **every** method throw `TwoFactorException`, and the file is left as it was. Only "no file" and "no users" mean off. Recovery hashes that are not 64 hex characters are dropped when reading (so a damaged one does not count as a code that works).
- `RecoveryCodes::generate()` makes ten codes of 12 characters (`XXXX-XXXX-XXXX`, alphabet without 0, 1, I and O, 5 bits per character straight from `random_bytes`); `hash()` is SHA-256 of the characters (case, hyphens and spaces ignored); `looksLike()` tells a recovery code from six digits, which package 3 needs to pick the check.
- Proof: `RecoveryCodesTest` and `TwoFactorStoreTest` (57 tests with package 1 in the filter `Auth`): the file form and modes, no recovery code in the file in any spelling, a code accepted once and across requests, the replay of the previous step, a wrong code leaves the file byte for byte, recovery codes once, replaced, case-insensitive, damaged files of eight kinds never read as off, a change of the password through `AdminStore` leaves the factor. The whole unit suite (3669 tests), `composer lint` and `composer stan` are clean.
- Not tested here: two processes at the same moment (the lock is the standard `flock`; the stack tests of package 7 are the place for it).

**Work package 3 (2026-10-31): the login in two steps.**

- `AuthController::login()` asks `TwoFactorStore::isOn()` after the password is right. Factor off: as before. Factor on: a new session **without a user** that holds `pending = {user, since, tries}`, and a redirect to `/login/code`. A file that cannot be read: `login.refused` in the audit log, status 500, no cookie, the message names `admin two-factor --disable` (the command comes with package 6).
- `GET|POST /login/code` (`codePage()`, `code()`, public routes). One field; a recovery code is told from six digits by `RecoveryCodes::looksLike()`. Right: the waiting session is destroyed, a new full one is made (`mfa` = `totp` or `recovery` in it), audit `login` with `mfa` (and `login.recovery` with `left` for a recovery code). Wrong: `login.code.failed`, the message with the server time in UTC, the fifth wrong code ends the wait ("Enter the password again"). The wait also ends after 300 seconds. The codes have their own throttle (`throttle/login-code.json`, the rule of the password throttle), which is not shared with the password's.
- The recovery code has its own variant of the page (`?recovery=1`, a hidden field `recovery` on the form): no `inputmode="numeric"`, since a recovery code has letters. (The plan wanted one field with the numeric keypad; the link "Lost the phone? Use a recovery code" is the change.)
- `Kernel::run()`, `Kernel::refuse()` and `Kernel::loggedIn()` ask for `Context::user()` and not for a session. `Router::routes()` (the table without the handlers) is new so that the test can walk it; `Services::$twoFactor` is the store.
- Not in this package (it is package 4): `Sessions::destroyExcept()`, the Security page, the roll-back note.
- Proof: `TwoFactorLoginTest` (17 tests): **every route of the table that is not public answers a waiting session with a redirect to the login (and a call of the script with 401)**, and the public routes are exactly four (a new one fails the test); the right code, a wrong code, five wrong codes, five minutes, a replayed code and the next one, a recovery code once, the throttle that is separate from the password's, a damaged file, the page for a waiting login only, `loggedIn()` for a waiting session; neither a code nor the secret in the audit log. The whole unit suite (3686 tests), `composer lint` and `composer stan` are clean.
- Seen in the browser against the real Apache of the lab (`web` on port 8390): the password, then the page of the code, then a code computed with `Totp::code()` led to the dashboard. The page was not yet run through `tools/a11y-audit.js` and not seen at phone width (package 7).

**Work package 4 (2026-10-31): the page Security.**

- `Admin\SecurityController` (new) and the menu entry *Security* (lock icon, between *Schedule* and *System*). Routes: `GET /security` (the step follows from the state: off, a secret waits, on), `POST /security/start|confirm|cancel|recovery|disable`.
- Step 1 (`start`): the password again; a secret from `Totp::newSecret()` waits **in the session** (`twofactor.setup`, 15 minutes), nothing in the file. Step 2 (`confirm`): the page shows the key in eight groups of four with a Copy button and a link `otpauth://...` for the phone itself; a right code switches the factor on (`enable()`, then `acceptCode()` so that the confirming code is spent), ends every other session and shows the recovery codes. A wrong code leaves the secret in place for another try.
- Recovery codes are **shown once, in the answer of the request that makes them**, with Copy, Save as a file and Print as on the page of a new key pair (`KeysController`), and are not kept anywhere in clear: not in the file, the session or the audit log. This differs from the plan, which let them stay in the session until the page was left; a reload loses them and "New recovery codes" makes new ones.
- "New recovery codes" and "Switch off" take the password and a code of the app or a recovery code (field names `new_*` and `off_*`, so that the ids on the page are unique). A wrong password counts in the throttle of the login, a wrong code in the throttle of the codes (`security.password.failed`, `security.code.failed` in the audit log). Switching off removes `two-factor.json` and ends every other session. A code that was used for the login a moment ago is refused (the replay rule): the page says to wait for the next one.
- `Sessions::destroyExcept($token)` ends every session of the directory but one, those that wait for a code included. **A trap for tests, not for users:** `Sessions::create()` clears files older than the session lifetime by their real modification time against the clock it was given, so two logins in one test with the clock at 2027 end each other; the tests that use two browsers set the clock to `time()`.
- The page says what to do when the phone is lost (a recovery code; `admin two-factor --disable`, which package 6 builds; deleting `two-factor.json`), and a damaged file is named on the page with the same advice.
- The roll-back button on the System page's update section warns when `two-factor.json` exists (`UpdateController`).
- Proof: `SecurityPageTest` (17 tests) and one more in `UpdateControllerTest`: the three steps, a wrong password and a wrong code change nothing, the secret is not in the file before the code and not in the session or the log after it, the confirming code cannot log in, other and waiting logins end on switching on and off, a setup gives up after 15 minutes or on Cancel, new codes replace all old ones, a recovery code switches off, the throttles, the warning for 3 or fewer codes left, no id twice on the page, every route needs a login. The whole unit suite (3704 tests), `composer lint` and `composer stan` are clean.
- Seen in the browser against the lab: all three steps with a code computed by `Totp::code()`, and the page of the recovery codes in the narrow pane (the layout of a phone). Not yet seen: the key in the real authenticator app (the QR code of package 5 now brings it in; the owner's field test is package 9), the contrast audit (package 7).

**Work package 5 (2026-10-31): `Auth\QrCode`** (`src/Auth/QrCode.php`) and the picture on the Security page.

- `QrCode::modules($text)` (the matrix) and `QrCode::svg($text, $label)` (an inline `<svg class="qr">`, a white square with the quiet zone of four modules and one path of black runs, no style attribute, no external reference). Byte mode, level M, **versions 1 to 10** (213 bytes; the plan said 1 to 6, but an address with the host name twice in it is up to 200 bytes, and the blocks of two sizes of versions 8 to 10 are no more work than those of 4 to 7). Longer text: `InvalidArgumentException`. The eight masks are tried and the lowest penalty of the standard wins.
- The Security page draws the picture above the key as text (the key stays: a wrong picture costs a typing, not the access). The host name in the address is cut to 30 characters so that the address fits. If it still does not fit the page shows the key only. `svg.qr` in `public/app.css`: 15 rem wide, black on white in both colour schemes.
- **Proof:** the Reed-Solomon codewords of two examples that tutorials of the standard print (`"01234567"` and `"HELLO WORLD"`, level M, version 1); the size of the picture at every boundary of length from 1 to 213 bytes; finder patterns, separators, timing and the dark module; the format information (both copies, its own checksum) and the version information from version 7 (both places, its checksum); a decoder written for the test that reads the picture back (mask, zigzag, blocks, a check of every correction codeword, byte mode) for 12 texts across all ten versions; the path of the SVG draws exactly the modules (`QrCodeTest`, 41 tests). **And `zbarimg`, a reader that is not ours, reads 13 pictures from 1 to 213 bytes** back to the text that was encoded (`tests/Integration/QrCodeZbarTest.php`; skipped where `zbarimg` is missing). The package `zbar-tools` is now in the image of the tools container (`.docker/php/Dockerfile`; package 7 only has to name it in `tests/stack/README.md`).
- **A finding of the size test:** `src/Auth` is part of the web interface, and `RestoreBuilder` and `InstallBuilder` bundled all of `src/` but `src/Admin` and `src/Build`: the single file `threadsafe-restore.php` grew past the 400 KB that `RestoreBuilderTest` allows. Both builders now leave out `src/Auth` too (the release package, which is the whole program, keeps it).
- The whole unit suite (3746 tests), `composer lint` and `composer stan` are clean. Seen in the browser against the lab: the picture in the dark scheme in the narrow pane. The scan with a phone camera and an authenticator app is the owner's (package 9).

**Work package 6 (2026-10-31): the command, the setting and the warning.**

- `threadsafe admin two-factor [status | --disable]` (`Cli\AdminCommand`): `status` prints `Second factor:  off` or `on since ... (N recovery codes left)` (never the secret) and exits 2 with the advice when the file is damaged; `--disable` switches it off and writes `twofactor.disabled` with `source: command line` to the audit log. **A damaged file does not stop `--disable`: it is removed**, since it is what is in the way (the message of the refused login names this command). Switching on is not a command (decision 8): `two-factor enable` is a usage error that points to the Security page. `admin status` has the line too, and `--json` has `twoFactor: {on, damaged, enrolled, recoveryLeft}`.
- `web.requireTwoFactor` (bool, default false; `Config::requireTwoFactor()`; `admin set web.requireTwoFactor true`; a checkbox in the Settings of the System page, which like the other checkbox there switches it off when it is not sent). `Kernel::secondFactorRequired()`: a login without a factor gets a redirect to `/security` with a warning (a call of the script gets 403 as JSON) on every route but `GET /security`, `POST /security/start|confirm|cancel` and `POST /logout`; the setting is in force at once for a session that is in. A file of the factor that cannot be read does not block a session that is already in (it could not repair anything otherwise), the login refuses it.
- `SystemChecks`: a line *Second factor* in the installation check of the System page: a warning while the login is one password, an ok line when the factor is on, a failure for a damaged file. It is not in `Environment` (which the command `doctor`, the installer and the update use too): it is about this web interface only.
- Found while testing: an answer that leaves before the end of `Kernel::run()` does not save the session, so a flash set on the way out was lost; `secondFactorRequired()` saves it itself.
- Proof: six tests in `AdminCommandTest` (status off and on, `--disable` with the audit entry, a damaged file for both, `enable` refused, the setting) and ten in `SecurityPageTest` (all routes shut out, the setup reachable, everything open afterwards, the way out, a factor that is on, a damaged file, the System warning and its disappearance, the checkbox, in force at once). The whole unit suite (3763 tests), `composer lint` and `composer stan` are clean. Seen in the browser against the lab: the requirement set with the command, a request for `/profiles` led to the Security page with the warning.

**Work package 7 (2026-10-31): the proof behind a real Apache.**

- `WebInterfaceTest::testTheSecondFactorLogsInWithTheCodesOfOathtoolAndTheQrCodeIsReadByZbar` (phase 4b, the real Apache with 5 s / 64 MB): switching on through the pages; **`zbarimg` reads the QR code of the page** (the SVG as the server delivered it, read back from its path) and gets the address of the link on the same page; **the code of `oathtool` is accepted** at the confirmation and, as the code of the next step, at a login; the password alone opens none of five pages; a wrong code, a replayed code and a used recovery code are refused; **two requests at the same moment with the same recovery code let exactly one in** (the lock of `TwoFactorStore`, which the unit tests could not reach); switching off with a recovery code ends the other login; afterwards neither the key nor a recovery code is anywhere in `/web-data`, and the audit log has the events and none of them. Both oracles are in the tools image now (`zbar-tools`, `oathtool`); `tests/Support/OathtoolOracle.php` and `ZbarOracle.php` wrap them (the latter also reads the modules back from an SVG, and `QrCodeZbarTest` uses it).
- `WebUi` can send a request with its own `Cookie` header, so that several logins can be asked at the same moment through one object.
- **Phase 4b is green on all three database servers** (MariaDB 10.11: 11 tests, 334 assertions; MySQL 8.4: 341; MariaDB 11.8: 339). The second factor touches no database; the whole group ran because the test depends on the journey before it. The other phases of `run.sh` were not run for this package (nothing in them reads the web login), and `matrix.sh` as a whole is left for the end of the phase.
- **The contrast and structure audit (`tools/a11y-audit.js`, light and dark): no finding** on the Security page in its three states (off, the key and the QR code, on), the page of the recovery codes, the second step of the login and its recovery variant (0 low contrasts, 0 weak edges, no structure finding, no sideways scroll). At 375 px width the QR code is 240 px wide inside its column (`max-width: 100%`) and the page does not scroll sideways. A trap met on the way: the style sheet is asked for with the version in the address, and the version of a development tree is always `0.0.0-dev`, so a browser keeps an old `app.css` after a change (reload with the cache bypassed); a release has a new address for every version.

**Work package 8 (2026-10-31): the documentation.** [WEB.md](WEB.md): the section *Security* (the three steps, the recovery codes, new codes and switching off, the way back from a lost phone, the requirement, what is stored and what the factor does not cover), the two-step login under "Logging in", the menu, the line in the installation check, the setting `web.requireTwoFactor`, the audit events, "Security in short", the note on the roll-back under "Updating", three rows of "When something does not work" (wrong codes and the clock, a file that cannot be read, a lost phone), the limits. `README.md` (the web interface line), `CHANGELOG.md` (`[Unreleased]`: Added and Changed), `DESIGN.md` (the QR code is the one picture that does not follow the tokens), `AGENTS.md` (the row of the phase, the map of the code). The release notes name the roll-back behaviour as `PHASE11.md` 3.9 asked.

**Work package 9 - for the owner (field test).** *Done (2026-10-31): the owner switched the factor on in the local test installation and used it with an authenticator app, and then did the same with 0.9.0-beta.4 on the live system: both worked. The reports do not say which of the steps below were tried (the QR scan and the login with the code are what "worked" covers), so the rest of the list stays as the checklist for a customer's server: recovery code, `--disable`, a wrong clock, the requirement, the roll-back note.* With a real phone and the customer's server (after the next release is installed there): (1) switch the factor on and scan the QR code with the app you use; then try the typed key and the link on the phone, too; (2) log out and in with the code; (3) log in with a recovery code and see the count fall; (4) press *Lost the phone?* and try `php bin/threadsafe admin two-factor --disable` over SSH; (5) look at the clock error: set the phone's clock a few minutes wrong and see what the page says; (6) switch on `web.requireTwoFactor` with a second browser logged in without a factor; (7) the roll-back note on *System → Update* (only where a release without the factor can be rolled back to). What is not known until then: how the QR code reads in the apps and cameras of the owner's phone (zbar reads it, which is no promise for every app), and how a host with a clock that is wrong behaves.
