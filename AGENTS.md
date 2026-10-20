# Working on ThreadSafe

For anyone - person or coding agent - who continues work on this project without having followed its history. Read this
first, then [docs/CONCEPT.md](docs/CONCEPT.md) (what and why) and the plan of the phase in progress
([docs/PHASE2.md](docs/PHASE2.md)).

## What this is

An open-source (GPL-3.0-or-later) backup and migration tool for PHP web applications, modelled on Akeeba Solo: one
instance backs up many sites ("site profiles"), restores them on another domain, path or database, and adjusts the
application automatically. First target: **PrestaShop 9.x**; MariaDB and MySQL; PHP 8.4+.

## Where the project stands

| Phase | Content | State |
|---|---|---|
| 1, 1b | Restore core: `threadsafe migrate <type>`, dev mode, module rules, self-test; database drivers; website type plugins | done |
| 2 | Site profiles and backup engine (CLI) | **in progress**: work packages 1-10 done, 11 open (table in [docs/PHASE2.md](docs/PHASE2.md) section 5) |
| 3 | `restore.php` (extractor and wizard) | not started |
| 4 | Web interface (dashboard, download) | not started |
| 5 | One trigger cron, retention, encryption | not started |

Each finished work package has an **"As built"** paragraph in `docs/PHASE2.md` (what exists, where it differs from the plan,
what is known to be missing). `docs/PHASE2.md` section 8 holds the findings of the spikes (`tools/spikes/`); they changed the
design more than once, so read them before touching the ZIP writer or the dumper.

## Rules

- **Language:** everything in the repository - documentation, comments, identifiers, CLI output, commit messages - is
  English.
- **No real data in the repository.** A real backup contains customer data. It is used only locally, never enters the
  repository, logs, reports or documentation (CONCEPT section 7). `*.zip`, `*.sql` (except `tests/fixtures`) and `/data/` are
  ignored; keep it that way. Tests use the Docker shop and synthetic fixtures.
- **Secrets:** passwords are never command line options (only `--...-file` or environment), never in logs, reports or
  manifests. Profile files (`data/profiles/`) hold passwords for types that need them: mode 0600, directory 0700.
- **Commit only when asked, never push.** The owner pushes. Commit messages: `Phase N, work package M: <what>`, a body that
  says why, the tests that prove it. Small, reviewable commits per work package.
- **Do not widen scope.** The plan's work packages are the unit of work. When the plan turns out wrong (it has: see
  PHASE2.md section 8, finding 6, and the corrections in the "As built" paragraphs), fix the plan text in the same commit as the code.
- **Documentation is part of the work:** update the work package table and its "As built" paragraph when something is
  finished; update `README.md` when behaviour users see changes.

## Running the checks

The host PHP usually has **no database driver and may be a different version** (this has produced false failures: symlink
tests, two PHPStan findings in `ParametersParser`). The reference environment is the `tools` container (PHP 8.4,
`pdo_mysql`, `mysqli`, Composer, `unzip`, the `mariadb` client). CI runs `composer lint`, `composer stan` and `composer test`
on PHP 8.4.

```bash
cd .docker
docker compose --profile tools run --rm tools composer install     # once
docker compose --profile tools run --rm tools composer check        # lint + PHPStan level 8 + unit tests (no database)
```

Integration tests (`tests/Integration`, skipped unless `THREADSAFE_STACK=1`) need the database containers:

```bash
tests/stack/run.sh                 # seed, clone, all integration tests in four phases (a few minutes)
# one test class, against the running stack:
docker compose -f .docker/docker-compose.yml --profile target --profile tools run --rm -e THREADSAFE_STACK=1 tools \
    vendor/bin/phpunit --testsuite integration --filter DumperTest
```

**Both servers must stay green.** The stack runs MariaDB 10.11 by default; MySQL 8.4 needs
`DB_IMAGE=mysql:8.4 VOLUME_SUFFIX=_mysql` *on every docker compose call* - a call without them silently recreates the `db`
container as MariaDB. `tests/stack/matrix.sh` runs everything on both. Details: [tests/stack/README.md](tests/stack/README.md).

Test conventions: unit tests need no database and no network; a test that needs a server extends `IntegrationTestCase`;
scratch databases and directories are created per test and removed afterwards (`ScratchShop`, `TempDirectory`).
Oracles are real tools where possible: `unzip -t`, the `mariadb` client, `CHECKSUM TABLE`.

## Map of the code

| Path | What |
|---|---|
| `src/Core`, `src/Migration`, `src/Replace` | Phase 1: steps, runner, report, journal; the migration request; domain replacement in files and database |
| `src/Cli` | Command line (`bin/threadsafe`): `Application`, `Command`, `Input`; commands `migrate`, `profile`, `backup` |
| `src/Database` | `Connection` facade over `PdoDriver` / `MysqliDriver`; `ServerInfo`; `stream()` and snapshot support |
| `src/Plugin` | `WebsiteType` (migration) and `Backupable` (backup) capabilities, `PluginRegistry` |
| `plugins/prestashop9`, `plugins/generic` | The two website types (manifest `plugin.json`, resources as data, steps, backup side) |
| `src/Engine` | Phase 2: the tick engine - `Task`, `Budget`, `RunStore`, `Lock`, `Executor` |
| `src/Archive` | Phase 2: streaming ZIP writer and reader (ZIP64, resumable, byte-identical regardless of tick cuts) |
| `src/Backup` | Phase 2: `Scanner`, `Filters`, `FileList`, `Dumper` and its helpers (`SqlWriter`, `SchemaReader`, `DumpState` ...); `BackupRun` (the task), `ArchivePacker` |
| `src/Profile` | Phase 2: `SiteProfile`, `ProfileStore`, `ProfileResolver`, `ConnectionTest` |
| `src/Storage`, `src/Backup/Manifest*` | Phase 2: `LocalStorage` (protection files, sidecar, listing), `BackupCatalog` (listing, sidecar rebuild), `SpaceCheck`, `Postproc`; `BackupName`, `Manifest`, `Sidecar`, `Verifier` |
| `tests/Unit`, `tests/Integration`, `tests/Support`, `tests/stack` | Tests, helpers, Docker stack scripts |
| `tools/spikes` | Throw-away scripts behind the findings in PHASE2.md section 8 (kept for reference) |
| `docs` | Concept, phase plans, `MIGRATE.md` (reference of the `migrate` command) |

## Design rules that are easy to break

- **Everything long-running is a `Task` that works in ticks.** A task object holds no state; it gets a plain-data state,
  returns a new one, and checks `Budget::exhausted()` between units of work. The `Executor` saves the state after every tick,
  also in the CLI, so resume is the normal path. Anything written to a file in a tick is cut back to the length the saved state
  remembers when work continues (`AppendFile`, `ZipWriter::resume`).
- **Archives are standard ZIP files** that open with ordinary tools; database dumps are plain SQL that the plain `mariadb` /
  `mysql` client imports. No private formats.
- **A dump must be exact.** Floats are read as `(col + 0e0)`, binary as hex, time zone UTC, generated columns left out (but
  not columns with `DEFAULT CURRENT_TIMESTAMP`, which MySQL marks `DEFAULT_GENERATED`). No SQL can build a value larger than
  `max_allowed_packet`; such cells are dumped and reported as a warning.
- **Application knowledge lives in plugins and in data files** (`plugins/<type>/resources/*.json`), not in the core.
- **The file system is the source of truth** for backups (archive plus sidecar `.json`); an index is only a cache.
- Do not rely on `exec` / `proc_open` (often disabled on shared hosting); the code must work in plain PHP with the limits of a
  web request.

## Next steps

`threadsafe profile`, `backup`, `backups` and `verify` work end to end and are proven by the integration tests (the
backup round trip of the seeded shop, SIGKILL and resume, low limits, damaged archives; `docs/PHASE2.md` 4.2). Phase 2, work
package 11 is next: `docs/BACKUP.md` - the profile file, the archive layout, the manifest and sidecar, the commands and
exit codes, how to take a backup apart with plain tools in an emergency (`unzip`, the `mysql` client, `threadsafe migrate`),
the private real-shop test procedure - and the final status updates (README, CONCEPT). Then phase 3 (`restore.php`) can be
planned the way phase 2 was: `docs/PHASE3.md` first, decisions with the owner, spikes for the risky parts.

Open questions for the owner (not blocking): which database server and which PHP functions (`exec`, CLI cron) the production
host offers - see CONCEPT section 10.
