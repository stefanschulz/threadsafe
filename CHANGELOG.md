# Changelog

All notable changes of ThreadSafe. The format follows [Keep a Changelog](https://keepachangelog.com/); the versions are
[semantic](https://semver.org/). A tag `vX.Y.Z` makes a release only if this file has a section `## [X.Y.Z]` with notes: they are the text
of the GitHub release (see "Making a release" in [AGENTS.md](AGENTS.md)).

## [Unreleased]

### Fixed

- **A site that was taken over could fill the disk and the memory of the central ThreadSafe.** A part of a backup was written for as long as the site sent, whatever size it had listed, and an answer of the agent was collected in memory without an end. A transfer is now cut at the size that was listed, an answer at 4 MB, and a sidecar file above 4 MB is not fetched.
- **Anybody who knew the address of an agent could stop the backups of the site.** Requests without a good signature were counted for all senders together, and while the count made the next attempt wait, the signed request of the central ThreadSafe was turned away too, which ended its run. The signature of every request is checked now (a secret of 256 random bits needs no protection against guessing); wrong ones are still logged in `agent.log`, and only the log is slowed down, so that a flood cannot fill the disk. The list of addresses that may ask closes the agent to everybody else and is still the advice.
- **The agent accepted a request whose nonce it could not write down** (a full disk), so that the request could have been sent a second time within the time window. It is refused now.

## [1.1.0-rc.1] - 2026-11-10

The first release candidate of 1.1.0: the **connector**, first cut (phase 13). A site that cannot run ThreadSafe itself (no second website, no cron) is backed up by `threadsafe-backup.php` working as an **agent**, and a ThreadSafe elsewhere plans, pulls and keeps the backups. It is a pre-release for a field test of a week by schedule; the WordPress plugin that installs and pairs the agent is not part of it yet, and the user documentation follows with 1.1.0 (the plan and what is built: [docs/PHASE13.md](docs/PHASE13.md) section 9). **Nothing changes for an installation that does not use it:** the agent is off in every tool that has not been paired, and a stranger who asks it gets the same answer as for a file that is not there. An installation of 1.0.0 can be updated to this version from the System page (or with `threadsafe update`); the format of the files in `data/` has not changed.

### Added

- **The agent in `threadsafe-backup.php`.** A signed API (status, start, tick, list, part, done, abort): every request carries a time, a nonce and a signature over method, path, body and time, made with a secret that only the pairing holds; the time window is 120 s and a nonce is accepted once. It makes **encrypted backups only** (the host never holds the secret key), keeps the newest backups after one is safe elsewhere (`keepOnHost`), and never removes one that has not been fetched. Set it up on the new page *The agent* of the wizard (pair, switch on and off, who may ask, plain http only on request) or in a shell with `php threadsafe-backup.php agent --setup ...`. The workspace (settings, run records, archives) can lie outside the directory of the tool, so that a replaced tool keeps the pairing and the archives (`THREADSAFE_WORKSPACE`, `--workspace`).
- **A site with an agent as a profile** on the central ThreadSafe: `threadsafe profile add <id> --pairing-file=FILE --storage=DIR`, or the form *New remote profile* in the web interface. The backup, the schedule, the retention, the catalog, `verify` and the restore treat it like any other profile. The central side starts the backup on the site step by step, brings the parts over with resume (`Range`, checked by SHA-256) and tries again after a failure that may pass. `profile test` asks the agent and reports its version, the clock, the limits and the free space of the site.

### Known limits of this candidate

- The tool has no button to remove itself when it was used only as an agent: switch the agent off, forget the pairing, delete the files.
- `profile test` of a site with an agent says "a backup is running there now" for the last run on the site, whatever its state.
- A run that the central side gives up leaves the run on the site marked as running for up to 3 x 0.6 x `max_execution_time` seconds (7 minutes at 240 s); a new backup started within that time takes that run up instead of making another.
- `backup` and `restore` on the command line print nothing between their first and their last line.
- Not tried: a full disk on the site (the free space the agent reports is that of the volume, not of the quota), two requests at once, a restore of an agent's backup with the wizard.
- Tried in the field so far: one WordPress site on a host with PHP 8.4 as `cgi-fcgi` and a firewall plugin (backups of 10.5 GB and 1.8 GB, `verify` with the key, a restore on a new domain). A week by schedule is what this candidate is for.

### Fixed

- **A restore ended as failed because the server could not look up the new name.** The self-test asks the restored site over HTTP, by its new domain, from the server itself. When the DNS record is not there yet (or a container has no resolver), every request fails on the lookup of the name and nothing is known about the site; the restore stopped with "the adaptation failed", although files, database and adaptation were done and the site worked. When **every** request of the self-test fails on the lookup of the name (and no `--self-test-url` names another address), the self-test now ends with a warning that says so and what to do, and the restore is done. Any other reason for no answer (connection refused, timeout, certificate), a page that answers wrongly and one page that fails while another answers are failures as before. Found in the field on a WordPress site; the same change is in the self-tests of PrestaShop, Joomla and the generic type.
- **The message of a failed self-test said nothing about how to go on.** It tells now that "Try the failed step again" (on the command line the same command) asks the self-test once more. That way existed before (a failed run goes on from the state of its last good tick); a unit test proves it for the self-test.

## [1.0.0] - 2026-11-05

The first stable release. The program is the one of 0.10.0-beta.4 (no code changed); what changed is the status: the owner field-tested it on real sites and servers and closed the beta phase.

**What was tried in the field:** PrestaShop, Joomla and WordPress sites, backed up (by the app, and by `threadsafe-backup.php` with a key pair and with a passphrase), restored on another domain with the wizard `threadsafe-restore.php`, the installation and the update from the System page, the schedule, the encrypted backups, and the two-factor login (also a recovery code). The Docker proof runs on MariaDB 10.11, MySQL 8.4 and MariaDB 11.8.

**Known limits** (named, not hidden): a WordPress shop with WooCommerce or a caching plugin has not been tried on a real site (it is proven in Docker with the plugins of real shops); sites of other people have not been tried much, so please report what you find; restoring over an existing installation, remote storage, a WordPress multisite and a MySQL 8 backup onto MariaDB are not supported (see [docs/RESTORE.md](docs/RESTORE.md) section 9). An installation of 0.9.0-beta.1 or later can be updated to this version from the System page (or with `threadsafe update`); the format of the files in `data/` has not changed.

## [0.10.0-beta.4] - 2026-11-05

Three fixes of the restore from the field test: the done page of the wizard shows the warnings of the adaptation, the buttons to the restored site come back for an encrypted backup, and a sentence in the report of older backups of `threadsafe-backup.php` that said nothing about the restore is left out. An installation of 0.9.0-beta.1 or later can be updated to this version from the System page (or with `threadsafe update`); the format of the files in `data/` has not changed.

### Fixed

- **No buttons "Open the site" and "Administration login" after the restore of an encrypted backup.** The page "The tool is gone" reads the type and the new domain from the archive, but the secret key or passphrase of an encrypted backup is not in the session any more once the run has started (the run has it until the files are unpacked), so the archive could not be opened and the page said nothing. It reads the manifest that the run kept now. Found in the field (backups of `threadsafe-backup.php` are encrypted by default).
- **The done page of the restore wizard did not show the warnings of the adaptation.** It listed the warnings of the checks before the restore and of the extraction, and a table of the steps, but not what the steps themselves had to say: a value that kept the old domain because it would not fit its column (0.10.0-beta.3), structured values that could not be parsed, a table without a primary key. The command line printed them, the wizard did not. The page lists them now ("Please read", with the step and the table.column; at most 30). Found in the field.
- **The restore of a backup made with `threadsafe-backup.php` before 0.10.0-beta.3 still showed "The backup itself reported: Storage: The storage directory is inside the site ...".** The sentence is part of the report inside the archive, so 0.10.0-beta.3 (which does not write it into new backups) could not remove it from old ones. The restore (command and wizard) now leaves out exactly this sentence for backups of the backup tool (profile `site`); every other warning of a report is shown as before. Found in the field.

## [0.10.0-beta.3] - 2026-11-05

Three fixes from the field test: a restore that stopped on a value that no longer fitted its column, a warning that frightened without reason, and a button that did nothing visible. An installation of 0.9.0-beta.1 or later can be updated to this version from the System page (or with `threadsafe update`); the format of the files in `data/` has not changed.

### Fixed

- **The restore failed in the step "replace-content-domains" with "Data too long for column 'old_url'".** A new domain that is longer than the old one made a value in a nearly full `varchar` column (found: the redirects of a Joomla site) too long for the column, and the server refused the `UPDATE` (error 1406) and stopped the whole step, after the files and the database had been restored. The replacement now knows the length of every text column: a value that would not fit keeps the old domain, and the report (also the plan of a dry run) says how many values of which column; the run goes on. It is the replacement that all website types share (PrestaShop, Joomla, WordPress, generic). Found in the field.
- **A backup made with `threadsafe-backup.php` carried the warning "The storage directory is inside the site ... a web server could serve its files", which the restore showed as "The backup itself reported".** The tool puts its archive into `.threadsafe-backup/storage-<random>/` on purpose (closed by `.htaccess`, unguessable, and the run does not start if the probe can fetch it), so the warning was noise. It is not given for that directory any more; a storage that the person chose inside the site still gets it. Backups made before keep the sentence in their report; it is harmless. Found in the field.
- **"Give up this backup" in `threadsafe-backup.php` did nothing visible.** While a step was working (the tool is in a step almost all the time) the server refused to remove the run, and the page just reloaded and went on. The button now stops the page from starting new steps, says "Giving up ... waiting for the step that is working to end", asks the server again every 1.5 seconds until the step has ended (at most its time budget), and then removes the backup and shows the summary. If the step that was working turns out to be the last one, the page shows the finished backup instead of removing it. Found in the field.

## [0.10.0-beta.2] - 2026-11-05

Two small hardenings from the review of the update, the crypto and the controllers; no vulnerability was found. An installation of 0.9.0-beta.1 or later can be updated to this version from the System page (or with `threadsafe update`); the format of the files in `data/` has not changed.

### Fixed

- **A line break at the end of an id, a version or a route was accepted.** PHP's `$` matches before a final line break, so `"shop\n"` was a valid profile id and `/profiles/shop%0A` a route. The patterns of ids, run ids, versions, the random part of a backup name, the work directories of the backup tool, the cron secret and the router have the modifier `D` now. Nothing could be reached by it; found by the review of the update, the crypto and the controllers (see [docs/PHASE12.md](docs/PHASE12.md) section 8).
- **A failed update did not put back `two-factor.json`.** The copy of the state files that an update takes (and restores if the new version does not start) was made before the second factor existed. Today no migration touches that file, so nothing was lost; it is in the copy now.

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
