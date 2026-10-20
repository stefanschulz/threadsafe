# Phase 2 – Site profiles and backup engine

**Status:** plan, draft 1 (2026-10-20); the decisions in section 7 are settled (confirmed 2026-10-20). Nothing in this document is implemented.
**Context:** see [CONCEPT.md](CONCEPT.md), sections 3 (architecture), 5 (profiles, dashboard, backup run) and 8 (plan); phase 1 ([PHASE1.md](PHASE1.md), [PHASE1B.md](PHASE1B.md)) is done. Facts about the test shop were checked against the PrestaShop 9.0.2 test stack (`.docker/`); items marked *(verify)* still have to be confirmed, and the first work package exists to confirm the risky ones.

## 1. Goal and scope

Back up one or more sites on the server from the **command line**: for a site profile, produce a **standard ZIP archive** (files plus one SQL file per database table plus a manifest) in the profile's storage directory, with a sidecar file, verifiably intact and **importable with ordinary tools**. The engine works in resumable **ticks**, so the same code runs later under the web interface and cron with PHP time limits (phases 4 and 5); in the CLI the ticks only make a crashed or killed run resumable.

Phase 2 ends with a **round trip without a restore wizard**: backup → unzip → import the SQL with the plain `mariadb`/`mysql` client → `threadsafe migrate` (phase 1) → self-test, and the restored database equals the source table by table.

| In scope | Out of scope (later phases) |
|---|---|
| site profiles as JSON files, connection test, CLI to manage them | web interface, dashboard, download (phase 4) |
| website types `generic` and `prestashop9` get a *backup* side (detection, database credentials, default filters, manifest extras) | further types (WordPress, Joomla ...) |
| resumable file scanner with filters; resumable database dumper (tables, views, triggers) | restore: `restore.php`, importing a dump, restoring over an existing installation (phase 3) |
| streaming ZIP writer (ZIP64, splitting into self-contained parts) and a ZIP reader | cron schedule, retention, encryption (phase 5) |
| manifest, sidecar file, local storage per profile, free-space check, locks, queue | SFTP/S3/WebDAV export (`Postproc` interface only; the local one is built) |
| CLI: `profile`, `backup`, `backups`, `verify` | anonymisation of customer data, incremental backups |
| resume after abort, artificially low limits in tests | routines and events of the database (detected and reported, not dumped) |

## 2. Deliverables

1. Library `ThreadSafe\` additions: `Engine/` (ticks, run state, locks), `Profile/`, `Archive/` (ZIP writer and reader), `Backup/` (scanner, dumper, manifest, storage), and the backup side of the two website type plugins.
2. CLI commands `profile add|edit|show|list|test|remove`, `backup <profile>` / `backup --all` / `backup --resume <run>`, `backups [<profile>]`, `verify <profile> [<backup>]`.
3. Unit tests (ZIP, dumper formatting, scanner, profile validation) and Docker integration tests including the round trip of 4.2.
4. Documentation of the profile file, the archive layout, the manifest and the CLI (`docs/BACKUP.md`, in the style of [MIGRATE.md](MIGRATE.md)).

## 3. Design

### 3.1 Package layout

```
src/
  Engine/                       Task, Budget (time/memory per tick), RunState + RunStore (JSON), Runner, Lock, Queue
  Profile/                      SiteProfile, ProfileStore (data/profiles/<id>.json, 0600), ConnectionTest
  Archive/                      ZipWriter (streaming, ZIP64, data descriptors, chunked deflate, parts), ZipReader, Entry
  Backup/                       BackupRun (phases), Scanner, Filters, Dumper (+ SqlWriter), Manifest, Sidecar, Storage (local), Postproc
  Plugin/                       WebsiteType stays; Backupable (new capability interface)
  Cli/                          ProfileCommand, BackupCommand, BackupsCommand, VerifyCommand
plugins/
  prestashop9/                  + Backup/: PrestaShop9Backup (detection, credentials from parameters.php, filters), resources/backup-defaults.json
  generic/                      + Backup/: GenericBackup (no detection, credentials from the profile)
data/
  profiles/<id>.json            site profiles (not in the repository)
  runs/<run-id>.json            state of a backup run while it runs
  locks/                        lock files
```

The phase 1 `Step`/`Plan`/`Runner` model is **not** reused for the engine: it plans everything, then applies; a backup is a long, resumable job with state between calls. What is reused: `Database/` (both drivers), `Plugin/` (registry, manifests, `WebsiteType`), `Core/Paths` and `Resources`, the report rendering of the CLI, the exit codes.

### 3.2 Engine: ticks, state, locks

Every component is a small state machine with `tick(Budget): TaskState` (`INIT → RUNNING → FINISHED | FAILED`). One `tick()` stays inside the budget (default ≈ 60 % of `max_execution_time` in web mode, unlimited in the CLI, `--tick-seconds=N` to test) and leaves everything needed to continue in a plain-array state that `RunStore` writes to `data/runs/<run-id>.json` (mode 0600) **at the end of every tick**. The CLI runner calls ticks in a loop and, with `--tick-seconds`, deliberately saves and reloads the state between ticks, so that the resume path is exercised by every test and not only after a crash.

- **Phases** of a backup: `init → scan → dump → pack → finale` (CONCEPT 3.1). `pack` consumes the file queue and the dumped tables; `scan` and `dump` can overlap in a later optimisation, not in phase 2.
- **State** per phase: scanner = stack of directories still to visit + position; dumper = table list, current table, last primary key, bytes written; writer = open part, current entry, offset, running CRC32 and SHA-256 (PHP 8.1+ can `serialize()` a `HashContext`, verified for `crc32b` and `sha256`), central directory records appended to a side file.
- **Locks:** one run per profile (`flock` on `data/locks/<profile>.lock`; a stale lock of a dead process is detected by the held `flock`, not by a timestamp), and **one run at a time globally** by default (`--parallel` later). `backup --all` runs the profiles one after the other.
- **Resume:** `backup --resume <run-id>` continues a run whose process died. A run in `RUNNING` state without a held lock is "interrupted" and listed by `backups`; the partial archive (`*.zip.part`) is continued from the saved offset (the file is truncated to the offset first), never silently reused if the state is missing.
- **Space check** before the run: free space ≥ last backup × 1.2 (or, for a first backup, the size of the site files + 1.5 × database size); `--force` skips it with a warning.
- **Failure:** a failed run deletes its `.part` files and keeps the run state for diagnosis; a finished run deletes the state. Result of a run: `ok`, `ok-with-warnings` (vanished files, unreadable files, skipped objects) or `failed`; exit codes follow phase 1 (0 / 1 usage / 2 refused / 3 failed / 5 internal; `ok-with-warnings` is 0 with the warnings in the report).

### 3.3 Site profile

`data/profiles/<id>.json`, one file per site (fields as in CONCEPT 5.1; schedule, retention and encryption are reserved fields that phase 5 fills):

```json
{
    "id": "shop",
    "name": "Webshop (live)",
    "type": "prestashop9",
    "path": "/var/www/shop",
    "database": null,
    "storage": "/var/backups/threadsafe/shop",
    "excludePaths": ["var/log-old"],
    "structureOnlyTables": [],
    "compression": 6,
    "partSize": null,
    "note": "live, shared hosting"
}
```

- `database: null` means "read it from the application's configuration" (PrestaShop: `parameters.php`) so that no second copy of the password exists; the `generic` type needs `{host, port, name, user, password, prefix, driver}` (the file is mode 0600, the directory 0700), or `null` for a pure file site.
- `excludePaths` / `structureOnlyTables` **add to** the type's defaults; `"useDefaultFilters": false` switches the defaults off.
- **Connection test** (`profile test`, also run by `profile add` and before every backup): path readable and, for the type, recognised (version shown); database reachable, MariaDB/MySQL supported, `SELECT`/`SHOW VIEW`/`TRIGGER` privileges and `START TRANSACTION WITH CONSISTENT SNAPSHOT` usable; storage exists/creatable, writable, **not inside the document root as far as detectable**, free space, and a protection file is written (`.htaccess` `Require all denied`, `index.php`, `web.config`); `open_basedir`, `memory_limit`, `max_execution_time`, `zlib`/`hash` loaded; whether `exec`/`proc_open` are allowed and whether a PHP CLI binary is found (it decides if cron-driven CLI backups and, later, background jobs from the web interface are possible). Problems are reported, never worked around.
- **Storage inside the site path** is excluded from the backup automatically; so is the `data/` directory of ThreadSafe itself if it lies in the site path.

### 3.4 Backup side of a website type

A capability interface next to `WebsiteType` (a type may support migration, backup or both):

```php
interface Backupable
{
    public function detect(string $path): ?DetectedSite;                  // application, version, admin folder ...; null = not this type
    public function databaseSettings(string $path): ?DatabaseSettings;     // from the application's configuration, without booting it
    public function defaultFilters(DetectedSite $site): Filters;           // excluded paths, structure-only tables
    public function manifestExtras(DetectedSite $site, ?Connection $db): array; // domains, admin folder, version ...
}
```

- `prestashop9`: detection as in phase 1 (`src/Core/Version.php`, admin folder = directory with `themes/new-theme`); credentials from `parameters.php` (safe reader of phase 1); filters from `resources/backup-defaults.json` (CONCEPT 5.5: `var/cache`, `var/logs`, `cache/smarty`, `img/tmp`, `.git`, `<admin>/autoupgrade/{backup,download,tmp,logs}`; structure-only `connections`, `connections_source`, `guest`, `connections_page`, `log`, `pagenotfound`, `page_viewed`, `statssearch`, `psgdpr_log`, `employee_session`, `customer_session`, the Smarty cache tables; the lists are data, not code); extras: all `shop_url` domains, `PS_SHOP_DOMAIN`, SSL flags, admin folder, installed modules (names and versions), PrestaShop version.
- `generic`: no detection (the profile names the type), credentials from the profile, no default filters, extras limited to the basics. It can back up *any* site as files plus database.

### 3.5 Scanner and filters

Resumable depth-first walk of the site path, `scandir` per directory (memory = the current directory), producing `(relative path, size, mtime, mode)` into the packing queue. Rules:

- Filters are patterns relative to the site root (`var/cache`, `*.log`, `**/.git`), matched on directory level so that an excluded tree is never entered. Always excluded: the storage path, `data/` of ThreadSafe, the run state.
- **Symlinks are not followed** (decision 5): skipped with a warning (restoring links would need care against path traversal); (decision 5).
- Unreadable files and files that vanish between scan and read are warnings, not failures. A file that changes while it is read is stored as read; its mtime/size at scan time are recorded and a size mismatch is a warning.
- Names: stored as given in UTF-8 (general purpose flag bit 11); a name that is not valid UTF-8 is stored with the flag cleared and reported; names containing `..`, absolute paths and NUL are refused by the *reader* (Zip Slip), the writer never produces them.
- Mode and mtime are stored (ZIP "version made by" Unix, external attributes, extended timestamp field), so a restore can set permissions.

### 3.6 Database dumper

One SQL file per table, `db/NNNN_<table>.sql` (NNNN = dump order, table name **without prefix**, prefix and charset recorded in the manifest), plus `db/_views.sql` and `db/_triggers.sql` if present. Format (rigid, so that a restore can rename tables reliably):

```sql
-- ThreadSafe dump, table `product`, format 1
SET NAMES utf8mb4; SET time_zone = '+00:00'; SET FOREIGN_KEY_CHECKS = 0; SET UNIQUE_CHECKS = 0; SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';
DROP TABLE IF EXISTS `ps_product`;
CREATE TABLE `ps_product` ( ... );          -- SHOW CREATE TABLE as the server prints it
INSERT INTO `ps_product` VALUES (...),(...),...;   -- about 1 MB per statement
```

- **Real table names in the SQL**, so a dump imports with any client. A restore into another prefix renames the tables (`RENAME TABLE` after import or a rewrite of the first token of the two generated statement kinds); the content is never searched for the prefix.
- **Paging by primary key** (`WHERE (pk...) > (?) ORDER BY pk LIMIT n`, the form `TableRewriter` already uses); the chunk size adapts to the average row length so that a statement stays ≈ 1 MB, and shrinks to one row for huge rows. Tables **without a primary key** (7 of 299 in PrestaShop 9.0.2) are read with a stable `ORDER BY` over all columns and `LIMIT/OFFSET` *(verify)* – or, in the CLI, in one unbuffered pass inside the snapshot.
- **Exact values:** everything is fetched as strings (no float rounding, `DECIMAL`/`DOUBLE`/`BIT`/`JSON`/`DATETIME(6)` exact); `BINARY`/`BLOB`/`BIT`/geometry columns are written as hex literals; text with the connection's escaping; `NULL`; zero dates kept. `time_zone` is pinned to UTC on the dumping connection and in the header, so `TIMESTAMP` values restore unchanged.
- **Structure only** tables get `DROP/CREATE` and no `INSERT`.
- **Views and triggers** are dumped when present (none in PrestaShop 9.0.2: 0 views, 0 triggers, 0 foreign keys, all 299 tables InnoDB); `DEFINER` is kept in the file and handled at restore. **Routines and events** are not dumped; if the database has any, the run warns and the manifest lists them under `unsupportedObjects`.
- **Consistency:** in the CLI the whole dump runs inside one `START TRANSACTION WITH CONSISTENT SNAPSHOT` (a snapshot cannot span PHP requests). In a ticked web run each table is consistent in itself and the manifest says `"consistency": "table"` (decision 1).
- MariaDB and MySQL: `SHOW CREATE TABLE` differs in details (collation names, `DEFAULT` expressions); the manifest records server type, version and the collations in use, so that a restore can warn (MySQL 8 `utf8mb4_0900_*` on MariaDB).

### 3.7 ZIP writer and reader

Own streaming implementation, standard format (CONCEPT 3.3):

- Local file headers with the **data descriptor** flag (bit 3), so entries can be streamed without knowing size and CRC; central directory written at the end of a part; **ZIP64** records whenever a size, an offset or the entry count exceeds the classic limits.
- **Compression:** raw `deflate` (level from the profile, default 6); **stored** for already compressed types (`jpg png gif webp zip gz pdf mp4 ...`, list in data). A **file larger than a tick** is compressed in chunks: each chunk is an independent raw deflate stream ended with `ZLIB_FULL_FLUSH`, the last one with `ZLIB_FINISH` – the concatenation is one valid deflate stream *(verified in PHP 8.4: 200 KB random + 600 KB text in 70 KB chunks inflates back to the original with `gzinflate`)*. CRC32 and SHA-256 run on serialisable hash contexts across ticks.
- **Parts:** when the current part would exceed `partSize` the writer closes it (central directory, end record) and opens the next one; every part is a complete ZIP that unzips on its own. A file is never split across parts; a database table file larger than the part size gets its own part. Contents: every part carries `threadsafe-part.json` (backup id, index, entries); the **last part also carries `threadsafe.json`**, the complete manifest. Single-part backups are one `<base>.zip`; split ones `<base>-p01.zip`, `-p02.zip` ...
- Written to `<name>.part` and renamed when closed; fsync of the file before the rename.
- **Reader** (needed for `verify`, the extractor of phase 3 and the tests): reads the central directory (ZIP64 aware), streams an entry with bounded memory, checks CRC32 and sizes, refuses unsafe names (Zip Slip, absolute paths, `..`, NUL), refuses entries claiming absurd sizes.
- Oracles in the tests: `unzip -t` (installed in the tools container), the own reader, and a cross-check that a part written in one tick equals the same part written in many ticks (byte for byte after the timestamps).

### 3.8 Archive, manifest, sidecar, storage

```
<profile>_<YYYYMMDD-HHMMSS>_<random8>.zip             (or ...-p01.zip, -p02.zip ...)
<profile>_<YYYYMMDD-HHMMSS>_<random8>.json            sidecar
inside:  files/...   db/0001_access.sql ...   threadsafe-part.json   threadsafe.json (last part)
```

- **Manifest** `threadsafe.json` (format 1): backup id, tool version, profile id/name, website type, application and version, PHP version, database server type/version/driver/charset/collations/prefix, `consistency`, source domains and admin folder (type extras), applied filters, counts (files, bytes, tables, rows) and the list of parts with size and SHA-256, start/end/duration, warnings, `unsupportedObjects`.
- **Sidecar** `<base>.json`: the manifest plus `size`, `sha256` per part, `status` (`ok` / `ok-with-warnings` / `unverified` / `faulty`), and an empty `transfers[]` for later exports. **The file system is the source of truth** (CONCEPT 5.3): `backups` lists from the sidecars; a missing or unreadable sidecar is rebuilt from the last part's manifest, otherwise the backup is listed as "unknown".
- **Storage:** local directory per profile, file mode 0600, directory 0700, random name component, protection files; the `Postproc` interface (`store(backup)`) exists with the local implementation as the only one. File names are never built from untrusted input beyond the validated profile id.

### 3.9 Verification

`verify` has two levels: **quick** (sidecar present, every part exists with the recorded size and SHA-256) and **full** (additionally every ZIP structure and every entry's CRC32 are read, the manifest's counts equal the archive's, the part chain is complete). Status goes to the sidecar. A failed verification exits with 3.

### 3.10 CLI

```
threadsafe profile add <id> --type=prestashop9|generic --path=… --storage=… [--name=…] [--db-host=… --db-name=… --db-user=… --db-password-file=… --prefix=…]
threadsafe profile edit|show|list|test|remove <id>
threadsafe backup <id> [--part-size=2G] [--compression=6] [--tick-seconds=N] [--force] [--report=file.json]
threadsafe backup --all | --resume <run-id>
threadsafe backups [<id>] [--json]
threadsafe verify <id> [<backup>] [--quick]
```

Passwords only from files or the environment, never as options (as in phase 1). Output and reports follow the style of `migrate` (text report, `--report` JSON, `-v`, `-q`).

## 4. Test strategy

### 4.1 Unit tests (no database)

- **ZIP writer:** entries of all sizes (0 bytes, 1 byte, around the classic 4 GiB / 65,535-entry limits via a *simulated* size source so that no 4 GiB file is written), data descriptors, ZIP64 records, names with UTF-8/spaces/odd characters, parts rolling over; every output accepted by `unzip -t` and the own reader; **property tests** with random content and random chunk and tick boundaries: result identical to the single-shot result.
- **ZIP reader:** hostile archives (Zip Slip names, truncated central directory, wrong CRC, huge claimed sizes, overlapping entries) are refused with a clear error.
- **Dumper formatting:** value escaping for every column type, hex literals, `NULL`, exact decimals/doubles, chunk sizing, keyset predicates for composite keys.
- **Scanner and filters:** pattern matching, excluded trees not entered, symlinks, unreadable directories, resume in the middle of a directory.
- **Profiles:** validation, store round trip, mode 0600/0700, unknown keys refused, storage inside the site path.
- **Engine:** state round trip, budgets, lock behaviour with a dead holder, run resume from every phase boundary (fake tasks).

### 4.2 Integration tests (Docker)

1. **Type round trip:** random tables with *every* column type (`DECIMAL`, `DOUBLE`, `FLOAT`, `BIT`, `BINARY`, `BLOB`, `JSON`, `ENUM`, `SET`, `DATETIME(6)`, `TIMESTAMP`, zero dates, 4-byte UTF-8, `NULL`s, a 40 MB cell) in a scratch database → dump → import with the plain client into a second scratch database → row-by-row hash equality. Run on **MariaDB 10.11 and MySQL 8.4**, with **both drivers** (`ConnectionDriverTest` shows the pattern).
2. **Shop round trip (the phase's main test):** back up the seeded source shop (299 tables, the 200,000-row log table as structure only, the fixture module) with its profile → `unzip` into the target stack's volume → import `db/*.sql` with the `mariadb`/`mysql` client → `threadsafe migrate prestashop9` with the new domain → self-test → table checksums (`CHECKSUM TABLE`) and file hashes equal to the source, except filtered paths and structure-only tables.
3. **Low limits:** `--tick-seconds=1` with a throttled source, `memory_limit=64M`, kill -9 in each phase and `backup --resume`; the final archive equals an undisturbed run. Concurrent second run refused by the lock; a second profile queued.
4. **Space check, storage protection, unreadable files** (chmod 000 in a scratch tree), file changing during the run, tables without primary key, a table with a 40 MB row, a profile whose site path vanished.
5. **`generic` profile** on a scratch directory with a small database (and one without a database).
6. **Verify:** flip a byte in a part, truncate a part, delete a part, delete the sidecar: `verify` finds each one.
7. Matrix as in phase 1b: MariaDB 10.11 and MySQL 8.4 (`tests/stack/matrix.sh`); the PHP/PrestaShop matrix later.

### 4.3 Private real-shop test (optional, local only)

As in [MIGRATE.md](MIGRATE.md) section 10: the real shop (the examined one, CONCEPT 2.3: 3.27 GB of files before filters, of which 1.26 GB `autoupgrade` ballast; database 863 MB, of which 843 MB are three log tables; a filtered archive should come to about 0.7 GB) is backed up **locally**, only to measure time, memory and archive size against the Akeeba backup (2.14 GB) and to run the restore chain by hand. Nothing from it enters the repository, logs or documentation; findings become synthetic fixtures.

## 5. Work packages

| WP | Content | Depends on | Size |
|---|---|---|---|
| 1 | **Spikes** (first, they decide details of 3 and 5): (a) one ZIP entry written over several "ticks" with serialised CRC/SHA state, checked by `unzip -t`; (b) exact string fetching of all column types with both drivers and a DB-to-DB round trip; (c) tables without primary key; (d) memory use with a 40 MB row. Result: a short findings section appended to this document | – | S |
| 2 | `Engine/`: `Budget`, `Task`, `RunState`/`RunStore`, `Lock`, run loop with save/reload between ticks, queue | – | M |
| 3 | `Archive/`: `ZipWriter` (ZIP64, data descriptors, chunked deflate, stored/deflate choice, parts) and `ZipReader` with the property and hostile-input tests | 1 | L |
| 4 | `Backup/Scanner` + `Filters` (resumable walk, patterns, symlinks, warnings) | 2 | M |
| 5 | `Backup/Dumper` + `SqlWriter` (tables, views, triggers, keyset paging, exact values, snapshot/table consistency, MariaDB/MySQL) with the type round trip test | 1, 2 | L |
| 6 | `Profile/` (model, store, validation, connection test) and the `Backupable` capability with `prestashop9` and `generic` implementations, `backup-defaults.json` | 2 | M |
| 7 | Manifest, sidecar, local storage and protection files, naming, `.part` handling, space check, `Postproc` interface | 3, 6 | M |
| 8 | `BackupRun`: phases `init → scan → dump → pack → finale`, parts rollover, warnings, failure cleanup, resume; CLI `profile`, `backup`, `backup --all`, `--resume` | 3, 4, 5, 6, 7 | L |
| 9 | `backups` and `verify` (quick/full), sidecar reconstruction, deleting a backup | 7 | S |
| 10 | Integration: shop round trip, low limits and kill/resume, `generic`, verify damage tests, MariaDB/MySQL | 8, 9 | L |
| 11 | Documentation: `docs/BACKUP.md` (profile file, archive layout, manifest, CLI, emergency extraction with plain tools, private-test procedure), README, CONCEPT status | all | S |

WP 2, 3, 4 and 5 can run in parallel after WP 1. Suggested order: 1 → (2, 3) → (4, 5, 6) → 7 → 8 → 9 → 10 → 11. **Milestone A** (after 3, 5): writer and dumper work on their own through the library and produce an archive that unzips and imports. **Milestone B** (after 8): `threadsafe backup <profile>` works end to end. **Milestone C** (after 10): the round trip is proven under low limits; phase 2 is done.

## 6. Acceptance criteria

1. `threadsafe backup shop` on the seeded source shop produces `<base>.zip` and `<base>.json` in the profile's storage directory; `unzip -t` accepts the archive and `threadsafe verify shop` reports it intact.
2. **Round trip:** unzip, import `db/*.sql` with the plain client, `threadsafe migrate prestashop9` to the target domain: home page, category, product, product image, contact and back office login answer; every table's `CHECKSUM TABLE` equals the source's except the structure-only tables, which have no rows.
3. Filtered content is absent (`var/cache`, `autoupgrade/...`, the 200,000-row log table's rows) and the manifest lists the filters; the storage directory and `data/` are never inside an archive.
4. A run with `--tick-seconds=1` and `memory_limit=64M` produces an archive equal to an undisturbed run (same entries, same content hashes); a run killed in each phase resumes to the same result.
5. Splitting: with `--part-size=50M` the parts are complete ZIPs, each unzips on its own, the manifest's part chain is complete, `verify` finds a missing or altered part.
6. All column types of the type round trip survive on **MariaDB and MySQL, both drivers**; tables without a primary key and a 40 MB row are dumped.
7. Two profiles (`prestashop9` and `generic`, the latter with and without a database) are backed up by `backup --all`; a second concurrent run is refused; the free-space check refuses a too-small storage and `--force` overrides it with a warning.
8. Hostile archives (Zip Slip, wrong CRC, truncated) are refused by the reader; storage files are mode 0600 in a 0700 directory with protection files.
9. Unreadable and vanishing files give warnings and `ok-with-warnings`, never a failed run; routines/events are reported under `unsupportedObjects`.
10. Unit tests, PHPStan level 8 and the integration tests (`tests/stack/matrix.sh`) pass.

## 7. Risks and decisions

**Risks**

- **Resumable compression** is the technical heart: a wrong chunk or flush boundary corrupts archives silently. Mitigation: WP 1 spike first, property tests against single-shot output, `unzip -t` and the full verify as oracles in every integration test.
- **Value fidelity of the dump** (floats, binary, zero dates, time zones, collations) decides whether a backup is a backup: the type round trip test runs on both servers and both drivers before anything else is built on it.
- **Huge rows and files:** a 100 MB `LONGBLOB` needs several times its size in memory (a cell cannot be streamed through PDO/mysqli); the profile test reports `memory_limit`, the dumper fails clearly instead of dying at the limit, documented as a known limit. Files above 4 GiB need ZIP64 (tested by simulation).
- **Consistency across ticks** (decision 1: per table in ticked runs, the manifest says so).
- **Disk space and time on shared hosting:** the examined shop has about 70,000 files and 1 GB of payload after filtering, which is minutes of CPU; the space check, the lock and the queue limit the damage; the private test measures it.
- **Security:** archives contain credentials and customer data. Storage mode 0600/0700, protection files, no secrets in logs or reports, restore-side path validation in the reader. Encryption is phase 5, so until then the storage directory is the only protection - said in the documentation.

**Decisions (2026-10-20)**

1. **Consistency:** CLI runs dump in one consistent snapshot; ticked runs (web, cron via web) are consistent per table and say so in the manifest (`"consistency": "table"`). The interface never *depends* on starting processes: many shared hosts disable `exec`/`proc_open` (research, 2026-10-20: it is the usual default; unlocking is up to the host), so the web interface does not rely on spawning a CLI job. The consistent path is a **cron job that calls the CLI** (`php bin/threadsafe backup <profile>`), which needs a PHP CLI binary but not `exec`; `profile test` reports whether `exec`/`proc_open` are allowed and whether a CLI binary is found. A maintenance mode of the site stays a possible later extra.
2. **Archive:** standard ZIP, split only when `partSize` is set (default: no splitting; ZIP64 handles single files over 4 GiB). Parts are self-contained ZIPs named `-p01`, `-p02`; the manifest is in the last part, `threadsafe-part.json` in each.
3. **SQL:** one file per table with real table names; prefix recorded in the manifest and renamed at restore; views and triggers dumped; routines and events reported, not dumped.
4. **Compression:** raw deflate level 6 by default (profile setting), stored for already compressed file types.
5. **Symlinks:** skipped with a warning (a later phase may store them as link entries that the extractor recreates only inside the target tree).
6. **Profile secrets:** database passwords of `generic` profiles are stored in plain text in `data/profiles/<id>.json` (mode 0600, directory 0700); encryption at rest of the data folder is not part of this phase.
7. **Hashes:** CRC32 per entry (ZIP), SHA-256 per part in the sidecar and the manifest; no per-file SHA-256 in the manifest (cost, little gain over CRC32 plus the part hash).
8. **CLI names:** `profile`, `backup`, `backups`, `verify` as in 3.10; deleting backups arrives with retention in phase 5 (`backups --delete` only as a plain manual command until then).
