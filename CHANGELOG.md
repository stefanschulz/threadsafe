# Changelog

All notable changes of ThreadSafe. The format follows [Keep a Changelog](https://keepachangelog.com/); the versions are
[semantic](https://semver.org/). A tag `vX.Y.Z` makes a release only if this file has a section `## [X.Y.Z]` with notes: they are the text
of the GitHub release (see "Making a release" in [AGENTS.md](AGENTS.md)).

## [Unreleased]

## [0.10.0-beta.1] - 2026-11-04

The new tool **`threadsafe-backup.php`** (a backup of a site that has no ThreadSafe), the findings of a security review and a scan with OWASP ZAP, and the first fix from the field test of that tool. An installation made with 0.9.0-beta.1 or later can be updated to this version from the System page (or with `threadsafe update`); the format of the files in `data/` has not changed.

### Added

- **`threadsafe-backup.php`**, a new file of every release (beside the installer and the restore tool, and in `SHA256SUMS`): a backup of a site **that has no ThreadSafe**, as one file with a wizard. Upload it with a key file `threadsafe-backup-key.php` to the site, open it in the browser, and it detects the application (PrestaShop 9, Joomla, WordPress or any site with a database), makes the standard backup in steps that fit the limits of a web request, and offers a **download per part** that can be resumed. The archive is **encrypted by default** (a key pair made in the wizard, whose secret key is shown once and stored nowhere; a public key; or a passphrase), lies in a closed directory with a random name, and the run does not start if a test shows that the directory can be fetched through the web server. The tool backs up only its own directory or a parent, never itself, and deletes itself on request (the archive stays unless you say otherwise). In a shell: `php threadsafe-backup.php backup --recipient=age1...` (one process, one consistent snapshot of the database; encryption is a choice that must be made). Restore with `threadsafe-restore.php`. No schedule, no retention, no list of sites: it is for the one-off backup. Documentation: [docs/BACKUP.md](docs/BACKUP.md) section 11; plan and what was proven: [docs/PHASE12.md](docs/PHASE12.md). **Tried in Docker on all three database servers, and in the field with a real PrestaShop site (backup and restore worked after one fix, see "Fixed").**

### Changed

- **More security headers.** A scan with OWASP ZAP of the web interface (authenticated, 25 minutes of active scan, plus an unauthenticated baseline) found no vulnerability, but headers that were missing: `Permissions-Policy` (camera, microphone, geolocation, payment and USB off), `Cross-Origin-Opener-Policy: same-origin` and `Cross-Origin-Resource-Policy: same-origin` are now on every page of the web interface and of the three single-file tools, and the style sheet, the script, the fonts and the icon that Apache sends as files got `X-Content-Type-Options: nosniff` and the resource policy from `public/.htaccess` (they had none; the same files sent through `index.php?asset=` had `nosniff` already). Not added: `Cross-Origin-Embedder-Policy` (it protects nothing here and can break a page that loads something from another site), and the `Server` header of Apache (a setting of the host: `ServerTokens Prod`). See [docs/PHASE12.md](docs/PHASE12.md) section 8.
- **Single-file tools on a web server that ignores `.htaccess` (nginx, IIS).** The summary page of `threadsafe-restore.php` now asks the web server for a small file in its state directory (where the database is unpacked as plain SQL) and **does not start the restore while it can be fetched**; the summary of `threadsafe-backup.php` looks at the directory of the tool as well as at the directory of the archive, and the tool's data directory (profile, run records) has a name of 128 random bits (`.threadsafe-backup/data-<random>/`). Found by a code review; see [docs/PHASE12.md](docs/PHASE12.md) section 8.
- The stubs of `threadsafe-restore.php` and `threadsafe-install.php` are made by one class (`Build\ToolStub`); the generated stubs are byte for byte the same as before.
- `Download` and `ByteRange` (the resumable download) live in `src/Web` now, so that the web interface and the backup tool send a file in the same way; they are part of `threadsafe-restore.php` (a few KB).
- The radio buttons of the wizards (`Html::css()`) are drawn in the colours of the design, so that they are visible in the dark scheme.

### Fixed

- **A backup with `utf8mb4_uca1400_ai_ci` was refused on MariaDB 11.4.** The restore's check wanted MariaDB 11.5, the version that made this collation the default; the collations of the UCA 14.0.0 family exist from 11.4 on (looked up on 10.11.18, 11.3.2 and 11.4.13). The rule is one function now (`Preflight::collationObstacle`) with a unit test per server and collation; the refusal on 11.3 and older and on MySQL stays, and its hint names 11.4. Found in the field test of `threadsafe-backup.php`.
- **The database connection with PDO ran several statements sent in one call.** `Connection::run()` is what the restore's importer uses for every statement of a dump, and `pdo_mysql` allows multi-statements by default (`mysqli` already refused them), so a statement that the splitter took for one could have carried a second. The PDO connection now sets `MYSQL_ATTR_MULTI_STATEMENTS` off; a test runs both drivers with two statements in one call (the second is refused and not run). A dump of a database with hostile content (cells and names that look like the end of a statement, `DELIMITER`, comments, a trigger with such a body) is restored by both drivers, in one tick and in one tick per statement, with equal checksums and nothing else done (`HostileDumpTest`).
- **A backup is code, and the documentation did not say so.** [docs/RESTORE.md](docs/RESTORE.md) section 1 and [docs/ENCRYPTION.md](docs/ENCRYPTION.md) section 2 now say: restore only a backup that you made yourself or that comes from a source you trust (it puts files into the web root and runs its SQL as it is; `age` encryption to a public key does not show who made the file). Found by a code review of the SQL import; see [docs/PHASE12.md](docs/PHASE12.md) section 8.
- **Web interface: the backups of a profile are tested too.** The System page asked the browser only whether `data/` can be fetched. It now does the same for the storage directory of every profile that lies below the document root (a file `probe.txt` in it): a web server that ignores `.htaccess` (nginx) would otherwise hand out the backups, whose names have 32 random bits only. See [docs/WEB.md](docs/WEB.md) "System".
- **Web interface: a stranger no longer learns which addresses exist.** `GET /logout` or any other address with the wrong method answered 405 with an `Allow` header before the login was asked for, while an address that does not exist redirected to the login. Now every address redirects to the login until you are logged in.
- **The slow-down of wrong keys and passwords could be got round with requests that arrive together.** The throttle looked at the wait and noted the attempt in two steps, so 30 guesses sent at once against a fresh state were all compared (and only one or two noted). An attempt is now counted before the key, password, code or trigger secret is checked, in one step under a lock on the file (`Throttle::begin()`); it applies to the key of the three single-file tools, the login, the second factor, the setup and the trigger address of the web interface, and the Security page. Found by a code review; see [docs/PHASE12.md](docs/PHASE12.md) section 8.
- **The error page of the three single-file tools told a stranger where they are.** An error before the key was shown (a directory that cannot be made, say) printed the message of the error and the absolute path of the state directory to anybody who asked for the file. Now only a person who has shown the key (a login, or a session) sees them; everybody else gets a general sentence, and the detail goes to the error log of the host. Found by a code review; see [docs/PHASE12.md](docs/PHASE12.md) section 8.
- A file that the backup cannot read gave **two warnings** that contradicted each other: "Stored empty, the file cannot be read" and then "The file changed while it was backed up (read 0 bytes, N when scanned)". Only the first, which names the cause, is given now; a change while a file is read still gets its own warning. [docs/BACKUP.md](docs/BACKUP.md) says what usually makes a file unreadable and what to do.

## [0.9.0-beta.4] - 2026-10-31

The web login can have a **second factor**: a code from an authenticator app. An installation made with 0.9.0-beta.1 to beta.3 can be updated to this version from the System page (or with `threadsafe update`); the factor is off until you switch it on on the new page *Security*. Going back to a release from before it logs in with the password only, see "Changed".

### Added

- A **second factor for the login** of the web interface: a code of six digits from an authenticator app (the standard TOTP of RFC 6238: Aegis, 2FAS, FreeOTP, Google Authenticator, Microsoft Authenticator, a password manager), asked for after the password. It is switched on on the new page **Security** (menu) in three steps: the password again, the key into the app (a **QR code** that the program draws itself, or the key as text), one code; then ten **recovery codes** are shown once. The password alone opens nothing; a code works once; the codes have a throttle of their own; the login ends after five minutes or five wrong codes; switching on or off ends every other login. The way back from a lost phone: a recovery code, `php bin/threadsafe admin two-factor --disable` (it also removes a file that cannot be read), or deleting `data/two-factor.json`. A file of the factor that cannot be read refuses the login instead of letting the password alone in.
- The setting `web.requireTwoFactor` (a box on *System → Settings*, or `admin set web.requireTwoFactor true`): a login without a second factor then reaches the Security page and nothing else. While the factor is off, the installation check of the System page warns that the login is one password.
- `threadsafe admin two-factor [status | --disable]`; `admin status` names the state too.

### Changed

- **Rolling back to a release from before the second factor** logs in with the password only (that release does not know the file `two-factor.json`); the roll-back button on *System → Update* says so when the file exists. The file stays, and the factor is on again with the next newer release.
- `src/Auth` (the second factor) belongs to the web interface and is not part of `threadsafe-restore.php` or the installer.

Documentation: [docs/WEB.md](docs/WEB.md) "Security"; the plan and what was proven: [docs/PHASE11.md](docs/PHASE11.md).

## [0.9.0-beta.3] - 2026-10-30

A small release: the web interface gets an About page. An installation made with 0.9.0-beta.1 or 0.9.0-beta.2 can be updated to this version from the System page (or with `threadsafe update`).

### Added

- The page **About** (behind the login, `/about`), reached from the **version under the name** in the menu: the version, the license (GPL-3.0-or-later, with the text at `/about/license`), the copyright (&copy; 2026 Stefan Schulz, The Loom), the source code, the fonts that are part of the program (IBM Plex, SIL Open Font License) and a block of plain facts for a report of a problem (version, PHP and its limits, drivers, website types; no paths, no secrets) with a copy button. The pages before the login no longer print the version, and the footer no longer repeats it.

## [0.9.0-beta.2] - 2026-10-30

Two fixes found in the field test of 0.9.0-beta.1 on a second host: the installer on a host where the rewrite rule of the `.htaccess` does not work as written, and a backup in the browser that showed no sign of life. An installation made with beta.1 can be updated to this version from the System page (or with `threadsafe update`); the `.htaccess` of the document root is not touched by an update, see the first entry.

### Fixed

- Installer: on a host where the rewrite rule of the `.htaccess` goes round in a circle (every address answered 500, also the style sheet, so the setup page had no style), the installer now writes `RewriteBase` into the `.htaccess` of the document root, from the address it runs at, also for a sub-directory. An installation made with 0.9.0-beta.1 needs the line `RewriteBase /` by hand ([INSTALL.md](INSTALL.md), "When something does not work").
- Web interface of an installation from a release: the style sheet, the script, the favicon and the fonts are always asked for through `index.php?asset=...`, so a rewrite rule that does not work can no longer take away the look of the pages.
- A step of work in a web request (backup, check, restore, installation, update) is now at most 20 seconds long, whatever `max_execution_time` says. A host that reports 50000 seconds made one step the whole backup: eight minutes in one request, with no bar, no label and no sign of life on the page.

## [0.9.0-beta.1] - 2026-10-30

The first release, a **beta**: everything below is built and tested on a Docker stack with MariaDB 10.11, MySQL 8.4 and MariaDB 11.8, and has been used on the maintainer's own sites and server (a PrestaShop shop, a Joomla site, a WordPress site, the installer and the update on a shared host). It has not yet met a variety of other people's sites. Not yet tried on a real site: a WordPress shop with WooCommerce or a caching plugin (only in the Docker lab), a WordPress in a sub-directory or with `wp-config.php` above the site, and the installer on a customer's server. Please report what you find. Not built: remote storage (SFTP, S3), restoring over an existing installation, WordPress multisite.

### Added

- The **Schedule** page (menu): the times of all profiles in one sortable table (by profile, website type and next run), with plain settings instead of cron - every few hours, daily, on chosen days of the week, on a day of the month -, one form for one or several profiles at once, a preview of the next runs, and **pause and resume** of a schedule. The profile form has the same fields; the command line takes `--schedule="daily at 03:30"`, `--pause-schedule` and `--resume-schedule`. The schedule is stored as a plain object in the profile file (no cron text).
- Restore wizard: when the tool is deleted at the end, the page offers buttons that open the restored site and the login of its administration (the website type names the paths: `Restorable::links()`).
- Restore: the option to delete the existing tables of the target database first (`--drop-tables`; a box with a warning in the wizard), for a database that is not empty and has tables with the prefix of the restore.
- The pages of a running backup, check, restore and installation show what the step that is working does right now and a clock, and the buttons "Continue" and "Try the failed step again" can be pressed only where they make sense.
- Restoring a backup into an empty directory and database, on another domain, path or database, with the adaptation of the application
  (PrestaShop 9, Joomla 5 and 6, and any site by the domain in its database): the command `threadsafe restore` and the single file
  `threadsafe-restore.php` with a wizard for a host where nothing else is installed.
- Site profiles and the backup engine: standard ZIP archives with plain SQL dumps, in steps that fit a web request; verify, delete,
  download, pin.
- The web interface: profiles, backups with progress, checks, downloads, the dashboard and the System page.
- One trigger (`threadsafe cron` or an address), schedules, retention, and backups encrypted in the format of `age`.
- The Joomla website type, with a dev mode for a live to dev copy.
- The **WordPress** website type (6.0 and later): backup, restore and `migrate wordpress` on another domain, in a sub-directory, with another database and another table prefix (`wp-config.php` and the old address in the content are adapted, serialized and JSON values stay valid, the user roles follow the prefix, cache drop-ins are switched off), a dev mode for a live to dev copy (no mail, search engines kept out), and a refusal of multisite networks. Documentation: `docs/WORDPRESS.md`.
- A WordPress restore waits while the site shows its maintenance page (an update that its own cron started) and for that cron to finish, and drops the CSS files that Elementor generated from the content (they name the old address). The dev mode of a WordPress copy pauses the webhooks of WooCommerce, so that the copy does not post to the services of the live shop.
- A logo (a safe with a restore arrow) in the menu and the login of the web interface, in the heading of the installer and of `threadsafe-restore.php`, as favicon, and in
  the README.
- Release packages: `threadsafe-X.Y.Z.zip`, the web installer `threadsafe-install.php` (also a ZIP archive), `threadsafe-restore.php` and `SHA256SUMS`,
  reproducible, built by the release workflow; `threadsafe doctor`; updates from a package checked by its SHA-256, switched by a pointer
  and taken back by themselves if the new version does not start.
