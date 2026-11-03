<p align="center"><img src=".design/logo.svg" width="128" height="128" alt="ThreadSafe"></p>

# ThreadSafe

Backup and migration for PHP web applications. One instance backs up many sites, restores them on another domain, path or database, and adjusts the application on the way. Open source (GPL-3.0-or-later), written in plain PHP 8.4, runs on shared hosting as well as on your own server.

> **Beta.** ThreadSafe is feature-complete and has been used on the maintainer's own sites and servers, but not yet on a variety of other people's sites. The versions stay below 1.0.0 for now. Please report what you find.

## What it does

- **Backs up** the files and the database of a site into one standard ZIP archive (the database as plain SQL), in small steps that fit the time and memory limits of a web request.
- **Restores** a backup into an empty directory and database, on another domain, path, database or table prefix, and fixes what depends on the place: configuration file, the old address in the database and in files, `.htaccess`, caches. A self-test requests the restored site.
- **Rebuilds a site from nothing:** the single file `threadsafe-restore.php` is a wizard that restores a backup on a host where nothing else is installed.
- **Backs up a site that has no ThreadSafe:** the single file `threadsafe-backup.php` is a wizard (or a shell command) that makes a standard, by default encrypted backup of the site it lies in, hands it out for download and takes itself off again.
- **Web interface:** all sites at a glance, backups with a progress bar, checks, downloads, schedules. The login can have a **second factor** (a code from an authenticator app, with recovery codes).
- **Schedule and retention:** one cron job (or one address to fetch) starts every backup that is due; old backups are removed by rules you set. Backups can be **encrypted** in the format of [age](https://age-encryption.org/).
- **Dev mode:** a live-to-dev copy that sends no mail, talks to no payment provider and is kept out of search engines.
- **No lock-in:** a backup opens with `unzip` and the plain `mariadb` / `mysql` client. See [docs/BACKUP.md](docs/BACKUP.md) section 8.

## What it supports

| Website type | Versions | Documentation |
|---|---|---|
| PrestaShop | 9.x | [docs/MIGRATE.md](docs/MIGRATE.md) |
| Joomla | 5 and 6 | [docs/JOOMLA.md](docs/JOOMLA.md) |
| WordPress | 6.0 and later (a multisite is refused) | [docs/WORDPRESS.md](docs/WORDPRESS.md) |
| Generic | any PHP site: files plus a database; the domain is replaced in the database | [docs/MIGRATE.md](docs/MIGRATE.md) section 12 |

Databases: **MariaDB** from 10.2 and **MySQL** from 5.7 (tested: MariaDB 10.11 and 11.8, MySQL 8.4). ThreadSafe itself needs PHP 8.4 with `pdo_mysql` or `mysqli`, `zlib` and `mbstring`; the restore wizard says what it misses. Website types are plugins (`plugins/`).

## Quick start

**Install** with the web installer (a host with FTP and a browser), from the release package, or from a clone: [INSTALL.md](INSTALL.md). Switch the web interface on and use it: [docs/WEB.md](docs/WEB.md).

**Back up** a site from the command line (the same is possible in the web interface):

```bash
php bin/threadsafe profile add shop --type=prestashop9 --path=/var/www/shop --storage=/var/backups/threadsafe/shop
php bin/threadsafe backup shop
php bin/threadsafe verify shop        # is the newest backup intact?
```

**Restore** it into an empty directory and database. Without `--yes` the command only shows what it would do and checks the requirements:

```bash
php bin/threadsafe restore /var/backups/threadsafe/shop/<backup>.zip --root=/var/www/new-shop \
    --to=new.example.org --db-host=localhost --db-name=newshop --db-user=newshop --db-password-file=/root/newshop.pw
```

On a host without ThreadSafe, upload `threadsafe-restore.php`, a key file and the backup, open the file in the browser and follow the wizard: [docs/RESTORE.md](docs/RESTORE.md). Keep a copy of `threadsafe-restore.php` (from a release) with your backups.

To back up a site **without installing anything**, upload `threadsafe-backup.php` and a key file to the site, open the file in the browser, and follow the wizard (it makes an encrypted backup, lets you download it and deletes itself on request): [docs/BACKUP.md](docs/BACKUP.md) section 11.

Passwords are never options of a command, only `--...-file` or the environment. Backups hold customer data: keep the storage directory outside the web root.

## Documentation

**Using ThreadSafe**

- [INSTALL.md](INSTALL.md): installing and updating
- [docs/WEB.md](docs/WEB.md): the web interface
- [docs/BACKUP.md](docs/BACKUP.md): profiles, backup commands, the archive, taking a backup apart by hand, `threadsafe-backup.php` for a site without ThreadSafe
- [docs/RESTORE.md](docs/RESTORE.md): restoring with the wizard or the command, checks, limits
- [docs/SCHEDULE.md](docs/SCHEDULE.md): schedules, the trigger, retention
- [docs/ENCRYPTION.md](docs/ENCRYPTION.md): keys, passphrases, what is protected
- [docs/MIGRATE.md](docs/MIGRATE.md): reference of `migrate` (request, report, journal, exit codes, dev mode, module rules, all website types)
- [docs/JOOMLA.md](docs/JOOMLA.md) and [docs/WORDPRESS.md](docs/WORDPRESS.md): what is special about these types
- [CHANGELOG.md](CHANGELOG.md): what changed

**Working on ThreadSafe**

- [AGENTS.md](AGENTS.md): rules, how to run the checks, map of the code, where the project stands
- [docs/CONCEPT.md](docs/CONCEPT.md): what it is for and why it is built this way
- [docs/DESIGN.md](docs/DESIGN.md): colours and type of the web interface and the wizard
- Plans and "As built" notes of each phase: [1](docs/PHASE1.md), [1b](docs/PHASE1B.md), [2](docs/PHASE2.md), [3](docs/PHASE3.md), [4](docs/PHASE4.md), [5](docs/PHASE5.md), [6](docs/PHASE6.md), [7](docs/PHASE7.md), [8](docs/PHASE8.md), [9](docs/PHASE9.md), [10, planned](docs/PHASE10.md), [11](docs/PHASE11.md), [12](docs/PHASE12.md)
- [tests/stack/README.md](tests/stack/README.md): the Docker test stacks

## Development

PHP tooling runs in a container (the host PHP usually has no database driver):

```bash
cd .docker
docker compose --profile tools run --rm tools composer install
docker compose --profile tools run --rm tools composer check   # lint, PHPStan (level 8), unit tests
```

The integration tests run against the Docker stacks (`tests/stack/run.sh`, `tests/stack/matrix.sh` for all three database servers). A release is made by tagging `vX.Y.Z`, see "Making a release" in [AGENTS.md](AGENTS.md). All code, comments and documentation are written in English.

## Data protection

Real backups contain customer data. They are used only locally as restore test cases and never enter this repository, logs or documentation (see `.gitignore`).

## License

GPL-3.0-or-later, see [LICENSE](LICENSE).
