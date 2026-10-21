# Phase 4 – the web interface: manage sites and backups in a browser

**Status:** **planned, decisions settled** (2026-10-21); no work package is done yet. Decisions 1-4 were settled with the owner on 2026-10-21 (only phase 4 in this plan; server-rendered HTML with a little script; one administrator with a password; a manual backup runs as ticks driven by the browser), decisions 5-12 were confirmed as proposed the same day (section 7). The findings of the spikes (work package 1, section 8) may still change the design; then the text is corrected together with the code.
**Context:** [CONCEPT.md](CONCEPT.md) section 5 (profiles, dashboard, backup overview, download, backup run) and section 6 (security) are the requirements; [BACKUP.md](BACKUP.md) is the engine this phase puts a face on; [RESTORE.md](RESTORE.md) and [PHASE3.md](PHASE3.md) 3.9 are the web wizard whose patterns (key file, sessions, CSRF, CSP, ticks over HTTP) are reused. Facts about the code were read from the repository on 2026-10-21; items marked *(verify)* are what the first work package (spikes) has to confirm.

## 1. Goal and scope

Today everything is done on the command line (`profile`, `backup`, `backups`, `verify`, `restore`). Phase 4 gives the person who has no shell, or does not want one, the same abilities in a browser: see all sites and the state of their backups at a glance, create and change site profiles, start a backup and watch it, check, download and delete backups, and get the tools to restore one. **It adds no new engine:** the web interface calls the same classes as the command line (section 3.3), and everything it does can still be done with the CLI.

| In scope | Out of scope (later phases) |
|---|---|
| login of one administrator, brute-force protection, CSRF, CSP, audit log, the interface can be switched off | several users and roles (the data model leaves room, section 3.2) |
| dashboard: one card per profile with last backup, status, warnings, running job; header totals | schedule, "next run", heartbeat of the trigger, retention, storage cap (phase 5; the places in the UI are left out, not drawn empty) |
| profile management: list, create (detect the application, propose the filters, connection test), edit, delete | remote storage (SFTP, S3, WebDAV) and the `transfers[]` columns |
| manual backup as ticks driven by the browser: start, progress, continue after the tab was closed, abandon, one run at a time | backups started by a cron trigger, run in a CLI process (phase 5; the dashboard must show and continue such runs, section 3.4) |
| backup overview per profile: list from the sidecars, details, **verify** (SHA-256 and ZIP structure, as ticks), delete | encryption of archives and the key handling (phase 5) |
| **download** with Range/resume, single-file and multi-part, warning above a threshold with path, command and SHA-256 | anonymisation of staging copies |
| "restore help": build and download `restore.php` and a fresh `restore-key.php`, instructions for the chosen backup | a restore started from inside the interface (the restore tool stays a separate, deletable single file, [PHASE3.md](PHASE3.md) 3.1) |
| an installation check ("security panel"): data folder and storage reachable from outside, HTTPS, PHP CLI binary found | automatic updates of ThreadSafe |

Phase 4 ends with the **browser walk-through** (section 6, criterion 1): in a real browser, log in, create the profile of the seeded Docker shop, run a backup under low PHP limits, verify it, download it in two parts with a broken connection in between, delete it – and the same backup restores through `restore.php`.

## 2. Deliverables

1. `src/Web/` grows from the wizard classes to an application: kernel/router, authentication, views, the pages of section 3.5; `public/index.php` as the only entry point.
2. Services shared with the CLI (`src/App`, section 3.3): the commands `profile`, `backup`, `backups`, `verify` are refactored onto them without a change of behaviour.
3. `threadsafe admin` (set or change the administrator password, switch the interface on or off) and a web setup for hosts without a usable shell.
4. `VerifyRun` (a ticked task, because a SHA-256 over a multi-gigabyte archive does not fit into a request).
5. Download streaming with Range, tokens and the warning dialog.
6. Unit tests (router, authentication, throttle, CSRF, token, range parser, escaping), integration tests in Docker through HTTP (the journeys of section 4), a browser walk-through, `docs/WEB.md` (installing and using the interface).

## 3. Design

### 3.1 Installation and entry point

ThreadSafe is installed as a directory (`git clone` or an archive, `composer install --no-dev` – no other dependency may appear at run time). The web server's document root should be `public/`; that directory contains only `index.php` and the static files (`app.css`, `app.js`), so `src/`, `data/`, `plugins/` and `vendor/` are never served. On shared hosting where the document root cannot be changed, the whole directory sits inside the web root and a `.htaccess` in every directory except `public/` denies access (the same technique as `StateDirectory`); the installation check (3.9) reports whether that holds, because it differs per web server and nothing here can be assumed.

- **`data/`** (profiles, runs, locks, sessions, audit log, administrator file, configuration): 0700, 0600 files, outside the web root where possible; the location is `THREADSAFE_DATA` or `data/` (as today). It already holds the database passwords of `generic` profiles, so it is as sensitive as the storage of the backups.
- **Storage of a profile** is the directory the profile names (as today); the interface never serves it directly – only `download` (3.6) reads from it.
- The interface does **not** exist until it is switched on: the default configuration has `web.enabled = false`. `threadsafe admin enable` (or the web setup, 3.2) turns it on; with it off `public/index.php` answers 404 and nothing else. Reason: CONCEPT 6 says "primarily operated via CLI; the UI can be switched off", and a tool that can read every site on the server should not be exposed by an unpacked archive.

### 3.2 Authentication and sessions

- **One administrator, one password**, stored in `data/admin.json` (mode 0600) as `password_hash(..., PASSWORD_ARGON2ID)`; hosts whose PHP lacks Argon2id fall back to bcrypt, and the file says which *(verify, spike 1c)*. The file has a `users` list with one entry so that roles can be added without a format change; the code only knows one.
- **Setting the password:** `threadsafe admin password` reads the new password from a prompt, `--password-file=FILE` or `THREADSAFE_ADMIN_PASSWORD` (never a command line option, AGENTS rule). Where there is no usable shell, the **web setup** works like the restore wizard: it is reachable only while `data/admin.json` does not exist **and** a key file `data/setup-key.php` (`<?php return 'secret';`, put there by the person, deleted by the setup) matches. Without the key file the page shows what to do, and nothing else.
- **Login:** the throttle of `AccessKey` (3 free failures, then 5 s doubling to 5 min, kept in a file, log of failures with address) is generalised into a `Throttle` that both use. A constant-time comparison, the same message for "no such user" and "wrong password", `password_needs_rehash` on success.
- **Sessions:** `Sessions` (hash-named files, 0600, idle timeout 2 h; absolute lifetime 12 h) moves from the wizard's state directory to `data/sessions/`; cookie `HttpOnly`, `SameSite=Strict`, `Secure` when the request is HTTPS; the session id is renewed on login; logout deletes the file. The plain-HTTP warning of the wizard (login over HTTP shows a confirmation) is kept.
- **CSRF:** a token per session in every form and in the header of every script request; every state-changing request is `POST` (nothing changes on `GET`, also not logout and not delete); `Origin`/`Referer` are checked when present.
- **CSP and headers:** `default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'`, `X-Content-Type-Options`, `Referrer-Policy: same-origin`, `Cache-Control: no-store` on every page. No inline script or style, so no nonce is needed: the script is the static `app.js`.
- **Audit log:** `data/audit.log` (one JSON line each): login success/failure, logout, profile created/changed/removed, backup started/finished/abandoned, verify, download started/finished, delete, password change, settings change; no passwords, no file contents. Rotated at a size (default 5 MB, three files). The interface shows the last entries.

### 3.3 One engine, two front ends (services)

The risk of a web interface is a second implementation of what the CLI does. The rule: **the pages contain no business logic.** What `BackupCommand`, `ProfileCommand`, `BackupsCommand` and `VerifyCommand` do between parsing options and printing text moves into services in `src/App`, and both front ends call them:

| Service | Does | Extracted from |
|---|---|---|
| `ProfileService` | list, load, create/update from a plain array (with the type's detection and proposed filters), test, remove; returns `TestReport` and validation errors per field | `ProfileCommand` (`fields`, `database`, `store`, `test`, `remove`) |
| `BackupService` | start a run (locks, space check, `force`), execute one tick, resume, abandon, list unfinished runs, report the result | `BackupCommand` (`backup`, `resume`, `executor`, `execute`) |
| `CatalogService` | list backups, details, delete, rebuild a sidecar, verify (as a run) | `BackupsCommand`, `VerifyCommand` |
| `Overview` | the dashboard's data: per profile last backup, age, size, status, warnings, unfinished run | new (reads `BackupCatalog`, `RunStore`, `SpaceCheck`, `LocalStorage::isProtected`) |

The commands keep their output and exit codes; the existing CLI tests are the proof that the refactoring changes nothing. The pages then format the same service results as HTML, and a service returns plain data (arrays, the existing value objects) so that it is testable without HTTP.

### 3.4 Running a backup from the browser

A manual backup from the web interface is a **web task** (CONCEPT 5.6): PHP limits apply, every request does one tick, the database is dumped per table and the manifest says `consistency: table`.

- **Start** (`POST /profiles/<id>/backup`): the checks the CLI does (profile exists, no other run, space check with the `force` checkbox), then `Executor::start`, answer with the run page. **One run at a time, instance wide** (as the CLI's `backup.lock`), so a second start is refused with a link to the running one.
- **Tick** (`POST /runs/<id>/tick`, answered as JSON): `Executor::execute($id, maxTicks: 1)` with `Budget::forThisProcess()` (≈ 60 % of `max_execution_time`); the script calls it again at once until the run is done, shows the progress line and a bar from the state (files done of files found, tables done of tables), and never calls it more often than once per answer. The answer carries the same fields the restore wizard's tick does.
- **The lock must live shorter than a process.** The CLI holds `backup.lock` with `flock` for the lifetime of the process; a web run spans many processes. So the **run's own lock** (`RunStore::lockPath`) is taken, non-blocking, **per tick**: a second browser tab that ticks the same run at the same moment gets "busy, try again" and waits, never a second writer into the same archive. Whether a run is *active* is decided by its state: status `running` and `updated` newer than a limit (default three times the tick budget, at least 60 s); an older one is **interrupted** (`RunStore::isInterrupted`). A new run is refused while another is active; an interrupted one is shown with **Continue** and **Abandon** (`BackupRun::abandon` cleans up its files). *(Verify in spike 1b: two ticks in parallel, a killed request, a closed tab.)*
- **Closing the tab** does not stop anything that matters: the next tick simply does not come, the run becomes *interrupted* after the limit, and it is continued from its saved state by **Continue**, by a later `threadsafe backup --resume=…`, or (phase 5) by the cron trigger. The dashboard shows an active or interrupted run on its card.
- **Result:** the finished run's result (archive names, size, files, tables, rows, warnings) is shown on the run page and kept in the backup's sidecar as today; the run file is deleted as the CLI does.
- **Failure:** the message of the engine is shown unchanged, with the same advice the CLI prints; the files of the failed run are removed (`abandon`), its state is kept for diagnosis like in the CLI.

### 3.5 Pages

| Route | Page | Notes |
|---|---|---|
| `GET /login`, `POST /login`, `POST /logout` | login | throttled; plain-HTTP confirmation |
| `GET /` | **dashboard** | one card per profile: name, type and version, status light, last backup (age, size, result), warnings, running or interrupted run with progress and **Continue**, buttons **Back up**, **Backups**, **Edit**; header: number of profiles, storage used in total, running jobs; recent audit entries |
| `GET /profiles/new`, `POST /profiles` | create | step 1: type and path → the type detects the application and proposes database access and filters (read from `parameters.php`, never shown) → step 2: storage, filters, compression, part size, note → **connection test** (files readable, database reachable, storage writable, free space, `open_basedir`, PHP CLI binary) → save |
| `GET /profiles/<id>`, `POST /profiles/<id>` | profile page, edit | the **backup overview** (3.7) above the settings; password fields never filled in, empty = unchanged |
| `POST /profiles/<id>/delete` | remove profile | confirmation page; the backups stay on disk (said so); option "also delete all its backups" with the number and size shown |
| `POST /profiles/<id>/test` | connection test | the same report as `profile test` |
| `POST /profiles/<id>/backup`, `GET /runs/<id>`, `POST /runs/<id>/tick`, `/continue`, `/abandon` | backup run | 3.4 |
| `GET /profiles/<id>/backups/<backup>` | details | manifest: domains, tables, exclusions, warnings, consistency, application version |
| `POST …/verify`, `GET /runs/<id>` | verify | ticked `VerifyRun`, result on the backup row |
| `POST …/delete` | delete a backup | confirmation page names the backup and its parts; removes parts and sidecar through `LocalStorage::removeBackup` |
| `GET …/download` | **download dialog**, then `GET /download/<token>/<part>` | 3.6 |
| `GET /restore-help/<id>/<backup>`, `POST …/kit` | **restore help** | 3.8 |
| `GET /system` | installation check, settings, audit log | 3.9; the settings of 3.10 |
| `GET /app.css`, `GET /app.js` | static (from `public/`) | served by the web server where possible |

Profile ids and backup names in the URL are validated with `ProfileStore::validId` / `BackupName::parse` before anything else; a name that does not parse is a 404, not a file system access. A page for a profile or backup the administrator does not own does not exist (there is one administrator, but every lookup goes through the catalog, never through a path built from the request).

### 3.6 Download and large archives

CONCEPT 5.4 describes the technique; this section fixes the details.

- **Token, not path.** `GET …/download` (a page, no bytes) shows the dialog; its button posts and receives a **token** (random, 32 bytes) that is stored in the session, bound to *profile + backup + part*, valid for 2 hours or until logout. `GET /download/<token>/<part>` streams the file. CONCEPT says "single-use"; a single-use token cannot work together with **Range**, because a resumed download is a second request. So the token is valid for repeated requests *of that one file in that one session*, and each use is written to the audit log (decision 7).
- **Streaming:** `Content-Length`, `Accept-Ranges: bytes`, `Content-Disposition: attachment` with the archive's own name, `Content-Type: application/zip`, `ETag` (the SHA-256 from the sidecar) and `If-Range`; single ranges (`bytes=a-b`, `a-`, `-n`; multiple ranges answer with the whole file); 206/416 handled; 1 MB blocks with `fread`/`echo`/`flush`, output buffering and compression switched off (`ini_set('zlib.output_compression', '0')`, `apache_setenv('no-gzip', '1')`), `set_time_limit(0)`, `ignore_user_abort(true)` and the session lock released before the first byte (so other pages work while a download runs).
- **`X-Sendfile` / `X-Accel-Redirect`:** off by default; `download.accel = sendfile|nginx` in the configuration, with the internal location the person set up. The installation check cannot detect whether the web server honours the header; the documentation says how to test it with a small file.
- **The warning:** above `download.warnAbove` (default 500 MB) the dialog says that large downloads can fail (PHP and web server time limits, proxies and CDNs, the browser), recommends server access, and shows the **absolute path**, an `scp` and an `rsync` example with placeholders (never a guessed user or host), and the **SHA-256** of each part to compare afterwards. The download button is still there.
- **Multi-part backups** list their parts with size and SHA-256 each; every part is its own download.
- **Never from the storage directly:** `GET /download/<token>/<part>` resolves `part` through the catalog (`BackupCatalog::archiveParts`), not from a path in the request.
- **Not in the first version:** compression of the download (the archive is compressed), a streamed re-zip of all parts, bandwidth limits.

### 3.7 Backup overview, verify, delete

The overview is read from the **sidecar files** (`BackupCatalog::entries`), never by opening ZIPs; a missing sidecar is rebuilt as `backups` does, with the cost of opening the archive once (the dashboard uses the cached list and does not rebuild; only the profile page does). Columns: date/time (UTC and the browser's local time through `<time datetime>`), kind (full / database only / files only), size and number of parts, duration, application version, status (**ok**, **unchecked**, **faulty**, **unknown**) and the verification date, actions **download**, **verify**, **details**, **delete**, **restore help**.

**Verify** reads every part (ZIP structure, CRC of every entry, SHA-256 of the part against the sidecar). That is as slow as reading the archive, so it runs as a task, `VerifyRun`, with the same tick/resume rules as every task: state = part index and byte offset of the running SHA-256 context (PHP's `hash_init`/`hash_update` can be serialised with `serialize()` since 8.1 – *verify*, otherwise restart the part), then the entry walk of `Verifier::full`. `threadsafe verify` keeps its behaviour and uses the same task with an unlimited budget. The result (status, date, findings) is written into the sidecar.

**Delete** needs a confirmation page that names what goes (backup, parts, sidecar) and a POST with CSRF; it removes the files through `LocalStorage::removeBackup` and writes the audit log. There is no "undo", the text says so. A backup that is being written (its run is active) cannot be deleted.

### 3.8 Restore help

For a chosen backup the page explains the restore in four steps (upload `restore.php` and `restore-key.php`, upload the backup parts, open `restore.php`, enter the key) and offers:

- **Download `restore.php`**: built on the fly from the installation's sources with `RestoreBuilder` (≈ 210 KB), cached in `data/cache/` under the program's version; or `dist/restore.php` if the installation ships it *(verify the build time and memory in spike 1d; if it is more than a few seconds, the build becomes a ticked step or a prebuilt file)*.
- **Generate `restore-key.php`** with a random key, shown once on the page and downloadable as the file; the key is **not stored** on the server (it is a secret that belongs to the restore, not to the instance).
- the parts of the backup to upload, with their sizes and SHA-256, and the hints from [RESTORE.md](RESTORE.md): the target must be empty, the database must exist, PHP 8.4, `pdo_mysql` or `mysqli`.

### 3.9 Installation check and dashboard warnings

A panel (`/system`, and a short list on the dashboard when something is wrong): PHP version and the extensions the engine needs; **is `data/` reachable from outside?** (the page makes a request from the browser to a probe URL inside `data/` – a file `probe.txt` with a known content – via the script; a loopback request from PHP is not reliable behind proxies); is each storage directory protected (`LocalStorage::isProtected`) and writable, with free space; HTTPS in use; a PHP CLI binary found (`PhpBinary::cli()`) for `bin/threadsafe` and phase 5; file leftovers (`restore.php`, `restore-key.php`, `.threadsafe-restore/` in a site root, `setup-key.php`); the effective `max_execution_time` and `memory_limit` and the tick budget derived from them; whether the process runs as root or as a user other than the files' owner (CONCEPT 6 "foreign account").

Dashboard warnings per profile (CONCEPT 5.2, those that need no phase 5): no successful backup for *n* days (default 7; per instance), the last run failed, an interrupted run, little free space (`SpaceCheck` against the size of the last backup × 1.2), the storage is not writable or not protected, the site path no longer exists. The checks use only cheap reads (stat, sidecar list, `disk_free_space`); the database is touched only by an explicit **Test** button.

### 3.10 Configuration

`data/config.json` (0600, written by the settings page and `threadsafe admin`): `web.enabled`, `web.sessionIdleMinutes`, `download.warnAbove`, `download.accel`, `dashboard.warnAfterDays`, `audit.maxBytes`. Unknown keys are refused, as in profiles. Nothing in it is secret. The administrator's hash lives in its own file, so the configuration can be read and shown freely.

### 3.11 Views and design

Pages are produced by plain PHP functions over the existing `Html` helper – no template engine, no front-end build. `app.css` is one file (system font, a small set of colour tokens, light and dark by `prefers-color-scheme`, a layout that works at 375 px and 1280 px, visible focus, status never by colour alone); `app.js` is one file without a framework: the tick loop and progress bar, confirmation dialogs through `<dialog>`, copy-to-clipboard for the download dialog, local time formatting. Every page works without script except the progress of a running backup (the run page then shows a "Continue" button that does one tick per click). The language of the interface is English with all texts in one place per page so that a translation is possible later (decision 8).

## 4. Test strategy

### 4.1 Unit tests (no database, no network)

- **Router and kernel:** route table, method checks (`GET` never changes state), 404 for unknown ids and names that do not parse, error pages without stack traces.
- **Authentication:** hash and verify (Argon2id and the bcrypt fallback), rehash, throttle (counts, doubling, cap, reset on success; clock injected), constant time comparison used, setup only with the key file and only while no administrator exists, session idle and absolute timeout, id renewal, logout.
- **CSRF and headers:** missing, wrong and foreign-session token refused, `Origin` mismatch refused, CSP and the other headers on every response type, cookie attributes (`Secure` only on HTTPS).
- **Views:** escaping of every user-controlled field (profile name, note, paths, archive names, error messages) tested with `"><script>` and with control characters; no inline script or style anywhere (a test over the rendered pages); no password in any page, also not in the edit form.
- **Services:** `ProfileService` field validation and detection with a fake type, `BackupService` start/refuse/continue/abandon with a fake task, `Overview` warnings from fabricated catalogs.
- **Download:** the Range parser (all forms, overlaps, invalid, `If-Range`), token lifetime/binding/revocation, part resolution refuses names outside the catalog, the response headers; streaming to a memory stream in block-sized pieces.
- **`VerifyRun`:** resume at every tick boundary gives the same result as one pass; a flipped byte, a missing part and a wrong sidecar hash are found.
- **The CLI tests** of `profile`, `backup`, `backups`, `verify` stay unchanged and green after the refactoring (work package 3 and 4).

### 4.2 Integration tests (Docker)

The interface runs in a **`web` service** of the Docker stack (Apache with PHP 8.4, the project mounted read-only, a `data` volume, the source shop's volumes mounted read-only so a profile can point at them) with the restore tests' limits (`max_execution_time 5`, `memory_limit 64M`). The tests talk HTTP with `WizardClient`-style helper (cookie jar, CSRF).

| Test | Proves |
|---|---|
| **Journey** | setup with the key file → login → create the profile of the seeded shop (detection, connection test) → start a backup, tick through it under the low limits (many requests) → the overview shows it → verify → download → the archive is byte-equal to the file on disk and `unzip -t` passes → delete → the files are gone |
| **Resume** | a backup interrupted by killing the browser side (no more ticks), shown as interrupted, continued by **Continue**, and also by `threadsafe backup --resume` from the CLI; the archives equal an uninterrupted run |
| **Concurrency** | two clients tick the same run at the same moment: one gets "busy", the archive is intact; a second backup start while one is active is refused |
| **Download** | Range at the start, middle and end; a download broken after 3 MB and resumed gives the full file; a file of 600 MB (stored, generated) shows the warning with the right SHA-256; a multi-part backup downloads part by part; a token of another session, an expired one, one for another part and a path-like part name all fail |
| **Security** | every page without a session redirects to login; POST without or with a wrong CSRF token fails; the throttle slows the fifth wrong password; the session cookie flags; `data/`, `src/`, `vendor/` and the storage are not reachable by URL with the document root on `public/` **and** with the whole tree in the web root (the `.htaccess` protection); the interface switched off answers 404; the audit log has the entries and no password |
| **Restore kit** | the kit from the interface (`restore.php` + generated key) restores the backup that was made through the interface onto the target stack (the phase 3 round trip, started from the new page) |
| **Cross-server** | the journey on MariaDB 10.11, MySQL 8.4 and 11.8 (the `matrix.sh` runs it) |

### 4.3 The browser walk-through

As for the wizard: the owner's acceptance is a real browser. The walk-through of criterion 1 is done in the built-in browser pane, at 375 px and at desktop width, light and dark, with the console open (CSP violations are errors); the findings go into the "As built" paragraph.

## 5. Work packages

| WP | Content | Depends on | Size |
|---|---|---|---|
| 1 | **Spikes** (section 8): (a) download streaming under Apache/PHP with limits, then through the live host's FastCGI; Range, aborts, memory; (b) the lock across ticks: two parallel ticks, a killed request, a closed tab, "active" versus "interrupted"; (c) Argon2id availability on the target builds and the live host, throttle and session file cost; (d) building `restore.php` on request: time and memory; (e) serialising a running `hash_init` context for a resumable SHA-256, and the speed of verify; (f) the browser-side probe for "is `data/` reachable" | – | M |
| 2 | **Foundations:** kernel and router, `Throttle` (generalised from `AccessKey`), sessions in `data/`, CSRF, headers, views and `app.css`/`app.js` skeleton, audit log, configuration, `public/index.php`, `threadsafe admin`, web setup, protection files; login page and an empty dashboard | 1c | L |
| 3 | **Profiles:** `ProfileService` extracted from `ProfileCommand` (CLI unchanged), pages list, create with detection and test, edit, remove | 2 | L |
| 4 | **Backup run:** `BackupService` extracted from `BackupCommand` (CLI unchanged), run page with tick loop and progress, per-tick lock, active/interrupted, continue, abandon, space check and `force` | 1b, 3 | L |
| 5 | **Overview and actions:** `CatalogService`, backup table and details, `VerifyRun` (+ `verify` onto it), delete, sidecar rebuild | 1e, 4 | L |
| 6 | **Download:** token, streaming with Range, warning dialog, multi-part, optional `X-Sendfile`/`X-Accel-Redirect` | 1a, 5 | M |
| 7 | **Dashboard and system:** `Overview`, cards, warnings, totals, installation check, settings page, audit view, restore help and kit | 1d, 1f, 5, 6 | L |
| 8 | **Integration:** the `web` service in the stack, the tests of 4.2 (journey, resume, concurrency, download, security, restore kit), matrix, the browser walk-through | 2-7 | L |
| 9 | **Documentation:** `docs/WEB.md`, README, CONCEPT and AGENTS status, user hints in BACKUP.md and RESTORE.md | all | S |

Suggested order: 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9. **Milestone A** (after 4): a backup can be started and followed in the browser. **Milestone B** (after 7): the interface is complete. **Milestone C** (after 8): proven and phase 4 is done. Work packages 3 and 4 touch the CLI commands; the CLI tests must stay green at the end of each.

## 6. Acceptance criteria

1. **Browser walk-through:** in a real browser (375 px and desktop), log in, create the profile of the seeded shop, run a backup under `max_execution_time=5` / `memory_limit=64M`, verify it, download it (multi-part, with a broken connection in between, SHA-256 equal), delete it; no CSP violation, no console error.
2. **The same engine:** a backup made through the interface and one made by the CLI from the same state are equal in content (files, tables, manifest apart from time, consistency and ids); the CLI tests of the four refactored commands pass unchanged.
3. **Restore:** the `restore.php` and key generated by the interface restore a backup made by the interface (disaster recovery on the Docker stack, as in phase 3).
4. **Running backups:** a closed tab leaves an interrupted run that **Continue** and `backup --resume` both finish; two simultaneous ticks never corrupt an archive; a second backup is refused while one is active.
5. **Download:** Range and resume work; a backup larger than the threshold shows the warning with path, command and SHA-256; a part can only be fetched through a valid token of the same session.
6. **Security:** nothing is reachable without login except the login page (and the setup page while no administrator exists, with the key file); every state change needs a CSRF token; brute force is throttled; the secrets (passwords, the setup and restore keys, the administrator hash) appear in no page, log, audit entry or error message; `data/`, `src/`, `vendor/` and every storage directory are not served by URL.
7. **Switched off:** with `web.enabled = false` the entry point answers 404 and nothing else; the CLI is not affected.
8. **Multi-site:** ten profiles on the dashboard render without opening an archive and without touching a database (measured by the number of file and DB operations in a test).
9. Unit tests, PHPStan level 8 and the integration tests (`tests/stack/matrix.sh`) pass on MariaDB 10.11, MySQL 8.4 and MariaDB 11.8, and `composer test:bundle` still passes (the restore bundle is not affected by the new code).

## 7. Risks and decisions

**Risks**

- **A tool that reads every site on the server, on the internet.** CONCEPT 6 names it. Mitigation: off by default, document root on `public/`, `.htaccess` fallback, an installation check that says what is exposed, throttle, CSRF, CSP, audit log, recommendation of additional web server authentication, CLI as the preferred way.
- **Two writers into one archive.** A tab that was reloaded, two browsers, a cron trigger in phase 5. Mitigation: the per-tick lock on the run (3.4), the active/interrupted rule, and tests that tick in parallel.
- **Hosting limits.** The same as in phase 3: per-request time, `memory_limit`, a web server cutting long responses (the download), `disable_functions`. Mitigation: short ticks, resumable everything, the download warning and the server-access advice; spikes 1a and 1b on the live host's kind of setup.
- **Refactoring the CLI commands** (work packages 3, 4, 5) can change behaviour. Mitigation: the existing tests stay as they are and must pass after each step; services return data and do not print.
- **Download of multi-gigabyte files through PHP** is slow and fragile by nature; the interface says so and offers the server access path. It is a convenience (CONCEPT 5.4).
- **Session and token storage in files** is simple and works everywhere; it is not meant for hundreds of users. One administrator is the design.
- **Scope creep** towards phase 5 (schedule and retention fields, "next run"): the pages leave those places out until phase 5 adds them.

**Decisions** (1-4 settled with the owner on 2026-10-21; 5-12 were proposals, confirmed as proposed on the same day)

1. **Scope – settled (2026-10-21):** only phase 4 in this plan; schedule, trigger, retention and encryption are phase 5 with its own plan.
2. **Technique – settled (2026-10-21):** server-rendered HTML, one CSS and one JS file, no build chain, no framework.
3. **Access – settled (2026-10-21):** one administrator with a password (Argon2id), own login with throttle and CSRF; the interface can be switched off. Basic authentication of the web server in front is documented as an addition, not required.
4. **Manual backup – settled (2026-10-21):** ticks driven by the browser; no background process started by the interface. (A CLI process or the phase 5 trigger may continue the same run.)
5. **Off by default - settled (2026-10-21):** `web.enabled = false` until `threadsafe admin enable` or the web setup with a key file – see 3.1/3.2.
6. **Where the code lives - settled (2026-10-21):** `public/index.php` as the only entry point and the document root on `public/`; the whole tree under the web root is supported with `.htaccess` protection but is reported by the installation check as the weaker setup.
7. **Download tokens - settled (2026-10-21):** valid for two hours for one file in one session, repeated use allowed for Range, each use audited, instead of CONCEPT's "single-use"; this is a deliberate deviation – single-use cannot be combined with resume.
8. **Language - settled (2026-10-21):** English only in the first version (like the CLI and all repository text), texts kept apart for a later translation.
9. **Restore kit - settled (2026-10-21):** the interface builds `restore.php` itself and generates `restore-key.php` with a random key it does not store; no copy of the secret stays on the instance.
10. **Several users - settled (2026-10-21):** not now; `admin.json` has a user list so roles can be added later without a format change.
11. **Deleting a profile - settled (2026-10-21):** removes only the profile file; its backups stay on disk unless the person ticks "also delete its backups" on the confirmation page.
12. **Dashboard freshness - settled (2026-10-21):** a status is computed from the sidecars and the file system on each page load (cheap), a database test only on request; no background refresh and no cache until a measurement shows a need.

## 8. Findings of work package 1 (spikes)

*To be filled in when the spikes are done. Each finding that changes the design above is also corrected in the text of the section it concerns, as in the earlier phases.*
