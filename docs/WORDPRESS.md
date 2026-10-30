# WordPress

How ThreadSafe backs up and restores a **WordPress** site (6.0 and later), what it adapts when the site moves, what it leaves out and what it does not do. The commands are the same as for every site type ([BACKUP.md](BACKUP.md), [RESTORE.md](RESTORE.md), [MIGRATE.md](MIGRATE.md) section 14); this page is what is special about WordPress. The design and the findings are in [PHASE9.md](PHASE9.md).

"Type" here means a *website type of ThreadSafe*: ThreadSafe runs outside your site and reads its files and database. Nothing is installed in WordPress (the one exception is the small file of a dev copy, section 6).

## 1. Quick start

```bash
# 1. a profile; the database access is read from the site's own wp-config.php (the profile stores no password)
php bin/threadsafe profile add blog --type=wordpress --path=/var/www/blog --storage=/var/backups/threadsafe/blog
php bin/threadsafe profile test blog     # what a backup would run into

# 2. a backup
php bin/threadsafe backup blog

# 3. a restore on another host: into an empty directory and an empty database, with another table prefix if you like
php bin/threadsafe restore /var/backups/threadsafe/blog/blog_20261029-031500_ab12cd34.zip --root=/var/www/staging \
    --to=staging.example.org --db-name=staging --db-user=staging --db-password-file=/root/staging.pw --prefix=wps_ --yes
```

The old address is read from the backup (the options `home` and `siteurl` of the database), so `--to` alone is enough; `--from` is only for the rare case that the backup does not say.

On a host where ThreadSafe is not installed, use the single file `threadsafe-restore.php` ([RESTORE.md](RESTORE.md)): it asks for the same things in a browser. Everything on this page about the restore holds for the command and the wizard alike. In the web interface, *Profiles* → *New profile* → type WordPress and **Look at the site** shows what was found ([WEB.md](WEB.md)).

## 2. Which WordPress

- **WordPress 6.0 and later.** An older one is not detected as this type (the test of the profile says the directory is not a WordPress it can handle; it can still be backed up as "Other website", the type `generic`), and a backup that holds one is refused by the restore. Between 6.0 and 6.6 the restore warns that the version works with deprecation notices on PHP 8.4.
- A site is **detected** by `wp-includes/version.php` (read as text, never run), `wp-load.php`, `wp-admin/` and a `wp-config.php`. The directory you give is the root of the site.
- **`wp-config.php` one directory above the site** (a common way to keep it out of the document root) is found by the rule of WordPress itself and read. The backup archives the site directory and nothing above it, so such a file is **not part of the backup**: the restore makes a new one from `wp-config-sample.php` with the database you give and **new keys and salts** (every login of the old site ends). The check before the restore says so.
- **Multisite is not supported.** It is detected (`MULTISITE` in `wp-config.php`, or a table `<prefix>blogs`) and refused with a message; back such a network up as "Other website" for a plain copy of files and database that you restore by hand.
- **MariaDB and MySQL**, through the same drivers as for the other types. A `DB_HOST` that is a socket path, and a `wp-config.php` that computes its database settings (`getenv()`, `$_SERVER`; the official Docker image does) are refused with the name of the setting: ThreadSafe reads the file as text and never runs it. Give the database in the profile by hand then.
- The **restored** site needs `mysqli` in the PHP of its web server, because WordPress itself uses nothing else. ThreadSafe restores files and database with `pdo_mysql` as well, but the site will not run on such a host; the check says so (a warning, because the PHP of a command line is not always the PHP of the web server). The checks also warn about missing `json`, `xml` and `mbstring`.

## 3. What a backup holds, and what it leaves out

A backup is the usual ZIP archive: `files/...` (the whole site), `db/...` (one SQL file per table), `threadsafe.json` (the manifest). The database is dumped as a snapshot.

**Left out by default** (the profile can add to both lists or switch the defaults off, see [BACKUP.md](BACKUP.md)):

| What | Why |
|---|---|
| `/wp-content/cache`, `/wp-content/upgrade`, `/wp-content/wflogs`, `/wp-content/et-cache`, `/wp-content/litespeed` (those of the **site**, written with a leading slash so that a directory called `cache` inside a plugin or a theme, which is code, stays in), `.git` | WordPress and its plugins rebuild them; the restore makes `uploads`, `upgrade` and `cache` again, empty |
| the archives of other backup plugins: `/wp-content/ai1wm-backups`, `/wp-content/updraft`, `/wp-content/backups-dup-pro`, `/wp-content/backups-dup-lite`, `/wp-content/backupwordpress-*`, `/wp-snapshots` | otherwise every backup would hold the one before |
| `/wp-content/debug.log`, `error_log` (at any depth), `/.maintenance` | logs; and a backup made during an update would restore a site that says "briefly unavailable" |
| the **rows** of `woocommerce_sessions`, `actionscheduler_logs`, `wfhits`, `wflivetraffichuman`, `redirection_logs`, `redirection_404` | sessions and logs; the tables themselves are in the backup, empty |

The list is short on purpose; what is not on it is kept. The **transients** (cached values in the options table) are in the backup and are removed by the restore.

What the manifest records besides the usual: the WordPress version and schema number, the table prefix, **`siteurl` and `home` as the database has them**, the site name, the active plugins and the theme, the permalink structure, the language, the **drop-ins** and must-use plugins that are present, whether `WP_HOME` / `WP_SITEURL` are set in `wp-config.php`, the settings that hold a path under the site directory, and a note if the configuration holds computed settings. It never holds a password or a salt. The archive itself does: `wp-config.php` has the database password and the keys, so treat a backup like the site's credentials ([ENCRYPTION.md](ENCRYPTION.md) shows how to encrypt it).

## 4. The old address: WordPress stores it

Unlike Joomla, WordPress knows its address: the options `siteurl` and `home`. The backup reads both, and the restore proposes the host of `home` as the old domain (the field "Old domain" in the wizard). Nothing is guessed. If the two options differ (WordPress in its own directory) both are kept and the check says so.

`WP_HOME` and `WP_SITEURL` in `wp-config.php` **override** the database; if the file has them, the restore moves them to the new address too, so a stale constant cannot keep the site on the old one. If the file does not have them, they are not added.

## 5. The restore

Fields of the restore, beyond the usual (target directory, database, table prefix):

| Field / option | Meaning |
|---|---|
| `--from` / "Old domain" | the domain the backed-up site had; proposed from the backup, normally left as it is |
| `--to` / "New domain" | the domain the restored site is reached under, e.g. `staging.example.org`. The same domain again: a disaster recovery, nothing is replaced |
| `--path` / "Base path" | where in the new domain the site lives, e.g. `/sub/`; empty keeps it. Needs a site that lived at the **root** of its old domain (section 9) |
| `--prefix` | another table prefix for the restored site (the restore renames the tables while it imports) |
| `--mode` / "Mode" | `production` (default) or `dev`, section 6 |
| `--keep-dropins` / "Cache drop-ins" | `keep` leaves `object-cache.php`, `advanced-cache.php` and `db.php` as they are; the default (`off`) switches them off, section 7 |
| `--include-guid` | also replaces the old domain in `wp_posts.guid`; the default leaves it alone, section 8 |
| `--server-path` | the site's directory as the web server sees it, if that differs from `--root` (ThreadSafe in another container) |

**What is adapted**, in this order (the list is the same for `migrate wordpress` on a copy you made yourself; every change goes into the report and the journal):

1. `ensure-configuration`: only for a site whose `wp-config.php` lay above the site: one is made from `wp-config-sample.php` (section 2).
2. `read-source-state`: checks that this is a WordPress 6.0 or later, **not a multisite**, whose database answers and has the table `<prefix>options` (otherwise: wrong prefix). Reads `siteurl` and `home`. Changes nothing.
3. `ensure-skeleton`: `wp-content/uploads` and `wp-content/upgrade` are made where they are missing.
4. `write-configuration`: in `wp-config.php` the database (host and port, name, user, password) and `$table_prefix`; `WP_HOME` / `WP_SITEURL` where the file has them; every setting that holds an absolute path through `wp-content`, `wp-includes` or `wp-admin` (`WPCACHEHOME` of WP Super Cache, `WP_CONTENT_DIR`) is put under the directory the site has now. Nothing is added, **the keys and salts stay** (so the logins of the site stay valid), every other byte of the file stays.
5. `replace-content-domains`: the old domain becomes the new one in every text column of the database, with serialized PHP values kept valid (their lengths are recalculated), JSON kept in its escaped style (`http:\/\/...`, as in the attributes of blocks and in page builders), and the URL-encoded form handled; except sessions, logs and `guid`. Other domains, sub-domains of the old one, e-mail addresses, relative links and the bare host name in a sentence stay as they are.
6. `fix-table-prefix`: only when the prefix changes: the option `<old>user_roles` and every `usermeta` key that begins with the old prefix (`<old>capabilities`, `<old>user_level`, ...) are moved to the new prefix. Without it every user would lose the rights and the administrator could not log in.
7. `rewrite-htaccess`: only when a base path is given: `RewriteBase` and the last rule `RewriteRule . /sub/index.php [L]` inside the block `# BEGIN WordPress` ... `# END WordPress`. The rules of other plugins around the block stay and are reported if they name the old path. A site without an `.htaccess` is left without one.
8. `discard-caches`: the transients, the option `rewrite_rules` (WordPress makes it again), the files of `wp-content/cache` and `upgrade`, **what plugins generate from the content** (section 7a) and the drop-ins (section 7).
9. `apply-dev-mode`: only in dev mode (section 6); in production it only warns if the site still carries the file of a dev copy.
10. `self-test`: asks the new site for its front page, `/wp-login.php` (a login form) and the REST index `/?rest_route=/` (JSON whose `home` is the new address); each must answer `200` and carry no link to the old domain. A copy that was not adapted answers `301` to the old address, which is the failure this finds. `--self-test-url` names the address to ask when the restored site is not reachable under its new name yet; `--no-self-test` switches it off. It also deals with the **maintenance mode** of section 10: it waits while WordPress shows "Briefly unavailable for scheduled maintenance" and lets the cron finish what the first requests started, so the restore ends - and the wizard offers the buttons that open the site and the administration - only when the site really serves.

The result is a site that opens in a browser and in whose administration you log in with the old accounts. The restore does not touch the old site, the old database or the DNS of the old domain.

## 6. Dev mode: a live site as a development copy

`--mode=dev` (the field "Mode" in the wizard) turns the copy into one that cannot mail customers and stays out of search engines. It is a small first cut:

- the option `blog_public` is set to 0 ("discourage search engines"): WordPress then adds the robots meta tag and answers `/robots.txt` with `Disallow: /` itself (no `robots.txt` file is written, so one the site has is not hidden);
- a block at the top of `.htaccess` (between `# BEGIN ThreadSafe dev mode` and `# END ThreadSafe dev mode`; made again on every run, never twice; the rules of WordPress stay behind it) sends `X-Robots-Tag: noindex, nofollow` with every response. It needs `mod_headers`; if the host has none, the other measures still apply. An `.htaccess` is made if the site has none;
- the **must-use plugin** `wp-content/mu-plugins/threadsafe-dev.php` stops outgoing mail: `wp_mail()` reports success and sends nothing, and the administration shows "This is a development copy". WordPress has no setting for outgoing mail, and a must-use plugin needs no knowledge of the mail plugins that send through `wp_mail()`. Delete the file to send mail again.

**What dev mode does not do.** A plugin that sends mail through a service of its own (an SMTP plugin with an API, a newsletter plugin), talks to a payment provider, a webhook or an analytics account, or runs scheduled jobs keeps doing so; this needs rules for single plugins, which do not exist yet. With **WooCommerce** active the plan says so in words: payment gateways in live mode are not switched off, and neither are its **webhooks** (the table `wc_webhooks` keeps the address of the other service, here a CRM, and the status `active`: the next order, product or customer event on the copy posts to it). Treat a dev copy of a shop as dangerous until you have looked through the active plugins in the manifest. The data of real people is in the copy as well, and WordPress's own cron (`wp-cron`) keeps running.

A backup of a dev copy holds the must-use plugin. When that backup is restored in production mode, the plan warns about the file; delete it if this is the live site.

## 7. The drop-ins

`wp-content/object-cache.php`, `advanced-cache.php` and `db.php` are *drop-ins*: WordPress loads them under exactly these names, and a caching plugin writes them. One that serves something the old host had (Redis, Memcached, a page cache with its own files, a database proxy) breaks **every page** of the restored site with an error. So by default the restore **renames them to `<name>.threadsafe-off`**; the content stays, the report says so, and the plugin that owns the file can write it again (a caching plugin is switched on again in its own settings). `--keep-dropins` (in the wizard: "Cache drop-ins" → keep) leaves them as they are, for a target that has the same services.

## 7a. What plugins generate from the content

Some plugins write files from the content of the site and do not write them again while a record says that they exist. **Elementor** writes the CSS of every page into `wp-content/uploads/elementor/css/` with the **address of its images** in it, and remembers that in the post meta `_elementor_css`; a restore on another domain would keep the old address there (the replacement of the old address works in the database, not in files), and the page would ask for its background image from the old site. So the restore empties that directory and deletes `_elementor_css` and the option `_elementor_global_css`, which is what Elementor's own *Regenerate CSS* does; Elementor makes the files again, with the new address, the first time a page is seen. The page itself (`_elementor_data`) is content and is replaced like any other.

What is dropped is a **list in a data file**, `plugins/wordpress/resources/generated.json` (directories, post meta keys, options), and holds only what was seen on a real site. A plugin that is not on it and writes the address into its own files is the kind of thing the field test is meant to find ([PHASE9.md](PHASE9.md)).

## 8. The table prefix and `guid`

- **Another prefix** (`--prefix=wps_`): the tables are renamed while the dump is imported, `$table_prefix` is written, and step 6 moves the two kinds of rows that are keyed by the prefix. It is refused if the backup holds views or triggers or a foreign key ([RESTORE.md](RESTORE.md) section 7); WordPress itself has none.
- **`guid`** of a post is an *identifier*, not a link: WordPress documents that it must not change when a site moves, and feed readers use it to tell a post they know from a new one. So the restore leaves `wp_posts.guid` alone (the real tool `wp search-replace` does the same with `--skip-columns=guid`, which the tests use as the oracle). `--include-guid` replaces the old domain there too.

## 9. Limits and what is known to be missing

- **A base path needs the site at the root of its old domain.** `--path=/sub/` writes `/sub` behind the new host of every address in the content (in the notation that was found), sets `RewriteBase` and the rule, and the site works. A site that lived in a directory (`https://example.org/blog`) cannot be given another base path: the content would need paths changed, which a replacement of hosts cannot do. The restore refuses a base path for such a site ("The site lives in the directory ... of its domain") and the site keeps its directory. Root-relative links in the content (`/wp-content/uploads/x.jpg`) are not rewritten either; WordPress writes absolute addresses, so this is rare.
- **No rules for single plugins.** A plugin that keeps the domain in a file (WP Rocket writes `wp-content/wp-rocket-config/<domain>.php`; page builders cache CSS with absolute addresses), in a form ThreadSafe does not know, or in its own tables as something other than text, is not adapted. The caches are cleared where WordPress and the plugins rebuild them, and the field test is meant to produce the first list. A caching plugin's files named after the old domain stay in `wp-content` until the plugin makes new ones. Only the generated files of section 7a are dropped; other plugins may keep the old address in files of their own.
- **Multisite, WordPress older than 6.0, PostgreSQL-like back ends (`db.php`), a database reached through a socket**: not supported.
- **Restoring over an existing installation** is not possible (the same for every type): the target directory and database must be empty.
- The self-test checks that three pages answer and carry no old address; it does not log in. A site behind a plugin that answers `401` for strangers fails it (the files and the database are restored, the message says which page). A site in maintenance mode is waited for (section 10). ThreadSafe does not switch the automatic updates of the copy off: a restored copy updates itself like any WordPress, which can change its files and database right after the restore.
- The restore does not move the cron jobs of the host (a real cron that calls `wp-cron.php`), the settings of the web server (PHP version, rewrite rules outside `.htaccess`, limits) or the DNS.

## 10. Maintenance mode right after a restore

The first request to a restored site starts the cron of WordPress, which runs what is overdue on a copy: the check for updates and, if the site allows them, the **automatic updates** of WordPress, plugins and themes. While an update is installed WordPress writes a file `.maintenance` into the site directory and answers **every** request - front end and administration - with `503` and *"Briefly unavailable for scheduled maintenance. Check back in a minute."*; when the update is done the file goes. So a site that was fine a moment ago can look broken for a short time (it was seen on the first field test). The backup does not hold `.maintenance`.

The self-test of the restore therefore

- **waits** while it gets that page (a `503` with the words, or with a `Retry-After` header, so a `maintenance.php` of the site is known too), asking again every two seconds for at most three minutes, and says in the report how long it waited; after three minutes it fails with the message of section 11 - the files and the database are restored, and the file is still there;
- after the three pages answered, **waits for the cron to finish** if it was started (the transient `doing_cron` of the options table; it gives the cron five seconds to show itself, and at most two minutes to work), and asks the pages once more.

The buttons that open the site and the login of the administration appear only after this, because the restore is not done before. A restore with `--no-self-test` has no such check; if the site shows the page, wait a minute and reload - or delete `.maintenance` if the file stays.

## 11. When something goes wrong

| Message | What to do |
|---|---|
| `This WordPress is a multisite network, which ThreadSafe does not restore yet.` | Back it up as "Other website" (type `generic`); restore the files and database by hand. |
| `The backup holds WordPress X; only WordPress 6.0 and later can be restored and adapted.` / `Only WordPress 6.0 and later is supported, found version X.` | The backup is of an older version; it can still be unpacked with plain tools ([BACKUP.md](BACKUP.md)) but not restored here. |
| `... does not look like a WordPress installation (wp-includes/version.php not found).` | The directory is not the root of the site, or it is not WordPress. |
| `... has no wp-config.php (in the directory, or in the one above it): WordPress is not installed there.` | The directory holds the files of WordPress that were never installed (only `wp-config-sample.php`), or is the wrong one. |
| `"DB_HOST" is computed by ...` / `Cannot read the database settings of the site: ...` | `wp-config.php` computes its settings (`getenv()` and the like), or `DB_HOST` is a socket. Give the database in the profile (`--db-host`, `--db-name`, `--db-user`, `--db-password-file`). |
| `Table "<prefix>options" does not exist: wrong table prefix, or this is not a WordPress database.` | The prefix does not fit the database; look at `$table_prefix` in `wp-config.php` and the `--prefix` of the command. |
| `The site lives in the directory "/blog/" of its domain; ThreadSafe can move a site into a directory only from the root of a domain ...` | Restore without `--path`; the site keeps its directory. |
| `The site has no wp-config.php: give the database ...` / `... no wp-config-sample.php to make one from` | The site kept its `wp-config.php` above the site directory; give the database (host, name, user, password) so that a new file can be made from `wp-config-sample.php`. |
| `This PHP has no mysqli ...` (warning) | Enable `mysqli` in the PHP of the web server of the new host; the restore continues, the restored site will not run before. |
| `The migrated site does not work: WordPress is still in maintenance mode after 180 seconds ...` | An update that WordPress started by itself may have stopped half way. Delete the file `.maintenance` in the site directory (if the update did not finish, WordPress shows the same page until it is gone or ten minutes old), then `threadsafe restore --resume=<run id>`; or switch the automatic updates off in `wp-config.php` (`define('WP_AUTO_UPDATE_CORE', false);`, `define('AUTOMATIC_UPDATER_DISABLED', true);`) for the copy. |
| `The migrated site does not work (N check(s) failed): front page (/): redirect to another host ...` | The adaptation did not take: the site still sends visitors to the old address. Look for `WP_HOME` / `WP_SITEURL` in a file that `wp-config.php` includes, a redirect in `.htaccess`, or a plugin that redirects. After you removed the cause, `threadsafe restore --resume=<run id>` runs the adaptation again. |
| `... the page has no login form` · `... the answer is not the REST index of WordPress` | A plugin, a security rule or the maintenance mode answers instead of WordPress; look at the page in a browser. |
| `N link(s) in the answer still point to the old domain` | Something outside the database holds the old address: a theme file, a caching plugin's file, a hard-coded URL. |
| Every page shows an error about Redis, Memcached or the database | A drop-in that the new host cannot serve was kept (`--keep-dropins`); rename `wp-content/*.php` of the drop-in, or remove the option. |
| The pages work, but every address except the front page gives `404` | `mod_rewrite` or `AllowOverride All` is missing on the new host (the `.htaccess` of WordPress needs them), or the web server is not Apache and needs its own rule. Saving *Settings → Permalinks* in the administration also writes the rules again. |
| The users cannot log in after a restore with another prefix | The prefix was changed outside ThreadSafe (the rows keyed by the old prefix were not moved): `<old>user_roles` in `options` and the `<old>capabilities` keys in `usermeta` must carry the new prefix. |
| `X-Robots-Tag` is missing on a dev copy | The host has no `mod_headers`; the meta tag and `robots.txt` of WordPress are there anyway. |

## 12. Proof

The Docker stack has three WordPress sites seeded with WP-CLI: **7.1**, **6.9** and **6.0** (`tests/stack/seed-wordpress.sh`: content with the own address in every notation, a foreign domain, a sub-domain, an e-mail address, serialized and JSON values, users with roles, transients, a plugin, the drop-ins). `tests/stack/matrix.sh` runs on MariaDB 10.11, MySQL 8.4 and MariaDB 11.8: the backup of all three sites; the restore of all three by one command onto another domain **with another table prefix**, compared file by file and table by table with the source, with the administrator, an editor and a subscriber logging in and having exactly their rights; a restore without `--yes`; the restore into a sub-directory with the rewritten rules; the restore through the `threadsafe-restore.php` wizard in requests of 5 seconds and 64 MB; a dev copy, checked in the answers of a real Apache and by sending a mail with WP-CLI; a site in **maintenance mode** (a real `.maintenance`, which WordPress answers with `503`) that the self-test waits for until the file is gone; and the **disaster recovery** (a working site is backed up, the target's containers and volumes are destroyed, the wizard brings it back on the same address). The real tool `wp search-replace --dry-run` is the oracle that nothing of the old address is left, in the plain, the escaped and the URL-encoded notation. The oracle was checked against the source site (it finds replacements there, so "none" means something).

What has not been tried: a WordPress backup restored across the two kinds of database server (MySQL → MariaDB); an encrypted WordPress backup in the stack (the encryption is the same code for every type, [ENCRYPTION.md](ENCRYPTION.md)); and above all a site of the real world with its plugins - WooCommerce, caching and page-builder plugins, a security plugin. That is the owner's field test ([PHASE9.md](PHASE9.md) section 4.3); its list of plugins that did something unexpected becomes the first rules for plugins.
