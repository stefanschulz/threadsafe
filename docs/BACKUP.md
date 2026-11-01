# Backups – reference

How ThreadSafe backs up a site from the command line (the web interface, [WEB.md](WEB.md), runs the same engine and reads the same files): the profile file, the commands, what a backup consists of, how to check it and how to take it apart with ordinary tools when nothing else is at hand. For the design and the reasons see [PHASE2.md](PHASE2.md); for what to do with a backup afterwards see [MIGRATE.md](MIGRATE.md) (`threadsafe migrate`). Restoring from the backup in one step (`threadsafe-restore.php`, `threadsafe restore`) is described in [RESTORE.md](RESTORE.md); the manual way is in section 8.

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
| **Website type** | A plugin that knows an application: `prestashop9` (detects the installation, reads `parameters.php`, leaves out caches and log tables, adds shop facts to the manifest) `joomla` (Joomla 5 and 6: detects the installation from `libraries/src/Version.php`, reads `configuration.php` without running it, leaves out caches, `tmp`, logs, sessions and the search index, adds version, extensions, templates, languages and the addresses of the content to the manifest; restoring and migrating it are described in [MIGRATE.md](MIGRATE.md) section 13), `wordpress` (WordPress 6.0 and later: detects the installation from `wp-includes/version.php`, also with `wp-config.php` one directory above, reads `wp-config.php` without running it, leaves out the caches, upgrade leftovers, the archives of other backup plugins and logs, keeps the rows of session and log tables out, and adds version, prefix, `siteurl` and `home`, the active plugins, the theme and the drop-ins to the manifest; a multisite is refused; see [WORDPRESS.md](WORDPRESS.md)) and `generic` (any site: files plus the database named in the profile, or files only). |
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
    "note": "live, shared hosting",
    "schedule": {"every": "day", "at": "03:30"},
    "retention": {"keep": 14, "maxAgeDays": 90, "maxBytes": 21474836480},
    "encryption": {"recipients": ["age1ql3z7hjy54pw3hyww5ayyfg7zqgvc7w3j2elw8zmrj2kg5sfn9aqmcac8p"]}
}
```

| Field | Meaning |
|---|---|
| `id` | 1-40 lower-case letters, digits, hyphens, underscores; starts with a letter or digit. Part of every file name of the backups. |
| `name` | Display name (default: the id). |
| `type` | A website type: `prestashop9`, `joomla`, `wordpress`, `generic`. |
| `path`, `storage` | Absolute directories, no `..`. If the storage lies inside the site it is left out of the backup automatically (also the program's `data/` directory). |
| `database` | `null`: take the settings from the application's own configuration (PrestaShop: `app/config/parameters.php`, read without running it, so a broken site can be backed up) or, for `generic`, back up files only. Or an object `{host, port, name, user, password, prefix, driver}` (`host` default `localhost`, `port` 3306, `driver` `auto`, `pdo` or `mysqli`). The password is stored in clear text in the 0600 profile file; `profile show` hides it. |
| `excludePaths` | Patterns for paths to leave out, relative to the site root, **added to the defaults of the type**. `var/cache`: that path and everything below it; `/cache`: a leading slash anchors a single name at the site root (a name without a slash, such as `cache`, matches at **any depth** and would also hit code directories of that name); `*.log`: a name at any depth; `**/` before a pattern: at any depth; `*` within one segment, `**` across segments, `?` one character; a trailing `/`: directories only. No negation, no absolute paths, no `..`. |
| `structureOnlyTables` | Tables (with or without the prefix) of which only `CREATE TABLE` is kept, no rows. Added to the defaults of the type. |
| `useDefaultFilters` | `false` switches the defaults of the type off. |
| `compression` | Deflate level 1-9; 0 stores everything uncompressed. Files that are compressed already (images, archives, PDFs ...) are always stored. |
| `partSize` | Bytes per archive part (at least 1 MiB), or `null` for one archive. |
| `note` | Free text. |
| `schedule` | Optional (`null` or absent: no schedule). One of `{"every": "hours", "interval": 6, "minute": 15}` (interval 1, 2, 3, 4, 6, 8 or 12), `{"every": "day", "at": "03:30"}`, `{"every": "week", "on": ["mon", "thu"], "at": "02:15"}`, `{"every": "month", "day": 1, "at": "04:00"}` (day 1 to 28); `"paused": true` next to any of them keeps the settings but makes no backup by them. Read in the time zone of the installation (setting `schedule.timezone`, see [WEB.md](WEB.md); UTC where nobody set one); the rules for daylight saving time are in [SCHEDULE.md](SCHEDULE.md) section 2. Set it on the Schedule page, in the profile form or with `--schedule`. The backups are made by the trigger, see 4.5. |
| `retention` | Optional. `{"keep": N, "maxAgeDays": D, "maxBytes": B}`, every rule optional: at most N backups, none older than D days, together at most B bytes (at least 1 MiB). Applied after every successful backup and once an hour, see 4.6. |
| `encryption` | Optional. `{"recipients": ["age1...", ...]}`: public keys of the `age` format that new backups will be encrypted for. Only the shape of a key is checked now. Recorded only so far (work package 6 of [PHASE5.md](PHASE5.md)). |

Unknown fields are refused. **Defaults of `prestashop9`** (`plugins/prestashop9/resources/backup-defaults.json`): `var/cache`, `var/logs`, `var/sessions`, `cache/smarty/cache`, `cache/smarty/compile`, `cache/cachefs`, `img/tmp`, any `.git`, and `<back office folder>/autoupgrade/{backup,download,tmp,logs}`; of the tables `connections`, `connections_source`, `connections_page`, `guest`, `log`, `pagenotfound`, `page_viewed`, `statssearch`, `psgdpr_log`, `employee_session`, `customer_session`, `smarty_cache` and `smarty_lazy_cache` only the structure. **Defaults of `joomla`** (`plugins/joomla/resources/backup-defaults.json`): the directories `cache`, `administrator/cache`, `tmp`, `administrator/logs`, `logs`, any `.git`, and the output directories of Akeeba Backup (`administrator/components/com_akeebabackup/backup`, `.../com_akeeba/backup`); of the tables `session`, `user_keys`, `updates` and the big tables of Smart Search (`finder_links`, `finder_links_terms`, `finder_logging`, `finder_taxonomy_map`, `finder_terms`, `finder_tokens`, `finder_tokens_aggregate`) only the structure. `finder_taxonomy` keeps its data (its ROOT row is needed), and so does `action_logs` (an audit trail). `generic` has no defaults.

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

Options of `add` and `edit`: `--name`, `--note`, `--db-host`, `--db-port`, `--db-name`, `--db-user`, `--db-password-file=FILE` (or the environment variable `THREADSAFE_DB_PASSWORD`; **a password is never an option value**), `--db-driver`, `--prefix`, `--exclude=PATTERN` and `--structure-only=TABLE` (repeatable), `--no-default-filters`, `--compression=LEVEL`, `--part-size=SIZE` (`500M`, `2G`), `--schedule="daily at 03:30"` (also `every 6 hours at :15`, `weekly on mon,thu at 02:15`, `monthly on 1 at 04:00`) / `--no-schedule` / `--pause-schedule` / `--resume-schedule`, `--keep=N`, `--max-age-days=DAYS`, `--max-bytes=SIZE` / `--no-retention` (a rule that is not named stays as it is), `--recipient=age1...` (repeatable; `edit` replaces the list) / `--no-encryption`. `profile show` prints the schedule with the time zone and the next run, the retention rules, and the public keys in short form.

**The connection test** (also run at the start of every backup) reports as *ok*, *info*, *WARNING* or *FAILED*: the site is there and the website type recognises it (PrestaShop: version 9, back office folder); the database is reachable and a supported server (MariaDB from 10.2, MySQL from 5.7), the user has `SELECT` (missing: FAILED) and `SHOW VIEW` and `TRIGGER` (missing: WARNING, views and triggers cannot be dumped), a consistent snapshot can be started, the size of the database, stored routines/events that will not be backed up, `max_allowed_packet`; the storage exists and is writable or can be created, free space, a warning if it lies inside the site; PHP version and limits, `zlib`, a database extension, `open_basedir` (a note only: the site path and the storage are checked against it, and one outside it is FAILED, so there is no warning to carry into every backup), whether `exec`/`proc_open` are disabled, whether a PHP command line binary is found. Failures stop a backup, warnings do not (they are copied into the backup's warnings).

### 4.2 `backup`

```bash
php bin/threadsafe backup <profile> [--part-size=2G] [--compression=6] [--tick-seconds=N] [--force] [--passphrase-file=FILE] [--report=FILE]
php bin/threadsafe backup --all
php bin/threadsafe backup --resume=<run id>
```

A run goes through: **init** (connection test, storage prepared, the backup named) → **scan** (the files are listed, filters applied) → **space check** → **dump** (every table into an SQL file in a work directory inside the storage) → **pack** (files, then SQL files, into the archive) → **finale** (manifest into the last part, sidecar file written, parts confirmed, work directory removed).

- **No time limit on the command line.** One tick does the whole backup (after a first tick that only names it and prepares its work directory, so that a run that is killed is continued into the same backup and `--discard-run` finds what it left), and the database is dumped inside **one consistent snapshot** (`START TRANSACTION WITH CONSISTENT SNAPSHOT`; for InnoDB tables). With `--tick-seconds=N` the work is cut into steps of about N seconds, each saved; then every table is consistent in itself but not with the others, and the manifest says `"consistency": "table"`. This is also how a run will behave where it has a time limit (a web request, a cron job that is killed after some time).
- **One backup at a time** (a lock in the data directory), and one per profile. A second `backup` while one runs is refused (exit 2) - also while a backup started in the web interface is going (it is "going" for a minute after its last step, even though no process holds a lock between two steps); `backup --resume` and `backups --discard-run` refuse such a run too. `--all` goes through the profiles one after the other and goes on after a failure; its exit code is the worst one.
- **Free space** is checked once the files are listed: the room needed is the last backup of the profile times 1.2, or for a first backup the files plus 1.5 times the database, plus the size of the database again for the dump files. Too little: refused; `--force` goes on with a warning.
- **A run that fails** is cleaned up (unfinished parts, work directory, sidecar), its state is kept for diagnosis in `<data>/runs/`, and the exit code is 3 (or 2 for a refusal). **A run whose process died** (power cut, `kill -9`) is listed by `backups` as *interrupted*; `backup --resume=<run id>` continues it from its saved state and produces the archive an undisturbed run would have made. A finished run deletes its state.
- **On a host with only the PHP CGI binary** (shared hosting where `php` in the shell is old and PHP 8 exists only as `/usr/bin/php8.4` or `/usr/lib/cgi-bin/php8.4`), run it as `php8.4 -q /path/to/bin/threadsafe backup <profile>`: `-q` keeps the binary from printing CGI headers. It behaves like the command line version (no time limit, one tick, one consistent snapshot). A child process started from a script that itself runs under FastCGI must be given `/dev/null` as standard input, or the CGI binary waits for requests forever.
- `--report=FILE` writes the result (backup id, parts, size, status, counts, duration, consistency) as JSON, mode 0600.
- **Encrypted backups.** A profile with public keys (`profile edit <id> --recipient=age1...`) makes encrypted backups, scheduled or by hand: after **pack** come **close** (the last part is closed, the date is fixed) and **seal**, and every part is written into the storage only as `<id>[-pNN].zip.age`, a file of the [age](https://age-encryption.org/) format around the ZIP part. The plain parts live in the work directory (`.work-<id>/parts/`) and a part is deleted as soon as its sealed file is complete, so at most one plain part waits for sealing; the free-space estimate counts it. For a large site set a `partSize` (for example 500M): that is also what keeps the plain data on the disk small. `--passphrase-file=FILE` (or the environment variable `THREADSAFE_PASSPHRASE`) encrypts this one backup **for that passphrase only** - not for the keys of the profile, because the format does not allow both in one file; a passphrase under 12 characters that `keys passphrase` did not make is refused. The passphrase is kept in the state file of the run (mode 0600) until the run is done and is nowhere else. Sealing runs in ticks like everything else; a run that is interrupted goes on from the last sealed block.
- **Warnings** do not fail a backup but give it the status `ok-with-warnings`: skipped symbolic links, special files, unreadable directories, files that cannot be read (stored empty), files that disappeared or changed while they were read, names that cannot go into a ZIP archive (not UTF-8, backslash), stored routines/events, cells above `max_allowed_packet`, a free-space shortfall overridden with `--force`.
  A file that cannot be read ("Stored empty, the file cannot be read") is stored empty with this one warning: the user that runs the backup may not read it. Usual causes are a file of mode 600 that belongs to another user, and a POSIX ACL whose mask a `chmod` of the site's application has cut back (`getfacl` shows `#effective:---`); `tools/hestia-grant-read.sh` says how such an access is given and why it must be repeated. Files that a security extension writes for itself while it runs (state, cache, keep-alive files) are no loss in a backup: leave them out with an exclude pattern such as `administrator/components/com_htprotect/data/*` (the directory itself stays) if the warning is a nuisance.

### 4.3 `backups`

```bash
php bin/threadsafe backups [<profile>] [--json]
php bin/threadsafe backups <profile> --pin=<backup id>      # retention never deletes it; --unpin lets it go
php bin/threadsafe backups <profile> --delete=<backup id> --yes
php bin/threadsafe backups --discard-run=<run id> --yes
```

Lists the backups of one or all profiles, newest first: id, UTC time, size, number of parts, **status**, application. The list is read from the sidecar files. A sidecar that is missing or unusable is made again from the manifest in the last archive part (status `unverified`: the sizes and checksums are those of the files as they are, so only `verify` says whether they are intact); if that is impossible the backup is listed as `unknown` with the reason. `incomplete` means that parts the sidecar lists are missing. **Interrupted runs** are listed with the commands to continue them (`backup --resume`) or give them up (`--discard-run`, which removes what the run left in the storage). `--delete` removes all files of a backup for good; it is a plain manual command until retention exists (phase 5).

Statuses: `ok`, `ok-with-warnings`, `unverified` (rebuilt sidecar, never fully checked), `faulty` (a verification found damage). An encrypted backup is marked `encrypted` (JSON: `"encrypted": true`). Its sidecar cannot be made again from the archive, because that needs the key: if it is lost, the backup is listed as `unknown`, and `age -d` still opens the parts.

### 4.4 `verify`

```bash
php bin/threadsafe verify <profile> [<backup id>] [--quick] [--json] [--identity-file=FILE | --passphrase-file=FILE]
```

Checks the newest backup of the profile (or the one named); the web interface runs the same check in steps, so that even a very large backup can be checked within a request time limit. **Full** (default): every part exists with the recorded size and SHA-256; every part is read as a ZIP archive, every entry against its CRC32 and size; the entry counts, the part markers (which backup, which number), the manifest in the last part against the sidecar's, the part chain, and the manifest's counts of files, directories and tables against what the archive holds. **Quick** (`--quick`): only size and SHA-256 of the parts and that no unlisted part is in the directory. The result is written into the sidecar: damage makes the status `faulty` and the exit code 3; a clean full check sets `ok` or `ok-with-warnings`; a clean quick check does not clear `unverified` or `faulty`. **Encrypted backups:** the quick check needs no key (size and SHA-256 of the sealed files). The full check needs one - `--identity-file=FILE` (a key file) or `--passphrase-file=FILE` / `THREADSAFE_PASSPHRASE` - and then reads every part through the decryption: every block is authenticated, every ZIP entry is checked as for a plain backup, and the manifest inside is compared with the minimal one in the sidecar. Without a key the check says so in a note and does the quick one. The web interface has no key and does the quick check of an encrypted backup.

### 4.5 `cron` (the trigger)

```bash
php bin/threadsafe cron          # run what the schedule says is due (in a crontab add -q: only problems are mailed)
php bin/threadsafe admin cron-secret [--show | --remove]
```

A profile with a `schedule` is backed up by itself, but only when something calls the trigger. `cron` is that call: it writes the heartbeat (`data/heartbeat.json`), continues a backup that was interrupted, and runs every scheduled backup that is due, one after the other, each to its end with the database read in one snapshot. It ends at once if another trigger is working. A scheduled time that the trigger missed is made up if it is less than `schedule.graceMinutes` (six hours) old and given up otherwise, so a cron job that runs once a day is enough for a daily schedule if its time lies shortly after the scheduled one. A failed backup is tried again 15 minutes later, twice at most, if a later call comes in time. Exit codes: 0 ok, 2 something was refused (for instance a manual backup was running), 3 a backup failed. `admin cron-secret` and the System page show a crontab line with the real paths.

For a host whose cron can only fetch an address, `admin cron-secret` makes the secret of `GET /cron/<secret>` (below `public/`, whether or not the web interface is switched on; without a secret there is no such address). A call starts `threadsafe cron` as a detached process and answers at once; on a host that does not allow that it does one step of work per call instead, so it must come often (the answer says `working` while there is more to do). The first word of the answer is `idle`, `started` or `working`. The secret is in the access log of the web server; it starts backups and nothing else, and a new one (`admin cron-secret`) replaces it. Everything about schedules, the trigger and its settings: [SCHEDULE.md](SCHEDULE.md); the design: [PHASE5.md](PHASE5.md) 3.3 and "As built".

### 4.6 `retention`

```bash
php bin/threadsafe retention <profile> --dry-run    # what would be deleted, and why; nothing is deleted
php bin/threadsafe retention <profile>              # delete it
```

The rules are those of the profile (`--keep`, `--max-age-days`, `--max-bytes`): **keep** at most N backups, **none older** than D days, **together** at most B bytes. Only complete, usable backups (`ok`, `ok-with-warnings`, `unverified`) count. **Never deleted:** the newest usable backup (a failing schedule or a rule set wrongly cannot leave the site without a backup), a **pinned** backup (it does not count towards `keep`, but its size counts towards the limit), a backup that a run is writing or a check is reading. A faulty or incomplete backup is left alone. Unfinished files of runs that died (work directories, `.part` files) that no run claims and that are older than a week are removed as well. The deletions come oldest first and the numbers behind each reason are recomputed after each one, so the dry run lists exactly what the real run does; every deletion is an entry `retention.deleted` in the audit log with the rule that caused it. When the storage directory cannot be read nothing is deleted (exit code 2).

The same rules are applied **by themselves** after every successful backup (the command, the browser and the trigger) and, by the trigger or a call of its address, once an hour for every profile with rules, so that `maxAgeDays` works for a site that makes no backups any more; not for a profile whose last backup run failed. **A rule that has not been seen at work and would delete more than one backup waits for your go-ahead**: the backup says so, the profile page shows the list, and `retention <profile>` (or the button) goes ahead and confirms the rules; changing the rules asks again.

More about rules, the go-ahead and the pin: [SCHEDULE.md](SCHEDULE.md) section 5.

### 4.7 `keys`

```bash
php bin/threadsafe keys generate [--output=FILE]     # a key pair, as the file of age-keygen
php bin/threadsafe keys passphrase [--output=FILE]   # a passphrase, e.g. kr7m-xq3d-hp9t-w4nc-2vfe
php bin/threadsafe keys check <file.age> --identity-file=FILE | --passphrase-file=FILE
```

Encrypted backups use the format of [age](https://age-encryption.org/) (section 5, "Encrypted backups"); the whole story - keys, passphrases, what is and is not protected, how to open a file with the real tool - is [ENCRYPTION.md](ENCRYPTION.md). `keys generate` shows a key file once: the secret key (`AGE-SECRET-KEY-1...`) and the public key (`age1...`); the public key goes into the profile (`profile edit <id> --recipient=age1...`), the secret key is for you to keep **somewhere other than this server** (a password manager, a printout). **Without it the backups made for that public key cannot be opened by anyone.** With `--output` the key file is written with mode 0600 and the secret key is not shown; an existing file is never overwritten. The real `age` tool opens a backup with that file (`age -d -i key.txt backup.zip.age`). `keys passphrase` makes a passphrase of five groups of four characters without look-alikes (about 99 bits); a passphrase is protected by scrypt with a work factor of 2^15, eight times less than the age tool's own, so a short passphrase that a person made up is weak and a generated one is not. A passphrase and public keys cannot be combined in one file (the format forbids it): a backup is for the passphrase or for the keys. `keys check` decrypts the first block of a file to show that a key or passphrase fits (the passphrase comes from a file or from `THREADSAFE_PASSPHRASE`, never from an option).

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

**The sidecar** (`<id>.json`) is the manifest plus what cannot be inside the part it describes: size, SHA-256 and entry count of every part, the status, a list of transfers to other places (empty; later phases fill it), and two optional marks: `"pinned": true` (retention never deletes the backup; set with `backups --pin`, lost when the sidecar is made again from the archive) and `"source": "manual"` or `"schedule"` (who asked for the backup; absent in backups made before phase 5). It is a convenience for listing; the archives are the truth.

### Encrypted backups

```
<profile>_<YYYYMMDD>-<HHMMSS>_<random8>.zip.age        one encrypted archive, or
<profile>_<YYYYMMDD>-<HHMMSS>_<random8>-p01.zip.age    the encrypted parts of a split archive
<profile>_<YYYYMMDD>-<HHMMSS>_<random8>.json           the minimal sidecar file
```

Each file is a standard `age` file: `age -d -i key.txt -o part.zip part.zip.age` opens it with the real tool (for a passphrase `age -d` asks for it), and the result is the ZIP part described above. Inside the encrypted file nothing differs from a plain backup, the manifest included. **Next to it the storage holds nothing that names the site:** the sidecar of an encrypted backup is the *minimal* one - time, application and version, the counts, the parts (sealed names, size, SHA-256), the status, `"encryption": {"format": "age", "recipients": ["age1xxxxxxxx...yyyyyy"], "passphrase": false}` (public keys in a short form, to tell the keys apart) - and no domain, no path, no table name, no filter, no database facts, and its warnings reduced to how many there were. The profile id stands in for the name. The complete manifest is in the last part.

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
- Restoring into another **table prefix** is not a rename of files: the statements contain the real table names. Restore with the same prefix, or use `threadsafe restore --prefix=...` / `threadsafe-restore.php` ([RESTORE.md](RESTORE.md)).
- Files restored this way belong to the user who extracted them; give them back to the web server's user. Directories left out by the filters (`var/cache` ...) are rebuilt by PrestaShop or by `threadsafe migrate`.
- **The copy still has the old domain, paths and database credentials.** `threadsafe migrate prestashop9 --root=/var/www/shop --to=NEW.DOMAIN ...` turns it into a working shop (domains, `parameters.php`, caches, `.htaccess`, self-test): see [MIGRATE.md](MIGRATE.md). For a restore on the same domain use it without `--to`.

## 9. Limits and what is not there

- **No transfer to other places** (remote storage is not built; see "later" in the plan of [CONCEPT.md](CONCEPT.md)); the `transfers` list of the sidecar is reserved for it. **Restoring an encrypted backup** takes the secret key or the passphrase ([RESTORE.md](RESTORE.md) section 12); the real `age -d` opens the parts as well.
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

**Done once (2026-10-30, by the maintainer, with 0.9.0-beta.1 on a shared host).** A real PrestaShop 9 shop: 67,231 files in 11,655 directories, 318 tables with 272,812 rows. Backed up from the web interface: 710 MB, 506 seconds, `verify` passed; the same backup from the shell took 42 seconds. Restored on another site: the shop, its back office and the pages looked right, nothing was missing. The backup tool used before (Akeeba Solo, JPA format) made about 2 GB of the same shop; the difference was **not analysed** (the filters of `backup-defaults.json` leave out caches, logs, sessions and the contents of the statistics tables, which is the likely part of it). Findings of that run: a web step was the whole backup because the host says `max_execution_time` 50000 (fixed, [PHASE7.md](PHASE7.md) section 8, finding 6), and the browser run was twelve times slower than the shell run. With 0.9.0-beta.2 (steps of at most 20 seconds) the same backup took 47 seconds in the browser, so the slow part was the one long request, not the work. No customer data went into the repository.

**A second real site (2026-11-01, the maintainer, with 0.9.0-beta.4):** a backup of 5.4 GB, beyond the 4 GiB that the classic ZIP format holds, so the first archive with real ZIP64 records (before that only the encoders were tested, with simulated sizes). `verify` (the full check) passed, and the real `unzip -t` read it. Its size is close to that of the Akeeba backup of the same site, which Akeeba splits into three files; the content looked right. So the gap in size on the shop above is not general: it comes from what the profile leaves out of that shop (caches, logs). No customer data went into the repository.
