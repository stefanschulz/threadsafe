# Phase 9 – the website type WordPress

**Status:** **planned** (2026-10-29, requested by the owner); nothing is built. The decisions of section 7.2 are open.
**Context:** [PHASE6.md](PHASE6.md) (the website type Joomla) is the model for this plan and for the code: the three capabilities (`Backupable`, `Restorable`, `WebsiteType`), a reader and writer for the configuration file, the steps of a restore, the Docker stack and its groups; [CONCEPT.md](CONCEPT.md) section 3.4 says how a type arises ("by extending `generic` with detection, filter defaults and restore steps"); [BACKUP.md](BACKUP.md), [RESTORE.md](RESTORE.md), [MIGRATE.md](MIGRATE.md), [WEB.md](WEB.md) and [JOOMLA.md](JOOMLA.md) describe what this phase extends. `plugins/generic`, `plugins/prestashop9` and `plugins/joomla` are the three examples in the code. The facts about WordPress below come from the project's documentation and from what is generally known about moving WordPress sites; what could be seen in a real installation is checked in work package 1 (section 8), and what is not yet checked is marked.

**What "plugin" means here.** ThreadSafe's website types live in `plugins/<type>/`; this phase adds `plugins/wordpress/`, so that ThreadSafe can back up, restore and migrate WordPress sites. It is **not** a plugin for WordPress (code that runs inside a WordPress site); that would be another product and is out of scope (decision 1).

## 1. Goal and scope

A site profile can say **`wordpress`**: ThreadSafe then recognises a WordPress installation, reads the database access from its `wp-config.php` without running it, leaves out what WordPress and its plugins rebuild (caches, upgrade leftovers, the archives of other backup plugins, logs), writes what a restore needs to know into the manifest, and **after a restore or a migration** makes the site work on its new domain, path, database, table prefix and directory - the way it does for PrestaShop 9 and Joomla. Backups are standard archives like every other (files plus one SQL file per table); nothing about the format changes.

WordPress differs from Joomla in three things that shape this plan: the **address is stored in the database explicitly** (`siteurl` and `home` in the options table; no guessing by frequency, section 3.4), the content is full of **serialized PHP and JSON** (the replace engine of `src/Replace` already handles both, and `generic` already uses it), and a **table prefix is part of the data** (two rows are keyed by it: section 3.5, step 4).

| In scope | Out of scope (later) |
|---|---|
| detection of WordPress 6.x from the files (`wp-includes/version.php`), also when `wp-config.php` lies one directory above the site; the database access from `wp-config.php` (read, never executed): `DB_HOST` as host, host and port; `DB_CHARSET`, `DB_COLLATE`, `$table_prefix` | a WordPress older than 6.0 (decision 4); **multisite** (detected and refused with a clear message, decision 2); sockets (`DB_HOST` as a path) as for every type; PostgreSQL-like back ends (`db.php` drop-ins) |
| default filters: caches, upgrade leftovers, the output of other backup plugins, logs, maintenance file; tables that only hold sessions or logs as structure only; the manifest with version, prefix, address, active plugins, theme, drop-ins | rules per plugin (like the module rules of PrestaShop: plugins that store the address in files or their own tables, payment gateways, mail plugins); anonymisation of customer data |
| restore and `threadsafe migrate wordpress` on another domain, in another directory or sub-directory, with another database or **another table prefix**: `wp-config.php`, `.htaccess`, the old address in the content (options, posts, post meta, widgets, theme mods, page builders), caches, drop-ins, transients, a self-test | restoring over an existing installation (a general gap, [RESTORE.md](RESTORE.md) section 9); changing the WordPress version |
| the type in the web interface (profile form, restore wizard of `threadsafe-restore.php`), the command line and the documentation | a WordPress plugin that runs ThreadSafe inside the site (decision 1) |
| a **dev mode** for a live -> dev copy in a small first cut (section 3.6) | the full set of dev-mode rules |

Phase 9 ends with the **proof on the Docker stack** (a real WordPress, backed up, restored on another domain and path, with another prefix, with the plain tools, with `threadsafe restore` and with the `threadsafe-restore.php` wizard under 5 seconds and 64 MB, on MariaDB 10.11, MySQL 8.4 and MariaDB 11.8, with the real WP-CLI as the oracle that nothing of the old address is left) and with a **field test** by the owner on a real WordPress site.

## 2. Deliverables

1. `plugins/wordpress/` with `plugin.json` (id `wordpress`, title "WordPress") and the classes of the three capabilities, registered like the other types; the web interface, the commands and `threadsafe-restore.php` find it without a change of the core.
2. A **reader and a writer for `wp-config.php`**: read by a tokenizer, never executed; written back changing only the values the restore sets and keeping every other byte (the file holds the salts and sites add their own code).
3. The steps of the type (read the state, write the configuration, replace the old address, fix the table prefix, rewrite `.htaccess`, drop caches and drop-ins, self-test) and the options of `migrate wordpress`.
4. The Docker stack for WordPress (a source site installed through WP-CLI, the target that the existing stack provides), a seed with problem cases, integration tests in groups, the matrix on the three database servers.
5. Unit tests for everything that needs no database; `docs/WORDPRESS.md` (user documentation), the changes to `README.md`, `AGENTS.md`, `WEB.md`, `RESTORE.md`, `MIGRATE.md` and this plan's "As built".

## 3. Design

### 3.1 What a WordPress installation looks like (to verify in work package 1)

- **Files.** `wp-includes/version.php` holds `$wp_version`; `wp-load.php`, `wp-settings.php`, `wp-admin/`, `wp-includes/`, `wp-content/` (`themes`, `plugins`, `mu-plugins`, `uploads`, and drop-ins `advanced-cache.php`, `object-cache.php`, `db.php`). `wp-config.php` is in the root **or one directory above** (WordPress looks there when the root has none and the parent has no `wp-settings.php`): detection looks in both, and the backup stores it either way (the archive keeps its place, so the restore puts it back where it was).
- **`wp-config.php`.** `define('DB_NAME' | 'DB_USER' | 'DB_PASSWORD' | 'DB_HOST' | 'DB_CHARSET' | 'DB_COLLATE', ...)`, `$table_prefix`, the salts, and often more: `WP_HOME` and `WP_SITEURL` (they **override** the database), `WP_CONTENT_DIR`, `WP_CONTENT_URL`, `UPLOADS`, `COOKIE_DOMAIN`, `FORCE_SSL_ADMIN`, `WP_CACHE`, `WP_DEBUG`, `MULTISITE` and its siblings (`DOMAIN_CURRENT_SITE`, `PATH_CURRENT_SITE`). Hosting set-ups also compute values (`getenv()`, `$_SERVER['HTTP_HOST']`, `if` blocks): the reader reports what it does not understand, and the profile can name the database by hand, as for every type.
- **`.htaccess`.** The block between `# BEGIN WordPress` and `# END WordPress` (`RewriteBase /`, `RewriteRule . /index.php [L]`) plus whatever other plugins wrote (caching, security).
- **Database.** Tables `<prefix>options`, `posts`, `postmeta`, `users`, `usermeta`, `terms` ...; the address in `options` (`siteurl`, `home`) and all over the content. Serialized values (`a:2:{s:3:"url";s:20:"https://old.example/";}`) must have their length prefixes recalculated: the replace engine does this with a tokenizer and no `unserialize()` (`SerializedCodec`), and does JSON in the escaped style of the original (`https:\/\/old.example`, as in Gutenberg block comments and Elementor's `_elementor_data`).
- **Not known until work package 1:** which files other than `wp-config.php` hold the domain (caching plugins write it into file names and files: `wp-content/wp-rocket-config/<domain>.php`; page builders cache CSS with absolute URLs), what exactly a moved copy without any adaptation does (redirects to the old address? admin cookie loop?), how big a fresh and a used WooCommerce site are, and what the official image creates at its first start.

### 3.2 The reader and the writer of `wp-config.php`

As for `configuration.php`: a tokenizer, not `include`. It reads `define()` calls with literal arguments and the assignment of `$table_prefix`; a value that is not a literal is reported by name. `DB_HOST` is read in its forms (`localhost`, `host:3306`, `[::1]:3306`); a path (a socket) is reported. The writer changes `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_HOST` and `$table_prefix` from the request, **`WP_HOME` and `WP_SITEURL` only if they are there** (a stale constant keeps the site on the old address), `WP_CONTENT_DIR`, `UPLOADS` and similar absolute paths only if they equal the old directory, `COOKIE_DOMAIN` only if it names the old domain; it never adds constants and never touches the salts. Every other byte stays. The password never appears in a log, a report or the manifest.

### 3.3 What a backup leaves out

In `plugins/wordpress/resources/backup-defaults.json`, in the style of the other types:

- **paths:** `wp-content/cache`, `wp-content/upgrade`, `wp-content/wflogs`, `wp-content/et-cache`, `wp-content/litespeed`, `wp-content/ai1wm-backups`, `wp-content/updraft`, `wp-content/backups-dup-pro`, `wp-content/backupwordpress-*`, `wp-snapshots`, `wp-content/debug.log`, `error_log` files at any depth, `.maintenance` (a backup made during an update would restore a site that says "briefly unavailable"), `.git`. The restore makes `wp-content/uploads`, `wp-content/upgrade` and `wp-content/cache` again where WordPress needs them to be writable (`EnsureSkeleton`);
- **structure only:** tables of sessions and logs, as data in the file: `woocommerce_sessions`, `actionscheduler_logs`, `wfhits`, `wflivetraffichuman`, `redirection_logs`, `statistics_*` and the like. The list is short on purpose and grows with the field test; what is not on it is kept. Transients are rows, not tables: they are removed on restore (3.5, step 5), not left out of the dump.

The profile can add to both lists or switch the defaults off, as for every type.

### 3.4 The manifest, and where the old address comes from

`manifestExtras()` writes, without ever failing when the database is missing: the WordPress version and `$wp_db_version`, the table prefix (not the password), `siteurl` and `home` (**the old address is read from the database at backup time: no candidates, no guessing**, decision 2 of the Joomla plan, the search for candidates, does not arise), the active plugins and the theme (`active_plugins`, `template`, `stylesheet`: read with the serialized tokenizer, nothing is unserialized), the **drop-ins** that are present (`advanced-cache.php`, `object-cache.php`, `db.php`), whether `WP_HOME`/`WP_SITEURL` are set in `wp-config.php`, whether the site is a **multisite**, and whether WooCommerce is active (it changes what a copy must not do: 3.6). If `siteurl` and `home` differ (a WordPress in its own directory), both are kept and the restore asks.

### 3.5 The restore and the migration

The adaptation after the files are in place and the database is imported is a migration (the type's steps run from `restore` as well as from `migrate wordpress`). Steps, in order, each one a `Step` with a report line:

1. **ReadWordPressState:** `wp-config.php` read, version, prefix, `siteurl`/`home` in the database against the manifest, the prefix present in the database (`<prefix>options`, `<prefix>posts`); a prefix that does not match the tables is an error, not a guess; a multisite is an error that says what to do (decision 2).
2. **WriteConfiguration:** the values of 3.2 from the request; the table prefix when the restore changes it.
3. **ReplaceContentDomains:** the generic replacement of the old by the new address in every text column, with serialized and JSON values kept valid and the escaped forms handled (`UrlMatcher`), plus what WordPress needs on top: `siteurl` and `home` set explicitly first (also when the replacement would have found them), the **base path** when it differs, and **`wp_posts.guid` left alone** (the WordPress rule: a GUID is an identifier, not an address; decision 5 makes it an option). Tables of logs and sessions are skipped. A dry run reports the rows per table.
4. **FixTablePrefix** (only when the prefix changes): the option `<old>user_roles` becomes `<new>user_roles`, and in `usermeta` the keys that begin with the old prefix (`<old>capabilities`, `<old>user_level`, `<old>user-settings` ...) get the new one. Without it every user loses the rights and the login of the administrator fails. The core's `Importer` does the renaming of the tables.
5. **DiscardCaches:** remove transients (`_transient_*`, `_site_transient_*` rows of the options table), delete the option `rewrite_rules` (WordPress makes it again from the permalink settings), empty the cache directories, and handle the **drop-ins**: an `object-cache.php` or `advanced-cache.php` that belongs to a service the target may not have (Redis, Memcached, a page cache with its own files) breaks the site at the first request ("Error establishing a Redis connection"): by default they are **renamed to `*.threadsafe-off`** and the report says so (decision 4); caching plugins' files named after the old domain are removed.
6. **RewriteHtaccess:** the WordPress block with the new `RewriteBase` and the new path of `index.php`; other blocks are kept; a path change is reported with the rules it touched.
7. **ApplyDevMode** (only with `--mode=dev`, 3.6).
8. **SelfTest:** the front page answers 200 and `/wp-login.php` carries its login form; the REST index (`/wp-json/`, or `/?rest_route=/` without pretty permalinks) answers JSON whose home is the new address; the head of the front page names the new address and not the old one; `<prefix>options` answers a query; reported as ok, warning or failure like the other self-tests. A site in maintenance mode or behind a plugin that answers 401 for strangers is said, not failed.

`restoreChecks()` (before anything is written): the version is 6.0 or later and **not multisite**; the PHP of the target satisfies it (a WordPress older than 6.7 on PHP 8.4 runs with deprecation notices: a warning that says so); the database server satisfies it; a MySQL 8 backup with `utf8mb4_0900_ai_ci` onto MariaDB is refused as everywhere ([RESTORE.md](RESTORE.md) section 9); the PHP extensions WordPress needs are there (`mysqli` or `pdo_mysql` as an error, because WordPress itself uses `mysqli`: a target PHP with only `pdo_mysql` can restore the files and the database but the site will not run, which the check says; `json`, `xml`, `mbstring`, `curl`, `gd`/`imagick` and `zip` as warnings).

Fields of the restore form (`Restorable::fields`): the old address (pre-filled from the manifest, `siteurl` and `home` when they differ), the new address, the base path, the mode, and whether drop-ins stay; the values every restore needs (target directory, database, prefix) are the core's.

### 3.6 Dev mode, in a small first cut

For a live -> dev copy: the option `blog_public` set to 0 ("discourage search engines") and an `X-Robots-Tag: noindex` block in `.htaccess`; **outgoing mail off**, by a **must-use plugin** that ThreadSafe writes into `wp-content/mu-plugins/threadsafe-dev.php` (a few lines that return early from `wp_mail`, and a visible notice in the administration: this is a copy), because there is no setting for it (decision 6); `WP_ENVIRONMENT_TYPE` is not set (it would be a new constant). A site with **WooCommerce** gets the warning that payment gateways in live mode are not switched off by this phase: that is plugin knowledge and stays out until the rules for plugins exist, but the report says it in words. Everything else of the PrestaShop dev mode is plugin knowledge too.

### 3.7 The interface and the command line

- `profile add --type=wordpress`: "Look at the site" shows "WordPress 6.7.1 found in /path", the database access read from `wp-config.php` (no password), the prefix, `siteurl`/`home`, the active plugins, the drop-ins, what is left out by default, a multisite as a refusal.
- `migrate wordpress` with the common options and the type's own (`--base-path`, `--mode=dev`, `--keep-dropins`, `--include-guid`); `restore` and the `threadsafe-restore.php` wizard ask the fields of 3.5.
- Nothing in `src/Admin` changes except what the registry gives for free: the profile card shows the title "WordPress" and the application "WordPress 6.7.1" from the manifest.

## 4. Test strategy

### 4.1 Unit tests (no database, no network)

- **The reader and writer of `wp-config.php`:** the files of fresh installations of the versions of the stack (values replaced by test values, `tests/fixtures/wordpress/`, made in work package 1) and variants: extra constants, `WP_HOME`/`WP_SITEURL`, a computed value, comments and `?>` at the end, CRLF, `DB_HOST` in all its shapes (socket reported), a file above the root; the writer changes each value and leaves every other byte; a file that would write a marker file when included proves that nothing is run.
- **Detection:** trees in a temporary directory (a `version.php`, with and without `wp-config.php`, config one level above, multisite constants, Joomla and PrestaShop trees are not WordPress, an empty directory).
- **Filters and manifest extras** against a fake `Connection`; the active plugins read from serialized text; the drop-ins; the two addresses.
- **The `.htaccess` rewrite** on the block WordPress writes and on variants (permalinks off, a sub-directory, other plugins' blocks around it).
- **`FixTablePrefix`** on rows of `options` and `usermeta` with the old prefix, with a prefix that contains the other as a part (`wp_` and `wp_2_`), a key that only looks like it; **the transient and drop-in handling**; **the domain replacement** on WordPress's own serialized and JSON shapes (widgets, theme mods, Gutenberg block attributes, Elementor data, `guid` untouched).
- **The checks** of the restore against manifests of each version, multisite, a target PHP and a database that are too old.

### 4.2 Integration tests (Docker)

A **WordPress service** in `.docker/docker-compose.yml` (the official `wordpress` image of the newest 6.x with PHP 8.4 and one older 6.x; the images install nothing until the first request), on the existing `db` service with a database of its own, installed and seeded by **WP-CLI** (the `wordpress:cli` image, used as a tool of the stack and never a dependency of ThreadSafe): `core install`, a few posts, widgets, a nav menu, options with serialized arrays and absolute URLs (plain, escaped, serialized, in block comments), a page-builder-like postmeta in JSON, a user with a role, a transient, an object-cache drop-in, a cache directory. Seeds of problem cases go in `tests/fixtures/`.

Groups, run by `tests/stack/run.sh` as new phases and by `matrix.sh` on the three servers:

1. **wordpress-backup:** the profile is detected from the container's files, the backup of the seeded site, `verify`, the archive opened with `unzip -t`, the SQL imported with the plain `mariadb` client and the tables compared with `CHECKSUM TABLE`; what is left out is absent, the structure-only tables are empty, `wp-config.php` is in the archive and its password is in no log or report.
2. **wordpress-restore:** the backup restored by `threadsafe restore` into the empty target on another domain, in a sub-directory, with another database and **another prefix**: the front page and `wp-login.php` answer, the administrator logs in and has the rights (`FixTablePrefix`), a post shows with its images, **`wp search-replace OLD NEW --dry-run` finds nothing** (the real tool as the oracle, `guid` excluded as the plan says), the foreign domain is untouched, the salts are unchanged, the Redis-style drop-in is off and the site works.
3. **wordpress-wizard:** the same through the `threadsafe-restore.php` wizard under 5 s and 64 MB, with the key typed in for an encrypted backup.
4. **wordpress-disaster:** the target destroyed and rebuilt, the site brought back through the wizard on the same address.
5. **The web interface:** the type in the profile form and the card, in the existing `web-ui` group.

### 4.3 The field test

By the owner, on a real WordPress site (with the plugins it really has): a backup, a restore on another domain on a test host, a look at the front page and the administration, and the list of plugins that did something unexpected (it becomes the first entries of the plugin rules of a later phase, as the list of extensions does for Joomla).

## 5. Work packages

| # | Package | Result | Size |
|---|---|---|---|
| 1 | **Spikes.** A WordPress of two versions installed in Docker through WP-CLI; the real `wp-config.php` and `version.php`, the table list with sizes of a fresh and a used site (with WooCommerce), what the official image creates, what a moved copy without adaptation does, which files hold the domain, the `.htaccess` block, the drop-ins, a multisite's tables, what `wp search-replace` does and skips (the oracle), the PHP extensions the target needs. Findings go into section 8. | section 8 | M |
| 2 | **The reader and writer of `wp-config.php`** (3.2) with their unit tests. | `WordPressConfigFile`, `WordPressConfigParser` | M |
| 3 | **The plugin, the backup side:** `plugin.json`, `WordPressType`, detection (also a config above the root), `databaseSettings`, the default filters (3.3), `manifestExtras` (3.4), the refusal of a multisite; the type in `profile add`, "Look at the site", the profile form. | a WordPress site can be backed up and checked from the command line and the browser | M |
| 4 | **The restore side:** `Restorable` (fields, settings, source domain, checks) and the request builder; the steps of 3.5 (`ReadWordPressState`, `WriteConfiguration`, `ReplaceContentDomains` with the WordPress rules, `FixTablePrefix`, `DiscardCaches`, `RewriteHtaccess`, `SelfTest`); `migrate wordpress`. | a restored copy works on its new address, with another prefix | L |
| 5 | **The Docker stack and the integration tests** (4.2): the service, WP-CLI, the seed, the groups 1-3 and 5, in `run.sh` and `matrix.sh`. | the proof of the plan on the three servers | L |
| 6 | **Dev mode, first cut** (3.6) and the disaster recovery group. | `--mode=dev`, one integration test | S |
| 7 | **Documentation:** `docs/WORDPRESS.md`, `README.md`, `AGENTS.md`, `WEB.md`, `RESTORE.md`, `MIGRATE.md`, "As built". | `docs/WORDPRESS.md` | S |
| 8 | **Field test** by the owner (4.3) and what it finds. | the open list for the plugin rules | - |

Packages 2 and 3 can follow 1 in either order; 4 needs both; 5 needs 3 and 4 and starts, as the Joomla stack did, with the source service so that the tests of 3 and 4 have a real site early.

## 6. Acceptance criteria

1. A WordPress 6.x site is **detected** from its files, with the version, also when `wp-config.php` is one directory above; a Joomla, a PrestaShop, a WordPress older than 6.0 and an empty directory are not (the old ones with a message that says why). A multisite is detected and refused.
2. The database access is read from `wp-config.php` **without the file being run**; a socket, a computed value and an unreadable file are reported with what to do; no password appears in a log, a report, the manifest, the web interface or the command line.
3. A backup **leaves out** the caches, the upgrade directory, the output of other backup plugins, logs and the maintenance file, keeps empty directories, makes sessions and logs structure only; the manifest names the version, prefix, **both addresses from the database**, the active plugins, the theme and the drop-ins.
4. A restore **on another domain, in a sub-directory, with another database and another prefix** gives a site whose front page and administration work, whose administrator has the rights, whose content has no trace of the old address (checked by `wp search-replace --dry-run`, the foreign domain and the e-mail address untouched, `guid` as decided), whose salts are unchanged and whose `.htaccess` and absolute paths are right; the self-test says ok.
5. The same holds through the **`threadsafe-restore.php` wizard under 5 s and 64 MB** and after a **disaster recovery** onto an empty host, on MariaDB 10.11, MySQL 8.4 and MariaDB 11.8 (and a MySQL 8 backup onto MariaDB is refused as everywhere).
6. A restore that cannot succeed says so **before anything is written** (multisite, version unsupported, PHP or database of the target too old, no `mysqli` where WordPress needs it, a prefix that does not match the tables).
7. A drop-in that the target cannot serve is switched off and said, never left to break the site silently.
8. `composer check` and the CI are green; the unit tests need no database; `docs/WORDPRESS.md` is true.

## 7. Risks and decisions

### 7.1 Risks

1. **Serialized and JSON data of plugins** that the replace engine cannot parse (`C:` custom serialization, base64-encoded attributes of some page builders, data in their own tables): the value is reported as unparseable and left alone (the engine's rule). The field test lists them; `wp search-replace` as the oracle of the stack shows what it misses.
2. **`wp-config.php` with computed values** (`getenv`, host-dependent blocks) that the reader cannot write back. Mitigation: it says so, names the value, and the restore stops before writing rather than guessing.
3. **Caching plugins and drop-ins** write the old domain into files and into `wp-config.php` (`WP_CACHE`) and break a copy at the first request. Mitigation: 3.5 step 5 and decision 4; the list of what is known grows with the field test.
4. **A table prefix change** is a source of lost rights; `FixTablePrefix` is small and tested against rows with prefixes that contain each other, but plugins also key data by prefix (rare). Known, documented.
5. **WooCommerce and other shop plugins** hold customer data and live payment settings; a dev copy is dangerous until their rules exist. The dev mode says so in words (3.6); nothing is switched off by guess.
6. **Large `uploads` directories** (many gigabytes, hundreds of thousands of files) are the normal WordPress case: the engine copes (ticks, parts), the numbers come from work package 1 and the field test.
7. **Database sockets** and **`DB_HOST` with a port in the host string** are common on shared hosts; `DatabaseSettings` knows host and port only (the same open question as for Joomla).
8. **WordPress on PHP 8.4** older than 6.7 shows deprecation notices on a target with `display_errors`; a warning in the checks, nothing the restore can fix.
9. **`mysqli` only:** WordPress itself uses `mysqli`; the PrestaShop image of the stack has `pdo_mysql` and no `mysqli` in its web server. The target of the stack for WordPress must have `mysqli` (the official WordPress image does); the check of 3.5 makes the difference visible on a real target.

### 7.2 Decisions for the owner

| # | Question | Recommendation |
|---|---|---|
| 1 | "Plugin for WordPress" means? | A **website type** of ThreadSafe (`plugins/wordpress/`), like Joomla. A plugin that runs inside WordPress (a backup button in the WordPress administration) is another product; if you want it, it is a later phase with its own plan. |
| 2 | Multisite | **Refused** with a message in this phase (the domain tables `blogs`, `site`, `sitemeta` and one set of tables per site need their own rules); a later phase if a site needs it. |
| 3 | Versions | **WordPress 6.0 and later**; older ones are refused for restore (backup still works as a generic backup). One id `wordpress`, the version is data. |
| 4 | Drop-ins the target may not serve (`object-cache.php`, `advanced-cache.php`) | **Switched off by renaming** (`*.threadsafe-off`) by default, with a report line and the option `--keep-dropins`; the alternative, leaving them, breaks a copy on the first request for the most common case (Redis). |
| 5 | `wp_posts.guid` | **Left alone** (the WordPress rule), with the option `--include-guid` for those who want it replaced. |
| 6 | Mail in dev mode | A **must-use plugin** that ThreadSafe writes (3.6). The alternative is no mail switch at all and a warning; the plugin is the only way that works without knowing the mail plugins. |
| 7 | Table prefix change in this phase | **Yes** (`FixTablePrefix`); without it the restore into a database that already holds tables of another WordPress is impossible, and the Joomla type has it. |
| 8 | Order of work | After the owner's field test of the Schedule page and of the Joomla type, so that their findings come first; packages 1 to 3 are small and can start earlier. |
| 9 | Plugin rules | Not in this phase (as for Joomla extensions); the field test produces the first list. |
