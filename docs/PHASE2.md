# Phase 2 – Site profiles and backup engine

**Status (2026-10-20):** in progress. Work packages **1-6 are done** (spikes, tick engine, ZIP writer and reader, scanner and filters, database dumper, site profiles); **7-11 are open** (manifest and storage, `BackupRun` and the CLI, `backups` and `verify`, integration tests, documentation). The work package table in section 5 and the "As built" paragraphs in sections 3.2-3.7 say what exists; where they differ from the text of the plan, **the "As built" paragraph and section 8 (findings) win** - the plan text is kept as written unless it would mislead. The decisions in section 7 are settled (confirmed 2026-10-20).
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

**As built (WP 2).** `src/Engine/`:

- `Task` has no state of its own: `initialState($parameters)` and `tick($state, Budget): TickOutcome` (new state, finished?, a progress line). The state is plain JSON data.
- `Budget` is the time and memory a tick may use: unlimited seconds in the CLI, 60 % of `max_execution_time` in a web request (20 s if PHP has no limit), and always 80 % of `memory_limit`; a task asks `exhausted()` between its units of work and stops at a boundary it can continue from.
- `RunStore` keeps one file per run in `data/runs/` (`<task>-<UTC time>-<random>.json`, directory 0700, files 0600, written atomically, ids validated against path tricks); `Run` has status `running` / `finished` / `failed`, tick count, progress line, error message and the state. No hash and no stack trace in the error line, only `Class: message`.
- `Lock` is an exclusive `flock` that the operating system releases when the process ends however it ends; `isHeld()` tells a live executor from a dead one, so **"interrupted" = status `running` without a held lock** (`RunStore::isInterrupted()`). Tested with a second process that is killed with signal 9.
- `Executor::execute($id, $maxTicks, $onTick)` takes the run's lock, then repeats: load the run from the store, one tick with a fresh budget, save, callback. State goes through the store **after every tick, in the same process too**, so the resume path is the normal path. A throwing task marks the run `failed` (the last good state is kept) and the exception goes to the caller; `resume($id)` refuses a run that another process executes; a failed run is not resumed.
- *Not built:* the persistent queue. In phase 2 the only queue is `backup --all` going through the profiles one after the other under the global lock; a persistent queue belongs with the trigger cron of phase 5.

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

**As built (WP 6).** `src/Profile/`, `src/Plugin/`, the two plugins:

- `SiteProfile` (immutable, validated): id `[a-z0-9][a-z0-9_-]{0,39}`, type, absolute `path` and `storage` (no `..`), optional explicit `database` block (host, port, name, user, password, prefix, driver; `null` = from the application, or none), `excludePaths` (validated with `Filters::problem`), `structureOnlyTables`, `useDefaultFilters`, `compression` 0-9, `partSize` (null or at least 1 MiB), `note`. Unknown fields are refused, so a typo is not silently ignored. `toArray(withPassword: false)` is what is shown to a person; `with([...])` changes fields and validates again (for `profile edit`).
- `ProfileStore`: `data/profiles/<id>.json`, directory 0700, file created with mode 0600 from the start (a temporary file written and renamed), ids checked against path tricks before they touch a path, a damaged file is explained by `load()` and skipped by `all()`.
- `Backupable` (`src/Plugin/`) differs from the sketch above in two details: `defaultFilters()` returns a `BackupDefaults` (paths and table names; the profile's lists are added by the resolver) instead of a `Filters` object, and `detect()` returns a `DetectedSite` (application, version, details such as the back office folder). `PluginRegistry::backupable($id)` returns the backup side of a type or a clear error. A type's entry class implements `WebsiteType` and/or `Backupable`; the logic sits in `Backup/PrestaShop9Backup` and `Backup/GenericBackup`, the entry class delegates.
- PrestaShop 9: detection from `src/Core/Version.php` (major 9 only) and the `themes/new-theme` folder (shared with the migration steps through `Installation`); database settings from `parameters.php` through the safe reader; defaults from `resources/backup-defaults.json` with `{admin}` replaced by the real folder (left out if there is none); extras: back office folder, all `shop_url` rows, the four domain/SSL configuration values, installed modules with version and active flag - a database error becomes an `extrasWarning`, never a failure. Differences to the list in 3.4: `var/sessions` and `cache/cachefs` are also left out, `cache/smarty` is left out as its two subdirectories `cache` and `compile` (the folder with its `index.php` stays), `smarty_last_flush` keeps its data.
- `generic`: always "detected" (a readable directory), no database from the application, no defaults, no extras.
- `ProfileResolver::resolve($profile)` asks the type, reads the database settings (the profile's block wins), merges defaults and profile filters (no duplicates) and adds the **literal** exclusions: the storage and the program's `data/` if they lie inside the site - also when the storage does not exist yet (nearest existing parent resolved). The result, `ResolvedProfile`, hands out the `Filters` and is what the backup run (WP 8) starts from.
- `ConnectionTest::run($profile)` returns a `TestReport` of `Check`s (ok / info / warning / failure; failures stop a backup, warnings do not): site and filters; database (credentials source, reachable, supported server, **privileges** from `SHOW GRANTS` - `SELECT` missing is a failure, `SHOW VIEW` / `TRIGGER` missing a warning -, whether a **consistent snapshot** can be started, size, routines/events/sequences that will not be backed up, `max_allowed_packet`); storage (exists and writable by creating a probe file, or can be created below its nearest existing parent, free space, inside the site -> warning); PHP (version, limits, `zlib`, a database extension if one is needed, `open_basedir`, whether `exec`/`proc_open` are disabled, whether a PHP command line binary is found). It changes nothing: **the protection files of the storage directory are written by the storage code of WP 7**, not by the test (deviation from 3.3).
- Tests: `SiteProfileTest`, `ProfileStoreTest`, `GrantsTest`, `ProfileResolverTest` (real plugins, fake shop directories, connection test without a database) and the integration test `ProfileConnectionTest` (scratch shop; users with fewer privileges; a wrong password; routines; manifest extras), on MariaDB 10.11 and MySQL 8.4.
- Not yet: the CLI (`profile add|edit|show|list|test|remove`, WP 8), `--db-password-file` handling, the registration of `data/` in the program's own exclusion beyond the literal path.

### 3.5 Scanner and filters

Resumable depth-first walk of the site path, `scandir` per directory (memory = the current directory), producing `(relative path, size, mtime, mode)` into the packing queue. Rules:

- Filters are patterns relative to the site root (`var/cache`, `*.log`, `**/.git`), matched on directory level so that an excluded tree is never entered. Always excluded: the storage path, `data/` of ThreadSafe, the run state.
- **Symlinks are not followed** (decision 5): skipped with a warning (restoring links would need care against path traversal); (decision 5).
- Unreadable files and files that vanish between scan and read are warnings, not failures. A file that changes while it is read is stored as read; its mtime/size at scan time are recorded and a size mismatch is a warning.
- Names: stored as given in UTF-8 (general purpose flag bit 11). A name that is not valid UTF-8, or that a ZIP archive must not hold (backslash, `..`, NUL ...), is **skipped with a warning** - decided while building WP 4: such a name cannot be restored faithfully anyway. The reader refuses the same names (Zip Slip); the writer never produces them.
- Mode and mtime are stored (ZIP "version made by" Unix, external attributes, extended timestamp field), so a restore can set permissions.

**As built (WP 4).** `src/Backup/`:

- `Filters`: patterns relative to the site root - `var/cache` (a path and everything below), `*.log` (a name at any depth), a leading `**` plus slash (`.git` at any depth), `*` within one segment, `**` across segments, `?`, a trailing slash for directories only; no negation. Invalid patterns (absolute, `..`, empty segments, backslash, NUL) are refused when the profile is saved. Literal paths (storage directory, program data) are excluded exactly; `Filters::relativeInside($root, $absolute)` finds them when they lie inside the site.
- `Scanner::start()` / `next($state, Budget, $maxItems)` return a `ScanBatch` (items, new state, warnings, done). Order: depth first, names sorted bytewise, a directory's entry before its contents, so the same tree always gives the same sequence. The state is a stack of `(directory, last name done)` frames - small plain data; every call reads the directories on the stack again, so files that appear or vanish between ticks cause no error. At least one entry is read per call, whatever the budget.
- Skipped with a warning: symbolic links (never followed, the target is shown), special files, unreadable directories, invalid names, files that disappear while scanning. Excluded paths cause no warning.
- `FileList` keeps what the scan found in a JSON-lines file (`append` truncates to the size the state remembers, `read` goes on at a byte offset and ignores a half-written last line), so 70,000 entries are neither in the run state nor in memory; the pack phase reads it sequentially and the total gives progress and the space estimate.

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
- **Explicit column lists:** `INSERT INTO t (a, b, c) VALUES ...` with the columns of `information_schema.COLUMNS`, **without generated columns** (virtual and stored; they are recomputed on import and a plain `VALUES` list fails for them) and listing the columns explicitly, because `SELECT *` leaves out invisible columns (verified in WP 5 on MariaDB 10.11 and MySQL 8.4: invisible columns are dumped and restored).
- **Paging by primary key** (`WHERE (pk...) > (?) ORDER BY pk LIMIT n`, the form `TableRewriter` already uses); the chunk size adapts to the average row length so that a statement stays ≈ 1 MB, and shrinks to one row for huge rows. Tables **without a primary key** (7 of 299 in PrestaShop 9.0.2, all empty there) are read **in one pass as one unit** (finding 4): inside the snapshot in the CLI, and as a single step that may overrun the tick budget in a ticked run.
- **Exact values** (finding 2): `FLOAT` and `DOUBLE` are selected as `(col + 0e0)` and written with PHP's shortest round-trip form (`var_export`); `DECIMAL`, dates, `JSON`, `ENUM`, `SET` come back as strings as they are; `BINARY`/`BLOB`/`BIT`/geometry columns are written as hex literals (`0x...`, `''` for the empty value); text with the connection's escaping; `NULL`; zero dates kept. `time_zone` is pinned to UTC on the dumping connection and in the header, so `TIMESTAMP` values restore unchanged (finding 2: read under another zone they are shifted).
- **Large cells** (finding 3): a cell is never fetched whole through `HEX(col)` - on MariaDB a result larger than `max_allowed_packet` becomes `NULL` **without an error**. Cells above 1 MB are read in pieces of 2 MB with `SUBSTRING(col, offset, 2097152)` and written straight to the output stream (constant memory). To keep the statements importable under the default `max_allowed_packet` (16 MB MariaDB, 64 MB MySQL), such a row is written as an `INSERT` with the first piece and `UPDATE t SET col = CONCAT(col, 0x...) WHERE <pk>` for the rest; a large cell in a table without primary key is written as one statement and the run warns. **Limit found while building WP 5 (finding 6):** no SQL can build a value larger than the importing server's `max_allowed_packet`, `UPDATE ... CONCAT` included. The pieces therefore help the dump (constant memory, no `HEX()` of a whole cell) and cells that are too big as one hex statement but fit as a value; a cell above the limit of the dumping server is **written and reported as a warning** (it needs a server with a higher limit to be restored).
- **Structure only** tables get `DROP/CREATE` and no `INSERT`.
- **Views and triggers** are dumped when present (none in PrestaShop 9.0.2: 0 views, 0 triggers, 0 foreign keys, all 299 tables InnoDB); `DEFINER` is kept in the file and handled at restore. **Routines and events** are not dumped; if the database has any, the run warns and the manifest lists them under `unsupportedObjects`.
- **Consistency:** in the CLI the whole dump runs inside one `START TRANSACTION WITH CONSISTENT SNAPSHOT` (a snapshot cannot span PHP requests). In a ticked web run each table is consistent in itself and the manifest says `"consistency": "table"` (decision 1). Built as: each tick of a run with `snapshot` runs inside a snapshot transaction, and the result says `snapshot` only if the whole dump took one tick; "table" means no consistency *across* tables, and a table that spans several ticks is read in key order, so rows changed meanwhile can be missing or appear changed.
- MariaDB and MySQL: `SHOW CREATE TABLE` differs in details (collation names, `DEFAULT` expressions); the manifest records server type, version and the collations in use, so that a restore can warn (MySQL 8 `utf8mb4_0900_*` on MariaDB).

**As built (WP 5).** `src/Backup/` (and `Database/`):

- `Dumper` is a `Task` (`dump`). Parameters: `directory` (work directory), `snapshot`, `structureOnly` (table names with or without prefix), and for tests `statementBytes` (1 MiB), `bigCellBytes` (1 MiB), `pieceBytes` (2 MiB), `maxPacket`. It writes `NNNN_<name without prefix>.sql` (number = alphabetical order, name reduced to `A-Za-z0-9_.-`), `_views.sql` (views ordered so that a view comes after those it uses) and `_triggers.sql` (`DELIMITER ;;` blocks, `sql_mode` per trigger; created after all data so the import fires nothing). The state (`DumpState`) holds the table list, the position, the file name and **length** of the table in progress, the last key (binary keys as hex), the rows so far and the list of finished tables with rows and bytes; a resumed tick cuts the file back to the saved length (`AppendFile`), so bytes written by a process that died are dropped.
- `SchemaReader` reads tables, columns, keys, `SHOW CREATE` statements, views, triggers and the objects that are not dumped (routines, events, sequences -> `unsupported`). A column is *generated* if `GENERATION_EXPRESSION` is set or `EXTRA` says `VIRTUAL`/`STORED GENERATED` - not `DEFAULT_GENERATED`, which MySQL puts on every `DEFAULT CURRENT_TIMESTAMP` column (the spike's substring test would have dropped those; the integration test has such a column). A primary key is used for paging only if it is complete, has no prefix part and consists of integer, `DECIMAL`, `CHAR`/`VARCHAR`, date/time or `BINARY`/`VARBINARY`/`BLOB` columns (not `ENUM`/`SET`, which sort by number, not `FLOAT`, `BIT`, geometry); otherwise the table is read in one pass through `Connection::stream()` (an unbuffered cursor on both drivers).
- Paging: `a > ?`, and for composite keys `a > ? OR (a = ? AND b > ?)` (MariaDB does not turn `(a, b) > (?, ?)` into an index range). The first page is small (8 rows for tables with large columns, else up to 500); the following ones size themselves to about `statementBytes` per `INSERT`.
- Values (`SqlWriter`, pure functions, tested without a database): own escaping (backslash, quote, NUL, line breaks, `\Z`) instead of the driver's, so a dump does not depend on the connection and imports with any client; the header resets `sql_mode`. Floats `(col + 0e0)` and `var_export`; binary columns that are not large come as `HEX(col)` (BIT would otherwise be an integer with mysqli and bytes with PDO), large ones raw and `bin2hex` in PHP; a large column is selected as `IF(LENGTH(c) > limit, NULL, c)` plus its length, and cells above the limit are read with `SUBSTRING` (bytes for binary columns, characters for text, at most `pieceBytes` per piece). `UPDATE` pieces set `ON UPDATE CURRENT_TIMESTAMP` columns to their own value so that they do not move. A column that a `CHECK (json_valid(...))` guards (MariaDB `JSON`) is never cut.
- Tests: `SqlWriterTest`, `DumpStateTest` (unit); `Integration/DumperTest` creates a scratch database with every column type, invisible/generated/`DEFAULT CURRENT_TIMESTAMP`/`ON UPDATE` columns, a composite key, a binary key with a huge cell, a table without key and with duplicate rows, JSON, a view that uses a view, a trigger and a function. It dumps once in one tick and once tick by tick (new connection and object each tick, state through JSON, junk appended to the file as a dead process would leave it), imports every file with the plain `mariadb` client and compares `CHECKSUM TABLE` plus every column with `<=>`. It also dumps the whole test shop (299 tables), imports it and compares the checksum of every table, and reads a 40 MB cell in pieces (peak memory below 48 MB; the pieces read back from the file give the original MD5). All green on MariaDB 10.11 and MySQL 8.4, both drivers. The tools image now has the `mariadb` client for this.
- Not done / known: `SYSTEM VERSIONED` tables are dumped without their history; a JSON cell above `max_allowed_packet` on MySQL (`json` type) is written whole; foreign keys are not ordered (the header switches the checks off).

### 3.7 ZIP writer and reader

Own streaming implementation, standard format (CONCEPT 3.3):

- Local file headers with the **data descriptor** flag (bit 3), so entries can be streamed without knowing size and CRC; central directory written at the end of a part; **ZIP64** records whenever a size, an offset or the entry count exceeds the classic limits.
- **Compression:** raw `deflate` (level from the profile, default 6); **stored** for already compressed types (`jpg png gif webp zip gz pdf mp4 ...`, list in data). A **file is compressed in fixed chunks of 256 KiB** (never cut by the tick): each chunk is an independent raw deflate stream ended with `ZLIB_FULL_FLUSH`, the last one with `ZLIB_FINISH` - the concatenation is one valid deflate stream, and a tick only decides *after which chunk* to stop. CRC32 and SHA-256 run on serialisable hash contexts across ticks. Consequence (finding 1): the archive is **byte-identical however the ticks are cut**.
- **Parts:** when the current part would exceed `partSize` the writer closes it (central directory, end record) and opens the next one; every part is a complete ZIP that unzips on its own. A file is never split across parts; a database table file larger than the part size gets its own part. Contents: every part carries `threadsafe-part.json` (backup id, index, entries); the **last part also carries `threadsafe.json`**, the complete manifest. Single-part backups are one `<base>.zip`; split ones `<base>-p01.zip`, `-p02.zip` ...
- Written to `<name>.part` and renamed when closed; fsync of the file before the rename.
- **Reader** (needed for `verify`, the extractor of phase 3 and the tests): reads the central directory (ZIP64 aware), streams an entry with bounded memory, checks CRC32 and sizes, refuses unsafe names (Zip Slip, absolute paths, `..`, NUL), refuses entries claiming absurd sizes.
- Oracles in the tests: `unzip -t` (installed in the tools container), the own reader, and a cross-check that a part written in one tick equals the same part written in many ticks (byte for byte after the timestamps).

**As built (WP 3).** `src/Archive/`: `ZipFormat` (the record layouts as pure functions, name safety, DOS time), `ZipWriter`, `ZipReader`, `Entry`, `PartInfo`, `VerifyResult`, `Compression` (the file types that are stored), `ZipException`.

- *Writer API.* `ZipWriter::create($path, $level, $forceZip64)` / `resume($state)`; `beginFile($name, $mtime, $mode, $expectedSize, $compress)`, `writeBlock($block)` (exactly 256 KiB), `endFile($tail)` (shorter than a block, possibly empty), `addDirectory()`, `addString()`; `state()` at the end of a tick; `needsRollover($partSize, $nextEntrySize)` tells the caller to `close()` this part and `create()` the next - the writer itself knows nothing about parts or manifests. `close()` writes the central directory and end records, fsyncs, renames `<path>.part` to `<path>` and returns a `PartInfo` with the SHA-256 computed while writing. The central directory is collected in `<path>.cd`.
- *ZIP64.* An entry gets ZIP64 records when its size is unknown or expected to reach 3.75 GiB; the central record uses the 64-bit fields only for values that do not fit 32 bits; the end records switch at 65,535 entries or 4 GiB. The 4 GiB limits are tested through the pure encoders and forced mode, not with 4 GiB of data.
- *Reader.* Streams an entry with bounded memory (input in 16 KiB steps, output stopped at the declared size), checks CRC32 and sizes, understands what the writer and the common tools produce, refuses unsafe names, encrypted entries, other methods, multi-disk archives, ZIP64 values that are announced but missing, absurd expansion claims (more than 1100:1) and data that runs into the central directory. `verify()` collects all problems of a part instead of stopping at the first.
- *Tests.* 40 seeded runs cut the writing into ticks at random points - a new writer object from the saved state, with junk appended to the part and directory files as a process that died after the save would leave - and require **byte-identical** output; every written archive is read back by the reader and accepted by `unzip -t`; hostile archives are built byte by byte (`tests/Support/RawZip.php`). Not covered: archives made by *other* writers (no `zip` tool or `ZipArchive` in the test container), which is why the reader stays strict about what it does not understand.

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

- **ZIP writer:** entries of all sizes (0 bytes, 1 byte, around the classic 4 GiB / 65,535-entry limits via a *simulated* size source so that no 4 GiB file is written), data descriptors, ZIP64 records, names with UTF-8/spaces/odd characters, parts rolling over; every output accepted by `unzip -t` and the own reader; **property tests** with random content and random tick boundaries: byte-identical to the single-shot result (the spike already shows this for 1, 3, 7 and 100000 chunks per tick).
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
| 1 | **Spikes** - **done**, findings in section 8: (a) one ZIP entry over several processes with serialised CRC/SHA state, checked by `unzip -t`, ZIP64 layout; (b) exact values of all column types, both drivers, MariaDB and MySQL, dump-import round trip; (c) tables without primary key; (d) memory with a 40 MB cell. Scripts in `tools/spikes/` | – | S |
| 2 | `Engine/`: `Budget`, `Task`, `Run`/`RunStore`, `Lock`, `Executor` with save/reload between ticks and resume - **done**, see "As built" in 3.2; the queue is not built (see there) | – | M |
| 3 | `Archive/`: `ZipWriter` (ZIP64, data descriptors, chunked deflate, stored/deflate choice, parts) and `ZipReader` with the property and hostile-input tests - **done**, see "As built" in 3.7 | 1 | L |
| 4 | `Backup/Scanner` + `Filters` (resumable walk, patterns, symlinks, warnings) - **done**, see "As built" in 3.5 | 2 | M |
| 5 | `Backup/Dumper` + `SqlWriter` (tables, views, triggers, keyset paging, exact values, snapshot/table consistency, MariaDB/MySQL) with the type round trip test - **done**, see "As built" in 3.6 | 1, 2 | L |
| 6 | `Profile/` (model, store, validation, connection test) and the `Backupable` capability with `prestashop9` and `generic` implementations, `backup-defaults.json` - **done**, see "As built" in 3.4 | 2 | M |
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
4. A run with `--tick-seconds=1` and `memory_limit=64M` produces an archive whose entries are byte-identical to those of an undisturbed run (only the manifest with its timestamps differs); a run killed in each phase resumes to the same result.
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
- **Huge rows and files:** cells are read in pieces (constant memory, tested with 40 MB under a peak of less than 48 MB); a cell above the `max_allowed_packet` of the server cannot be restored by any SQL and is reported as a warning (finding 6). Files above 4 GiB need ZIP64 (tested by simulation).
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

## 8. Findings of work package 1 (spikes, 2026-10-20)

Scripts: `tools/spikes/zip-ticks.php`, `dump-values.php`, `nopk-paging.php` (throw-away, kept for reference; they run in the tools container against the `db` service). Servers: MariaDB 10.11.18 and MySQL 8.4.11, both PHP interfaces.

1. **A ZIP entry can span PHP processes - confirmed.** An archive with five entries (9 MB with random and text parts, an exact multiple of the chunk size, an empty file, a UTF-8 name, a database file) was written by a separate PHP process per tick, 1, 3, 7 and 100,000 chunks per tick, classic and with forced ZIP64 records (data descriptors with 8-byte sizes, ZIP64 extra fields, end-of-central-directory-64 record and locator). Every archive passes `unzip -t`, extracts to the original bytes, and **all tick patterns give byte-identical archives** when the chunk size is fixed. The SHA-256 of the whole archive, carried as a serialised `HashContext`, equals `sha256sum` of the file; CRC32 the same way. The writer truncates the archive to the saved offset at the start of a tick, so bytes written by a process that died after saving are dropped. An `unzip` without a UTF-8 locale shows `#U00fc...` for non-ASCII names; with `LC_ALL=C.UTF-8` the names extract correctly (bit 11 is set) - a property of the tool, not of the archive.
2. **Dump values are exact - with three rules.** All 25 column types of the test table (all integer widths, `DECIMAL(65,30)`, `FLOAT`, `DOUBLE`, `BIT(64)`, `BIT(1)`, `CHAR`, 4-byte UTF-8, a text with NUL, quotes and backslashes, `BINARY` with zeros, empty `VARBINARY`, a `LONGBLOB` of all 256 byte values, `ENUM`, `SET`, zero date, `DATETIME(6)`, `TIMESTAMP(6)`, `TIME(3)` at ±838:59:59, `YEAR`, `JSON`, `POINT`, one virtual and one stored generated column) dump and import without a single difference on **both servers and both interfaces**, and `CHECKSUM TABLE` of the copy equals the source (and equals a server-side copy; it is a valid equality oracle for the tests). Rules: (i) **floating point**: converting the PHP float with `(string)` loses digits (`0.3333333333333333` becomes `0.33333333333333`), `var_export` of the fetched value is exact for `DOUBLE`, but `FLOAT` at the range limits (`3.4028235e38`, `1.1754944e-38`) breaks with both `var_export` and `CAST(col AS CHAR)`; **`(col + 0e0)` selected and written with `var_export` is exact for both**. (ii) the **time zone** must be pinned: `TIMESTAMP` read under `+05:00` comes back five hours shifted. (iii) **generated columns** must be left out of the column list. Both interfaces return the same PHP types (`int` for integers up to `BIGINT`, `string` for `BIGINT UNSIGNED` beyond range, `DECIMAL`, `BIT`, dates, `JSON`; `float` for `FLOAT`/`DOUBLE`).
3. **Large cells: do not use `HEX()` on whole cells.** A 40 MB `LONGBLOB`: fetched whole as `HEX(col)` the server **returned `NULL` without any error on MariaDB** with the default `max_allowed_packet` of 16 MB (MySQL 8.4 returned the 80 MB result with its 64 MB default), and with a raised limit the peak memory was **240 MB** (six times the cell). Read in pieces of 2 MB with `SUBSTRING` and written to a stream, the peak was **12 MB** with the default packet size, on both servers and both interfaces, independent of the cell size. A dump statement of that row is 80 MB; importing it needs `max_allowed_packet` of at least that size, hence the `INSERT` plus `UPDATE ... CONCAT` pieces in 3.6.
4. **Tables without a primary key are best read in one pass.** 301,000 rows (1,000 of them exact duplicates): one pass 0.34 s; pages of 5,000 rows with `ORDER BY` over all columns and `OFFSET` return the correct multiset of rows but take 0.15-0.45 s per page, about 25 s for the table (about 70 times as long, growing with the table). In PrestaShop 9.0.2 seven tables have no key (`customer_message_sync_imap`, `eventbus_job`, `gsitemap_sitemap`, `linksmenutop_lang`, `order_detail_tax`, `order_invoice_tax`, `wishlist_product_cart`), none has a unique index; they are small. Decision: such a table is one unit.
5. **Open from the spikes - settled in WP 5:** invisible columns (MariaDB 10.3+, MySQL 8.0.23+) are in the explicit column list and survive the round trip; views and triggers are dumped and restored (the test shop has none, the integration test creates them).
6. **A cell cannot grow beyond `max_allowed_packet` - not even by `UPDATE ... SET c = CONCAT(c, piece)`** (`tools/spikes/concat-limit.php`, found while building WP 5). On MariaDB 10.11 with the default 16 MB the fifth 4 MB piece fails with error 1301 "Result of concat() was larger than max_allowed_packet"; the limit applies to every intermediate string. Consequence: splitting a cell into pieces does not make larger cells importable (the design of 3.6 assumed it did). What it does give: constant memory while dumping, no `HEX()` of a whole cell, and cells of 8-16 MB that would be 16-32 MB as one hex literal. A cell above the dumping server's own limit is reported. Not tested on MySQL 8.4 (the dumper tests, which stay below the limit, pass there).
