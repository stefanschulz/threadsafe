# Working on ThreadSafe

For anyone - person or coding agent - who continues work on this project without having followed its history. Read this
first, then [docs/CONCEPT.md](docs/CONCEPT.md) (what and why) and the plan of the last finished phase
([docs/PHASE3.md](docs/PHASE3.md)); the next phase has no plan yet.

## What this is

An open-source (GPL-3.0-or-later) backup and migration tool for PHP web applications, modelled on Akeeba Solo: one
instance backs up many sites ("site profiles"), restores them on another domain, path or database, and adjusts the
application automatically. First target: **PrestaShop 9.x**; MariaDB and MySQL; PHP 8.4+.

## Where the project stands

| Phase | Content | State |
|---|---|---|
| 1, 1b | Restore core: `threadsafe migrate <type>`, dev mode, module rules, self-test; database drivers; website type plugins | done |
| 2 | Site profiles and backup engine (CLI) | done (work packages in [docs/PHASE2.md](docs/PHASE2.md) section 5; user documentation [docs/BACKUP.md](docs/BACKUP.md)) |
| 3 | `restore.php` (extractor and wizard) | done (plan and "As built" in [docs/PHASE3.md](docs/PHASE3.md); user documentation [docs/RESTORE.md](docs/RESTORE.md)); milestone A: `threadsafe restore`, milestone B: the single file `dist/restore.php` with the wizard; disaster recovery proven on the Docker stack |
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

**All three servers must stay green** (MariaDB 10.11, MySQL 8.4, MariaDB 11.8 - the live host's version). The stack runs MariaDB 10.11 by default; MySQL 8.4 needs
`DB_IMAGE=mysql:8.4 VOLUME_SUFFIX=_mysql` *on every docker compose call* - a call without them silently recreates the `db`
container as MariaDB. `tests/stack/matrix.sh` runs everything on both. Details: [tests/stack/README.md](tests/stack/README.md).

Test conventions: unit tests need no database and no network; a test that needs a server extends `IntegrationTestCase`;
scratch databases and directories are created per test and removed afterwards (`ScratchShop`, `TempDirectory`).
Oracles are real tools where possible: `unzip -t`, the `mariadb` client, `CHECKSUM TABLE`.

## Map of the code

| Path | What |
|---|---|
| `src/Core`, `src/Migration`, `src/Replace` | Phase 1: steps, runner, report, journal; the migration request; domain replacement in files and database. Phase 3: `TickedRunner` (the runner for a run in several processes), `ResumableStep`, `HasFindings` |
| `src/Cli` | Command line (`bin/threadsafe`): `Application`, `Command`, `Input`; commands `migrate`, `profile`, `backup` |
| `src/Database` | `Connection` facade over `PdoDriver` / `MysqliDriver`; `ServerInfo`; `stream()` and snapshot support |
| `src/Plugin` | `WebsiteType` (migration) and `Backupable` (backup) capabilities, `PluginRegistry` |
| `plugins/prestashop9`, `plugins/generic` | The two website types (manifest `plugin.json`, resources as data, steps, backup side) |
| `src/Engine` | Phase 2: the tick engine - `Task`, `Budget`, `RunStore`, `Lock`, `Executor` |
| `src/Archive` | Phase 2: streaming ZIP writer and reader (ZIP64, resumable, byte-identical regardless of tick cuts) |
| `src/Backup` | Phase 2: `Scanner`, `Filters`, `FileList`, `Dumper` and its helpers (`SqlWriter`, `SchemaReader`, `DumpState` ...); `BackupRun` (the task), `ArchivePacker` |
| `src/Restore` | Phase 3: `RestoreRun` (the task: preflight, extract, import, adapt, finalize) with `BackupSource`, `Preflight`; `Extractor` (resumable extraction of the archive parts); `SqlSplitter`, `PrefixRewriter`, `Importer` (resumable SQL import in plain PHP, optional table prefix change) |
| `src/Web`, `src/Build` | Phase 3: the restore wizard (`Wizard`, `AccessKey`, `Sessions`, `Cleanup`, `Entry` ...); the build of the single file `restore.php` (`Bundler`, `RestoreBuilder`, `RestoreStub`; `php tools/build-restore.php`). `tools/restore-web.php` is the wizard's development entry |
| `src/Profile` | Phase 2: `SiteProfile`, `ProfileStore`, `ProfileResolver`, `ConnectionTest` |
| `src/Storage`, `src/Backup/Manifest*` | Phase 2: `LocalStorage` (protection files, sidecar, listing), `BackupCatalog` (listing, sidecar rebuild), `SpaceCheck`, `Postproc`; `BackupName`, `Manifest`, `Sidecar`, `Verifier` |
| `tests/Unit`, `tests/Integration`, `tests/Support`, `tests/stack` | Tests, helpers, Docker stack scripts |
| `tools/spikes` | Throw-away scripts behind the findings in PHASE2.md section 8 (kept for reference) |
| `docs` | Concept, phase plans, `MIGRATE.md` (reference of the `migrate` command), `BACKUP.md` (reference of the backup commands and the archive), `RESTORE.md` (reference of the restore: wizard, command, checks, limits) |

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
  web request. Where a child process is started anyway, **its standard input must be `/dev/null`**: a PHP that runs as FastCGI
  passes its listening socket on to children, and a child PHP CGI binary then waits for requests forever (seen on the live
  host, `docs/PHASE3.md` section 8, finding 6). The limits of a request (`max_execution_time`, `memory_limit`) differ per virtual
  host: read them at run time.

## Next steps

Phases 1, 1b, 2 and 3 are done and proven: `threadsafe migrate`, `profile`, `backup`, `backups`, `verify` and `restore` work end to
end, and the single file `restore.php` (`php tools/build-restore.php`) restores a backup behind a web server on a host where
nothing else is installed. The disaster recovery test (`tests/stack/run.sh`, phase 5) destroys the target stack, makes it again
empty and brings the shop back through the wizard; `tests/stack/matrix.sh` does all of it on MariaDB 10.11, MySQL 8.4 and
MariaDB 11.8, `tests/stack/cross.sh` between the two kinds of server. The references are `docs/BACKUP.md`, `docs/RESTORE.md` and
`docs/MIGRATE.md`.

**Phase 4, the web interface** (login, dashboard, profile management, backup overview, manual backup with progress, download -
`docs/CONCEPT.md` section 5) comes next. It needs a plan first, `docs/PHASE4.md`, the way phases 2 and 3 had one: decisions with
the owner, spikes for the risky parts, work packages with "As built" paragraphs. The engine (ticks, `RunStore`, `Executor`, `Lock`),
the wizard classes in `src/Web` (`Request`, `Response`, `Html`, sessions, CSRF, the key file) and `Budget::forThisProcess()` are
there to build on.

Known gaps that the owner may want to turn into work (all listed in `docs/RESTORE.md` section 9): restoring **over** an existing
installation with a rollback (left out of phase 3 on purpose); a MySQL 8 backup onto MariaDB (refused because of the collation
`utf8mb4_0900_ai_ci`; mapping it while importing would be a feature); views and triggers whose `DEFINER` does not exist on the
new server; a prefix change for backups with views, triggers or foreign keys; website types for WordPress and Joomla (the
`Restorable` capability in `src/Plugin` is what they implement, `plugins/generic` is the smallest example).
The live host (probe runs of 2026-10-21, `docs/PHASE3.md` section 8, finding 6): PHP 8.4.26 only as CGI builds (web: 30 s per
request, 512 MB, `exec` allowed, no cut-off up to 40 s; the SSH shell's `php` is PHP 4 but `/usr/bin/php8.4 -q` works),
database MariaDB 11.8.8 with 64 MB `max_allowed_packet`. `bin/threadsafe` runs with the CGI binary. No open question about
the host remains; see CONCEPT section 10.
