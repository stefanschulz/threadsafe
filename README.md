# ThreadSafe

A website backup application. One instance manages backups for many sites through site profiles (dashboard, backup overview per site, manual backups, download). Backups can be restored on a different domain or path, with all application-specific adjustments done automatically. First supported application: **PrestaShop 9.x**.

Status: phase 1 (the restore core) is implemented: `threadsafe migrate prestashop9` turns a restored PrestaShop 9 copy into a working site on a new domain, path or database, or back in place, with a dev mode for live → dev copies; `threadsafe migrate generic` replaces the domain in the database of any site. Website types are plugins (`plugins/`). Phase 2 (site profiles and the backup engine) is **done**: the building blocks exist as libraries and are tested - tick engine, streaming ZIP writer and reader, file scanner with filters, database dumper (MariaDB and MySQL), site profiles with a connection test, manifest and local storage - and `threadsafe backup <profile>` makes a verifiable standard ZIP archive (files plus one SQL file per table plus a manifest) of a PrestaShop 9 or any other site; `backups` lists them and `verify` checks that they are intact ([docs/BACKUP.md](docs/BACKUP.md)). There is no restore wizard (phase 3), web interface (phase 4), schedule, retention or encryption (phase 5) yet. There is no restore wizard or web interface yet.

- Working on the project (rules, how to run the checks, map of the code, next steps): [AGENTS.md](AGENTS.md)
- Concept: [docs/CONCEPT.md](docs/CONCEPT.md)
- Phase 1 plan (restore core): [docs/PHASE1.md](docs/PHASE1.md)
- Backups: profile file, commands, archive layout, manifest, taking a backup apart with plain tools: [docs/BACKUP.md](docs/BACKUP.md)
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

ThreadSafe supports **MariaDB** (from 10.2) and **MySQL** (from 5.7); the stack runs on MariaDB 10.11 by default and on MySQL 8.4 with
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
