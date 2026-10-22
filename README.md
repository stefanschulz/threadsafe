# ThreadSafe

A website backup application. One instance manages backups for many sites through site profiles (dashboard, backup overview per site, manual backups, download). Backups can be restored on a different domain or path, with all application-specific adjustments done automatically. First supported application: **PrestaShop 9.x**.

Status: phase 1 (the restore core) is implemented: `threadsafe migrate prestashop9` turns a restored PrestaShop 9 copy into a working site on a new domain, path or database, or back in place, with a dev mode for live → dev copies; `threadsafe migrate generic` replaces the domain in the database of any site. Website types are plugins (`plugins/`). Phase 2 (site profiles and the backup engine) is **done**: the building blocks exist as libraries and are tested - tick engine, streaming ZIP writer and reader, file scanner with filters, database dumper (MariaDB and MySQL), site profiles with a connection test, manifest and local storage - and `threadsafe backup <profile>` makes a verifiable standard ZIP archive (files plus one SQL file per table plus a manifest) of a PrestaShop 9 or any other site; `backups` lists them and `verify` checks that they are intact ([docs/BACKUP.md](docs/BACKUP.md)). Phase 3 (restoring a backup) is **done**: `threadsafe restore <archive> --root=DIR` restores a backup into an empty directory and database, and the single file `restore.php` (built by `php tools/build-restore.php`, about 200 KB) does the same behind a web server with a wizard, on a host where nothing else is installed; a destroyed web stack, brought back that way, serves the same shop again (disaster recovery test) - see [docs/RESTORE.md](docs/RESTORE.md). The web interface (phase 4, plan [docs/PHASE4.md](docs/PHASE4.md)) is **under way**: so far the login, the first setup in the browser, the dashboard shell, the pages for the site profiles (create with a look at the site and a test, edit, test, remove) and **backups started and followed in the browser** (in steps, with a progress bar; a closed tab leaves a run that can be continued) exist (`public/index.php`, switched off until `php bin/threadsafe admin password` and `admin enable`, or `data/setup-key.php`); the list of backups per site, **checking that a backup is intact** (in steps, with a progress bar) and deleting one, and **downloading** a backup (with resume and a warning for large files) exist; the dashboard with a card per site (state, newest backup, warnings), a System page (installation check, settings, activity) and the **restore help** (it builds `restore.php` and a new key for you) exist. It is tested end to end against a real Apache with limits of 5 seconds and 64 MB on MariaDB 10.11, MySQL 8.4 and MariaDB 11.8 (`tests/stack/run.sh`, phase 4b); the user documentation (`docs/WEB.md`) is still to be written. Phase 5 (schedule through one trigger, retention, encryption; plan [docs/PHASE5.md](docs/PHASE5.md)) has begun: the spikes are done and a profile can now record a **schedule** (`profile add|edit --schedule="30 3 * * *"`), **retention rules** (`--keep`, `--max-age-days`, `--max-bytes`) and the **public keys** its backups are to be encrypted for (`--recipient`), with the scheduler's arithmetic (cron expressions, daylight saving time, grace and retries) in place. **Scheduled backups now happen**: `threadsafe cron` (a cron job, `-q`) or the address `/cron/<secret>` (`admin cron-secret`; for a host whose cron can only fetch an address, it starts a detached worker process) makes the backups that are due, and the dashboard and the System page show the next run and warn when the trigger stays away ([docs/BACKUP.md](docs/BACKUP.md) 4.5). The retention and the encryption are the next work packages.

- Working on the project (rules, how to run the checks, map of the code, next steps): [AGENTS.md](AGENTS.md)
- Concept: [docs/CONCEPT.md](docs/CONCEPT.md)
- Phase 1 plan (restore core): [docs/PHASE1.md](docs/PHASE1.md)
- Backups: profile file, commands, archive layout, manifest, taking a backup apart with plain tools: [docs/BACKUP.md](docs/BACKUP.md)
- The web interface (installing it, switching it on, using it, security, settings): [docs/WEB.md](docs/WEB.md); how it was built: [docs/PHASE4.md](docs/PHASE4.md)
- Restoring a backup (`restore.php`, `threadsafe restore`): [docs/RESTORE.md](docs/RESTORE.md); the design and what was built: [docs/PHASE3.md](docs/PHASE3.md)
- Phase 2 plan (site profiles and backup engine): [docs/PHASE2.md](docs/PHASE2.md)
- Phase 1b (MariaDB and MySQL, selectable driver, website type plugins): [docs/PHASE1B.md](docs/PHASE1B.md)
- Reference of the `migrate` command (request, report, journal, exit codes, trying it on a real backup): [docs/MIGRATE.md](docs/MIGRATE.md)
- Test environment (PrestaShop 9 + MariaDB or MySQL + Mailpit): [.docker/docker-compose.yml](.docker/docker-compose.yml)
- Phase-0 tool: [tools/domain-inventory.php](tools/domain-inventory.php) – read-only report of where a domain occurs in database and files of a PrestaShop

## Usage: back up a site

A site is described once as a **profile**; the backups of a profile go to its storage directory.

```bash
# the database settings of a PrestaShop are read from its own parameters.php; the profile is tested before it is stored
php bin/threadsafe profile add shop --type=prestashop9 --path=/var/www/shop --storage=/var/backups/threadsafe/shop

# any other site: files plus the database named here (the password from a file, never as an option)
php bin/threadsafe profile add blog --type=generic --path=/var/www/blog --storage=/var/backups/threadsafe/blog     --db-name=blog --db-user=blog --db-password-file=/root/blog.pw

php bin/threadsafe profile test shop    # what a backup would run into: privileges, free space, PHP limits ...
php bin/threadsafe backup shop          # or: backup --all
php bin/threadsafe backups              # what exists, and interrupted runs
php bin/threadsafe verify shop          # is the newest backup intact? (--quick: sizes and SHA-256 only)
```

A backup is `<profile>_<UTC time>_<random>.zip` (or `-p01.zip`, `-p02.zip` ... with `--part-size=2G`) plus a `.json` sidecar
in the storage directory: a standard ZIP archive with `files/...`, `db/0001_<table>.sql ...` and `threadsafe.json`, which
`unzip` and the plain `mysql` / `mariadb` client can take apart. `backup --tick-seconds=N` works in steps that save their
state (`backup --resume=<run id>` continues a run whose process died). Keep the storage directory outside the web root;
archives hold credentials and customer data. Exit codes: 0 ok, 1 usage error, 2 refused (profile test failed, too little
space, another backup running), 3 failed (`verify`: the backup is damaged).

### Restoring a backup

A backup goes back into an **empty directory and an empty database** (a restore never overwrites or deletes an installation):

```bash
php bin/threadsafe restore /var/backups/threadsafe/shop/shop_20261020-123456_ab12cd34.zip --root=/var/www/new-shop \n    --to=new.example.org --db-host=localhost --db-name=newshop --db-user=newshop --db-password-file=/root/newshop.pw     # inspects only
php bin/threadsafe restore <the same> --yes                                                                                # restores
php bin/threadsafe restore --resume=<run id>                                                                               # after an interruption or a failure
```

Without `--yes` it only shows what the backup is and every requirement it checked (free space, empty target, database reachable
and empty, collations, table prefix). With `--yes` it unpacks the files and the SQL files, imports the dump in plain PHP (no
`mysql` client needed; `--prefix=` changes the table prefix), and adapts the site to its new place like `migrate` does (the
options of `migrate` are accepted). It works in saved steps, so a run that stops continues where it was. Everything about it - the
checks, the messages and what to do, what it cannot do - is in [docs/RESTORE.md](docs/RESTORE.md).

**On a host where ThreadSafe is not installed** - the usual case when a site has to be rebuilt in a hurry - use the single file
`restore.php` (about 200 KB, built once, the same for every backup and website type; keep a copy with your backups):

```bash
php tools/build-restore.php            # writes dist/restore.php
php tools/build-restore.php --key      # prints a random key and the line for restore-key.php
```

Upload `restore.php`, a `restore-key.php` (`<?php return 'the key';` - it does nothing without), and all parts of the backup into
the (empty) directory of the site, an empty database ready, and open `restore.php` in the browser: the wizard asks for the key,
the backup, the database and the new address, and restores in steps that fit the time limit of the host (tested with
`max_execution_time=5`). At the end it deletes itself, the key and its state; the backup stays. In a shell the same file is the
command: `php restore.php restore <archive> --root=DIR ...` (no key needed). PHP 8.4 with `zlib`, `mbstring` and `pdo_mysql` or
`mysqli` is all it needs; on an older PHP it says so and stops.

## Usage: the web interface

The same things in a browser - all sites at a glance, profiles, backups with a progress bar, check, download, and the tools to restore - are in the web interface. It is **off until you switch it on**, and its document root is `public/`:

```bash
composer install --no-dev
php bin/threadsafe admin password     # the one administrator
php bin/threadsafe admin enable
```

Then point the web server at `public/` (Apache needs `mod_rewrite`; an nginx rule is in the documentation) and log in, over https. Where there is no shell: put a `data/setup-key.php` (`<?php return 'a secret';`) in place and set the password in the browser. A backup made in the browser is made in steps that fit the PHP time limit, can be continued after a closed page, and reads the database table by table; the command line stays the way for anything that must be exact and automated. Installing, every page, the settings, the security measures and what to do when something does not work: [docs/WEB.md](docs/WEB.md).

## Usage: migrate a restored PrestaShop 9 copy

`threadsafe migrate prestashop9` takes an already restored copy (files in place, database imported) and makes it work
on a new domain, path or database. Without `--yes` it only plans and reports.

```bash
# plan only: shows every change, writes nothing
php bin/threadsafe migrate prestashop9 --root=/var/www/html --to=shop.example     --db-host=127.0.0.1 --db-name=shop --db-user=shop --db-password-file=/root/shop.pw

# apply, with a journal of every changed value and a JSON report
php bin/threadsafe migrate prestashop9 --root=/var/www/html --to=shop.example     --db-host=127.0.0.1 --db-name=shop --db-user=shop --db-password-file=/root/shop.pw     --report=/root/migration.json --yes
```

The old domain is read from the shop's database (or given with `--from`). Without `--to` and without a new domain the
run is a restore in place on the same domain: caches, directories and database credentials are fixed, nothing is
replaced. Exit codes: 0 ok, 1 usage error, 2 refused (wrong shop, unsupported version or database), 3 a step failed,
4 self-test failed, 5 internal error. `threadsafe help migrate` lists all options; the full reference is [docs/MIGRATE.md](docs/MIGRATE.md). The journal (default
`<root>/var/threadsafe-journal`) contains old and new values, including passwords: keep it private.

### Self-test

After a real run the shop is requested over HTTP (home page, category, product, a product image through its friendly URL,
contact, login, back office login). A failure ends the run with exit code 4; the migration stays applied. If the web
server cannot be reached under the shop's domain from where ThreadSafe runs, give its address with
`--self-test-url=http://127.0.0.1:8080` (the shop's domain is sent as Host header). `--no-self-test` switches the check
off, `--no-finalize` the cache clearing by PrestaShop itself.

### Dev mode: a live shop as a dev copy

`--mode=dev` makes sure the copy cannot talk to the live world: mail goes to a mail catcher (or nowhere), PayPal is
switched to sandbox without live credentials, the PrestaShop Account tokens and the Facebook token are removed, modules
that report to the cloud are deactivated and search engines are kept out (`robots.txt`, `X-Robots-Tag`).

```bash
php bin/threadsafe migrate prestashop9 --root=/var/www/html --to=dev.shop.test --mode=dev --mail-catcher=127.0.0.1:1025 --yes
```

Without `--mail-catcher` the shop sends no mail at all. `--dev-skip=RULES` leaves rules out (`mail`, `payment`,
`accounts`, `tracking`, `noindex`), `--dev-rules=FILE` adds your own (the format is in
`plugins/prestashop9/resources/dev-mode.json`; docs/PHASE1.md 3.9). The copy still holds the customers' personal data - the
run warns about it and about settings that look like credentials no rule handles.

### Rules for third-party modules

ThreadSafe knows nothing about individual modules. Put what you know about a module in `data/rules/<module>.json` (or
another directory given with `--rules-dir`): columns with a known format, settings to keep, files to delete or rewrite,
what the dev mode must switch off. The dry run lists tables with the domain in them that no rule knows ("No module rule
for table ..."). Format and examples: docs/PHASE1.md 3.8 and `plugins/prestashop9/resources/module-rule.schema.json`.

## Test environment

```bash
cd .docker
docker compose up -d        # first start installs PrestaShop (a few minutes)
# Shop http://localhost:8190, back office http://localhost:8190/admin-dev, Mailpit http://localhost:8191
```

ThreadSafe supports **MariaDB** (from 10.2) and **MySQL** (from 5.7); the stack runs on MariaDB 10.11 by default (also tested: MySQL 8.4 and MariaDB 11.8) and on MySQL 8.4 with
`DB_IMAGE=mysql:8.4 VOLUME_SUFFIX=_mysql tests/stack/run.sh`. `tests/stack/matrix.sh` runs the whole pipeline on both. The PHP
interface to the database is `pdo_mysql` or `mysqli` (`--db-driver=auto|pdo|mysqli`; auto prefers `pdo_mysql`).

Run the domain inventory inside the shop container (it has PHP 8.4 and `pdo_mysql`):

```bash
docker cp tools/domain-inventory.php threadsafe-dev-prestashop-1:/tmp/
docker exec threadsafe-dev-prestashop-1 php /tmp/domain-inventory.php --root=/var/www/html --domain=localhost:8190
```

## Development

PHP tooling runs in a container (the host PHP has no database driver). PHP 8.4+, MariaDB or MySQL.

```bash
cd .docker
docker compose --profile tools run --rm tools composer install
docker compose --profile tools run --rm tools composer check   # lint, PHPStan (level 8), unit tests
```

Single steps: `composer lint`, `composer stan`, `composer test`. The checks are meant to run in this container (PHP 8.4); a host PHP without the database drivers or of another version gives false failures. Integration tests (`tests/Integration`) run against Docker stacks: `tests/stack/run.sh` seeds the source shop, clones it into an isolated target stack and runs them (see [tests/stack/README.md](tests/stack/README.md) and [docs/PHASE1.md](docs/PHASE1.md)). All code, comments and documentation are written in English.

## Data protection

Real backups contain customer data. They are used only locally as restore test cases and never enter this repository, logs or documentation (see `.gitignore`).

## License

GPL-3.0-or-later, see [LICENSE](LICENSE).
