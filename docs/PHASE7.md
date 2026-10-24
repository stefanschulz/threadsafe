# Phase 7 – installation and update

**Status:** **plan** (2026-10-23; nothing is built). The owner's answers of the same day are the frame: ThreadSafe must also run on shared hosting without a shell, so a **web installer** is needed (1); **GitHub Releases** are the only way to get it for now (2); a **checksum** is enough, no signature (3); an **update must be possible from the web interface** (4). The open decisions of section 7.2 are for the owner.
**Context:** [WEB.md](WEB.md) "Installing" describes the installation today; [PHASE3.md](PHASE3.md) 3.1 and 3.2 (the stub and the key file of `restore.php`) and `src/Build/` (the builder) are the model for the installer; [PHASE4.md](PHASE4.md) is the web interface that gets the update page; [CONCEPT.md](CONCEPT.md) 3.5 ("no database of its own, state as JSON in a protected data folder") stays true.

## 1. Goal and scope

Today ThreadSafe is installed by putting the repository on the server and running `composer install --no-dev`; there is no package, no installer and no way to update from the browser. That works for the owner and for anybody with a shell and Composer. It does not work on **shared hosting**, where there is often neither. Phase 7 makes ThreadSafe **installable and updatable by a person with an FTP account and a browser**: one file to upload, one page to click through, and later one package to upload for an update.

| In scope | Out of scope (later) |
|---|---|
| a **release package** (a ZIP with the program, the autoloader included, so that no Composer is needed) built by a script, with a version, a manifest of its files and a **SHA-256 checksum**; a GitHub workflow that builds and publishes it on a tag | signed releases (decision 3), package managers (Composer global, Docker image, deb, Homebrew), a mirror or an update server of our own |
| the **web installer** `install.php`: a single file with the package inside, a preflight, the extraction in steps under the limits of a request, the setup of the administrator, and its own removal | an installer that downloads the package (the file brings it), a command line installer beyond what `unzip` and `doctor` give |
| a **layout** in which the program can be replaced without changing the document root (releases, a pointer, a stub), also on hosts where the document root cannot be changed | a second administrator, a configuration management recipe |
| the **update from the web interface** (and from the command line): upload a package, verify it, switch, check, and **roll back**; the data stays and is migrated | automatic updates, "check for updates" that calls out to GitHub (section 3.5, decision 2), updates of the website types separately from the program |
| the **preflight** (`threadsafe doctor`), shared by the installer, the update and the System page | |
| a version scheme, a changelog, `INSTALL.md`, and the first pre-release | |

Phase 7 ends with the **proof on the Docker stack** (a fresh host without Composer, installed through the installer under limits of 5 seconds and 64 MB, then updated to the next version through the interface, then rolled back) and with a **field test** by the owner on a real shared host.

## 2. Deliverables

1. `tools/build-release.php` and `src/Build/` additions: the package `threadsafe-X.Y.Z.zip` (the program, `vendor/` with the autoloader only, the manifest `release.json`), the installer `install.php` (a stub with that ZIP behind it), the file of checksums `SHA256SUMS`; the build is reproducible (the same input gives the same bytes).
2. `.github/workflows/release.yml`: on a tag `vX.Y.Z` it runs the checks, builds the files, tests them on a clean PHP without Composer, and publishes a GitHub release with the files; `CHANGELOG.md`.
3. The layout and its code: the stub `index.php` that every installation has in its document root, the pointer `current.php`, the serving of the static files, `Paths` and `Admin\Entry` changes; a clone with Composer keeps working unchanged.
4. `src/Install/` (the installer's wizard: key, preflight, place, extraction, setup, cleanup) and the stub of `install.php`.
5. `src/Update/` (verify, extract, preflight, data backup, migration, switch, health check, rollback), the **Update** section of the System page, and the command `threadsafe update`.
6. The preflight `src/Core/Environment` extracted from `Admin\SystemChecks`, the command `threadsafe doctor`.
7. The data format version (`data/version.json`) and the migration mechanism.
8. Unit tests, integration tests on the stack (a fresh host: install, update, roll back, failures), documentation: `INSTALL.md`, `WEB.md`, `README.md`, `AGENTS.md`, this plan's "As built".

## 3. Design

### 3.1 Versions and the release

- **Version.** Semantic versions (`Core\Version::isValid` already checks the shape). `Core\Version::CURRENT` is `0.0.0-dev` in the repository and is **stamped by the build** (the package has the real one); a tag `vX.Y.Z` must equal the stamped version, and the workflow refuses a mismatch. The first public release is a pre-release (decision 1).
- **The package** `threadsafe-X.Y.Z.zip` holds one top directory `threadsafe-X.Y.Z/` with `bin/`, `plugins/`, `public/` (the assets), `src/`, `vendor/` (made by `composer install --no-dev --optimize-autoloader`; it is only the autoloader, `composer.json` has no runtime dependency), `composer.json`, `LICENSE`, `README.md`, `INSTALL.md`, the part of `docs/` that users read, and `release.json`. Not in it: `tests/`, `tools/`, `.docker/`, `.github/`, development dependencies. Measured: about 357 PHP and asset files, **0.5 MB compressed without and 0.75 MB with the documentation**, so it fits every upload limit.
- **`release.json`:** `version`, the date of the build (`SOURCE_DATE_EPOCH`), `php` (the minimum, 8.4), `extensions` (required and recommended), `dataFormat` (the integer of 3.6), and every file with its size and **SHA-256**. The installer and the update verify the extracted files against it; a package that does not match its own manifest is refused.
- **Reproducible:** files in sorted order, fixed times (`SOURCE_DATE_EPOCH`), fixed modes (644, 755), so that two builds of one tag give the same checksum.
- **Assets of a release:** `threadsafe-X.Y.Z.zip`, `install.php`, `restore.php` (the generic restore tool, which the interface can also build on demand), and `SHA256SUMS`. The checksum is what the owner decided on (3): it shows that a file arrived whole and is the one on the release page; it does not prove who made it.

### 3.2 The layout on a host

One layout for every host, in place of today's three ([WEB.md](WEB.md) "Where the web server points"):

```
<home>/                      "ThreadSafe home": outside the document root where the host allows it
  releases/0.1.0/            a package, unpacked (the program; read-only for the web server in principle)
  releases/0.2.0/            the next one, while or after an update
  data/                      profiles, runs, schedule, config, audit log: shared by all releases (as today)
  current.php                the pointer: <?php return ['release' => '0.1.0', 'path' => '/.../releases/0.1.0'];
<document root>/             a directory the web server serves
  index.php                  the stub: never changes with a release
  .htaccess                  hands every address that is not a file to index.php
```

- **The stub `index.php`** (a few lines, in the syntax of old PHP like the stub of `restore.php`): checks the PHP version, reads `current.php`, sets `THREADSAFE_PROGRAM_DIR` and `THREADSAFE_DATA` (`Paths::program()` and `Paths::data()` already honour both), loads the `vendor/autoload.php` of the release and calls `Admin\Entry::run()`. So **a new version is a new directory and a rewritten pointer**: no document root to change, no file of the running program overwritten, and going back is writing the old pointer again.
- **Static files** (`app.css`, `app.js`, the fonts) live in the release's `public/`. A document root that is a different directory cannot serve them directly, so the stub does: it answers `/app.css`, `/app.js` and `/fonts/*` from the active release (a whitelist of that directory, no `..`), with a correct type, an `ETag` and `Cache-Control: public, max-age=31536000, immutable`; the pages ask for them with `?v=<version>` so that a new version is never served from an old cache. Whether this costs too much on a small host is measured in work package 1; if it does, the installer copies the assets into the document root and the update does the same.
- **Where `<home>` goes.** The installer proposes the directory next to the document root (`../threadsafe` of `public_html`) if PHP can write there; if it cannot, `<home>` is a sub-directory of the document root (`threadsafe-home/`), closed by an `.htaccess` and an empty index file as today's weaker layout is, and the installer says so plainly. On Apache that works; on nginx and IIS the installer says what the rule must be and refuses to go on without the person's confirmation.
- **A clone with Composer** (how it is installed today, and how development runs) has no `current.php`: `Paths` falls back to the directory the code is in, everything works as before, and the System page says "installed from a clone: update with `git pull` and `composer install`".

### 3.3 The web installer

`install.php` is **one file**: the stub, then the package. It is a ZIP behind a PHP stub, with the offsets of the ZIP shifted by the length of the stub as self-extracting archives do, so that `unzip` can open the file too (**verify** in work package 1) and a person without a browser has a way in. The stub is written in the syntax of old PHP and refuses below 8.4 with a message that says what to do, like the stub of `restore.php` ([PHASE3.md](PHASE3.md) 3.2); the wizard behind it reuses what `src/Web` has (`Wizard`, `Sessions`, `Throttle`, `Cleanup`, the resumable `Extractor`).

The pages, in order:

1. **The key.** Nothing happens, and nothing is written, until the person has put a file `install-key.php` next to `install.php` (`<?php return 'a secret you choose';`; PHP on purpose, so that nobody can fetch it). That is the proof that the person can write to the host, as for `restore.php`. Wrong keys are slowed down by the existing `Throttle`.
2. **The preflight** (3.4): PHP version, extensions, whether the directories can be written, `open_basedir`, the limits of time and memory, whether `data/` would be reachable from outside. A failure says what to ask the host for; "Check again" runs it again.
3. **The place:** where `<home>` goes (3.2), with what each choice means.
4. **The extraction,** in steps (each request does about 60 % of the time limit and the next goes on, as the restore does), into `<home>/releases/X.Y.Z/`; the files are verified against `release.json`; then the stub, the `.htaccess`, `data/` (mode 0700, closed) and `current.php` are written. A request that is cut off leaves nothing half-installed: the pointer is written last.
5. **The setup.** The installer writes a `data/setup-key.php` with a random key and hands the person on to the setup page of the installed interface, which asks for the password (the existing setup; it switches the interface on). The key is passed as a form field, never in an address.
6. **The cleanup:** `install.php`, `install-key.php` and its own work files are removed (`Cleanup`); if PHP cannot remove them the page says which files to delete by hand and that the installer does nothing without its key in the meantime.

Nothing in this needs a database: ThreadSafe has none of its own.

### 3.4 The preflight, shared

The checks of `Admin\SystemChecks` (PHP, extensions, the data directory, https, whether `data/` can be fetched) move to `src/Core/Environment` so that four callers use **one** list: the installer, the update (against the new release's `release.json`), the System page, and the new command `threadsafe doctor` (useful before switching the interface on, and in support). A check is a name, a level (ok, warning, failure) and a sentence with what to do; the sentences are the ones the System page has.

### 3.5 The update from the web interface

A new section **Update** of the System page (`Admin\Tabs`, which the System page and the page of a profile already use, serves it) and the same service for the command line (`threadsafe update <package> --sha256=...`).

1. **What it shows:** the installed version and the layout; whether an update is possible (a release layout, `<home>` writable, no backup running); the previous release (for the roll-back); the link to the releases page. It makes **no call to GitHub** (decision 2).
2. **Upload.** The person uploads `threadsafe-X.Y.Z.zip` and types or pastes its SHA-256 from the release page (or, when the file is too large for the host's upload limit, puts it into `data/updates/` by FTP and names it). The page computes the checksum of what arrived and **refuses unless it equals the one given**. Upload, extraction and every later step work under the limits of a request, in ticks.
3. **Verify.** `release.json` is read from the package; the version must be newer than the installed one (a downgrade needs a confirmation); `dataFormat` must not be older than the installed data; the package is unpacked into `releases/X.Y.Z/` and **every file is checked against its hash**; the paths are checked (no `..`, nothing outside the release directory). No code of the package runs before both checks passed.
4. **Preflight of the new version** (3.4) with the requirements in `release.json`: a host whose PHP or extensions are no longer enough is told so **before** anything changes.
5. **Switch.** Under the global lock (no backup is running; the trigger waits): a copy of the small state files of `data/` (profiles, config, schedule, retention, the administrator; not the runs, the sessions, the backups) goes to `data/update-backup/<from>-<time>/`; the new release's **data migration** runs (3.6); the pointer `current.php` is rewritten atomically (`AtomicFile`).
6. **Health check.** The service asks the installation, through its own address, for a page that answers only to a token it has just stored; the answer must carry the new version. If it does not answer, or answers wrongly, or the migration failed: **automatic roll-back** (the pointer is written back, the state files are restored from the copy) and the page says what happened.
7. **After.** The previous release stays for a **manual roll-back** (a button, same steps backwards) and older ones are removed (decision 5: keep two). The audit log has `update.applied` and `update.rolled_back`. The page offers to build the new `restore.php` ([WEB.md](WEB.md) "The restore help").

Security: the whole section needs the administrator's session and the CSRF token; the upload has a size limit; ZIP paths are checked (no slip); a package is installed only if its checksum matched what a person typed and its files matched its manifest; the update never fetches anything by itself.

### 3.6 The data format and the migrations

`data/version.json` holds `{"dataFormat": N, "program": "X.Y.Z"}`. A release knows the format it writes and a list of migrations from the earlier ones (`src/Update/Migrations`, one class per step, idempotent). At its first start a release compares: older data is migrated (after the copy of 3.5), the same format needs nothing, **newer data is refused** ("these files were made by a newer ThreadSafe"). Format 1 is today's. The rule for authors: a release must read the data of the release before it, so that the roll-back of a failed update is the restore of the copy, not a repair.

### 3.7 The release process

`.github/workflows/release.yml`, on a pushed tag `vX.Y.Z`: install, `composer check`, build (`tools/build-release.php`), then **a test of the built files on a clean PHP 8.4 without Composer** (unpack, `bin/threadsafe --version`, `doctor`, the unit tests that need no source tree), then the release with its four files, marked as a pre-release while the version is `0.x`, with the notes from `CHANGELOG.md`. The action that publishes is pinned to a tag, like the other actions of the CI, and the job has only the permission it needs (`contents: write`).

## 4. Test strategy

### 4.1 Unit tests (no network, no web server)

The builder (the file list, the exclusions, the stamped version, the manifest and its hashes, the order and the fixed times, the offsets of the ZIP behind the stub); the stub's refusal below PHP 8.4 (the syntax of old PHP is checked by a test that parses it with `php -l` and by the rules the restore stub already has); the key and the throttle; the extraction (paths, a slip attempt, a manifest that does not match); the pointer (atomic, the old one back); `Paths` with and without a pointer; the stub's serving of static files (whitelist, types, cache headers, `..`); the update (a version that is not newer, an older data format, a checksum that does not match, a path outside the release, a failed migration restores the copy, an active run refuses); the migrations (idempotent, newer data refused); the preflight against a manifest whose requirements are not met.

### 4.2 Integration tests (Docker)

A service **`fresh`** in `.docker/docker-compose.yml`: Apache and PHP 8.4 **without Composer and without the repository**, an empty document root, limits of 5 s and 64 MB, to which the tests upload only the built `install.php`. Groups in `tests/stack/run.sh` as new phases (and in `matrix.sh` where a database matters; the installer and the update do not need one):

1. **install:** the installer through HTTP only: key, preflight, place, extraction, the setup page, the cleanup; then the interface works (login, a profile for a site on the stack, a backup in steps, `doctor`), and `unzip -t` on `install.php` is clean.
2. **layouts:** `<home>` outside the document root; `<home>` below it (the weaker layout, closed by `.htaccess`: `data/` and `releases/` cannot be fetched); the static files served by the stub with the right headers.
3. **update:** a second build, stamped with a higher version (and one with a higher data format and a migration), uploaded through the page: switched, health check ok, data intact, the previous release kept, the **roll-back** button; the same through `threadsafe update`.
4. **failures:** a wrong checksum, a corrupt ZIP, a file that does not match the manifest, a package that needs a PHP the host does not have, a migration that fails, a health check that fails (a release whose entry point breaks), a backup running during the update, two updates at once: each leaves the installation as it was, working, and says what happened.
5. **clone:** a repository installation is unaffected and shows "installed from a clone".

### 4.3 The field test

By the owner on a real shared host: the installer with the host's own PHP (it is a CGI build, [PHASE3.md](PHASE3.md) section 8), an update, and what the host's limits do to either.

## 5. Work packages

| # | Package | Result | Size |
|---|---|---|---|
| 1 | **Spikes.** What a shared host allows: `rename` of directories, writing the pointer atomically, file modes, `open_basedir`, upload limits; the cost of serving the assets through PHP (requests per second, memory) against copying them; the offsets of a ZIP behind a PHP stub (`unzip`, our `ZipReader`); the time and memory of extracting ~360 files under 5 s / 64 MB; how the existing setup page takes a key from a form; the first measurements of the package. Findings go into section 8. | section 8 filled; decisions confirmed or changed | M |
| 2 | **The release build:** the version stamp, `release.json`, the reproducible ZIP, `tools/build-release.php`, the checksum file; the test of the built package. | `threadsafe-X.Y.Z.zip` and `SHA256SUMS` | M |
| 3 | **The layout:** the stub, the pointer, the serving of static files, the changes to `Paths` and `Admin\Entry`, the `?v=` of the assets, the clone fallback. | an installation that is a home, a document root and a pointer | M |
| 4 | **The shared preflight and `doctor`** (3.4). | one list of checks, four callers | S |
| 5 | **The web installer** (3.3): the stub, the builder of `install.php`, the wizard, the extraction, the setup hand-over, the cleanup. | a fresh host installed from a browser | L |
| 6 | **The update** (3.5, 3.6): the data format and migrations, the service, the System page section, the command, the roll-back, the health check. | an installation updated and rolled back from a browser | L |
| 7 | **The release workflow** (3.7), `CHANGELOG.md`, the first pre-release. | a tag gives a GitHub release | S |
| 8 | **The Docker stack and the integration tests** (4.2). | the proof of the plan | L |
| 9 | **Documentation:** `INSTALL.md`, `WEB.md`, `README.md`, `AGENTS.md`, "As built" here. | | S |
| 10 | **Field test** by the owner (4.3) and what it finds. | | - |

Packages 2, 3 and 4 follow 1 in any order; 5 needs 2, 3 and 4; 6 needs 2 and 3 (`Admin\Tabs` exists); 8 grows with 5 and 6, starting with the `fresh` service as soon as 3 has a layout to try.

## 6. Acceptance criteria

1. A person with **FTP and a browser** can install ThreadSafe on a host with PHP 8.4 and no shell, no Composer and a document root they cannot change: upload `install.php`, create `install-key.php`, click through, choose a password, and have a working interface; `install.php` and its key file are gone afterwards.
2. The installer under **limits of 5 s and 64 MB** finishes; a request that is cut off leaves nothing half installed and the installer can go on; a host that cannot run it is told **what to ask for**, before anything is written.
3. The package is **reproducible** (two builds, one checksum), contains no development file, runs on a clean PHP 8.4 **without Composer**, and every file of it matches `release.json`.
4. An **update through the web interface** from a package and its checksum switches to the new version with the data intact, the previous release kept, a health check passed; the **roll-back** button and the automatic roll-back (a failed migration, a failed health check) both bring back a working installation; a wrong checksum, a manifest mismatch and a host too old are refused before any change.
5. No update starts while a backup runs, and none runs twice at once; nothing in the update reaches the network by itself.
6. An installation from a **clone** works as before and says how it is updated.
7. `composer check` and the CI are green; the release workflow builds, tests and publishes on a tag; `INSTALL.md` is true.

## 7. Risks and decisions

### 7.1 Risks

1. **An `install.php` that is left on a server** is the largest risk of an installer. Mitigation: it does nothing without a key file that only a person with write access can make, it removes itself, and it says so loudly when it cannot.
2. **Hosts that forbid what the layout needs** (`rename` of directories, writing outside the document root, `open_basedir`). Work package 1 finds out what is common; the weaker layout inside the document root is the fallback and is said to be weaker.
3. **Serving static files through PHP** is slower than the web server doing it. The files are small, cacheable for a year and few; the fallback is to copy them into the document root.
4. **The update replaces the program that is running it.** The design does not overwrite anything: a new directory and a pointer. What can still go wrong is the switch itself (a half-written pointer): it is written atomically, and the health check and the roll-back cover the rest.
5. **The upload limit of a host** can be smaller than the package (unlikely at 0.5 to 0.75 MB). The page says so and offers the FTP way (a file in `data/updates/`).
6. **A migration that cannot be undone.** The rule of 3.6 (a release reads the data of the one before) and the copy before the switch make a roll-back a restore of the copy.
7. **A package that is not the one the author made.** A checksum from the same page as the file proves integrity, not origin (decision 3). The page says so; a signature can come later.
8. **Opcache** can serve old code after a switch on hosts that never check timestamps. The pointer changes the path of the files, so there is no stale file under a new path; the update also calls `opcache_invalidate` for the stub and the pointer.

### 7.2 Decisions for the owner

| # | Question | Recommendation |
|---|---|---|
| 1 | The first version number | `0.1.0`, as a pre-release (GitHub marks it so); `1.0.0` after the field test. |
| 2 | "Check for updates" (a call to GitHub) | Not in this phase; the page links to the releases. Add it later as an option that is off by default (it makes the host call out and tells GitHub the host's address). |
| 3 | The layout for a fresh host | `<home>` next to the document root when PHP can write there, otherwise below it with the closing `.htaccess` (3.2). |
| 4 | The file names | `install.php`, `install-key.php`, `restore.php`, `threadsafe-X.Y.Z.zip`, `SHA256SUMS`. |
| 5 | How many releases stay on the host | Two (the active one and the previous), the rest are removed after a successful update. |
| 6 | Where the package is built | By the GitHub workflow only, so that the file people get is the one the workflow made; a local build exists for tests. |
| 7 | Order of work | After the owner's field test of the current phases, and in parallel to or after Joomla (phase 6): the two share nothing except `Admin\Tabs` and the release. |

## 8. Findings of work package 1 (spikes)

*To be written by work package 1.*

## 9. As built

*To be written as the work packages are finished.*
