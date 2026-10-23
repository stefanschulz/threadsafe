# Phase 6 – the website type Joomla

**Status:** **plan** (2026-10-23; nothing is built; the open decisions in section 7.2 are for the owner).
**Context:** [CONCEPT.md](CONCEPT.md) section 3.4 (website types: "new types arise by extending `generic` with detection, filter defaults and restore steps") and section 4 (the PrestaShop restore, the model for what a type adapts after a restore); [BACKUP.md](BACKUP.md), [RESTORE.md](RESTORE.md), [MIGRATE.md](MIGRATE.md) and [WEB.md](WEB.md) describe what this phase extends; `plugins/generic` (the smallest type) and `plugins/prestashop9` (the largest) are the two examples in the code. The facts about Joomla below come from the Joomla project's documentation and from hosting guides (section "Sources"); every one that the plan relies on and that has not been seen in a real installation is marked **(verify)** and is checked in work package 1.

## 1. Goal and scope

A site profile can say **`joomla`**: ThreadSafe then recognises a Joomla installation, reads the database access from its `configuration.php` without starting Joomla, leaves out what Joomla rebuilds (caches, sessions, logs, the search index), writes what a restore needs to know into the manifest, and **after a restore or a migration** makes the site work on its new domain, path, database and directory - the way it does for PrestaShop 9. Backups of a Joomla site are standard archives like every other (files plus one SQL file per table); nothing about the format changes.

| In scope | Out of scope (later) |
|---|---|
| detection of Joomla 4.4, 5.x and 6.x from the files; the database access from `configuration.php` (read, never executed); the database types `mysqli` and `pdomysql` (MariaDB and MySQL) | Joomla 3 and older (end of life, and they do not run on the PHP 8.4 that ThreadSafe needs); a Joomla with `dbtype` `pgsql` (refused with a clear message) |
| default filters: caches, temporary files, logs, sessions, the Smart Search index, the archives of other backup extensions; the manifest with version, table prefix, address, extensions, templates, languages | rules per extension (like the module rules of PrestaShop: extensions that store the domain in files or in their own tables); Joomla multi-site set-ups; the `Joomla Update` through ThreadSafe |
| restore and `threadsafe migrate joomla` on another domain, in another directory or sub-directory, with another database or table prefix: `configuration.php`, `.htaccess`, the old address in the content of the database, caches, sessions, a self-test | restoring over an existing installation (a general gap, [RESTORE.md](RESTORE.md) section 9) |
| the type in the web interface (profile form, the restore wizard of `restore.php`), the command line and the documentation | WordPress (a type of its own, a later phase; this phase shows what a type needs) |
| a **dev mode** for a live -> dev copy, in a small first cut (section 3.6) | the full set of dev-mode rules |

Phase 6 ends with the **proof on the Docker stack** (a real Joomla, backed up, restored on another domain and path with the plain tools, with `threadsafe restore` and with the `restore.php` wizard under 5 seconds and 64 MB, on MariaDB 10.11, MySQL 8.4 and MariaDB 11.8) and with a **field test** by the owner on a real Joomla site (the same way as the private test with the live PrestaShop shop, [BACKUP.md](BACKUP.md) section 10).

## 2. Deliverables

1. `plugins/joomla/` with `plugin.json` (id `joomla`, title "Joomla") and the classes of the three capabilities (`Backupable`, `Restorable`, `WebsiteType`), registered like the other two types; the web interface, the commands and `restore.php` find it without a change of the core (`restore.php` bundles every plugin).
2. A **reader and a rewriter for `configuration.php`**: read by a tokenizer, never executed, with the same care as `ParametersParser` of PrestaShop; written back changing only the values that the restore sets and keeping every other byte (the file holds secrets, and sites add their own properties).
3. The migration steps of the type (read the state, write the configuration, replace the old address in the database, rewrite `.htaccess`, discard caches and sessions, self-test) and the options of `migrate joomla`.
4. The Docker stack for Joomla (a source site, the empty target that the existing target stack provides) and a seed with problem cases; integration tests in groups; the matrix on the three database servers.
5. Unit tests for everything that needs no database: the configuration reader and rewriter, detection, filters, the address detection, the `.htaccess` rewrite, the domain replacement on Joomla's JSON.
6. `docs/JOOMLA.md` (the user documentation: what is backed up and left out, what a restore adapts, what stays manual, the limits), the changes to `README.md`, `AGENTS.md`, `WEB.md`, `RESTORE.md` and this plan's "As built" section.

## 3. Design

### 3.1 What a Joomla installation looks like (to verify in work package 1)

| Fact | What the type does with it |
|---|---|
| **Versions.** Joomla 4 reached its end of life on 2025-10-14; Joomla 5 gets security fixes until 2027-10-12; Joomla 6.0 came out on 2025-10-14 and needs PHP 8.3, MariaDB 10.4 or MySQL 8.0.13 at least (dates from secondary sources, **verify** on the project's lifecycle page). | Detect 4.4, 5.x and 6.x as one type, `joomla` (the layout of `configuration.php` and of the tables is the same across them; the version goes into the manifest, not into the id). `restoreChecks` refuse a version outside this range, and refuse a target PHP or database server below what the backed-up Joomla needs. Joomla 4 is end of life but still common in the field: it is supported on a best-effort basis (decision 1). |
| **Version** is in `libraries/src/Version.php` (`MAJOR_VERSION`, `MINOR_VERSION`, `PATCH_VERSION`, `EXTRA_VERSION`) (**verify**). | `detect()`: read the constants from the file by a tokenizer (never include the file), together with the presence of `configuration.php`, `administrator/index.php`, `libraries/`. |
| **`configuration.php`** is a PHP class `JConfig` with one `public $name = 'value';` per setting: `dbtype` (`mysqli`, `pdomysql`, `pgsql`), `host` (with the port as `host:3306`, or a socket), `user`, `password`, `db`, `dbprefix`, `live_site` (usually empty), `secret`, `log_path`, `tmp_path`, `cache_path`, `sef`, `sef_rewrite`, `offline`, `mailonline`, `smtppass` ... (**verify** the exact set for 4.4, 5 and 6). | The database access comes from here. The file holds **secrets** (database and SMTP passwords, `secret`): they never go into a log, a report, the manifest or a page, like `parameters.php` of PrestaShop. |
| **The absolute paths** `log_path`, `tmp_path` (and `cache_path` where set) are written at the installation and name the directories of the old host. | A restore on another path sets them to the new ones (otherwise Joomla complains that `tmp` is not writable). |
| **The address of the site** is stored nowhere as a single value: `live_site` is mostly empty, and Joomla takes the address from the request. The old address is in the **content** (articles, modules, menus, template styles, the parameters of extensions - mostly JSON, so `https:\/\/` with escaped slashes). | This is the one design question of the type: where does the "old domain" of a restore come from? See 3.4 and decision 2. The replacement itself is what `generic` already does for every text column (serialized PHP and JSON stay valid). |
| **`.htaccess`** is the file `htaccess.txt` of Joomla copied under that name; `RewriteBase` is usually commented out; sites on Apache with search-engine friendly addresses depend on it. `web.config` is its IIS twin. | Rewrite `RewriteBase` and the base path when the site moves to a sub-directory or out of one; leave the rest; never touch `web.config` (out of scope, reported). |
| **Volatile data:** the directories `cache`, `administrator/cache`, `tmp`, `administrator/logs` (and `logs`); the tables `#__session`, `#__user_keys`, `#__updates`, the Smart Search index (`#__finder_*`, large), `#__action_logs` (the user actions log, can be large), the scheduler and mail logs (**verify** the names in 4.4, 5 and 6). | Default filters (3.3). Akeeba Backup, the most used Joomla backup extension, leaves out the sessions table and the Smart Search term and taxonomy tables by default for the same reason. |
| **Other backup extensions keep their archives inside the site** (for example `administrator/components/com_akeebabackup/backup`). | Left out by default: a backup must not contain the backups. |
| **A `configuration.php` that is read-only** (mode 0444, set by many hosts) cannot be rewritten. | The rewriter makes the file writable for the write and puts the mode back; if that fails the restore says so and does not leave a half-adapted site. |

### 3.2 The reader and the rewriter of `configuration.php`

`JoomlaConfigFile` (the reader) tokenizes the file: it finds the class `JConfig` and takes every `public $name = <literal>;` where the literal is a single- or double-quoted string without interpolation, a number, a boolean, `null` or an array of those. Anything else (a constant, a function call, a heredoc) is reported as "not understood", not guessed; the properties the type does not need may stay unparsed. It never `include`s the file.

`host` has three shapes: `name`, `name:port`, and a socket (`:/path/to/socket` or `localhost:/path`); an IPv6 address in brackets. The first two map to the `host` and `port` of `DatabaseSettings`; **a socket is not supported yet** and is reported with the setting to change (risk 5). `dbtype` `mysqli` and `pdomysql` map to the PHP interface setting (`auto` is the profile default); `pgsql` is refused.

`JoomlaConfigRewriter` (the writer) replaces the **literal of named properties** in place and leaves everything else byte for byte, including the line ending style and the comments; a property that does not exist is added before the closing brace of the class. It is used only by the restore (3.5).

### 3.3 What a backup leaves out

In `plugins/joomla/resources/backup-defaults.json`, in the style of the PrestaShop file (paths relative to the site root, tables without the prefix):

- **paths:** `cache`, `administrator/cache`, `tmp`, `administrator/logs`, `logs`, `.git`, and the output directories of other backup extensions (`administrator/components/com_akeebabackup/backup`, `administrator/components/com_akeeba/backup`); `tmp` and the cache directories are **kept as empty directories** (the existing filters keep a directory entry; **verify** that `tmp` must exist after a restore: Joomla does not create it);
- **structure only:** `session`, `user_keys`, `updates`, `finder_*` (the search index; `Smart Search` rebuilds it), and - decision 5 - `action_logs`.

The profile can add to both lists or switch the defaults off, as for every type.

### 3.4 The manifest, and where the old address comes from

`manifestExtras()` writes, without ever failing when the database is missing: the version, the table prefix (not the password), the database type, `live_site`, the site name, the language list, the templates (name, whether site or administrator) and the **extensions** (type, element, version, enabled; third party ones are what a restorer wants to know about), and a list of **address candidates**.

The old address is found in this order (decision 2): (1) `live_site`, if set; (2) the address the owner typed in the profile (a new optional field of the type, asked at "Look at the site" and shown with the candidates); (3) the **candidates found in the content** at backup time: the hosts that occur in absolute URLs in the content columns (`#__content`, `#__modules`, `#__menu`, `#__template_styles`, `#__extensions`) counted by frequency, so that the most frequent one is proposed and the others are shown. The candidates are a proposal: the restore form asks for the old domain, pre-filled, and the replacement runs only when it is filled. Nothing is replaced on a guess.

### 3.5 The restore and the migration

The adaptation after the files are in place and the database is imported is a migration (as for PrestaShop: the type's steps run from `restore` as well as from `migrate joomla`). Steps, in order, each one a `Step` with a report line:

1. **ReadJoomlaState:** `configuration.php` read, version, prefix, `live_site`, the old address (from the request or the manifest), the database reachable, the prefix present in the database (tables named `<prefix>extensions` ...). A prefix that does not match the tables is an error, not a guess.
2. **WriteConfiguration:** `host`, `user`, `password`, `db` (and `dbprefix` when the restore changes it) from the request; `log_path`, `tmp_path`, `cache_path` set to the new directories when they differ; `live_site` rewritten only if it was set; `cookie_domain` and `cookie_path` only if set; **`secret` is kept** (it signs sessions and tokens; changing it logs everybody out and is not needed). With a table prefix change (an option, as in [RESTORE.md](RESTORE.md)) the prefix is set in the same step.
3. **ReplaceContentDomains:** the generic replacement of the old by the new address in every text column, plus what Joomla needs on top: the forms in JSON with escaped slashes (`https:\/\/old.example`), with and without the scheme, and the path change when the base path differs (`/old-dir/` to `/`); the tables that never get it (sessions, logs) are skipped. A dry run reports the number of rows it would change per table.
4. **RewriteHtaccess:** `RewriteBase` and the base path, as 3.1 says.
5. **DiscardCaches:** empty `cache`, `administrator/cache`, `tmp`; truncate `#__session`; clear `#__updates` (the update information is fetched again).
6. **ApplyDevMode** (only with `--dev`, 3.6).
7. **SelfTest:** the front page answers 200 and carries the Joomla generator tag (unless the site turned it off, then only the status), `/administrator/` answers 200 with its login form, the site is not in offline mode by accident, and the database answers a query on `#__extensions`; reported as ok, warning or failure like the PrestaShop self-test.

`restoreChecks()` (before anything is written): the Joomla version is one of 4.4, 5 or 6; the PHP of the target satisfies the Joomla version (6.x: 8.3 at least); the database server of the target satisfies it (MariaDB 10.4 / MySQL 8.0.13 for 6.x); a backup made on MySQL 8 with the collation `utf8mb4_0900_ai_ci` is refused on MariaDB as everywhere else ([RESTORE.md](RESTORE.md) section 9); the PHP extensions Joomla needs are present on the target (**verify** the list: `json`, `simplexml`, `dom`, `zlib`, `mbstring`, a database driver, `gd` or `imagick` as a warning).

Fields of the restore form (`Restorable::fields`): the old address (pre-filled from the manifest), the new address, the base path (sub-directory), and the dev-mode switch; the values every restore needs (target directory, database) are the core's.

### 3.6 Dev mode, in a small first cut

For a live -> dev copy: `offline` stays as it is; `robots` set to `noindex, nofollow`; outgoing mail switched off (`mailonline` false) so that a copy does not send mail to customers; the site name gets a visible mark ("[DEV]"); an `.htaccess` block that sends `X-Robots-Tag: noindex`. Everything the PrestaShop dev mode does with module rules (payment, analytics, cron) is extension knowledge and stays **out** until the rules for extensions exist.

### 3.7 The interface and the command line

- `profile add --type=joomla` (type list from the registry: nothing to change); "Look at the site" shows "Joomla 5.2.3 found in /path", the database access read from `configuration.php` (no password), what is left out by default and how many tables are structure only, and the address candidates.
- `migrate joomla` with the common options of the command and the type's own (`--base-path`, `--dev`); `restore` and the `restore.php` wizard ask the fields of 3.5; `docs/JOOMLA.md` says what each one does.
- Nothing in `src/Admin` changes except what the registry gives for free; the profile card shows the title "Joomla" and the application "Joomla 5.2.3" from the manifest.

## 4. Test strategy

### 4.1 Unit tests (no database, no network)

- **The configuration reader:** real `configuration.php` files from fresh installations of 4.4, 5.x and 6.x (values replaced by test values, kept in `tests/fixtures/joomla/`), a file with extra properties, with double-quoted strings, with comments, with CRLF, with `host` in all three shapes, with `pgsql`; a file that does not parse is reported, never executed (a fixture that would write a marker file when included proves it).
- **The rewriter:** the same files with each property changed; the bytes outside the literal are identical; a read-only file; a property that is missing is added.
- **Detection:** trees built in a temporary directory (a `Version.php` with the constants, without `configuration.php` (not installed), Joomla 3 (refused), WordPress (no)).
- **Filters and manifest extras** against a fake `Connection`; the address candidates on text with escaped slashes, sub-directories, `www.` and without it.
- **The `.htaccess` rewrite** on the file Joomla ships and on sites' variants; **the domain replacement** on Joomla's JSON (the generic step's tests already cover serialized data).
- **The checks** of the restore against a manifest of each version, a target PHP and a target database that are too old.

### 4.2 Integration tests (Docker)

A **Joomla service** in `.docker/docker-compose.yml` (the official `joomla` image with Apache and PHP 8.4, one tag per supported major; the exact tags are fixed in work package 1), on the existing `db` service with a database of its own, installed through Joomla's own command line installer (`php installation/joomla.php install ...`, **verify** the options of 5 and 6), then seeded with `tests/fixtures/seed-joomla-problem-cases.sql`: absolute URLs in articles (plain, with `www.`, as `<img>` and `<a>`), in module and template-style parameters (JSON with escaped slashes), in menu links and redirects; a foreign domain and an e-mail address that must **not** be replaced; some sessions and action-log rows; a table of an "extension" with another prefix; a second language. The empty target is the one the PrestaShop stack already has (Apache and PHP in an isolated network; **verify** that its PHP has what Joomla needs).

Groups, run by `tests/stack/run.sh` as new phases and by `matrix.sh` on the three servers:

1. **joomla-backup:** the profile is detected from the container's files, the backup of the seeded site, `verify`, the archive opened with `unzip -t`, the SQL file imported with the plain `mariadb` client and the tables compared with `CHECKSUM TABLE`; what is left out is absent, the structure-only tables are empty, `configuration.php` is in the archive and its password is not in any log or report.
2. **joomla-restore:** the backup restored by `threadsafe restore` into the empty target on another domain, in a sub-directory, with another database and another prefix: the site answers, the administrator logs in (the seeded administrator's password is a test value), an article shows with its images, the old address is nowhere in the content, the foreign domain is untouched, `secret` is unchanged.
3. **joomla-wizard:** the same through the `restore.php` wizard under 5 s and 64 MB (the stack's `web` service), with the key typed in for an encrypted backup.
4. **joomla-disaster:** the target destroyed and rebuilt, the site brought back through the wizard on the same address.
5. **The web interface:** the type in the profile form and the card, in the existing `web-ui` group.

### 4.3 The field test

By the owner, on a real Joomla site: a backup, a restore on another domain on a test host, a look at the front page and the administration, and the list of extensions that did something unexpected (it becomes the first entries of the extension rules of a later phase).

## 5. Work packages

| # | Package | Result | Size |
|---|---|---|---|
| 1 | **Spikes.** A Joomla 5 and a Joomla 6 installed in Docker through the command line installer; the real `configuration.php` and `Version.php`, the table list with sizes of a fresh and of a used site, what `tmp` and the cache directories hold and whether Joomla creates them, the `.htaccess`, what breaks when the site is moved (a moved copy without any adaptation), the options of the installer; the exact tags; the extension list of the PHP the target needs. Findings go into section 8. | section 8 filled; decisions 1-6 confirmed or changed; the fixtures of 4.1 | M |
| 2 | **The configuration reader and rewriter** (3.2) with their unit tests. | `JoomlaConfigFile`, `JoomlaConfigRewriter` | M |
| 3 | **The plugin, the backup side:** `plugin.json`, `JoomlaType`, detection, `databaseSettings`, the default filters (3.3), `manifestExtras` with the address candidates (3.4); the type in `profile add`, "Look at the site", the profile form. | a Joomla site can be backed up and checked from the command line and the browser | M |
| 4 | **The restore side:** `Restorable` (fields, settings, source domain, checks) and the request builder; the steps `ReadJoomlaState`, `WriteConfiguration`, `ReplaceContentDomains` (Joomla forms), `RewriteHtaccess`, `DiscardCaches`, `SelfTest` (3.5); `migrate joomla`. | a restored copy of a site works on its new address | L |
| 5 | **The Docker stack and the integration tests** (4.2): the service, the seed, the groups 1-3 and 5, in `run.sh` and `matrix.sh`. | the proof of the plan, on the three servers | L |
| 6 | **Dev mode, first cut** (3.6) and the disaster recovery group. | `--dev`, group 4 | S |
| 7 | **Documentation:** `docs/JOOMLA.md`, `README.md`, `AGENTS.md`, `WEB.md`, `RESTORE.md`, the "As built" section here. | | S |
| 8 | **Field test** by the owner (4.3) and what it finds. | the open list for the extension rules | - |

Packages 2 and 3 can follow 1 in either order; 4 needs both; 5 needs 3 and 4 (and starts, like the PrestaShop stack did, with the source service so that the tests of 3 and 4 have a real site early).

## 6. Acceptance criteria

1. A Joomla 4.4, 5 and 6 site is **detected** from its files, with the version; a Joomla 3, a WordPress and an empty directory are not.
2. The database access is read from `configuration.php` **without the file being run**; a socket, `pgsql` and an unreadable file are reported with what to do; no password appears in a log, a report, the manifest, the web interface or the command line.
3. A backup **leaves out** the caches, `tmp`, logs, sessions, the search index and the archives of other backup extensions, and keeps the empty directories; the manifest names the version, prefix, extensions and the address candidates.
4. A restore **on another domain, in a sub-directory, with another database and another prefix** gives a site whose front page and administration work, whose content has no trace of the old address (the foreign domain and the e-mail address untouched), whose `secret` is unchanged and whose `.htaccess` and absolute paths are right; the self-test says ok.
5. The same holds through the **`restore.php` wizard under 5 s and 64 MB** and after a **disaster recovery** onto an empty host, on MariaDB 10.11, MySQL 8.4 and MariaDB 11.8 (and a MySQL 8 backup onto MariaDB is refused as everywhere).
6. A restore that cannot succeed says so **before anything is written** (Joomla version unsupported, PHP or database server of the target too old, a prefix that does not match the tables).
7. `composer check` and the CI are green; the unit tests need no database; `docs/JOOMLA.md` is true.

## 7. Risks and decisions

### 7.1 Risks

1. **The old address is not stored anywhere.** The detection by frequency in the content can propose a wrong host (a CDN, a social network). Mitigation: it is a proposal, shown with the other candidates and the counts, replaced only when the owner confirms it.
2. **A custom or very old `configuration.php`** (constants, computed values, an include). The reader reports what it does not understand; the profile can name the database by hand, as for any type.
3. **Extensions that keep the address in files or in their own tables in forms the generic replacement cannot know** (page builders with their own serialization, SEF extensions with large URL tables, caches in the database). Known, out of scope; the field test lists them; the "as built" of work package 4 says what was tested.
4. **Large tables of sessions, logs and the search index** make dumps slow and big; they are structure only by default. The numbers come from work package 1.
5. **Database sockets** (`localhost:/path`) are common on shared hosts. `DatabaseSettings` knows host and port only. If the field test shows that sockets matter, a socket field is added to `DatabaseSettings` (a change of the core and of the profile format, so it is not part of this plan).
6. **MySQL 8 collations onto MariaDB** (`utf8mb4_0900_ai_ci`): the known gap of the restore; Joomla 5 and 6 use `utf8mb4`, so it hits Joomla sites as much as PrestaShop sites.
7. **Joomla's own behaviour after a move** that the plan does not foresee (a stored absolute path in a table, a cached URL). The spikes of work package 1 move a copy without adaptation to see what breaks.

### 7.2 Decisions for the owner

| # | Question | Recommendation |
|---|---|---|
| 1 | Which versions? | Joomla 5 and 6 fully, **4.4 best effort** (end of life since 2025-10-14, but common). One id `joomla` for all; the version is data. |
| 2 | Where does the old address come from? | `live_site`, then the owner's entry in the profile, then the candidates from the content (3.4), replaced only on confirmation. |
| 3 | Dev mode in this phase? | A small first cut (3.6); extension rules later. |
| 4 | The id and the title | `joomla`, "Joomla" (no version in the id: it would have to change with each major version, and profile files name the id). |
| 5 | `action_logs` structure only by default? | Yes for the sessions, the search index and the update cache; for `action_logs` **ask**: it is an audit trail some sites must keep. Recommendation: kept (data), documented as a candidate for the filter list. |
| 6 | PostgreSQL | Out (refused with a message). |
| 7 | Order of work | After the owner's field test of the current phases, so that findings from it come first. The packages 1-3 are small and can start earlier. |

## 8. Findings of work package 1 (spikes)

*To be written by work package 1.*

## 9. As built

*To be written as the work packages are finished.*

## Sources

- Joomla version lifecycle and requirements (secondary sources; **verify** on the project's pages): [Joomla lifecycle status](https://endoflife.ai/joomla), [Joomla 5](https://endoflife.ai/joomla/5), [Joomla 6 technical requirements](https://mysites.guru/blog/joomla-6-technical-requirements/).
- `configuration.php`: [A Guided Tour of Joomla's configuration.php File](https://www.joomlashack.com/blog/joomla/guided-tour-your-joomla-configurationphp-file/), [the Joomla 4 configuration.php file](https://www.inmotionhosting.com/support/edu/joomla/joomla-4/configuration-php.md).
- Moving a Joomla site (the settings to change, `.htaccess`, the addresses in the database): [InMotion Hosting](https://www.inmotionhosting.com/support/edu/joomla/changing-url-joomla-website/), [Loopia](https://support.loopia.com/wiki/migrate-joomla/).
- What other backup tools leave out: Akeeba Backup's [database tables exclusion](https://www.akeeba.com/documentation/akeeba-backup-joomla/database-tables-exclusion.html) and its note on the [Smart Search term and taxonomy tables](https://www.akeeba.com/support/akeeba-backup/23083:skip-finder-terms-and-taxonomy-tables.html).
