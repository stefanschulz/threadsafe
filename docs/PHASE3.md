# Phase 3 – `restore.php`: restoring a backup in one go

**Status:** plan, draft 2 (2026-10-21); work package 1 (spikes) done, findings in section 8. Decisions 1-4 are settled (2026-10-21: single file with stub; access protection by a key file `restore-key.php`; prefix change with restriction; **no restore over an existing installation in this phase**); decisions 5-9 are proposals as written. Nothing in this document is implemented.
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
| 1 | **Spikes - done**, findings in section 8: (a) bundling the sources into one file and running the phase 1 tests against it, with the PHP 5-compatible stub; (b) the SQL importer in plain PHP against real dumps of both servers: speed and memory against `mariadb < file`; (c) extraction speed and the "file larger than a tick" question with a 2 GB deflated file; (d) prefix rewriting on the PrestaShop dump; (e) `ReplaceContentDomains` as a ticked step on the 200,000-row log table; (f) what a typical shared host allows (`disable_functions`, `open_basedir`, request limits of the web server in front of PHP) | – | M |
| 2 | `Restore/Extractor`: resumable extraction with path safety, modes, parts, CRC checks, `ZipReader` extensions (resumable directory walk) | 1 | M |
| 3 | `Restore/SqlSplitter` + `Importer`: streaming splitter, resumable import, prefix change, error reporting | 1 | L |
| 4 | Resumable migration: `ResumableStep`, ticked runner, ticked `ReplaceContentDomains`, the CLI `migrate` unchanged | 1 | M |
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
6. **Files larger than a tick – settled by spike 1c (2026-10-21):** restart a deflated file from its beginning; no block index (the reader inflates 16 GB per minute; see section 8, finding 4).
7. **State and secrets:** a state file in `.threadsafe-restore/`, 0600, deleted at the end, holding the database password (proposed) – or ask for the password in every request and keep it only in a PHP session.
8. **After the restore:** `restore.php` and the state are deleted automatically; the archive parts are kept unless the person asks to delete them (proposed).
9. **PHP version of the target:** 8.4 or newer, as everywhere (proposed). A restore tool for older PHP would need a second code base.

## 8. Findings of work package 1 (spikes, 2026-10-21)

Scripts: `tools/spikes/bundle.php`, `sql-import.php`, `inflate-speed.php`, `host-probe.php` (throw-away, kept for reference; they run in the tools container against the `db` service). Servers: MariaDB 10.11.18, PHP 8.4.25 (and 5.6, 7.4, 8.3 for the stub).

1. **One file works.** `bundle.php build` turns the 193 PHP files of `src/` and the two plugins into one file of `namespace X { … }` blocks (**657 KB**; token-based, classes ordered by `extends` / `implements` / trait use). With `php -d auto_prepend_file=bundle.php`, **all 2,058 unit tests pass against the bundle** (and a reflection check shows the classes come from it). `bundle.php restore` writes `restore.php` = a stub in PHP 5-compatible syntax + `__halt_compiler()` + the gzip-compressed payload: **244 KB**. The stub, run on real PHP versions, prints "needs PHP 8.4; this server runs PHP 5.6.40 / 7.4.33 / 8.3.35" and stops; on 8.4 it unpacks to `.threadsafe-restore/` once (a cache keyed by size and mtime of the file), includes the bundle, and plugins and resources work. **What the bundle needs beyond the code:** the plugins' non-PHP files (`plugin.json`, `resources/*.json`) are read from disk by `PluginRegistry` and `Resources`, so the stub unpacks them into `.threadsafe-restore/program/`, and `Paths::program()` has to honour a constant (`THREADSAFE_PROGRAM_DIR`) – the spike patches that one line in the bundle; WP 7 makes it a real change in `src/`. `finalize.php` (a resource that is copied into the shop) travels the same way. No `eval`, no extension beyond `tokenizer` at build time.
2. **The SQL importer in plain PHP is as fast as the client and needs little memory.** A streaming splitter (one regular expression that jumps over whole string literals, quoted names and comments; `DELIMITER` support; blocks of 1 MiB) splits the dump at **540-690 MB/s** with a peak of 12 MB. Import of a table with **1,000,000 rows (151 MB): 6.0 s with PDO, 6.0 s with mysqli, 6.5 s with the `mariadb` client**; the seeded shop (299 tables, 18 MB, 2,314 statements): 5.9-6.7 s against 8.2 s for the client – there the server's `CREATE TABLE` time dominates. Both interfaces give tables with **identical `CHECKSUM TABLE`** for all tables. The splitter passes 11 tricky cases (semicolons in strings, escaped and doubled quotes, backslash before the closing quote, comments, executable comments, backtick names, `DELIMITER ;;` and `$$`, `4--1`) **also when the file is read in blocks of 7 bytes and of 1 byte**, and a single statement of 5 MB. Findings that change the design: (a) **`pcre.backtrack_limit` (default 1,000,000) stops the splitter on a string literal with many escapes** ("Backtrack limit exhausted", reproduced with a 5 MB string); the importer must raise it with `ini_set` and check `preg_last_error()` – with the JIT (`pcre.jit=1`) the limit is not hit, a host without it is slower and needs the higher limit; (b) a token cut by the end of a block must make the scanner wait for more data instead of guessing (a `--` or `/*` at the end, a `DELIMITER` line, an opening quote); (c) comments must be recognised only with their line end, so that a comment cut by a block end is not mistaken for text.
3. **Prefix change works on the PrestaShop dump.** Rewriting the table name after `DROP TABLE IF EXISTS`, `CREATE TABLE`, `INSERT INTO` and `UPDATE` (anchored at the statement start) imported all **299 tables as `xy_…`, each with the checksum of its `ps_…` original**. Detecting "foreign keys, views, triggers" **must be structural**: a naive search for `REFERENCES` or `CREATE … TRIGGER` in the dump gave false hits in the data (`PREFERENCES` in `ps_configuration`, a CMS text); the check belongs to the tokenizer (outside strings and comments) on the `CREATE TABLE` statements and to the existence of `_views.sql` / `_triggers.sql`. The shop has no foreign key at all.
4. **Extraction is fast enough that restarting a file is no problem.** The pure PHP reader inflates **364 MB/s** (278 MB/s with writing to a file): **16 GB fit into one 60-second tick** on the development machine, with a peak memory of 8 MB. Walking the central directory of an archive with **70,000 entries takes 0.1 s**, so a tick may start at the beginning. Extracting 70,000 small files took 4.4 s (15,900 files/s) on the container's file system – but the host probe measured **436 files/s on the Windows bind mount** (70,000 files in 160 s) and 444 files/s on PHP 5.6 in the same place: **file creation speed varies by a factor of 40 between file systems, which is what makes ticks necessary**, not the inflate speed. A deflated file that does not fit into a tick would be restarted; at these speeds only a file of many gigabytes on a very slow host could suffer. Decision 6 is therefore settled: **no block index in the archive format**.
5. **The content replacement is quick on the shop.** The whole dry run of the phase 1 content replacement over the 299 tables (the 200,000-row log table skipped as always) took **1.0 s**, 4,579 changes planned. A resumable variant (3.7) is needed for robustness against shops with large non-log tables (orders, carts, messages with millions of rows) and for slow hosts, not because the typical shop takes long; the step already pages by primary key, so the change is a budget check and a saved position (table, last key) – work package 4 is size M rather than L. The dry run for the summary step of the wizard must be skippable or ticked in the same way.
6. **The host probe exists for the owner.** `host-probe.php` (written for PHP 5.2+, read-only, no secrets; command line or web with a token) reports the PHP version and limits, `disable_functions`, `open_basedir`, extensions, file creation and read/write speed, inflate speed, symlinks, `chmod`/`touch`, whether `exec` works, where `php` is, and with `--busy=60` how long a request may run before something cuts it; with a database password file also the server version, `max_allowed_packet`, `sql_mode` and collation. **Run on the owner's live host through the web server (2026-10-21; host name and paths left out here):** PHP **8.4.26** as `cgi-fcgi` (binary `/usr/lib/cgi-bin/php8.4`), `memory_limit` 512M, **`max_execution_time` 30**, `post_max_size` 8M and `upload_max_filesize` 2M (an archive cannot be uploaded through PHP, which the wizard never asks for), no `open_basedir`, **nothing in `disable_functions`** (`exec`, `proc_open`, `shell_exec` work), `ini_set` and `set_time_limit` available, `pcre.jit` on, `pcre.backtrack_limit` at the default 1,000,000 (the importer raises it, finding 2), no opcache, HTTPS. All needed extensions are loaded. File system: **1,107 files/s** created (70,000 files take about 63 s), 170 MB/s write, inflate **120 MB/s**, symlinks, `chmod`, `touch` fine, 577 GB free. **The shell of the same account (SSH) runs PHP 4**, so a plain `php` there is useless: the command line path of ThreadSafe (`php bin/threadsafe …`, a cron job calling it) works only through an explicit PHP 8.4 binary, if the CGI binary can be used that way - the probe now reports which PHP binaries exist and what each says for `-v`. **Consequences:** the **web wizard is the realistic restore path on this host**, and its ticks have to fit into 30 s (the budget of 60 % gives 18 s); 45,000 files take about 40 s of file creation alone, so a restore needs a few ticks - exactly what the design provides. For phase 5 the protected-URL trigger of CONCEPT 5.6 (web tasks, consistency `table`) is the primary scheduling path here, the command line one an option. **Still unknown:** whether something in front of PHP cuts a request before the 30 s (the probe's `?busy=40` finds out), and the database server (the probe's command line mode needs a PHP 8 CLI; through the web server the probe offers a form - `&form=1` - whose POST carries the settings once; nothing is stored on the server and nothing printed, because a settings file next to the probe would be one more thing for a stranger to find). **The probe itself had a bug** that a first run on another server of the owner found: it called `shell_exec`, which that server disables, and PHP 8 turns a call of a disabled function into a fatal error; all optional calls are now guarded.

**Second and third run on the live host (web, two URLs of the same account):** the two runs saw **different PHP settings** - one through a plain-HTTP address with `memory_limit` 128M and `max_execution_time` 60, one through HTTPS with 512M and 30 s - so the limits depend on the virtual host, and the restore tool must read its budget from the `max_execution_time` it finds at run time, never assume one (the design does: 60 % of the value, 20 s if unlimited). The speeds fluctuate: **785-909 files/s** (70,000 files in 76-89 s), **inflate 15-26 MB/s** (120 MB/s an hour earlier), write 78-90 MB/s - a shared machine under varying load. Planning figure for this host: about 20 MB/s inflate and 800 files/s, so a restore of 45,000 files and a 100 MB dump is a matter of **four to eight ticks of 18 s**. Both pages **stopped printing at the heading "Processes"** and never reached the `?busy=` test or the database test. Cause, reproduced in the container: the probe ran `php8.4 -v` on the CGI binary through `exec`, and **a PHP started from a PHP that runs as FastCGI inherits the FastCGI listening socket as its standard input; the CGI binary then waits for requests instead of answering, until the time limit kills the page** (`php-cgi -v` with a listening socket as stdin: still running after 2.5 s; with stdin from `/dev/null`: done at once). **Rule for everything ThreadSafe will ever start from the web side** (background jobs, a CLI trigger): give the child `/dev/null` (or a file) as standard input, run it with a timeout where possible, and never wait for it without a limit. The probe now does so and runs this check last, after everything else has been printed.

**Consequences for the plan:** the design of sections 3.1-3.7 stands. Decision 6 is settled (restart a deflated file from its beginning). Work package 3 starts from the spike's splitter and its 11 cases (they become unit tests, with the block sizes 1, 7 and 1 MiB). Work package 7 makes the `Paths` change and writes the bundler properly (the spike's token handling is the base; the real one needs tests for classes with traits, enums, attributes and `use function`). Work package 4 shrinks to M. What the spikes did not settle: the behaviour on a real shared host (the probe), PHP versions between 8.4 and 8.5 for the bundle (only 8.4 was run), the HTTP side of the wizard.
