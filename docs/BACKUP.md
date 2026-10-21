# Backups – reference

How ThreadSafe backs up a site from the command line: the profile file, the commands, what a backup consists of, how to check it and how to take it apart with ordinary tools when nothing else is at hand. For the design and the reasons see [PHASE2.md](PHASE2.md); for what to do with a backup afterwards see [MIGRATE.md](MIGRATE.md) (`threadsafe migrate`). Restoring from the backup in one step (`restore.php`, `threadsafe restore`) is described in [RESTORE.md](RESTORE.md); the manual way is in section 8.

## 1. Quick start

```bash
# 1. describe the site once; the profile is tested before it is stored
php bin/threadsafe profile add shop --type=prestashop9 --path=/var/www/shop --storage=/var/backups/threadsafe/shop

# 2. back it up (or: backup --all)
php bin/threadsafe backup shop

# 3. look at what exists, and check that the newest backup is intact
php bin/threadsafe backups
php bin/threadsafe verify shop
```

The backup is a standard ZIP archive and a small `.json` file next to it in the storage directory. **Keep the storage directory outside the web root and out of reach of other users**: a backup holds the database password in `app/config/parameters.php` and all customer data. There is no encryption yet (phase 5).

## 2. Terms

| Term | Meaning |
|---|---|
| **Profile** | One site to back up: where it is, what type it is, where its backups go, what to leave out. A JSON file in the data directory (section 3). |
| **Website type** | A plugin that knows an application: `prestashop9` (detects the installation, reads `parameters.php`, leaves out caches and log tables, adds shop facts to the manifest) and `generic` (any site: files plus the database named in the profile, or files only). |
| **Storage** | The directory for the backups of one profile (section 6). |
| **Backup** | `<profile>_<UTC date>-<UTC time>_<8 hex digits>`: one archive (or several parts) and a sidecar file. The name is the backup's id. |
| **Run** | The work of making one backup. It works in **ticks** and saves its state after each; a run whose process died can be continued (section 4.2). |
| **Data directory** | `data/` next to the program, or the directory in the environment variable `THREADSAFE_DATA`: `profiles/`, `runs/`, `locks/`, your module rules. Not part of the repository. |

## 3. The profile file

`<data>/profiles/<id>.json`, mode 0600 in a directory with mode 0700. Created and changed with `profile add` / `profile edit`; you can read it, but let the command write it.

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
    "useDefaultFilters": true,
    "compression": 6,
    "partSize": null,
    "note": "live, shared hosting"
}
```

| Field | Meaning |
|---|---|
| `id` | 1-40 lower-case letters, digits, hyphens, underscores; starts with a letter or digit. Part of every file name of the backups. |
| `name` | Display name (default: the id). |
| `type` | A website type: `prestashop9`, `generic`. |
| `path`, `storage` | Absolute directories, no `..`. If the storage lies inside the site it is left out of the backup automatically (also the program's `data/` directory). |
| `database` | `null`: take the settings from the application's own configuration (PrestaShop: `app/config/parameters.php`, read without running it, so a broken site can be backed up) or, for `generic`, back up files only. Or an object `{host, port, name, user, password, prefix, driver}` (`host` default `localhost`, `port` 3306, `driver` `auto`, `pdo` or `mysqli`). The password is stored in clear text in the 0600 profile file; `profile show` hides it. |
| `excludePaths` | Patterns for paths to leave out, relative to the site root, **added to the defaults of the type**. `var/cache`: that path and everything below it; `*.log`: a name at any depth; `**/` before a pattern: at any depth; `*` within one segment, `**` across segments, `?` one character; a trailing `/`: directories only. No negation, no absolute paths, no `..`. |
| `structureOnlyTables` | Tables (with or without the prefix) of which only `CREATE TABLE` is kept, no rows. Added to the defaults of the type. |
| `useDefaultFilters` | `false` switches the defaults of the type off. |
| `compression` | Deflate level 1-9; 0 stores everything uncompressed. Files that are compressed already (images, archives, PDFs ...) are always stored. |
| `partSize` | Bytes per archive part (at least 1 MiB), or `null` for one archive. |
| `note` | Free text. |

Unknown fields are refused. **Defaults of `prestashop9`** (`plugins/prestashop9/resources/backup-defaults.json`): `var/cache`, `var/logs`, `var/sessions`, `cache/smarty/cache`, `cache/smarty/compile`, `cache/cachefs`, `img/tmp`, any `.git`, and `<back office folder>/autoupgrade/{backup,download,tmp,logs}`; of the tables `connections`, `connections_source`, `connections_page`, `guest`, `log`, `pagenotfound`, `page_viewed`, `statssearch`, `psgdpr_log`, `employee_session`, `customer_session`, `smarty_cache` and `smarty_lazy_cache` only the structure. `generic` has no defaults.

## 4. Commands

Exit codes of all four: **0** ok (also with warnings), **1** usage error (wrong arguments, unknown profile or backup), **2** refused (the profile cannot be backed up, too little space, another backup is running), **3** failed (a run broke, `verify` found damage). `-v` shows more, `-q` less; `help <command>` lists the options.

### 4.1 `profile`

| Command | What it does |
|---|---|
| `profile add <id> --type=TYPE --path=DIR --storage=DIR [options]` | Creates a profile. Runs the connection test first and refuses to store a profile that cannot be backed up (`--no-test` stores it anyway). |
| `profile edit <id> [options]` | Changes the given fields; the rest stays. A list option (`--exclude`, `--structure-only`) replaces the stored list; `--no-database`, `--default-filters`, `--no-part-size` undo settings. |
| `profile show <id> [--json]`, `profile list [--json]` | Shows profiles; the password is never shown. |
| `profile test <id> [--json]` | Looks at everything a backup depends on and changes nothing (below). Exit code 2 if a backup would fail. |
| `profile remove <id> --yes` | Removes the profile file. Its backups stay where they are. |

Options of `add` and `edit`: `--name`, `--note`, `--db-host`, `--db-port`, `--db-name`, `--db-user`, `--db-password-file=FILE` (or the environment variable `THREADSAFE_DB_PASSWORD`; **a password is never an option value**), `--db-driver`, `--prefix`, `--exclude=PATTERN` and `--structure-only=TABLE` (repeatable), `--no-default-filters`, `--compression=LEVEL`, `--part-size=SIZE` (`500M`, `2G`).

**The connection test** (also run at the start of every backup) reports as *ok*, *info*, *WARNING* or *FAILED*: the site is there and the website type recognises it (PrestaShop: version 9, back office folder); the database is reachable and a supported server (MariaDB from 10.2, MySQL from 5.7), the user has `SELECT` (missing: FAILED) and `SHOW VIEW` and `TRIGGER` (missing: WARNING, views and triggers cannot be dumped), a consistent snapshot can be started, the size of the database, stored routines/events that will not be backed up, `max_allowed_packet`; the storage exists and is writable or can be created, free space, a warning if it lies inside the site; PHP version and limits, `zlib`, a database extension, `open_basedir`, whether `exec`/`proc_open` are disabled, whether a PHP command line binary is found. Failures stop a backup, warnings do not (they are copied into the backup's warnings).

### 4.2 `backup`

```bash
php bin/threadsafe backup <profile> [--part-size=2G] [--compression=6] [--tick-seconds=N] [--force] [--report=FILE]
php bin/threadsafe backup --all
php bin/threadsafe backup --resume=<run id>
```

A run goes through: **init** (connection test, storage prepared, the backup named) → **scan** (the files are listed, filters applied) → **space check** → **dump** (every table into an SQL file in a work directory inside the storage) → **pack** (files, then SQL files, into the archive) → **finale** (manifest into the last part, sidecar file written, parts confirmed, work directory removed).

- **No time limit on the command line.** One tick does the whole backup, and the database is dumped inside **one consistent snapshot** (`START TRANSACTION WITH CONSISTENT SNAPSHOT`; for InnoDB tables). With `--tick-seconds=N` the work is cut into steps of about N seconds, each saved; then every table is consistent in itself but not with the others, and the manifest says `"consistency": "table"`. This is also how a run will behave where it has a time limit (a web request, a cron job that is killed after some time).
- **One backup at a time** (a lock in the data directory), and one per profile. A second `backup` while one runs is refused (exit 2). `--all` goes through the profiles one after the other and goes on after a failure; its exit code is the worst one.
- **Free space** is checked once the files are listed: the room needed is the last backup of the profile times 1.2, or for a first backup the files plus 1.5 times the database, plus the size of the database again for the dump files. Too little: refused; `--force` goes on with a warning.
- **A run that fails** is cleaned up (unfinished parts, work directory, sidecar), its state is kept for diagnosis in `<data>/runs/`, and the exit code is 3 (or 2 for a refusal). **A run whose process died** (power cut, `kill -9`) is listed by `backups` as *interrupted*; `backup --resume=<run id>` continues it from its saved state and produces the archive an undisturbed run would have made. A finished run deletes its state.
- **On a host with only the PHP CGI binary** (shared hosting where `php` in the shell is old and PHP 8 exists only as `/usr/bin/php8.4` or `/usr/lib/cgi-bin/php8.4`), run it as `php8.4 -q /path/to/bin/threadsafe backup <profile>`: `-q` keeps the binary from printing CGI headers. It behaves like the command line version (no time limit, one tick, one consistent snapshot). A child process started from a script that itself runs under FastCGI must be given `/dev/null` as standard input, or the CGI binary waits for requests forever.
- `--report=FILE` writes the result (backup id, parts, size, status, counts, duration, consistency) as JSON, mode 0600.
- **Warnings** do not fail a backup but give it the status `ok-with-warnings`: skipped symbolic links, special files, unreadable directories, files that cannot be read (stored empty), files that disappeared or changed while they were read, names that cannot go into a ZIP archive (not UTF-8, backslash), stored routines/events, cells above `max_allowed_packet`, a free-space shortfall overridden with `--force`.

### 4.3 `backups`

```bash
php bin/threadsafe backups [<profile>] [--json]
php bin/threadsafe backups <profile> --delete=<backup id> --yes
php bin/threadsafe backups --discard-run=<run id> --yes
```

Lists the backups of one or all profiles, newest first: id, UTC time, size, number of parts, **status**, application. The list is read from the sidecar files. A sidecar that is missing or unusable is made again from the manifest in the last archive part (status `unverified`: the sizes and checksums are those of the files as they are, so only `verify` says whether they are intact); if that is impossible the backup is listed as `unknown` with the reason. `incomplete` means that parts the sidecar lists are missing. **Interrupted runs** are listed with the commands to continue them (`backup --resume`) or give them up (`--discard-run`, which removes what the run left in the storage). `--delete` removes all files of a backup for good; it is a plain manual command until retention exists (phase 5).

Statuses: `ok`, `ok-with-warnings`, `unverified` (rebuilt sidecar, never fully checked), `faulty` (a verification found damage).

### 4.4 `verify`

```bash
php bin/threadsafe verify <profile> [<backup id>] [--quick] [--json]
```

Checks the newest backup of the profile (or the one named). **Full** (default): every part exists with the recorded size and SHA-256; every part is read as a ZIP archive, every entry against its CRC32 and size; the entry counts, the part markers (which backup, which number), the manifest in the last part against the sidecar's, the part chain, and the manifest's counts of files, directories and tables against what the archive holds. **Quick** (`--quick`): only size and SHA-256 of the parts and that no unlisted part is in the directory. The result is written into the sidecar: damage makes the status `faulty` and the exit code 3; a clean full check sets `ok` or `ok-with-warnings`; a clean quick check does not clear `unverified` or `faulty`.

## 5. What a backup consists of

```
<profile>_<YYYYMMDD>-<HHMMSS>_<random8>.zip           one archive, or
<profile>_<YYYYMMDD>-<HHMMSS>_<random8>-p01.zip       the parts of a split archive (-p02, ...; partSize set)
<profile>_<YYYYMMDD>-<HHMMSS>_<random8>.json          the sidecar file
```

Time is UTC. A backup made with `partSize` always numbers its parts, even if it ends up with one. Every part is a **complete ZIP archive on its own** (`unzip` opens any part); a file is never split across parts, and a file larger than the part size gets a part of its own. Files over 4 GiB and archives over 4 GiB use ZIP64.

Inside:

| Entry | Content |
|---|---|
| `threadsafe-part.json` | First entry of every part: format, backup id, part number, whether the backup is split. |
| `files/<path>` | The files of the site with their paths, modes and modification times; empty directories are entries (`files/<path>/`). Symbolic links are not stored. |
| `db/0001_<table>.sql`, `db/0002_...` | One SQL file per table, numbered in the order of the dump, named after the table **without the prefix**. |
| `db/_views.sql`, `db/_triggers.sql` | Views (a view after the views it uses) and triggers, if the database has any. |
| `threadsafe.json` | The manifest; only in the **last** part. |

**The SQL files** are plain SQL that the `mariadb` / `mysql` client imports. Each starts with a comment and the settings that make the import exact:

```sql
-- ThreadSafe dump, table `ps_product`, format 1
SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;
SET UNIQUE_CHECKS = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';
DROP TABLE IF EXISTS `ps_product`;
CREATE TABLE `ps_product` ( ... );      -- as the server prints it
INSERT INTO `ps_product` (`id_product`, ...) VALUES
(1, ...),
(2, ...);
```

The statements use the **real table names** (with the prefix); rows come in `INSERT` statements of about 1 MB with an explicit column list (generated columns are left out, the server recomputes them). Values are exact: `FLOAT`/`DOUBLE` in their shortest exact form, binary columns as hex literals, `NULL` as `NULL`, zero dates kept, time zone UTC. A cell above 1 MB is written as an `INSERT` with the first piece and `UPDATE ... SET col = CONCAT(col, ...)` statements for the rest. Tables kept as *structure only* have `DROP` and `CREATE` and no rows. `_triggers.sql` uses the `DELIMITER` command of the `mysql` client and must be imported with that client.

**The manifest** (`threadsafe.json`, format 1) says what the backup is: `backupId`, `toolVersion`, `profile {id, name}`, `type`, `application {name, version}`, `php`, `database {server, version, driver, name, prefix, charset, collations}` (empty without a database), `consistency` (`snapshot`, `table` or `none`), `extras` (from the website type; PrestaShop: back office folder, the shop URLs with their domains, the domain and SSL settings, the installed modules with versions), `filters` (the exclude patterns, structure-only tables and literal exclusions that applied), `counts` (files, directories, file bytes, tables, rows, database bytes), `parts` (name and number of entries), `started`, `finished` (UTC), `warnings` and `unsupportedObjects` (stored routines, events, sequences, which are not dumped). **It holds no secret and no absolute path of the server.**

**The sidecar** (`<id>.json`) is the manifest plus what cannot be inside the part it describes: size, SHA-256 and entry count of every part, the status, and a list of transfers to other places (empty; later phases fill it). It is a convenience for listing; the archives are the truth.

## 6. The storage directory

- The directory is created with mode 0700 (an existing one is left as it is), every file ThreadSafe writes into it has mode 0600 (archive parts from the first byte on).
- `.htaccess` (Apache 2.4 and 2.2), `index.php` (answers 403) and `web.config` (IIS) are written into it so that a web server does not serve the backups if it lies below the document root. They are a second line of defence, not a reason to put the storage there.
- While a backup is made, `<id>.zip.part`, `<id>.zip.cd` and the work directory `.work-<id>/` (the dump files and the file list) exist beside the finished files; they are removed when the backup finishes, fails or is discarded. The dump files are plain text with your data in them, so the free space estimate counts them.
- File names are built only from the validated profile id, the time and random characters.

## 7. Where things are

| What | Where |
|---|---|
| Profiles | `<data>/profiles/<id>.json` |
| State of runs | `<data>/runs/backup-<time>-<random>.json` (deleted when the run finishes) |
| Locks | `<data>/locks/backup.lock`, `<data>/locks/profile-<id>.lock` |
| Environment | `THREADSAFE_DATA` (the data directory), `THREADSAFE_DB_PASSWORD` (the database password for `profile add/edit` without a password file) |

## 8. Taking a backup apart by hand

When ThreadSafe is not at hand, a backup can be restored with `unzip` and a database client. For a split backup do the first two steps for every part.

```bash
# 1. extract (the archive is a standard ZIP file)
mkdir restore && unzip -q shop_20261020-123456_ab12cd34.zip -d restore
#    restore/files/...      the site, with its modes
#    restore/db/*.sql       the database
#    restore/threadsafe.json   what it is (look at it first)

# 2. put the files where the site lives
cp -a restore/files/. /var/www/shop/

# 3. create the database and import the SQL files in name order: tables (0001_ ...), then _views.sql, then _triggers.sql
mysql -e 'CREATE DATABASE shop CHARACTER SET utf8mb4'
for f in restore/db/*.sql; do mysql shop < "$f" || break; done
```

Notes:

- `mysql` / `mariadb` read the password from `~/.my.cnf` or the environment variable `MYSQL_PWD`; do not put it on the command line.
- The shell sorts `_views.sql` and `_triggers.sql` after the numbered files, which is the order they need.
- A cell that is larger than `max_allowed_packet` of the *importing* server cannot be restored by any SQL, `UPDATE ... CONCAT` included; the backup's warnings name such cells. Raise `max_allowed_packet` of the importing server (`SET GLOBAL`, needs the privilege) or restore on a server where it is high enough.
- Restoring into another **table prefix** is not a rename of files: the statements contain the real table names. Restore with the same prefix, or use `threadsafe restore --prefix=...` / `restore.php` ([RESTORE.md](RESTORE.md)).
- Files restored this way belong to the user who extracted them; give them back to the web server's user. Directories left out by the filters (`var/cache` ...) are rebuilt by PrestaShop or by `threadsafe migrate`.
- **The copy still has the old domain, paths and database credentials.** `threadsafe migrate prestashop9 --root=/var/www/shop --to=NEW.DOMAIN ...` turns it into a working shop (domains, `parameters.php`, caches, `.htaccess`, self-test): see [MIGRATE.md](MIGRATE.md). For a restore on the same domain use it without `--to`.

## 9. Limits and what is not there

- **No encryption**, no retention (old backups are deleted by hand with `backups --delete`), no schedule, no transfer to other places, no web interface: phases 3-5 of [CONCEPT.md](CONCEPT.md). The `transfers` list of the sidecar is reserved for them.
- **Database objects:** tables, views and triggers are backed up. Stored routines, events and sequences are not; they are reported. `SYSTEM VERSIONED` tables of MariaDB are dumped without their history. Foreign keys are not ordered (the import switches the checks off). Only InnoDB tables are covered by the consistent snapshot.
- **Large cells:** a cell above the `max_allowed_packet` of the dumping server is dumped and reported as a warning (section 8). Cells are read in pieces, so memory stays small however large they are.
- **Tables without a usable primary key** (none, a prefix key, a key of an unsuitable type) are read in one pass as one unit of work; under a time limit this step can take longer than a tick.
- **Files** that change while they are read are stored as read and reported; a symbolic link is skipped, never followed.
- **A run that is killed in the few milliseconds in which an archive part is being closed** cannot be continued; start a new backup (`backups --discard-run=...` first). There is no persistent queue: `backup --all` goes through the profiles in one process.
- **`CHECKSUM TABLE`** of MariaDB and MySQL differ for the same data; compare tables of a restore into the other kind of server row by row, not by checksum.

## 10. Trying it on a real shop (private, local only)

The tests of the repository use the Docker shop and synthetic fixtures. A real shop is only a final reality check, and it holds customer data (see [MIGRATE.md](MIGRATE.md) section 10 and [PHASE2.md](PHASE2.md) 4.3): keep everything below outside the repository (`/private/` is ignored by Git), never paste contents into logs, issues or documentation.

1. Put a copy of the shop where it is not connected to anything live (a disposable Docker environment; the isolated target stack of `tests/stack/` is one): the files and the database, restored by hand from your existing backup.
2. Create a profile for the copy with the storage under `/private/`, run `profile test`, then `backup <profile> -v` and note time, peak memory (`/usr/bin/time -v`) and the size of the archive against the backup tool you use today. The default filters should shrink a shop with a large `autoupgrade` directory and log tables considerably.
3. `verify`, then take the backup apart as in section 8 into a second disposable environment, run `migrate` with `--mode=dev` and a mail catcher, and compare the result with the copy.
4. Read the warnings of the manifest. Every one is a finding. Turn findings into **synthetic** fixtures or module rules (names of tables and settings, never contents).
5. Delete the copy, the archives, the sidecars, the reports and the run states: they hold customer data and secrets.
