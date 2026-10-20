# Phase 1b: database drivers and website type plugins

Phase 1 delivered the restore core for PrestaShop 9 on MariaDB ([PHASE1.md](PHASE1.md)). Before the backup engine and the
site profiles of phase 2 are built on it, two couplings are loosened, because they get more expensive with every
feature on top: the database layer knows only one server type and one PHP interface, and the website type
(`prestashop9`) is wired into the command line tool. Decisions of 2026-10-20 (also in [CONCEPT.md](CONCEPT.md), section 10).

## 1. Decisions

| Topic | Decision |
|---|---|
| Database systems | **MariaDB and MySQL.** Nothing else for now (no PostgreSQL, no SQLite). The code stays open for more systems but is not built for them: no general SQL dialect layer, only the few differences between MariaDB and MySQL are handled where they occur. |
| PHP interface | Selectable **per site profile** (and per request): `pdo` (`pdo_mysql`), `mysqli`, or `auto` (default: `pdo_mysql` if loaded, otherwise `mysqli`). Reason: on shared hosting one of the two is sometimes missing. |
| Supported servers | MariaDB ≥ 10.2 and MySQL ≥ 5.7 (the minimums of PrestaShop 9); tested with MariaDB 10.11 and MySQL 8.4. Older servers are refused (exit code 2); newer ones are used. |
| Website types as plugins | A website type becomes a **plugin**: a directory below `plugins/<type>/` with a manifest, loaded by the tool. |
| Plugin trust | Plugins come **only from the author** for now, live in the repository, and are code that runs with the tool's rights. Consequences: no installation from the web interface (that would be remote code execution), no signature or hash checks, no stable plugin API and no developer documentation yet - the interface stays internal and may change. *Module rules* (data in JSON, [PHASE1.md](PHASE1.md) 3.8) stay the safe way for users to extend the tool. |
| Order | First the database layer (the backup engine's dumper depends on it), then the plugin extraction together with the `generic` type (which phase 2 needs anyway, so that the interface is shaped by two real cases). |

## 2. Work package A - database layer for MariaDB and MySQL

**Design.** `Database\Connection` keeps its public methods (the 11 callers do not change) and delegates to a small
`Driver` interface (`select`, `scalar`, `execute`, `lastInsertId`, `begin`/`commit`/`rollBack`, `quoteString`, server
version) with two implementations: `PdoDriver` and `MysqliDriver`. A `ServerInfo` value object parses the version string
(`10.11.18-MariaDB-ubu2204`, `5.5.5-10.11.6-MariaDB` as sent by PDO to MariaDB, `8.4.2`, `5.7.44-log`) into type and version
and decides whether the server is supported.

**Differences to look at** (everything else in the code is plain SQL): the guard in `ReadSourceState` (accepts both
servers), `information_schema` (aliases already lower case, works on both), the `seq_1_to_200000` sequence table of the test
seed (MariaDB only), the `mariadb` client and `mariadb-dump` in the stack scripts, collations (`utf8mb4_0900_*` exists
only in MySQL 8: relevant for the dump of phase 2), and `sql_mode` (strict by default in MySQL 8).

**Settings.** `database.driver` in the request (`--db-driver=auto|pdo|mysqli`); later the same field in the site profile.

**Tests.** Unit: `ServerInfo` parsing and the support rules, driver selection. Integration: one test class run against
both drivers (parameters, NULL, UTF-8, binary data, affected rows, transactions with rollback, errors as exceptions); the
whole stack (`tests/stack/run.sh`) runs once per server type: `DB_IMAGE=mariadb:10.11` (default) and `DB_IMAGE=mysql:8.4`.

**Done** (2026-10-20): `composer check` is green; `run.sh` is green on MariaDB 10.11 and on MySQL 8.4 (145 + 6 + 3 integration tests
each), the first migration run with the default interface and the repeated one with `mysqli`; the documentation says MariaDB and
MySQL. Run both with `tests/stack/matrix.sh`.

**As built.** `Connection` is a facade with the same public methods plus `server` (a `ServerInfo`), `driverName()` and
`serverVersion()`; `PdoDriver` and `MysqliDriver` implement `Driver` and throw the same `DatabaseError`;
`DatabaseSettings::$driver` carries the choice (request setting `database.driver`, option `--db-driver`). The stack scripts call
the client through `dbclient`/`dbdump` (they pick `mariadb` or `mysql`), the Compose file takes `DB_IMAGE` and `VOLUME_SUFFIX`
(separate volumes per server type in the same project, because a second project needs more Docker networks than a crowded host
has address pools for), and the seed makes its 200,000 log rows without MariaDB's sequence engine. Not needed in the end: a
SQL dialect class - on the two servers the code ran unchanged. The collation difference (`utf8mb4_0900_*`) is for the dumper of phase 2.

## 3. Work package B - website types as plugins

**Design.** A plugin is a directory `plugins/<type>/` with `plugin.json` (id, name, version, entry class, PHP
requirements, supported database systems, autoload namespace and directory), its classes and its data (`resources/`, module
rules). A small registry in the core scans `plugins/*/plugin.json` and registers a PSR-4 autoloader per plugin (no Composer
needed on the hosting). The entry class implements an internal `WebsiteType` interface (CONCEPT 3.4): detection, reading the
database credentials, default exclusions, the options of the migration command, building the pipeline.
`migrate <type>` becomes generic; the options the core knows (`--root`, `--db-*`, `--report`, `--journal-dir`, `--yes` ...)
stay, the type adds its own (`--ssl`, `--path`, `--mode` ... for PrestaShop).

**Moves.** `src/PrestaShop9`, the PrestaShop parts of `src/Migration` (`ShopUrlRow`, `SourceState`, `Mode`, `SslMode`) and
`resources/prestashop9` go into `plugins/prestashop9`; the core keeps `Core`, `Replace`, `Database`, `Http`, `Cli`.
The new `plugins/generic` handles any site: detection by choice, database optional, restore = unpack and import.

**Tests.** The existing unit and integration tests move with their code and keep running; new: registry and manifest
validation, a plugin with a broken manifest is refused with a clear message, `migrate` with an unknown type.

**Done when:** `prestashop9` and `generic` load as plugins, the command line has no PrestaShop names left in the core, all
tests pass unchanged in substance.

## 4. Order and sizes

| WP | Content | Size |
|---|---|---|
| A | database layer: drivers, `ServerInfo`, MariaDB and MySQL, test matrix | M |
| B | plugin registry, extraction of `prestashop9`, `generic` type | M |

A first, then B; B can start earlier if the dumper (phase 2) is not yet in sight, since the two do not touch each other.
