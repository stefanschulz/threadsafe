# Joomla

How ThreadSafe backs up and restores a **Joomla 5 or 6** site, what it adapts when the site moves, what it leaves out and what it does not do. The commands are the same as for every site type ([BACKUP.md](BACKUP.md), [RESTORE.md](RESTORE.md), [MIGRATE.md](MIGRATE.md) section 13); this page is what is special about Joomla. The design and the findings are in [PHASE6.md](PHASE6.md).

## 1. Quick start

```bash
# 1. a profile; the database access is read from the site's own configuration.php (the profile stores no password)
php bin/threadsafe profile add site --type=joomla --path=/var/www/site --storage=/var/backups/threadsafe/site
php bin/threadsafe profile test site     # what a backup would run into

# 2. a backup
php bin/threadsafe backup site

# 3. a restore on another host: into an empty directory and an empty database
php bin/threadsafe restore /var/backups/threadsafe/site/site_20261024-031500_ab12cd34.zip --root=/var/www/new-site \
    --from=www.example.org --to=staging.example.org --db-name=newsite --db-user=newsite --db-password-file=/root/newsite.pw --yes
```

On a host where ThreadSafe is not installed, use the single file `restore.php` (see [RESTORE.md](RESTORE.md)): it asks for the same things in a browser. Everything on this page about the restore holds for the command and the wizard alike.

## 2. Which Joomla

- **Joomla 5 and 6.** Joomla 4 and older are not supported: they are not detected, a profile cannot be made for them, and a backup that holds one is refused by the restore (`only Joomla 5 and 6 can be restored and adapted`).
- A site is **detected** by `libraries/src/Version.php` (read as text, never run), a `configuration.php` and an `administrator` directory. The directory you give is the root of the site: the one that holds `configuration.php`.
- **MariaDB and MySQL**, through the same drivers as for the other types. A database on PostgreSQL, or one reached through a socket (`localhost:/path/to/socket`), is refused with a message.
- A restore needs the PHP extensions that Joomla itself insists on (`json`, `zlib`, `xml`, `mbstring`); the restore warns if the host lacks them, and about `gd`, `iconv`, `zip` and `intl`, which Joomla recommends. It also warns if the database server is older than the minimum of Joomla 6.

## 3. What a backup holds, and what it leaves out

A backup is the usual ZIP archive: `files/...` (the whole site), `db/...` (one SQL file per table), `threadsafe.json` (the manifest). The database is dumped as a snapshot.

**Left out by default** (the profile can add to both lists or switch the defaults off, see [BACKUP.md](BACKUP.md)):

| What | Why |
|---|---|
| the directories `cache`, `administrator/cache`, `tmp`, `administrator/logs`, `logs` and `.git` | Joomla rebuilds them; the restore makes the first four again, empty, with their `index.html` |
| the output directories of other backup extensions (`administrator/components/com_akeebabackup/backup`, `.../com_akeeba/backup`) | otherwise every backup would hold the one before |
| the **rows** of `session`, `user_keys`, `updates` and the big tables of the search index (`finder_links`, `finder_links_terms`, `finder_logging`, `finder_taxonomy_map`, `finder_terms`, `finder_tokens`, `finder_tokens_aggregate`) | sessions and update information are rebuilt; the index is made again by *Smart Search*; the tables themselves are in the backup, empty |

**Kept on purpose:** `action_logs` (the log of the administration), and `finder_taxonomy` with its data: its `ROOT` row is needed, without it the Smart Search indexer stops.

What the manifest records besides the usual: the Joomla version, the table prefix and database type, `live_site`, the site name, the languages and templates, the **extensions that Joomla does not ship** (type, element, version, whether enabled) and the **address candidates** (section 4). It never holds a password. The archive itself does: `configuration.php` has the database password and the `secret`, so treat a backup like the site's credentials ([ENCRYPTION.md](ENCRYPTION.md) shows how to encrypt it).

## 4. The old address: Joomla stores it nowhere

PrestaShop knows its domain; Joomla does not. Its address is spread over absolute links in articles, modules, menus and template styles, and `live_site` in `configuration.php` is empty on most sites. So a restore on another domain needs to be told the **old domain** (`--from`; the field "Old domain" in the wizard). The proposal comes from the backup, in this order:

1. the `live_site` of the site, if it was set;
2. otherwise the host that the content points to most often (counted when the backup was made in the columns where a person writes addresses; the wizard shows the first five with their counts).

It is a **proposal**: a CDN or a social network can be the most frequent host. Look at the list, then correct the field. Nothing is replaced without a new domain: if you give no `--to`, the old domain is left out as well. A profile has no field for the address; give it when you restore.

## 5. The restore

Fields of the restore, beyond the usual (target directory, database):

| Field / option | Meaning |
|---|---|
| `--from` / "Old domain" | the domain the backed-up site had; replaced in the content of the database |
| `--to` / "New domain" | the domain the restored site is reached under, e.g. `staging.example.org`. Empty: the domain stays, only the host-bound settings are adapted (disaster recovery) |
| `--path` / "Base path" | where in the domain the site lives, e.g. `/sub/`; empty keeps it. Sets `RewriteBase` in `.htaccess` |
| `--mode` / "Mode" | `production` (default) or `dev`, section 6 |
| `--db-driver` | `auto` (default), `pdo` or `mysqli`: which PHP interface the **site** will use (section 7) |

**What is adapted**, in this order (the list is the same for `migrate joomla` on a copy you made yourself; every change goes into the report and the journal):

1. `read-source-state`: checks that this is a Joomla 5 or 6 whose database answers and has the tables of the prefix. Changes nothing.
2. `ensure-skeleton`: `tmp`, `cache`, `administrator/cache` and `administrator/logs` are made where they are missing.
3. `write-configuration`: in `configuration.php` the database (host, name, user, password, prefix), `dbtype`, `log_path`, `tmp_path` and `live_site`. Nothing else in the file changes (the `secret`, the mail settings and the rest stay); the file keeps its read-only mode. `live_site` follows the move to the new address; it is **cleared** if it names a host that is not part of the move, because a stale value makes the front page redirect to the old site.
4. `replace-content-domains`: the old domain becomes the new one in every text column of the database (also inside JSON and in the escaped forms Joomla stores), except sessions, the update cache and the big tables of the search index. Other domains, sub-domains of the old one, e-mail addresses and relative links stay as they are.
5. `rewrite-htaccess`: only when a base path is given (`--path`): `RewriteBase`, and the three root-anchored conditions of the API (`/api/`) so that the API also works in a sub-directory. A site without an `.htaccess` (Joomla needs none while its search-engine friendly addresses are off) is left without one.
6. `block-search-engines`: only in dev mode (section 6).
7. `discard-caches`: the files of the caches, the sessions and the update cache.
8. `self-test`: asks the new site for its front page and `/administrator/` (both must answer `200`) unless you switch it off or the host cannot reach itself; `--self-test-url` names the address to ask when the restored site is not reachable under its new name yet.

The result is a site that opens in a browser and in whose administration you can log in with the old accounts. The restore does not touch the old site, the old database or the old domain's DNS.

## 6. Dev mode: a live site as a development copy

`--mode=dev` (the field "Mode" in the wizard) turns the copy into one that cannot reach customers and stays out of search engines. It is a small first cut:

- `mailonline` is switched **off**: the site sends no mail;
- `robots` is `noindex, nofollow` (the robots meta tag of every page);
- the site name gets the mark `[DEV] ` in front, so nobody mistakes the copy for the live site (a second run does not add it twice);
- `robots.txt` is replaced by `Disallow: /`;
- a block at the top of `.htaccess` (between `# BEGIN ThreadSafe dev mode` and `# END ThreadSafe dev mode`) sends the header `X-Robots-Tag: noindex, nofollow`. It needs `mod_headers`; if the host has none, the other three still apply.

`offline` stays as it is. **What dev mode does not do:** an extension that sends mail on its own, talks to a payment provider, a newsletter service or an analytics account, or runs scheduled jobs keeps doing so; this needs rules for single extensions, which do not exist yet. Treat a dev copy of a site with such extensions as dangerous until you have looked through the list of extensions in the manifest. The data of real people is in the copy as well.

## 7. The database driver

A command line and the web server do not always run the same PHP. Joomla's `dbtype` is `mysqli` or `pdomysql`; the restore writes the one that fits **the PHP that will run the site**:

- in the `restore.php` wizard, which runs in the web server's PHP, the default `auto` is right: it takes the interface that PHP has (`mysqli` first);
- with the command, the PHP of the shell decides, which can be wrong: if the shell has both extensions and the web server only `pdo_mysql`, give `--db-driver=pdo` (the other way round `--db-driver=mysqli`).

If the restored site shows a database error that the credentials do not explain, look at `dbtype` in `configuration.php` first.

## 8. Limits and what is known to be missing

- **A base path in the content is not replaced.** `--path=/sub/` sets `RewriteBase` and `live_site`, but root-relative links in articles (`/old-dir/images/x.jpg`) keep pointing to the old directory. Links with a domain are replaced. If the site moves into or out of a directory, look for such links (the search of the administration finds them).
- **No rules for single extensions.** An extension that keeps the address or the path in a file or in a form ThreadSafe does not know (a page builder with its own cache, a CDN setting, a mail or payment extension) is not adapted; the backup and a restore on the same domain are not affected. The first rules will come from the experience of real sites.
- **Joomla 4 and older, PostgreSQL, a database reached through a socket**: not supported.
- **Restoring over an existing installation** is not possible (the same for every type): the target directory and database must be empty.
- The self-test checks that the front page and `/administrator/` answer; it does not log in.
- The restore does not move scheduled tasks of the host (the cron jobs that call Joomla's task scheduler or CLI) or the settings of the web server (PHP version, rewrite rules outside `.htaccess`, `Header` and `php_value` limits); a site that needs a certain PHP version needs it on the new host as well.

## 9. When something goes wrong

| Message | What to do |
|---|---|
| `The backup holds Joomla X; only Joomla 5 and 6 can be restored and adapted.` | The backup is of another version; it can still be unpacked with plain tools ([BACKUP.md](BACKUP.md)) but not restored here. |
| `... does not look like a Joomla 5 or 6 installation (libraries/src/Version.php not found).` | The directory is not the root of the site (the one with `configuration.php`), or it is not Joomla 5 or 6. |
| `... has no configuration.php: Joomla is not installed there.` | The directory is a Joomla that was never installed (only its files), or the wrong one. |
| `Cannot read the database settings of the site: ...` | `configuration.php` is damaged, or its `host` is a socket or `p:name`. Open the file; ThreadSafe reads it as text and never runs it. |
| `Table "<prefix>extensions" does not exist: wrong table prefix, or this is not a Joomla database.` | The prefix does not fit the database; look at `$dbprefix` in `configuration.php` and the `--prefix` of the command. |
| `The PHP extension "..." is missing on this host; Joomla needs it` (warning) | Enable it on the new host; the restore continues, the restored site will not run before. |
| `The setting "..." of configuration.php is not a plain value; it is left as it is.` (warning) | A site that put code in its configuration; check that setting on the new host by hand. |
| `The migrated site does not work (2 check(s) failed): front page (/): HTTP 500; ...` | The files and the database are restored, the adaptation is not proven. Look at the Joomla error log (`administrator/logs`) and the PHP error log; the most frequent causes are `dbtype` (section 7), a PHP version Joomla does not run on, or a missing extension. After you removed the cause, `threadsafe restore --resume=<run id>` runs the adaptation again. |
| The front page redirects to the old domain | The old domain stands somewhere that the restore does not look at (`live_site` is handled): a redirect rule in `.htaccess` of your own, or an extension setting. |
| The pages work but every link except the front page gives `404` | `mod_rewrite` or `AllowOverride All` is missing on the new host (the `.htaccess` of Joomla needs them), or *Search Engine Friendly URLs → Use URL Rewriting* is on without it. |
| `X-Robots-Tag` is missing on a dev copy | The host has no `mod_headers`; `robots.txt` and the meta tag are there anyway. |

## 10. Proof

The Docker stack has a Joomla 5.4 and a Joomla 6 (`tests/stack/seed-joomla.sh`, a seed with articles, menus, a foreign domain, an e-mail address and four-byte characters). `tests/stack/matrix.sh` runs seven tests on MariaDB 10.11, MySQL 8.4 and MariaDB 11.8: the backup of both sites; the restore by one command of both onto another domain, compared table by table with the source; the restore into a sub-directory with the rewritten rules; the restore through the `restore.php` wizard in requests of 5 seconds and 64 MB; and a dev copy, checked in the answers of a real Apache. What has not been tried: a site of the real world with many extensions (the field test, [PHASE6.md](PHASE6.md) section 4.3).
