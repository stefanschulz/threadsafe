# ThreadSafe

A website backup application. One instance manages backups for many sites through site profiles (dashboard, backup overview per site, manual backups, download). Backups can be restored on a different domain or path, with all application-specific adjustments done automatically. First supported application: **PrestaShop 9.x**.

Status: concept and Phase 0 (test environment, domain inventory). No backup or restore code yet.

- Concept: [docs/CONCEPT.md](docs/CONCEPT.md)
- Phase 1 plan (restore core): [docs/PHASE1.md](docs/PHASE1.md)
- Test environment (PrestaShop 9 + MariaDB + Mailpit): [.docker/docker-compose.yml](.docker/docker-compose.yml)
- Phase-0 tool: [tools/domain-inventory.php](tools/domain-inventory.php) – read-only report of where a domain occurs in database and files of a PrestaShop

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
4 self-test failed, 5 internal error. `threadsafe help migrate` lists all options. The journal (default
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
`resources/prestashop9/dev-mode.json`; docs/PHASE1.md 3.9). The copy still holds the customers' personal data - the
run warns about it and about settings that look like credentials no rule handles.

### Rules for third-party modules

ThreadSafe knows nothing about individual modules. Put what you know about a module in `data/rules/<module>.json` (or
another directory given with `--rules-dir`): columns with a known format, settings to keep, files to delete or rewrite,
what the dev mode must switch off. The dry run lists tables with the domain in them that no rule knows ("No module rule
for table ..."). Format and examples: docs/PHASE1.md 3.8 and `resources/prestashop9/module-rule.schema.json`.

## Test environment

```bash
cd .docker
docker compose up -d        # first start installs PrestaShop (a few minutes)
# Shop http://localhost:8190, back office http://localhost:8190/admin-dev, Mailpit http://localhost:8191
```

ThreadSafe targets **MariaDB** only (MySQL is not supported). The PHP driver is still called `pdo_mysql`; it talks to MariaDB.

Run the domain inventory inside the shop container (it has PHP 8.4 and `pdo_mysql`):

```bash
docker cp tools/domain-inventory.php threadsafe-dev-prestashop-1:/tmp/
docker exec threadsafe-dev-prestashop-1 php /tmp/domain-inventory.php --root=/var/www/html --domain=localhost:8190
```

## Development

PHP tooling runs in a container (the host PHP has no database driver). PHP 8.4+, MariaDB only.

```bash
cd .docker
docker compose --profile tools run --rm tools composer install
docker compose --profile tools run --rm tools composer check   # lint, PHPStan (level 8), unit tests
```

Single steps: `composer lint`, `composer stan`, `composer test`. Integration tests (`tests/Integration`) run against Docker stacks: `tests/stack/run.sh` seeds the source shop, clones it into an isolated target stack and runs them (see [tests/stack/README.md](tests/stack/README.md) and [docs/PHASE1.md](docs/PHASE1.md)). All code, comments and documentation are written in English.

## Data protection

Real backups contain customer data. They are used only locally as restore test cases and never enter this repository, logs or documentation (see `.gitignore`).

## License

GPL-3.0-or-later, see [LICENSE](LICENSE).
