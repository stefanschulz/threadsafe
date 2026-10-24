# Restoring a backup – reference

How to put a ThreadSafe backup back: with the single file `restore.php` on a host where nothing is installed, with `threadsafe restore` in a shell, or by hand with ordinary tools. What the restore checks, what it does, what to do when it stops, and what it cannot do. For the design and the reasons see [PHASE3.md](PHASE3.md); for making backups see [BACKUP.md](BACKUP.md); for what happens to the site after the files and the database are back see [MIGRATE.md](MIGRATE.md).

## 1. The rules of a restore

- **A restore goes into an empty directory and an empty database. It never overwrites or deletes an installation.** A directory with files in it, or a database with tables of the same prefix, is refused before a single byte is written. To redo a restore, empty both yourself (section 8).
- The restored site is **adapted** to its new place at the end: new domain, new database credentials, caches, `.htaccess` (for PrestaShop; for **Joomla** `configuration.php`, the old address in the content, `.htaccess` and the directories Joomla writes to, [MIGRATE.md](MIGRATE.md) section 13 and [JOOMLA.md](JOOMLA.md); for `generic` sites only the domain in the database content - see section 7).
- It works in **steps that are saved**, so it fits the time limit of a web request, survives a closed browser and continues after a failure.
- The backup stays as it is. The restore reads it and never changes it.

What the host needs: **PHP 8.4 or newer** with `zlib`, `mbstring` and `json`, and `pdo_mysql` or `mysqli` if the backup holds a database. Nothing else: no Composer, no `exec`, no command line, no `mysql` client.

## 2. Quick start: on a host without ThreadSafe (`restore.php`)

(If you run the web interface, the page of a backup has **How to restore it**: it builds `restore.php` from your installation and makes a new `restore-key.php` for you - [WEB.md](WEB.md). Otherwise build them as below.)

You need three things next to each other in the (empty) web directory of the new site: the tool, a key file and the backup.

```bash
# on a machine with ThreadSafe (once; the same file restores every backup of every website type - keep a copy with your backups)
php tools/build-restore.php                # writes dist/restore.php (about 200 KB)
php tools/build-restore.php --key          # prints a random key and the line for the key file
```

1. Upload `restore.php` and **all parts of the backup** (`shop_20261020-123456_ab12cd34.zip`, or `...-p01.zip`, `-p02.zip` ...; the `.json` file next to them is not needed) into the web directory of the new site.
2. Create `restore-key.php` next to it with the key (at least 8 characters):
   ```php
   <?php return 'the key you chose';
   ```
   It is a PHP file on purpose: asked for over HTTP it prints nothing, where a text file would be served to whoever asks. **Without this file the tool does nothing at all.**
3. Create an empty database and a database user for it, at your host.
4. Open `https://new-site.example/restore.php` and follow the wizard (section 3).
5. At the end press **Delete the tool now**.

If the host runs an older PHP, the tool says so (`This restore tool needs PHP 8.4 or newer; this server runs PHP 7.4.33`) and stops; many hosts let you choose the PHP version in their control panel.

## 3. The wizard

| Step | What you do | What the tool checks |
|---|---|---|
| **Key** | Type the key. | The key is compared in constant time. After three wrong keys within 15 minutes each further attempt has to wait 5 seconds, doubling up to 5 minutes; every failure is logged in `.threadsafe-restore/access.log`. Over plain `http` from another machine you must confirm that the key and later the database password cross the network unencrypted - use `https` where you can. A session ends after two idle hours. |
| **Choose the backup** | Pick the backup found next to the tool, or give the path of any one of its parts. For an **encrypted** backup (`.zip.age`, listed as such) also enter its **secret key** (the line `AGE-SECRET-KEY-1...` of the key file) or the **passphrase** it was made for. | All parts are there and belong together; the manifest is readable. A missing part is named. For an encrypted backup the key must open the last part; if it does not, or PHP has no `sodium` extension, the page says so and stays here. |
| **Requirements** | Read what the server offers. | PHP version and extensions, write permission, free space, `max_execution_time`, `memory_limit`, `open_basedir`. |
| **Where to restore** | The directory (by default the one of the tool), host, port, name, user and password of the **empty** database, the table prefix (by default the one of the backup), and the fields of the website type: for PrestaShop the new domain (required), a new base path, SSL mode (none / login / all), production or dev mode and a mail catcher for dev mode; for `generic` an old and a new domain, both optional. "Test the connection" shows what the database holds. Two options: let placeholder files of the web server (`.htaccess`, `index.html` ...) stay in the directory; check the restored site over HTTP at the end (**off by default**: the new domain often does not reach this server yet). | Nothing yet. |
| **Summary** | Read it. | Everything that can be checked before writing (section 6). Problems are listed with the way out and block the start. **Nothing has been written up to here.** |
| **Restoring** | Press "Restore now" and keep the page open. | The page keeps the restore going, one request per step (60 % of the host's time limit each), with a progress bar. If it stops or fails, a button repeats the failed step. If you close the page, open the tool again and log in: **the restore continues where it was**. |
| **Done** | Read the result and the warnings. Press **Delete the tool now**, and tick the box if the backup files may go too. | The tool deletes `restore.php`, `restore-key.php` and `.threadsafe-restore/` (state, journal, session files). What it cannot delete is listed - delete that by hand, now. |

## 4. The command line (`threadsafe restore`)

```bash
php bin/threadsafe restore /backups/shop_20261020-123456_ab12cd34.zip --root=/var/www/new-shop --to=new.example.org \
    --db-host=localhost --db-name=newshop --db-user=newshop --db-password-file=/root/newshop.pw        # only inspects
php bin/threadsafe restore <the same> --yes                                                            # restores
php bin/threadsafe restore --resume=restore-20261021-104511-1f2e3d4c                                   # after an interruption or a failure
```

The archive is **any one part** of the backup (for an encrypted backup a `.zip.age` file; add `--identity-file=KEYFILE` or `--passphrase-file=FILE`, or set `THREADSAFE_PASSPHRASE`; see section 12). Without `--yes` the command shows what the backup is, what it would do and every check with its result, and changes nothing. The password never goes on the command line: `--db-password-file`, or the environment variable `THREADSAFE_DB_PASSWORD`.

| Option | Meaning |
|---|---|
| `--root=DIR` | Where to restore to (required); created if it does not exist. |
| `--to=DOMAIN`, `--from=DOMAIN`, `--domain=OLD=NEW` | The new domain; the old one defaults to what the backup says. |
| `--db-host`, `--db-port`, `--db-name`, `--db-user`, `--db-password-file`, `--db-driver` | The empty database (the database must exist). `--db-driver` is `auto` (pdo_mysql, else mysqli), `pdo` or `mysqli`. |
| `--prefix=PREFIX` | A table prefix other than the one of the backup (section 7). |
| `--allow-leftovers` | Let web server placeholders (`.htaccess`, `index.html`, `index.htm`, `robots.txt`, `favicon.ico`, `cgi-bin`, `.well-known`) stay in the target directory. |
| `--type=TYPE` | Restore as another website type than the backup says. |
| `--work-dir=DIR` | Where the unpacked SQL files and the journal go (default `<root>/.threadsafe-restore`). |
| `--tick-seconds=N` | Work in steps of N seconds (default: no limit). |
| `--no-self-test`, `--self-test-url=URL`, `--self-test-insecure` | The self-test at the end; see [MIGRATE.md](MIGRATE.md). |
| `--report=FILE` | Write the result as JSON (no passwords in it). |
| the options of `migrate` | `--ssl`, `--path`, `--mode=dev`, `--mail-catcher`, `--rules-dir`, `--skip-tables`, `--replace-bare` ... are passed on to the adaptation. |

**Exit codes:** 0 done · 1 wrong command line · 2 refused (a requirement is not met; nothing was written) · 3 a step failed (the run is kept; the message says how to continue) · 4 restored, but the self-test of the site failed.

The state of a run is a file in the data directory (`runs/`, mode 0600); it holds the database password until the run is done and is deleted when it succeeded.

**The same file works in a shell:** `php restore.php restore <archive> --root=...` does what `threadsafe restore` does, and needs no key (shell access is trusted). On a host where only a CGI build of PHP 8 exists: `php8.4 -q restore.php restore ...`.

## 5. What a restore does

1. **Preflight** – the checks of section 6. Nothing is written.
2. **Extract** – the files into the directory, the SQL files into `.threadsafe-restore/db/`. Every file is checked against its CRC32 while it is written. Names that could write outside the directory (`..`, absolute paths), symbolic links in the way and the tool's own files are never written through. Modes and times of files are restored (not setuid bits); directories get their mode, at least `u+rwx`, and **no modification time**. When the restore runs as root, files get the owner of the target directory.
3. **Import** – the SQL files in order (tables, then views, then triggers) with plain PHP, no `mysql` client. A file begins with `DROP TABLE IF EXISTS`, so repeating a file is safe. The SQL files are deleted when they are imported. The import **stops if the server changes a value instead of storing it** (a cell larger than `max_allowed_packet`, a truncated or out-of-range value) - it never reports success for damaged data.
4. **Adapt** – the steps of the website type, the same as `threadsafe migrate` (domain in the database content with serialized values kept intact, `parameters.php`, `.htaccess`, caches, ...), in steps that fit the time limit, with a journal of every change in `.threadsafe-restore/journal/`.
5. **Finalize** – the unpacked SQL files and the password in the saved state go.

## 6. What is checked before anything is written

| You see | Meaning and what to do |
|---|---|
| `The archive "..." does not exist` · `... is not a part of a ThreadSafe backup` | Wrong file. The name looks like `<profile>_<date>-<time>_<code>.zip` or `...-p01.zip`. |
| `The backup consists of N part(s); missing in "...": ...` · `The last part of the backup (...) cannot be used ... Is a later part missing?` | Upload all parts. |
| `The directory "..." is not empty: a, b, c` | Restore into an empty directory, or empty it yourself. Placeholders of the host (`index.html`, `.htaccess` ...) pass with `--allow-leftovers` / the checkbox. The tool's own files, the backup parts and the protection files of ThreadSafe's storage directory do not count. |
| `Not enough free space in "...": X free, about Y needed` | Files plus the unpacked dump, plus 10 %. |
| `Cannot connect to database ...` | Host, port, name, user, password. **The database must exist; a restore does not create it.** |
| `The database "..." already has tables with the prefix "..."` | Use an empty database, or another prefix, or empty it by hand. |
| `The backup uses the MySQL 8 collation "utf8mb4_0900_ai_ci", which MariaDB does not have` | A backup made on MySQL 8 cannot be restored on MariaDB (section 9). Restore onto MySQL 8. |
| `The table prefix cannot be changed ...: the backup contains views or triggers` | Restore with the prefix of the backup. |
| `The backup holds a cell of 40 MB; the database server takes no more than 16 MB (max_allowed_packet)` | Raise `max_allowed_packet` of the database server (`SET GLOBAL max_allowed_packet = ...`, or `my.cnf`) above the size of the cell, then try again. |
| `The backup was made on mysql, this is MariaDB ...` (warning) | The kinds of server differ; tables and data are portable. |
| `The backup does not contain the function "..."` (warning) | ThreadSafe does not back up stored functions and procedures; create them again by hand. |
| `The backup itself reported: ...` (warning) | A warning of the backup run, e.g. a skipped symbolic link. |
| `The default character set of the database is "utf8mb3"` (warning) | The tables are created with their own character set; no action. |
| `The backup holds PrestaShop X; only PrestaShop 9 can be restored and adapted` | Only PrestaShop 9, Joomla 5 and 6 (`The backup holds Joomla X; only Joomla 5 and 6 ...`) and `generic` exist as website types. |

## 7. Another domain, path, database or table prefix

- **Domain:** `--to` / the field "New domain". The old domain comes from the backup (for PrestaShop the main shop URL; for Joomla, which stores its own address nowhere, the `live_site` of the site or the host that its content points to most often, shown in the field "Old domain" with the others that were found). On the same domain (disaster recovery) give the same domain again; nothing is replaced then.
- **Database:** any empty database; `parameters.php` is written for it.
- **Table prefix:** the SQL files contain the real table names, so the restore rewrites the table name in the statements it knows (`DROP TABLE`, `CREATE TABLE`, `INSERT INTO`, `UPDATE`). That is only safe where no other statement names a table, so a prefix change is **refused if the backup holds views or triggers**, and a `CREATE TABLE` with a foreign key stops it before the first row is written. PrestaShop 9 has none.
- **Dev mode, SSL, base path:** the options of [MIGRATE.md](MIGRATE.md), in the wizard as fields.
- **`generic` sites:** the files and the database come back as they were; with an old and a new domain the domain is replaced in the database content. Configuration files (`wp-config.php` and the like) are **not** changed: do that by hand as in [MIGRATE.md](MIGRATE.md) section 12.

## 8. When something goes wrong

| Situation | What to do |
|---|---|
| The browser was closed or the connection lost | Open `restore.php` again, enter the key: the restore goes on where it was. |
| A step failed (message on the page, or exit code 3) | Remove the cause the message names (disk full, wrong password, a limit) and press "Try the failed step again", or run `threadsafe restore --resume=<run>`. A file that was half extracted or imported starts again from its beginning; nothing is done twice that could do harm. |
| The database was emptied or replaced while the restore waited | The import starts over from the first file by itself. |
| Too many wrong keys | Wait the time the message names. |
| You want to start completely again | Delete the content of the directory (or use another one) and empty the database (or drop and create it), then restore. **There is no rollback by design: the tool deletes nothing that is not its own.** If the tool itself is still there, keep `restore.php`, the key and the backup. |
| The restore stopped at the self-test (exit code 4) | The files and the database are restored; the site does not answer as expected. The message and the report say which page; the journal in `.threadsafe-restore/journal/` lists what the adaptation changed. |

`.threadsafe-restore/` holds the journal (old and new values of everything the adaptation changed, **passwords included**) and, until the end, the state of the run. On the web it is closed (mode 0700, an `.htaccess` that denies everything, an empty `index.html`); with the command line it stays after the restore. **Delete it when you no longer need the journal.**

## 9. Limits and what is not there

- **No restore over an existing installation, no rollback.** Decided for phase 3; the cleanup of leftover tables and files is a later phase.
- **MySQL 8 → MariaDB is refused.** PrestaShop on MySQL 8 creates its tables with `utf8mb4_0900_ai_ci`, which MariaDB does not have. MariaDB → MySQL works, MariaDB → MariaDB and MySQL → MySQL too (tested on MariaDB 10.11, MariaDB 11.8 and MySQL 8.4, each onto itself).
- **A view or trigger with a `DEFINER` that does not exist on the new database server can fail the import** with the server's error (typically 1449, "the user specified as a definer does not exist"); create the user, or restore the SQL by hand with the definer removed. Not tested.
- **One file that inflates slower than a request is long** cannot be restored through the web: a single compressed file is always finished in the request that begins it (the pure PHP reader inflates 15 to 360 MB/s, so only files of hundreds of MB are at risk). The command line has no limit.
- Directory modification times are not restored; stored functions and procedures are not backed up; symbolic links are not backed up.
- The self-test of the wizard cannot be told which address the web server answers on (`--self-test-url` exists only on the command line).
- The state directory is closed by an `.htaccess`, which only Apache and compatible servers read. Where the web server is another one, the mode 0700 and the key protect it; add a rule to your server configuration (`location ~ /\.threadsafe-restore { deny all; }` for nginx).
- **Encrypted backups** are restored (section 12) but need `ext-sodium`, and a backup for a passphrase costs about three seconds of key derivation in each step of a restore, on a small host a noticeable share of a request. A backup that the real `age` tool sealed with its own, larger work factor for a passphrase may need more memory than the wizard has (the message names it): open it with `age -d` or on the command line with a higher `memory_limit`.

## 10. Without any tool

[BACKUP.md](BACKUP.md) section 8 takes a backup apart with `unzip` and the `mysql` client. The SQL files are plain SQL and the archive is a standard ZIP archive; nothing in a backup depends on ThreadSafe to be read.

## 11. Trying it (for developers)

```bash
tests/stack/run.sh        # five phases incl. disaster recovery: the target stack is destroyed, made again empty, and restore.php brings the shop back
tests/stack/cross.sh      # a backup from MySQL onto MariaDB (refused) and from MariaDB onto MySQL
composer check            # lint, PHPStan, unit tests, and the unit tests again against the bundle that restore.php is made of
```

## 12. Encrypted backups

A backup that was made for public keys, or for a passphrase, consists of `<id>.zip.age` (or `<id>-p01.zip.age` ...) files: standard files of the [age](https://age-encryption.org/) format around the ZIP parts ([BACKUP.md](BACKUP.md) section 5). Only the **secret key** that belongs to one of the public keys, or the **passphrase**, opens them - the server that made the backup does not have it, and nobody can make it again.

- **Command line:** `php restore.php restore shop_..._p01.zip.age --root=DIR --identity-file=key.txt ...` (the key file of `keys generate` or `age-keygen`), or `--passphrase-file=FILE` / `THREADSAFE_PASSPHRASE` for a passphrase. The key is never an option value. Without it the command says that the backup is encrypted and stops; with a key that does not fit it says so with the name of the part.
- **Wizard:** the field *Secret key or passphrase* on the page where the backup is chosen. The wizard keeps it in its private session file for the one step, then in the state of the run (mode 0600, like the database password) **until the files are unpacked**, and blanks it there; the connection carries it, so the confirmation of plain `http` applies to it too.
- **How it is read:** the parts are not decrypted to disk. The ZIP reader reads each part through a decrypting stream, one 64 KiB block at a time, every block authenticated: a part that was changed or cut stops the restore with a message instead of producing wrong files. So the free space needed is the same as for a plain backup.
- **The target PHP needs `ext-sodium`** (part of PHP 8.4 builds; the message names the extension when it is missing). Without it, or for a very large passphrase work factor, decrypt the parts on another machine (`age -d -i key.txt -o part.zip part.zip.age`) and restore the `.zip` files.
- A failed run keeps its state, including the key if the extraction had not finished; it is a file of mode 0600 in the state directory, removed by **Delete the tool now** (wizard) or by the command when the run succeeds. After a failure you do not want to continue, delete it (`.threadsafe-restore/`, or `<data>/runs/`).
