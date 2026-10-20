# ThreadSafe

A website backup application. One instance manages backups for many sites through site profiles (dashboard, backup overview per site, manual backups, download). Backups can be restored on a different domain or path, with all application-specific adjustments done automatically. First supported application: **PrestaShop 9.x**.

Status: concept and Phase 0 (test environment, domain inventory). No backup or restore code yet.

- Concept: [docs/CONCEPT.md](docs/CONCEPT.md)
- Phase 1 plan (restore core): [docs/PHASE1.md](docs/PHASE1.md)
- Test environment (PrestaShop 9 + MariaDB + Mailpit): [.docker/docker-compose.yml](.docker/docker-compose.yml)
- Phase-0 tool: [tools/domain-inventory.php](tools/domain-inventory.php) – read-only report of where a domain occurs in database and files of a PrestaShop

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

Single steps: `composer lint`, `composer stan`, `composer test`. Integration tests (`tests/Integration`) need the Docker stacks and are added with the integration harness (see [docs/PHASE1.md](docs/PHASE1.md)). All code, comments and documentation are written in English.

## Data protection

Real backups contain customer data. They are used only locally as restore test cases and never enter this repository, logs or documentation (see `.gitignore`).

## License

GPL-3.0-or-later, see [LICENSE](LICENSE).
