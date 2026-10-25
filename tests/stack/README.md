# Docker test stacks

Integration tests run against two PrestaShop 9 stacks defined in `.docker/docker-compose.yml`:

| Stack | Services | Purpose |
|---|---|---|
| **source** | `db` (MariaDB 10.11, MySQL 8.4 or MariaDB 11.8), `prestashop`, `mailpit` | A freshly installed shop at `localhost:8190`, seeded with problem cases and with the live connections of a real shop (`seed-live-links.sql`) |
| **target** | `target-db`, `target-prestashop` | Apache + PHP only, in an **isolated network**. Receives a clone of the source and is then migrated (`localhost:8290`) |
| **web** | `web` (profile `web`) | The web interface of ThreadSafe itself: Apache + PHP 8.4 with limits of 5 seconds and 64 MB, the program directory read-only, the source shop read-only as a site to back up, its own data and storage volumes (the tools container mounts them at `/web-data` and `/web-storage`). Port 80 (`localhost:8390`) has the document root on `public/`; port 81 (`localhost:8391`) has the whole program directory below the web root, to test the `.htaccess` protection |

The target has its own network on purpose: the cloned `parameters.php` still says "database host: `db`", and inside
the target network `db` does not exist. A clone can therefore never read or write the source database by accident,
and before the migration it is broken in exactly the way a restored copy is (HTTP 500).

## Run everything

```bash
tests/stack/run.sh            # seed → clone → PHPUnit phase 1 (incl. the migration of the clone) → oracle → phase 2 → phase 3 (dev mode) → phase 4
                              # (the backup round trip: back up the source shop, restore it into the wiped target with the plain tools and with
                              # `threadsafe restore`, also into another database with another table prefix) → phase 4b (the web interface, group `web-ui`: setup in the
                              # browser, a backup through the pages in many steps, check, download with resume, a run that is continued, two requests at
                              # once, security, the restore kit from the page restoring that backup onto the target) → phase 4c (group `trigger`: `threadsafe cron`, the address `/cron/<secret>` by ticks and
                              # through a detached worker under 5 s / 64 MB, a killed trigger continued, retention on real backups) → phase 4d (group `encryption`: the shop backed up for a key
                              # pair, opened by the real `age` tool, restored by the command and through the wizard with the key typed in) → phase 5 (disaster recovery: the
                              # target's containers and volumes are destroyed and made again empty; restore.php, served by the target's own
                              # Apache under limits of 5 seconds and 64 MB, brings the shop back)
                              # → phase 6 (group `joomla`: two seeded Joomla sites, Joomla 5.4 and 6, backed up and restored on another domain, into a sub-directory and through
                              # the wizard; `seed-joomla.sh` starts and seeds them, `docs/PHASE6.md` 4.2)
                              # → phase 7 (group `fresh`: the service `fresh`, Apache and PHP 8.4 with nothing on it and limits of 5 s / 64 MB; the releases are built
                              # by `tools/build-release.php`, `install.php` installs through the browser interface, the System page updates, a version that cannot
                              # start is taken back; no database needed, `docs/PHASE7.md` 4.2)
tests/stack/cross.sh          # backups across the two kinds of database server (MySQL 8.4 -> MariaDB and back), needs the source seeded on both
```

## Database servers: MariaDB and MySQL

The stack runs on MariaDB 10.11 by default. The same pipeline runs on MySQL 8.4 with its own volumes:

```bash
DB_IMAGE=mysql:8.4 VOLUME_SUFFIX=_mysql tests/stack/run.sh   # one server
tests/stack/matrix.sh                                        # MariaDB 10.11, then MySQL 8.4, then MariaDB 11.8
```

The first start on a new volume installs PrestaShop (a few minutes; `seed.sh` waits for it). The volumes of the variants
stay, so switching back and forth is fast; the containers are recreated on every switch and both variants use the same
ports.

Single steps:

| Script | What it does |
|---|---|
| `seed.sh` | Idempotent. Installs the fixture module `threadsafe_fixture`, loads `tests/fixtures/seed-problem-cases.sql` (CMS text in many URL notations, a foreign domain, a cache table entry, 200,000 log rows) and writes a `php.ini` and a sitemap with host-bound values |
| `clone.sh` | Wipes the target, copies files (without `var/cache` and `cache/smarty`, like a backup) and the database from the source, checks isolation |
| `cross.sh` | Runs the group `cross-server` twice, with the source stack on one kind of database server and the target's database on the other (`TARGET_DB_IMAGE`, `TARGET_DB_SUFFIX` of the compose file): the shop is backed up, restored with `threadsafe restore`, compared table by table; a MySQL 8 collation is refused on MariaDB |
| `seed-joomla.sh` | Idempotent. Makes the databases `joomla5` and `joomla6` on the `db` service, starts the services `joomla5` (port 8195) and `joomla6` (port 8196; profile `joomla`, installed by the image's own installer on the first start), and loads `tests/fixtures/seed-joomla.sql` (content with the site's own domain in every form, a foreign domain, a sub-domain, an e-mail address, a third-party extension, sessions) and the files that a backup leaves out, `.htaccess` and the search-engine friendly addresses |
| `oracle.sh <source\|target>` | Saves the current `.htaccess` and the one PrestaShop itself would generate (`oracle/htaccess-oracle.php`, `Tools::generateHtaccess`) to `.cache/oracle/` |

The scripts need Docker and Bash (Git Bash on Windows works). The PHPUnit tests run inside the `tools` container,
which sits in both networks, and are skipped unless `THREADSAFE_STACK=1` is set (which `run.sh` does).

## What is seeded

`threadsafe_fixture` stores the shop domain the way real modules do: in a serialized option (with a nested JSON
string and an object), in a JSON option with escaped slashes, as a plain URL, as a bare host name, in a webhook
URL, in two tables (one with a language table) and in a generated file. Look-alikes that must **not** be rewritten
are included: a foreign domain, an e-mail address, a bare host name.

## Extending

- New problem cases: add SQL to `tests/fixtures/seed-problem-cases.sql` or data to the fixture module, remove the
  `THREADSAFE_SEEDED` configuration row (or recreate the source stack) and run `seed.sh` again.
- The end-to-end migration is `tests/Integration/DatabaseMigrationTest.php`: it starts `bin/threadsafe migrate
  prestashop9` as a real process against the clone. `FileMigrationTest` checks the files afterwards, and
  `MigratedSiteTest` (group `after-migration`, run in the second phase) checks the site over HTTP and against the
  `.htaccess` oracle.
- Other variants (SSL on, other table prefix, other PrestaShop version): change `PS_TAG`, `PS_ENABLE_SSL`,
  `DB_PREFIX` in `.docker/.env` and recreate the source stack with `docker compose down -v`.

## Tests that need only a database

Most step tests (`ReadSourceStateTest`, `DiscardCachesTest`, `UpdateConfigurationTest`, `ReplaceContentDomainsTest`,
`StepPipelineTest`) build a small fake PrestaShop (`tests/Support/ScratchShop.php`): a scratch database on the source
database server and a directory tree in the temp folder, both removed after each test. They need the `db` service and
`THREADSAFE_STACK=1`, but not the clone:

```bash
docker compose -f .docker/docker-compose.yml up -d --wait db
docker compose -f .docker/docker-compose.yml --profile tools run --rm -e THREADSAFE_STACK=1 tools vendor/bin/phpunit --testsuite integration --filter ReplaceContentDomainsTest
```

`DatabaseMigrationTest` migrates the real clone (the files of the target stack are mounted into the `tools` container
at `/shops/target`). It changes the target database, so it needs a fresh clone: `run.sh` always clones first.
