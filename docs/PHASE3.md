# Phase 3 – `restore.php`: restoring a backup in one go

**Status:** plan, draft 2 (2026-10-21). Decisions 1-4 are settled (2026-10-21: single file with stub; access protection by a key file `restore-key.php`; prefix change with restriction; **no restore over an existing installation in this phase**); decisions 5-9 are proposals as written. Nothing in this document is implemented.
**Context:** see [CONCEPT.md](CONCEPT.md) (3.2 and 3.3 architecture, 4 restore core, 4.6 same-domain restore, 6 security, 7 tests) and [BACKUP.md](BACKUP.md) for the archives this phase reads. Phase 1 ([MIGRATE.md](MIGRATE.md)) already turns a restored copy into a working site; phase 2 makes the archives. Facts about the code were read from the repository on 2026-10-21; items marked *(verify)* are what the first work package (spikes) has to confirm.

## 1. Goal and scope

Today a restore is manual: unzip, copy, import, `threadsafe migrate` ([BACKUP.md](BACKUP.md) section 8). Phase 3 makes it **one operation** that works on a host where **ThreadSafe is not installed**: the person uploads one file (`restore.php`) and the backup (all parts) to the target, opens `restore.php` in a browser, answers a few questions, and gets a working site – on a new domain, a new path, a new database, or the same domain after a disaster. (A rollback over a working installation is not part of this phase; see 3.8.) The same engine is available on the command line (`threadsafe restore`), which is how it is tested and what a person with shell access uses.

| In scope | Out of scope (later phases) |
|---|---|
| `restore.php`: a generated single file that holds extractor, importer, wizard and the phase 1 migration core | web interface of ThreadSafe itself, download of backups, "restore help" page (phase 4) |
| restore engine as resumable ticks (works under `max_execution_time` and `memory_limit`), progress, resume after an interrupted request | schedule, retention, encryption (phase 5; the design leaves a hook for encrypted archives) |
| extraction of all parts with path safety, modes and times; import of the SQL files in plain PHP; table prefix change | restoring from a remote location (SFTP/S3), anything but local files |
| adaptation through the phase 1 core for `prestashop9`, extract-and-import for `generic`; type-specific wizard fields | further website types |
| restore onto an **empty** target: a new domain or path, a new database, or the same domain after a disaster | **restoring over an existing installation** (rollback with cleanup of leftovers): decided out of this phase, a non-empty target is refused (3.8); incremental restores; single files or tables |
| CLI `threadsafe restore`, build script for `restore.php`, self-deletion, access protection | a graphical restore from within the ThreadSafe UI |

Phase 3 ends with **disaster-recovery proof** (CONCEPT 7): back up the running source shop, destroy the target's containers and volumes, recreate them empty **on the same domain and port**, restore with `restore.php` through HTTP, and the shop serves.

## 2. Deliverables

1. `src/Restore/` (engine, extractor, importer, state, preflight), the ticked variant of the phase 1 pipeline, the restore side of the two website types (`Restorable`).
2. `tools/build-restore.php`: builds the universal `restore.php` from the sources (and a helper to write `restore-key.php`, section 3.2).
3. CLI `threadsafe restore <archive|backup id> --root=… [--to=… --db-… …]` (dry run unless `--yes`, as `migrate`).
4. Unit tests (SQL splitter, prefix rewriting, extractor against hostile archives, state machine), integration tests in Docker including the HTTP wizard and the disaster-recovery round trip.
5. `docs/RESTORE.md` (using `restore.php`, the CLI, how to do a rollback by hand, what can go wrong).

## 3. Design

### 3.1 Packaging: one file that runs on any PHP 8.4 host

`restore.php` has to run where nothing is installed and where the PHP may be too old. Proposed construction (section 7, decision 1):

- The build script **bundles** the sources the restore needs (`src/`, the two plugins, their resources) into **one PHP file**: each source file becomes a `namespace X { … }` block (token-based; no dependencies beyond the `tokenizer` extension at build time). No Composer on the target, no autoloader files.
- Because PHP 8.4 syntax is a parse error on older PHP, the file **starts with a small stub written in PHP 5-compatible syntax** that checks the version and prints a plain message ("this restore needs PHP 8.4; the host runs 7.4") and then loads the bundle that follows `__halt_compiler()` (written to `.threadsafe-restore/` once and included from there; `eval` is not used). *(verify: hosts with `disable_functions`, `open_basedir`, opcache restrictions; the size of the bundle – the sources are about 19,000 lines.)*
- The generated file is a **build artifact** (`dist/restore.php`, not in Git). The release process and, in phase 4, the UI hand it out.

### 3.2 Access protection

A restore tool left on a web server is an invitation: whoever finds the address (scanners look for `restore.php`, `kickstart.php` and the like) can start it. On a fresh target that would let a stranger unpack the backup - customer data, `parameters.php`, SQL files - into the web directory, or feed the wizard database access of their own. The biggest exposure is the time between upload and the end of the restore, and a file that is forgotten afterwards. Proposed (decision 2):

- `restore.php` is **one universal file**, built once from the program (`tools/build-restore.php`; from phase 4 a download in the UI) and not tied to any backup. You need ThreadSafe to create it, not to use it; keep a copy with your backups.
- It **does nothing until a key file exists** next to it: `restore-key.php` containing `<?php return 'a secret you choose';`. The key is a *PHP file* on purpose: fetched over HTTP it executes and prints nothing, whereas a plain `restore.key` would be served as text. Without the key file `restore.php` shows only "no access key configured"; with it, the wizard shows only a field for the key until the key is right. No hashing is needed: the secret is as safe as the file, which is as safe as the other files of the site.
- Failed attempts are slowed down (a counter in the state directory) and logged; forms carry a CSRF token; the state directory has a `.htaccess` denying access and mode 0700 and holds no secret except the database password the person types in (decision 7).
- The **command line needs no key**: shell access is already trusted.
- The wizard warns when it is reached over plain HTTP from another host (the key and the database password cross the network in clear text) and asks the person to confirm; many restore targets have no certificate yet, so this is a warning, not a block.
- At the end `restore.php`, `restore-key.php` and the state directory are deleted and, on request, the archive parts. A reminder is shown if deleting fails (read-only directory).
### 3.3 The restore engine

A `Task` like the backup run (`src/Engine`), phases in this order:

| Phase | What happens |
|---|---|
| **preflight** | Reads the manifest and the sidecar (if present) of the backup; checks that **all parts** are there and, if a sidecar exists, size and SHA-256 of each (optional quick verification, `verify` logic); checks the target: PHP version and extensions, free space for archive contents plus the SQL work files, writable **and empty** root (3.8), database reachable with the given credentials and **without tables of the target prefix** (3.8), server kind and collations against the manifest (MySQL 0900 collations onto MariaDB are refused, MariaDB onto MySQL is a warning), `max_allowed_packet` against the cells the manifest warned about. |
| **extract** | Files into the root, `db/*.sql` into the work directory (3.4). |
| **import** | The SQL files in name order, then views, then triggers (3.5). |
| **adapt** | The phase 1 migration steps with the values from the wizard (3.7). |
| **finalize** | Self-test, then self-deletion. |

State is a JSON file in the state directory (`.threadsafe-restore/state.json`, written atomically after every tick, mode 0600, **removed at the end** because it holds the database password), not a PHP session: it survives a closed browser, a killed request and a different session. A tick is one HTTP request of the wizard (the browser polls `?tick`) or one pass of the CLI loop. Budget: 60 % of `max_execution_time` (20 s if unlimited), as in `Budget::forThisProcess`.

### 3.4 Extraction

- Reads the archive with the phase 2 `ZipReader` (PHP with zlib's `inflate_*` functions; the `zip` extension is not needed). **Parts** are taken in the order of the markers, not of the names; a missing or duplicated number stops the restore in preflight.
- **Path safety** is the reader's: absolute names, `..`, NUL, backslashes are refused; the target of every file is checked to be inside the root after resolving, and a symbolic link in the root is never followed when creating a file. Entries that are not `files/…` or `db/…` are ignored.
- Modes and modification times are restored (`chmod`, `touch`); ownership stays that of the PHP user. Directory entries are created with the mode of the entry but at least 0755/ u+rwx so that the restore can go on.
- **Resuming:** the walk through the central directory is resumable (an index into it, kept in the state); a tick ends between two entries. A file entry that does not fit into a tick is **restarted from its beginning** in the next one, except for stored entries, which continue at the byte offset. The budget (tens of seconds, 100+ MB/s of inflate) makes this a theoretical problem; *(verify in the spikes, with a 2 GB deflated file)*. Decision 6 asks whether a per-block index is worth adding to the archive format instead.
- Every entry is checked against its CRC32 while it is written; a mismatch fails the restore with the file name (the target is then in a partial state, which the message says).
- The SQL files go to `.threadsafe-restore/db/` and are deleted after they are imported, so that the import can resume at a **byte offset in a plain file** instead of re-inflating.

### 3.5 Importing SQL in plain PHP

- A streaming **statement splitter** that understands strings (`'…'` with backslash escapes and doubled quotes), identifiers in backticks, `--`, `#` and `/* */` comments, `/*! … */` executable comments, and the `DELIMITER` command of triggers. It reads the file in blocks of 1 MiB and hands out one statement at a time; a statement is never held in memory twice. The dump format of phase 2 is regular enough for a fast path (a statement ends at a line end with `;`, strings contain no raw line breaks), but the splitter must also take dumps of other tools *(verify: speed against `mariadb < file`)*.
- Execution through the existing `Connection` (`pdo_mysql` or `mysqli`): `SET` statements first as the file says, then each statement. Errors stop the import with file, byte offset and the server's message. State: file index and byte offset of the next statement.
- `DROP TABLE IF EXISTS` in every file makes importing over leftovers possible; foreign key checks are off as the header says.
- `max_allowed_packet` of the target limits the statements; the preflight compares it with the largest statement the manifest and the file sizes imply.
- **Table prefix change:** the statements contain the real table names (BACKUP.md). A different target prefix is applied by rewriting the table name in the statement kinds ThreadSafe itself generates (`DROP TABLE`, `CREATE TABLE`, `INSERT INTO`, `UPDATE … SET … CONCAT`), never by searching content. `CREATE TABLE` bodies with foreign keys, and views and triggers, mention other tables inside: a prefix change is refused when the manifest shows any (PrestaShop 9.0 has none, section 7 decision 3).

### 3.6 Types and wizard fields

A new capability next to `WebsiteType` and `Backupable`: `Restorable` with `fields(Manifest): list<Field>` (what the wizard asks beyond the common values, pre-filled from the manifest: PrestaShop – new domain, SSL mode, base path, dev mode and mail catcher), `settings(array $values): array` (the request settings for the core, as `WebsiteType::settings` does for the CLI) and `preflight(Manifest, Target): list<Check>`. `generic` asks nothing and its `adapt` phase does nothing (the person fixes configuration by hand, as in [MIGRATE.md](MIGRATE.md) section 12), or optionally runs the domain replacement.

### 3.7 The migration core must become resumable

The phase 1 `Runner` runs all steps in one call. Most steps are quick; **`ReplaceContentDomains` is not** – it walks every table with text columns, and a shop with large tables needs minutes to hours. Under a web time limit that cannot work. Phase 3 therefore adds an optional `ResumableStep` capability (`apply` with a `Budget` and a state) and a ticked runner that keeps the plan, the journal and the state between ticks; `ReplaceContentDomains` becomes resumable at table and primary-key level (it already pages by key, as `TableRewriter` shows). The other steps stay atomic. The CLI `migrate` keeps working as before (unlimited budget, one tick).

### 3.8 The target must be empty (no restore over an installation)

Decided (decision 4): this phase restores onto an empty target and **does not delete anything**. The rollback over a working installation (CONCEPT 4.6, the cleanup of leftover tables and files) is left for a later phase, because it is the one operation here that destroys data.

- **Preflight refuses** a root that contains anything but what belongs to the restore itself (`restore.php`, its state directory, the archive parts and their sidecar files, a web server's `.htaccess`/`index.html` placeholder, which the option `--allow-leftovers` (a checkbox in the wizard) lets through) and a database that already holds tables with the target prefix. The message says what was found and what the person can do: empty both by hand, choose another directory or prefix, or restore elsewhere and move.
- The exception that makes resuming work: a restore that was started by this tool and is continued (the state file says so) may find its own files and tables; its first tick wrote a marker into the state, and `DROP TABLE IF EXISTS` in the dump files makes re-importing a table harmless.
- A rollback therefore means: empty the directory and the database (or use a new database), then restore. The documentation says how.

### 3.9 The wizard and its HTTP interface

Server-rendered HTML, plain CSS, a few lines of JavaScript (`fetch` to poll the tick endpoint; works without JavaScript by a "next step" button). Steps: **1 key** → **2 the backup** (shows what was found: application, version, date, parts, warnings of the backup) → **3 requirements** (preflight, with the way out for each problem) → **4 target values** (database, the type's fields; pre-filled; "test the connection") → **5 summary** (what will be written where; the plan of the adapt phase as a dry run, like `migrate` without `--yes`) → **6 run** (progress per phase, log, resume link) → **7 done** (self-test result, what to do next, delete the tool). One endpoint per step plus `?tick`; every state-changing request carries the CSRF token; all text goes through one escaping function.

### 3.10 CLI

```
threadsafe restore <archive-or-backup-id> --root=DIR [--profile=ID] [--to=DOMAIN …]      # dry run
        [--db-host=… --db-name=… --db-user=… --db-password-file=… --prefix=…]
        [--allow-leftovers] [--keep-archive] [--tick-seconds=N] [--report=FILE] --yes
threadsafe restore --resume=<run id>
```

The options of `migrate` that make sense after a restore are accepted and passed on. Exit codes as before (0 / 1 / 2 refused / 3 failed / 4 self-test failed).

## 4. Test strategy

### 4.1 Unit tests (no database)

- **SQL splitter:** strings with escapes and doubled quotes, comments, executable comments, `DELIMITER` blocks, statements split across read blocks, the dumps of phase 2 byte for byte, files of other tools, hostile inputs (unterminated strings), and a property test: split → join equals the input.
- **Prefix rewriting:** every statement kind; refusal for foreign keys, views and triggers.
- **Extractor:** hostile archives (Zip Slip, absolute names, symlink-like names, huge claimed sizes, wrong CRC, truncated), modes and times, parts out of order, missing parts, resume between entries and inside a stored entry.
- **State machine:** the restore task with fake phases (resume from every boundary), the key check and the attempt counter, CSRF.
- **Bundle build:** the build output parses on PHP 8.4, the stub gives the message on a simulated old version, every class the bundle needs is in it.

### 4.2 Integration tests (Docker)

1. **CLI round trip:** back up the seeded source shop (phase 2 test material) → `threadsafe restore` into the wiped target stack on a **new domain** → self-test → table and file comparison as in `BackupRoundTripTest`.
2. **HTTP wizard:** `restore.php` placed in the target volume, driven by HTTP requests from the tools container (key, steps, ticks) with `max_execution_time=5` and `memory_limit=64M` set in the target's `php.ini` → the shop serves; `restore.php` and the state directory are gone.
3. **Disaster recovery (CONCEPT 7):** back up, **destroy the target's containers and volumes**, recreate them empty on the **same domain and port**, restore through the wizard, smoke test (home page, category, product, image, contact, back office login).
4. **Non-empty target refused:** a root with files, a database with tables of the prefix; nothing is written; the messages name what was found.
5. **Different prefix** (prefix change; refusal when the backup has views, triggers or foreign keys) and **different database**.
6. **Interrupted restore:** kill the PHP process (CLI) or abandon the requests (HTTP) in each phase; continue; same result.
7. **Cross-server:** MariaDB backup restored onto MySQL and the reverse where possible; the refusal with the 0900 collations.
8. **`generic`** with and without a database; a split backup (several parts); a backup with a 40 MB cell onto a server with a low `max_allowed_packet` (clear message).
9. Matrix: MariaDB 10.11 and MySQL 8.4 (`tests/stack/matrix.sh`), PHP versions later.

## 5. Work packages

| WP | Content | Depends on | Size |
|---|---|---|---|
| 1 | **Spikes** (findings in section 8): (a) bundling the sources into one file and running the phase 1 tests against it, with the PHP 5-compatible stub; (b) the SQL importer in plain PHP against real dumps of both servers: speed and memory against `mariadb < file`; (c) extraction speed and the "file larger than a tick" question with a 2 GB deflated file; (d) prefix rewriting on the PrestaShop dump; (e) `ReplaceContentDomains` as a ticked step on the 200,000-row log table; (f) what a typical shared host allows (`disable_functions`, `open_basedir`, request limits of the web server in front of PHP) | – | M |
| 2 | `Restore/Extractor`: resumable extraction with path safety, modes, parts, CRC checks, `ZipReader` extensions (resumable directory walk) | 1 | M |
| 3 | `Restore/SqlSplitter` + `Importer`: streaming splitter, resumable import, prefix change, error reporting | 1 | L |
| 4 | Resumable migration: `ResumableStep`, ticked runner, ticked `ReplaceContentDomains`, the CLI `migrate` unchanged | 1 | L |
| 5 | `RestoreRun` task (preflight, extract, import, adapt, finalize), state file, `Restorable` for both types, CLI `restore` | 2, 3, 4 | L |
| 6 | Wizard: access protection, steps, HTTP interface, progress | 5 | L |
| 7 | Build script: bundle, stub, `dist/restore.php`, key file helper; bundle tests | 1, 6 | M |
| 8 | Integration: CLI and HTTP round trips, disaster recovery, refusal of a non-empty target, prefix, interruption, cross-server, matrix | 5-7 | L |
| 9 | Documentation: `docs/RESTORE.md`, README, CONCEPT status | all | S |

Suggested order: 1 → (2, 3, 4) → 5 → 6 → 7 → 8 → 9. **Milestone A** (after 5): `threadsafe restore` restores a backup end to end from the command line. **Milestone B** (after 7): the same through `restore.php` in a browser. **Milestone C** (after 8): disaster recovery is proven and phase 3 is done.

## 6. Acceptance criteria

1. `threadsafe restore` restores the backup of the seeded source shop into the wiped target on a new domain; tables and files equal the source (except filtered paths and structure-only tables) and the self-test passes.
2. `restore.php` does the same through HTTP under `max_execution_time=5` and `memory_limit=64M`; after it the file, the state directory and the work files are gone; a wrong key gets nothing but the password form.
3. **Disaster recovery:** target destroyed and recreated empty on the same domain and port, restored through the wizard, the shop serves home page, category, product, product image, contact and back office login.
4. **A non-empty target is refused** before anything is written (files in the root, tables of the prefix in the database), with a message that says what was found; the tool never deletes anything that was not its own.
5. A restore interrupted in any phase continues to the same result; the state file never survives a finished restore.
6. A different table prefix and a different database work for PrestaShop 9; a prefix change on a backup with views, triggers or foreign keys is refused with the reason.
7. Hostile archives (Zip Slip, wrong CRC, missing or duplicated parts) are refused before anything is written; a backup of another kind of server (0900 collations onto MariaDB) is refused in preflight with the reason.
8. The bundle parses on PHP 8.4 and shows the version message on older PHP; no Composer or extension beyond the standard ones is needed on the target (`pdo_mysql` or `mysqli`, `zlib`).
9. Unit tests, PHPStan level 8 and the integration tests (`tests/stack/matrix.sh`) pass on MariaDB 10.11 and MySQL 8.4.

## 7. Risks and decisions

**Risks**

- **Hosting limits** decide whether the wizard works: request time limits of the web server in front of PHP (nginx `fastcgi_read_timeout`, a CDN or proxy), `disable_functions`, `open_basedir`, the file count and speed of the file system. Mitigation: short ticks, resumable everything, a CLI path, the requirements step that says what is wrong; spike 1f.
- **Partial states.** A restore that fails half way leaves a half-restored site. Mitigation: preflight does everything that can be checked before writing; extraction before import before adaptation; the message says exactly where it stopped and that the same command continues.
- **The migration core is not resumable today** (3.7): the largest change to existing code of this phase; the CLI behaviour must stay exactly as tested.
- **A restore tool on a web server** is a security risk (3.2): key file, CSRF, deletion, warnings; it can never be perfect, so the documentation says to remove it at once and to use the CLI where possible.
- **Restoring onto the wrong target:** the tool writes a lot of files and tables; because it refuses a non-empty target (3.8) the damage of a mistake is limited to an empty directory and database.
- **Dumps from MySQL 8 on MariaDB** (collations, `utf8mb4_0900_*`), `sql_mode` and `max_allowed_packet` of the target.
- **Bundle fidelity:** the bundled code must behave exactly as the code in `src/`; the unit tests run against the bundle as well.

**Decisions to take (proposals; the owner decides)**

1. **Packaging – settled (2026-10-21):** a generated single file with a PHP 5-compatible stub.
2. **Access – settled (2026-10-21):** a universal `restore.php` plus a key file `restore-key.php` (`<?php return 'secret';`) that the person puts next to it; without the key file nothing works (3.2). Rejected: a password baked in at build time (needs the building instance in an emergency); a one-time password in the archive as CONCEPT 6 says (it would put the key next to the lock); no protection but self-deletion.
3. **Prefix change – settled (2026-10-21):** supported by rewriting the generated statements, refused when views, triggers or foreign keys exist.
4. **Replace an installation – settled (2026-10-21): not in phase 3.** The target must be empty (3.8); the rollback is a later phase.
5. **Database creation:** the database must exist; the wizard tests the connection and says what is missing (proposed) – or tries `CREATE DATABASE` when the user has the privilege.
6. **Files larger than a tick:** restart a deflated file from its beginning (proposed, if spike 1c shows it is no practical problem) – or add a block index to the archive format in a compatible way (more work in phase 2 code).
7. **State and secrets:** a state file in `.threadsafe-restore/`, 0600, deleted at the end, holding the database password (proposed) – or ask for the password in every request and keep it only in a PHP session.
8. **After the restore:** `restore.php` and the state are deleted automatically; the archive parts are kept unless the person asks to delete them (proposed).
9. **PHP version of the target:** 8.4 or newer, as everywhere (proposed). A restore tool for older PHP would need a second code base.

## 8. Findings of work package 1 (spikes)

*Not started.*
