# Working on ThreadSafe

For anyone - person or coding agent - who continues work on this project without having followed its history. Read this
first, then [docs/CONCEPT.md](docs/CONCEPT.md) (what and why) and the plan of the last finished phase
([docs/PHASE4.md](docs/PHASE4.md)); phase 5 is planned in [docs/PHASE5.md](docs/PHASE5.md).

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
| 4 | Web interface (dashboard, download) | done (plan and "As built" in [docs/PHASE4.md](docs/PHASE4.md); user documentation [docs/WEB.md](docs/WEB.md)); login and setup, profiles, backups in steps with progress, check, delete, download, dashboard and System page, restore help; proven against a real Apache with 5 s / 64 MB limits on all three database servers (phase 4b of `run.sh`) |
| 5 | One trigger cron, retention, encryption | **planned**: [docs/PHASE5.md](docs/PHASE5.md) (decisions settled with the owner on 2026-10-22); work packages 1 (spikes, findings in section 8) 2 (schedule core) and 3 (the trigger: `threadsafe cron`, the address `/cron/<secret>`, "As built" in section 9) 4 (retention: `threadsafe retention`, pinned backups, the go-ahead for rules not yet seen) and 5 (the `age` core in `src/Crypto`, `threadsafe keys`) done, 6 (encryption in the backup) is next |

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
| `src/Cli` | Command line (`bin/threadsafe`): `Application`, `Command`, `Input`; commands `migrate`, `profile`, `backup`, `restore`, `admin` ... |
| `src/Database` | `Connection` facade over `PdoDriver` / `MysqliDriver`; `ServerInfo`; `stream()` and snapshot support |
| `src/Plugin` | `WebsiteType` (migration) and `Backupable` (backup) capabilities, `PluginRegistry` |
| `plugins/prestashop9`, `plugins/generic` | The two website types (manifest `plugin.json`, resources as data, steps, backup side) |
| `src/Engine` | Phase 2: the tick engine - `Task`, `Budget`, `RunStore`, `Lock`, `Executor` |
| `src/Archive` | Phase 2: streaming ZIP writer and reader (ZIP64, resumable, byte-identical regardless of tick cuts) |
| `src/Backup` | Phase 2: `Scanner`, `Filters`, `FileList`, `Dumper` and its helpers (`SqlWriter`, `SchemaReader`, `DumpState` ...); `BackupRun` (the task), `ArchivePacker` |
| `src/Restore` | Phase 3: `RestoreRun` (the task: preflight, extract, import, adapt, finalize) with `BackupSource`, `Preflight`; `Extractor` (resumable extraction of the archive parts); `SqlSplitter`, `PrefixRewriter`, `Importer` (resumable SQL import in plain PHP, optional table prefix change) |
| `src/Web`, `src/Build` | Phase 3: the restore wizard (`Wizard`, `AccessKey`, `Sessions`, `Cleanup`, `Entry` ...); the build of the single file `restore.php` (`Bundler`, `RestoreBuilder`, `RestoreStub`; `php tools/build-restore.php`). `tools/restore-web.php` is the wizard's development entry |
| `src/Admin`, `public/` | Phase 4: the web interface (`Kernel`, `Router`, `AuthController`, `View` ...; not part of `restore.php`); `public/index.php` is its only entry point, `public/app.css`/`app.js` its static files |
| `src/App` | Phase 4: services that the commands and the web pages share (`ProfileService`, `BackupService`, `CatalogService`, `Overview`); phase 5: `TriggerService` (the work of `threadsafe cron` and of the address), `WorkerLauncher` (starts the detached worker: clean environment, standard input `/dev/null`), `TriggerStatus`, `TriggerHelp` |
| `src/Profile` | Phase 2: `SiteProfile`, `ProfileStore`, `ProfileResolver`, `ConnectionTest`; phase 5: `ScheduleSettings`, `RetentionSettings`, `EncryptionSettings` (the optional profile fields) |
| `src/Crypto` | Phase 5: the `age` format in plain PHP with ext-sodium - `AgeSealer`/`AgeReader` (chunked ChaCha20-Poly1305, resumable sealing), `AgeDecryptStream` (seekable stream for `ZipReader`), `Bech32`, `AgeKeys`, `X25519*`/`ScryptRecipient`/`PassphraseIdentity`, `Scrypt`, `Passphrases` |
| `src/Retention` | Phase 5: `Retention` (plan = dry run and preview, apply), `RetentionPlan`/`RetentionAction`, `RetentionState`/`RetentionStateStore` (`data/retention/`); `App/RetentionService` is what the command, the pages and the trigger call |
| `src/Schedule` | Phase 5: `CronExpression` (next/previous instant, daylight saving time by our own rule), `Scheduler` (due, grace, retries, queue), `ScheduleState`/`ScheduleStore` (`data/schedule/`), `CronSecret` (`data/cron-secret`), `Heartbeat` (`data/heartbeat.json`) |
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
  host, `docs/PHASE3.md` section 8, finding 6), **and its environment must be cleaned** (`env -i PATH=...`): a CGI binary that
  inherits `REQUEST_METHOD` or `QUERY_STRING` runs the child as another web request (`docs/PHASE5.md` section 8, finding 9). The limits of a request (`max_execution_time`, `memory_limit`) differ per virtual
  host: read them at run time.

## Next steps

Phases 1, 1b, 2, 3 and 4 are done and proven: `threadsafe migrate`, `profile`, `backup`, `backups`, `verify`, `restore` and `admin` work end to
end, the single file `restore.php` (`php tools/build-restore.php`) restores a backup behind a web server on a host where nothing
else is installed, and the web interface (`public/`, switched on with `admin enable`) does profiles, backups in steps, checks,
downloads and the restore help in a browser. `tests/stack/run.sh` is the whole proof: baseline and migration, dev mode, the backup
round trip, the web interface behind a real Apache with limits of 5 s and 64 MB (phase 4b), and the disaster recovery (phase 5, which
destroys the target stack and brings the shop back through the wizard); `tests/stack/matrix.sh` does all of it on MariaDB 10.11, MySQL
8.4 and MariaDB 11.8, `tests/stack/cross.sh` between the two kinds of server. The references are `docs/BACKUP.md`, `docs/RESTORE.md`,
`docs/WEB.md` and `docs/MIGRATE.md`.

**Phase 5** (one trigger cron job with the schedule inside, retention, archive encryption in the format of `age` - `docs/CONCEPT.md`
sections 5.6 and 8) comes next. The plan is `docs/PHASE5.md` (all twelve decisions settled with the owner on 2026-10-22;
work package 1, the spikes, is done: the format of `age` works in PHP and agrees with the real tool, the passphrase is the native `age`
recipient at scrypt N=2^15, the cron rule for daylight saving time is our own, an idle trigger call is cheap, and on the customer's host
(cron = one HTTP GET a day) the address starts a detached worker that lives on - with a clean environment (`env -i`), see the trap in
section 8, finding 9; read section 8 before touching the format of `age` or the worker);
work packages 2 to 4 are built - the schedule core (`src/Schedule`, the profile fields, `admin set`), the
trigger (`threadsafe cron`, the address `/cron/<secret>` with a detached worker or ticks, the heartbeat, `admin cron-secret`, the
dashboard and System page parts) and the retention (`src/Retention`, `App/RetentionService`, the sidecar marks `pinned` and `source`), see
"As built" in section 9 - and the `age` core (5: `src/Crypto`, `threadsafe keys`; read its "As built" for the rule that a passphrase and keys cannot share a file)
is built too; the encryption in the backup (6) comes next, then the restore (7); a sealed part needs the sidecar to stay minimal and `Retention`
and the catalog to treat `.zip.age` parts like `.zip` ones).
Building blocks that are there: `TriggerService` (the one class behind both doors of the trigger), `BackupService` (a run in ticks, the rule
for active and interrupted runs: a web run is active while its last tick is recent), `Executor`/`RunStore`/`Lock`, `Overview`, `Config`
and the System page, `Postproc` (for remote storage).

Open items for the owner (not blocking): the download of a few hundred MB
through the live host's FastCGI (`tools/spikes/web-download.php`, PHASE4.md section 8, finding 1); a **private test with a real backup** of the live shop (`docs/BACKUP.md` section 10); the web-server modes `X-Sendfile` /
`X-Accel-Redirect` and the nginx rule in `docs/WEB.md` have never met a web server. Small gaps of the interface: the sidecar keeps no
date of the last check, the list has no column for the kind of backup, the key and the command of the download page have no "copy"
button, and the language is English only.
Known gaps that the owner may want to turn into work (all listed in `docs/RESTORE.md` section 9): restoring **over** an existing
installation with a rollback (left out of phase 3 on purpose); a MySQL 8 backup onto MariaDB (refused because of the collation
`utf8mb4_0900_ai_ci`; mapping it while importing would be a feature); views and triggers whose `DEFINER` does not exist on the
new server; a prefix change for backups with views, triggers or foreign keys; website types for WordPress and Joomla (the
`Restorable` capability in `src/Plugin` is what they implement, `plugins/generic` is the smallest example).
The live host (probe runs of 2026-10-21, `docs/PHASE3.md` section 8, finding 6): PHP 8.4.26 only as CGI builds (web: 30 s per
request, 512 MB, `exec` allowed, no cut-off up to 40 s; the SSH shell's `php` is PHP 4 but `/usr/bin/php8.4 -q` works),
database MariaDB 11.8.8 with 64 MB `max_allowed_packet`. `bin/threadsafe` runs with the CGI binary. No open question about
the host remains; see CONCEPT section 10.
