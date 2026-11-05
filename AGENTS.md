# Working on ThreadSafe

For anyone - person or coding agent - who continues work on this project without having followed its history. Read this
first, then [docs/CONCEPT.md](docs/CONCEPT.md) (what and why) and the plan of the last finished phase
([docs/PHASE5.md](docs/PHASE5.md); its section 9 "As built" tells what differs from the plan).

## What this is

An open-source (GPL-3.0-or-later) backup and migration tool for PHP web applications, modelled on Akeeba Solo: one
instance backs up many sites ("site profiles"), restores them on another domain, path or database, and adjusts the
application automatically. First target: **PrestaShop 9.x**; MariaDB and MySQL; PHP 8.4+.

## Where the project stands

**The application is in beta** (decided with the owner, 2026-10-30): everything below is built and field-tested on the owner's own sites and server, but not yet on a variety of customer sites; the versions stay below 1.0.0 (pre-releases) until that has happened. What is still open is named in the table and in "Next steps", not hidden.

| Phase | Content | State |
|---|---|---|
| 1, 1b | Restore core: `threadsafe migrate <type>`, dev mode, module rules, self-test; database drivers; website type plugins | done |
| 2 | Site profiles and backup engine (CLI) | done (work packages in [docs/PHASE2.md](docs/PHASE2.md) section 5; user documentation [docs/BACKUP.md](docs/BACKUP.md)) |
| 3 | `threadsafe-restore.php` (extractor and wizard) | done (plan and "As built" in [docs/PHASE3.md](docs/PHASE3.md); user documentation [docs/RESTORE.md](docs/RESTORE.md)); milestone A: `threadsafe restore`, milestone B: the single file `threadsafe-restore.php` with the wizard (a development build in `dist/dev/threadsafe-restore.php`; the one to hand out is `dist/release/threadsafe-restore.php` of a release); disaster recovery proven on the Docker stack |
| 4 | Web interface (dashboard, download) | done (plan and "As built" in [docs/PHASE4.md](docs/PHASE4.md); user documentation [docs/WEB.md](docs/WEB.md)); login and setup, profiles, backups in steps with progress, check, delete, download, dashboard and System page, restore help; proven against a real Apache with 5 s / 64 MB limits on all three database servers (phase 4b of `run.sh`) |
| 5 | One trigger cron, retention, encryption | done (plan and "As built" in [docs/PHASE5.md](docs/PHASE5.md); user documentation [docs/SCHEDULE.md](docs/SCHEDULE.md) and [docs/ENCRYPTION.md](docs/ENCRYPTION.md)); the schedule in the profile and `threadsafe cron` / the address `/cron/<secret>` (detached worker or ticks), retention with pinned backups and a go-ahead, backups encrypted in the format of `age` (public keys of the profile, or a passphrase for one backup), restore with the key; proven on all three database servers (phases 4c and 4d of `run.sh`) |
| 6 | Website type Joomla 5 and 6 (backup, restore, migration) | **done, field-tested** (the owner backed up and restored a real Joomla site, no findings; so there are no extension rules yet): work packages 1 to 8; the Docker proof runs on all three database servers (plan and "As built" in [docs/PHASE6.md](docs/PHASE6.md); user documentation [docs/JOOMLA.md](docs/JOOMLA.md), reference [docs/MIGRATE.md](docs/MIGRATE.md) section 13; phase 6 of `run.sh`; dev mode in a small first cut, `--mode=dev`); rules for single Joomla extensions are written when a real site shows one that needs it |
| 7 | Installation and update (release package, web installer, update from the web interface) | **done; field-tested on the owner's own server** (work package 10, findings 1 to 4 in [docs/PHASE7.md](docs/PHASE7.md) fixed; the owner repeats the installation and an update on a customer's server); user documentation [INSTALL.md](INSTALL.md) and [docs/WEB.md](docs/WEB.md) "Updating"; details: the plan is [docs/PHASE7.md](docs/PHASE7.md) (work packages in section 5); work packages 1 (spikes, findings in section 8) 2 (the release build: `php tools/build-release.php --version=X.Y.Z`, `src/Build/Release*`) 3 (the layout: `Core\Home` the pointer, `Core\Layout` the stub and the command of a home, `Admin\Assets`, `Paths::home()`) 4 (`Core\Environment`, the one list of preflight checks; `threadsafe doctor`) 5 (the web installer `threadsafe-install.php`: `Build\Install*`, `Install\*`; the file is also a ZIP archive) 6 (the update: `Update\*`, the section *Update* of the System page, `threadsafe update`; the new release migrates the data at its first start, `Migrations::all()` is empty), 7 (the release workflow `.github/workflows/release.yml`, `CHANGELOG.md`, `tools/test-release.php`) and 8 (the service `fresh` and phase 7 of `run.sh`) and 9 (the documentation) are done |
| 8 | Central schedule management (a page for all schedules, plain settings instead of cron, sortable table, pause) | **done; used by the owner without problems**: plan and "As built" in [docs/PHASE8.md](docs/PHASE8.md); user documentation [docs/SCHEDULE.md](docs/SCHEDULE.md) section 2 and [docs/WEB.md](docs/WEB.md) "Schedule"; the profile file holds `{"every": "day", "at": "03:30"}`, no cron text (`Profile\ScheduleSettings`, which makes the cron expression for the engine); `App\ScheduleService`, `Admin\ScheduleController` and `ScheduleFields` |
| 9 | Website type WordPress (backup, restore, migration, dev mode in a first cut) | **built and proven in Docker; field test partly done** (the owner restored a real site on a new domain without visible problems; a real shop site with WooCommerce or a caching plugin, and special cases such as a sub-directory or a `wp-config.php` above the site, are tested when the owner has a site that needs them; user documentation [docs/WORDPRESS.md](docs/WORDPRESS.md), reference [docs/MIGRATE.md](docs/MIGRATE.md) section 14): the plan and the decisions (settled 2026-10-29) are in [docs/PHASE9.md](docs/PHASE9.md); work packages 1 (the spikes on WordPress 6.9.4 and 7.1.4, findings in section 8; fixtures in `tests/fixtures/wordpress/`) 2 (the reader and writer of `wp-config.php`, `plugins/wordpress/src/Config/`) 3 (the plugin, the backup side: `plugins/wordpress/`) 4 (the restore side and `migrate wordpress`: the steps in `plugins/wordpress/src/Steps/`; core: `DomainMapping::$toPath`, `MigrationRequest::$skipColumns` and `$options`) 5 (the Docker stack: the services `wordpress7`, `wordpress6`, `wordpress60` and `target-wordpress`, WP-CLI in the tools image, `tests/stack/seed-wordpress.sh`, the group `wordpress` as phase 8 of `run.sh`, proven on all three database servers) and 6 (dev mode `--mode=dev`: `Steps\ApplyDevMode`, a must-use plugin that stops `wp_mail`; the disaster recovery as phase 9 of `run.sh`) and 7 (the documentation) are done, and the field test in Docker with the plugins of real shops (WooCommerce, Yoast, Redis, W3 Total Cache, Elementor; `tests/stack/seed-wordpress-plugins.sh`, `WordPressPluginSitesTest`) found three things that are fixed; "plugin" means a ThreadSafe website type, not a plugin inside WordPress |
| 10 | A retention strategy (staged rules: the newest backup of each of the last N days, weeks, months and years; presets; a default and a hint for new profiles; the cost shown before it is applied) | **shelved** (2026-11-05: the owner does not need it now; do not start it unprompted): the plan with the decisions for the owner (7.2) is [docs/PHASE10.md](docs/PHASE10.md); today's retention (`keep`, `maxAgeDays`, `maxBytes`, protections, go-ahead) is [docs/SCHEDULE.md](docs/SCHEDULE.md) section 5 |
| 11 | Two-factor authentication for the web interface (TOTP as a second step of the login, recovery codes, a page *Security*, a QR code drawn by the program) | **done; released as 0.9.0-beta.4; the owner's test with a real authenticator app worked locally and on the live system** (recovery code, `--disable`, the requirement and the roll-back note are not reported as tried): the plan, the settled decisions (7.2) and "As built" are in [docs/PHASE11.md](docs/PHASE11.md); user documentation [docs/WEB.md](docs/WEB.md) "Logging in", "Security" and "Security in short". Work packages 1 (`Auth\Base32`, `Auth\Totp`) 2 (`Auth\TwoFactorStore`, `Auth\RecoveryCodes`: its own file `data/two-factor.json`) 3 (the login in two steps: `AuthController::code()`; **a session without a user is not a login**: `Kernel` asks `Context::user()`) 4 (the page *Security*: `Admin\SecurityController`, `Sessions::destroyExcept()`) 5 (the QR code: `Auth\QrCode`) 6 (`admin two-factor`, `web.requireTwoFactor`, the warning on the System page) 7 (phase 4b of `run.sh` with `oathtool` and `zbarimg` as oracles, all three database servers; the contrast audit) and 8 (the documentation) are done. `src/Auth` is part of the web interface and is left out of `threadsafe-restore.php` and the installer (`RestoreBuilder`, `InstallBuilder`) |
| 12 | `threadsafe-backup.php`: a backup of a site without the ThreadSafe app, as one file with a wizard, analogous to `threadsafe-restore.php` (encrypted by default, a download with resume, a shell mode) | **built, proven in Docker and field-tested** (work package 10 done 2026-11-04: the owner backed up and restored a real PrestaShop site, one finding fixed, the collation check of `Preflight`; Joomla and WordPress sites are not reported as tried; user documentation [docs/BACKUP.md](docs/BACKUP.md) section 11): the plan and the decisions (7.2: the recommendations apply, the owner said to start) are [docs/PHASE12.md](docs/PHASE12.md); work packages 1 to 9 are built (the group `backup-tool` is phase 4e of `run.sh` on the working target shop, green on all three database servers; `threadsafe-backup.php` is part of the release: `tools/build-release.php`, `tools/test-release.php`, the workflow, `SHA256SUMS`; `Cli\ToolBackupCommand`: `php threadsafe-backup.php backup ...` in a shell, encryption a required choice; `Web\BackupWizard`: key, site, options with the key pair, summary with the probe, the run in ticks, the done page with a download per part and the clean-up that leaves the archive; `Web\BackupWorkspace`: `.threadsafe-backup/` with `data/` and the storage with a random name; `Web\BackupTarget`: the allowed directory, the tool's self-exclusion, the profile `site`; `Web\Download` and `Web\ByteRange` moved from `Admin`; `Build\ToolStub`, one stub for the restore tool, the installer and the backup tool, byte-identical for the first two; `BackupBuilder`, `tools/build-backup.php`; `Web\BackupEntry` runs the wizard on the web and `ToolBackupCommand` in a shell). The engine, the website types and the encryption exist already: `RestoreBuilder` bundles them into the restore tool |
| 13 | The connector: a site that cannot run ThreadSafe (no second website, no cron) is backed up by `threadsafe-backup.php` in an agent mode (signed API: start, steps, list, parts) and planned, pulled and kept by a ThreadSafe elsewhere (remote profile, HTTPS pull with resume); a thin WordPress plugin (Joomla later) installs it, pairs it and sets the backup directory | **planned, not built** (written down 2026-11-03; decided with the owner 2026-11-05: it is done once the current version (0.10.0-beta.x) is stable, as the next minor version with the "satellite" apps; the decisions of 7.2 still have to be gone through first): the plan with the decisions for the owner (7.2) is [docs/PHASE13.md](docs/PHASE13.md) |

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
- **Colours are tokens.** `public/app.css` and `Html::css()` (the wizard) define the same tokens (`docs/DESIGN.md`); change a colour in both and keep every readable text at least 4.5:1 (`tools/a11y-audit.js` checks a page in both schemes; `docs/DESIGN.md` "Accessibility" says how).
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
| `plugins/prestashop9`, `plugins/generic`, `plugins/joomla`, `plugins/wordpress` | The website types (manifest `plugin.json`, resources as data, steps, backup and restore side); Joomla and WordPress build on the generic type's replacement of the old address and have their own readers and writers of the configuration file (`src/Config`: `configuration.php`, `wp-config.php`) |
| `src/Engine` | Phase 2: the tick engine - `Task`, `Budget`, `RunStore`, `Lock`, `Executor` |
| `src/Archive` | Phase 2: streaming ZIP writer and reader (ZIP64, resumable, byte-identical regardless of tick cuts) |
| `src/Backup` | Phase 2: `Scanner`, `Filters`, `FileList`, `Dumper` and its helpers (`SqlWriter`, `SchemaReader`, `DumpState` ...); `BackupRun` (the task), `ArchivePacker` |
| `src/Restore` | Phase 3: `RestoreRun` (the task: preflight, extract, import, adapt, finalize) with `BackupSource`, `Preflight`; `Extractor` (resumable extraction of the archive parts); `SqlSplitter`, `PrefixRewriter`, `Importer` (resumable SQL import in plain PHP, optional table prefix change) |
| `src/Web`, `src/Build` | Phase 3: the restore wizard (`Wizard`, `AccessKey`, `Sessions`, `Cleanup`, `Entry` ...; `RunView` is the page of a running restore or installation: bar, clock, what the working step does, buttons); the build of the single file `threadsafe-restore.php` (`Bundler`, `RestoreBuilder`, `ToolStub`; `php tools/build-restore.php`; phase 12: `BackupBuilder`, `php tools/build-backup.php`; `RestoreBuilder::ONLY_RESTORE` / `ONLY_BACKUP` name the files that only one of the two tools carries). `tools/restore-web.php` is the wizard's development entry |
| `src/Admin`, `public/` | Phase 4: the web interface (`Kernel`, `Router`, `AuthController`, `View` ...; phase 8: `ScheduleController` and `ScheduleFields`, the page of all schedules and the fields that the profile form shares; phase 11: `SecurityController`; not part of `threadsafe-restore.php`); `public/index.php` is its only entry point, `public/app.css`/`app.js` its static files |
| `src/Auth` | Phase 11: the second factor of the web login - `Totp` (RFC 6238), `Base32`, `TwoFactorStore` (`data/two-factor.json`, locked read-and-write), `RecoveryCodes`, `QrCode` (byte mode, level M, versions 1 to 10, inline SVG); part of the web interface, not of the restore file or the installer |
| `src/App` | Phase 4: services that the commands and the web pages share (`ProfileService`, `BackupService`, `CatalogService`, `Overview`); phase 5: `ScheduleService` (phase 8: the rows of the table of schedules, sorting, change/pause/resume of several profiles), `TriggerService` (the work of `threadsafe cron` and of the address), `WorkerLauncher` (starts the detached worker: clean environment, standard input `/dev/null`), `TriggerStatus`, `TriggerHelp` |
| `src/Profile` | Phase 2: `SiteProfile`, `ProfileStore`, `ProfileResolver`, `ConnectionTest`; phase 5: `ScheduleSettings` (phase 8: plain schedule - hours, day, weekdays, month - with `paused`, its cron expression, words and command line text), `RetentionSettings`, `EncryptionSettings` (the optional profile fields) |
| `src/Crypto` | Phase 5: the `age` format in plain PHP with ext-sodium - `AgeSealer`/`AgeReader` (chunked ChaCha20-Poly1305, resumable sealing), `AgeDecryptStream` (seekable stream for `ZipReader`), `Bech32`, `AgeKeys`, `X25519*`/`ScryptRecipient`/`PassphraseIdentity`, `Scrypt`, `Passphrases` |
| `src/Retention` | Phase 5: `Retention` (plan = dry run and preview, apply), `RetentionPlan`/`RetentionAction`, `RetentionState`/`RetentionStateStore` (`data/retention/`); `App/RetentionService` is what the command, the pages and the trigger call |
| `src/Schedule` | Phase 5: `CronExpression` (next/previous instant, daylight saving time by our own rule), `Scheduler` (due, grace, retries, queue), `ScheduleState`/`ScheduleStore` (`data/schedule/`), `CronSecret` (`data/cron-secret`), `Heartbeat` (`data/heartbeat.json`) |
| `src/Storage`, `src/Backup/Manifest*` | Phase 2: `LocalStorage` (protection files, sidecar, listing), `BackupCatalog` (listing, sidecar rebuild), `SpaceCheck`, `Postproc`; `BackupName`, `Manifest`, `Sidecar`, `Verifier` |
| `tests/Unit`, `tests/Integration`, `tests/Support`, `tests/stack` | Tests, helpers, Docker stack scripts |
| `tools/spikes` | Throw-away scripts behind the findings in PHASE2.md section 8 (kept for reference) |
| `docs`, `INSTALL.md`, `CHANGELOG.md` | `INSTALL.md` (the user guide for installing and updating: the web installer, the package, a clone; it is in the release package), `CHANGELOG.md` (the notes of a release come from it, see "Making a release"). Concept, phase plans, `MIGRATE.md` (reference of the `migrate` command), `BACKUP.md` (reference of the backup commands and the archive; section 11: `threadsafe-backup.php`), `RESTORE.md` (reference of the restore: wizard, command, checks, limits, encrypted backups), `WEB.md` (the web interface), `SCHEDULE.md` (schedules, the trigger, retention), `ENCRYPTION.md` (keys, passphrases, what is protected), `JOOMLA.md` (the Joomla type: what a backup holds, the old address, the restore, dev mode, limits), `WORDPRESS.md` (the same for WordPress: the address in the database, the table prefix, the cache drop-ins, dev mode, limits), `DESIGN.md` (colours and type of the web interface and the wizard; `DESIGN-theLoom.md` is the palette it follows) |

## Making a release

A release is made by the workflow, not by hand (docs/PHASE7.md 3.7, decision 6): put the notes into `CHANGELOG.md` under `## [X.Y.Z] - date` (rename `[Unreleased]`), commit, then tag `vX.Y.Z` and push the tag. The workflow checks the tag and the notes, runs `composer check`, builds `threadsafe-X.Y.Z.zip`, `threadsafe-install.php`, `threadsafe-restore.php`, `threadsafe-backup.php` and `SHA256SUMS` with `php tools/build-release.php --version=X.Y.Z`, tests the built files on a clean PHP (`tools/test-release.php`), and publishes the release (a pre-release below 1.0.0). The source says `0.0.0-dev` always; the version is stamped into the package. To try the whole thing locally: `vendor/bin/phing verify -Dversion=0.1.0` (the version can stand in `build.properties`, which is ignored by Git and copied from `build.properties.dist`; `-Dversion=` wins over the file; the release workflow does not read it: Phing is a development dependency; `build.xml` runs `tools/build-release.php` and `tools/test-release.php`, the files go to `dist/release`; `phing -l` lists the targets, `phing all` also runs `composer check`).

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

Phases 1 to 9, 11 and 12 are built and proven in Docker (phase 10 is shelved, phase 13 is planned for the next minor; phase 12 is field-tested with a PrestaShop site; the current release is 0.10.0-beta.4, and the code review of 2026-11-05 covered the update, the `age` crypto, the admin controllers and the settings, see [docs/PHASE12.md](docs/PHASE12.md) section 8); the table above says which are field-tested. The core of it: `threadsafe migrate`, `profile`, `backup`, `backups`, `verify`, `restore`, `retention`, `keys`,
`cron` and `admin` work end to end, the single file `threadsafe-restore.php` (`php tools/build-restore.php`) restores a backup (also an encrypted one) behind a web
server on a host where nothing else is installed, and the web interface (`public/`, switched on with `admin enable`) does profiles, schedules, backups
in steps, checks, downloads, keys and the restore help in a browser. `tests/stack/run.sh` is the whole proof: baseline and migration, dev mode, the backup
round trip, the web interface behind a real Apache with limits of 5 s and 64 MB (phase 4b), the trigger and retention on real backups (4c), encrypted
backups opened by the real `age` tool and restored by the command and the wizard (4d), and the disaster recovery (phase 5, which destroys the target
stack and brings the shop back through the wizard); `tests/stack/matrix.sh` does all of it on MariaDB 10.11, MySQL 8.4 and MariaDB 11.8,
`tests/stack/cross.sh` between the two kinds of server. The references are `docs/BACKUP.md`, `docs/RESTORE.md`, `docs/WEB.md`, `docs/SCHEDULE.md`,
`docs/ENCRYPTION.md` and `docs/MIGRATE.md`.

**Phase 5 - what to know before touching it** (the details are in `docs/PHASE5.md` section 9): a **passphrase** and public keys cannot share an `age` file, so
a passphrase backup is for the passphrase only; the **plain parts of an encrypted backup wait in the work directory**, never in the storage; the **web
interface holds no secret key** (it checks an encrypted backup only quickly), and the sidecar file of an encrypted backup is minimal and cannot be made again;
`Restore\Parts` addresses must live as long as their `ZipReader` (call `Parts::releaseAll()` when the readers are done) and **a `ZipReader` on a stream of a
wrapper must not get a large read chunk** (every seek would decrypt it: `ZipReader::readFully()` asks again instead); the **first tick of a backup only names
it** and prepares the work directory, so that a killed run is continued into the same backup; on the customer's host (cron = one HTTP GET a day) the address
starts a **detached worker with a clean environment** (`env -i`, `docs/PHASE5.md` section 8, finding 9); the cron rule for daylight saving time is our own, not PHP's.
Building blocks: `TriggerService` (the one class behind both doors of the trigger), `BackupService` (a run in ticks, the rule for active and interrupted runs: a
web run is active while its last tick is recent), `Executor`/`RunStore`/`Lock`, `Overview`, `Config` and the System page, `Postproc` (for remote storage).

What is not planned in detail: **remote storage** (SFTP/S3; the `transfers` list of the sidecar file is reserved, `Postproc` is the hook), a **remote source** (a profile that reads its site over SFTP, for sites on other servers; the considerations are in [docs/CONCEPT.md](docs/CONCEPT.md) section 9, "A remote source"), further website types
(WordPress is built and was restored once from a real site, [docs/WORDPRESS.md](docs/WORDPRESS.md); Joomla is done, [docs/JOOMLA.md](docs/JOOMLA.md); rules for single Joomla extensions and WordPress plugins are written when a real site shows one that needs it), restoring over an existing installation.
Open items for the owner (not blocking): the download of a few hundred MB
through the live host's FastCGI (`tools/spikes/web-download.php`, PHASE4.md section 8, finding 1); the web-server modes `X-Sendfile` /
`X-Accel-Redirect` and the nginx rule in `docs/WEB.md` have never met a web server. The private test with a real shop is done (backup in the browser, restore, `docs/BACKUP.md` section 10); the browser run that took 506 seconds (one step as long as the backup) takes 47 seconds with steps of 20 seconds; the difference in size to Akeeba Solo's backup of that shop is not explained, but a second real site (5.4 GB, the first archive with real ZIP64 records; `verify` and `unzip -t` passed) came out about as large as its Akeeba backup (`docs/BACKUP.md` section 10). Small gaps of the interface: the sidecar keeps no
date of the last check, the list has no column for the kind of backup, the key and the command of the download page have no "copy"
button, and the language is English only.
Known gaps that the owner may want to turn into work (all listed in `docs/RESTORE.md` section 9): restoring **over** an existing
installation with a rollback (left out of phase 3 on purpose); a MySQL 8 backup onto MariaDB (refused because of the collation
`utf8mb4_0900_ai_ci`; mapping it while importing would be a feature); views and triggers whose `DEFINER` does not exist on the
new server; a prefix change for backups with views, triggers or foreign keys; a WordPress multisite (detected and refused; the
`Restorable` capability in `src/Plugin` is what a website type implements, `plugins/generic` is the smallest example).
The live host (probe runs of 2026-10-21, `docs/PHASE3.md` section 8, finding 6): PHP 8.4.26 only as CGI builds (web: 30 s per
request, 512 MB, `exec` allowed, no cut-off up to 40 s; the SSH shell's `php` is PHP 4 but `/usr/bin/php8.4 -q` works),
database MariaDB 11.8.8 with 64 MB `max_allowed_packet`. `bin/threadsafe` runs with the CGI binary. No open question about
the host remains; see CONCEPT section 10.
