# Docker test stacks

Integration tests run against two PrestaShop 9 stacks defined in `.docker/docker-compose.yml`:

| Stack | Services | Purpose |
|---|---|---|
| **source** | `db`, `prestashop`, `mailpit` | A freshly installed shop at `localhost:8190`, seeded with problem cases |
| **target** | `target-db`, `target-prestashop` | Apache + PHP only, in an **isolated network**. Receives a clone of the source and is then migrated (`localhost:8290`) |

The target has its own network on purpose: the cloned `parameters.php` still says "database host: `db`", and inside
the target network `db` does not exist. A clone can therefore never read or write the source database by accident,
and before the migration it is broken in exactly the way a restored copy is (HTTP 500).

## Run everything

```bash
tests/stack/run.sh            # seed → clone → oracle → PHPUnit "integration" suite (about a minute)
```

Single steps:

| Script | What it does |
|---|---|
| `seed.sh` | Idempotent. Installs the fixture module `threadsafe_fixture`, loads `tests/fixtures/seed-problem-cases.sql` (CMS text in many URL notations, a foreign domain, a cache table entry, 200,000 log rows) and writes a `php.ini` and a sitemap with host-bound values |
| `clone.sh` | Wipes the target, copies files (without `var/cache` and `cache/smarty`, like a backup) and the database from the source, checks isolation |
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
- The end-to-end migration test is `tests/Integration/MigrationTest.php`; it is a placeholder until
  `bin/threadsafe migrate` exists.
- Other variants (SSL on, other table prefix, other PrestaShop version): change `PS_TAG`, `PS_ENABLE_SSL`,
  `DB_PREFIX` in `.docker/.env` and recreate the source stack with `docker compose down -v`.
