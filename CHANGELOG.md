# Changelog

All notable changes of ThreadSafe. The format follows [Keep a Changelog](https://keepachangelog.com/); the versions are
[semantic](https://semver.org/). A tag `vX.Y.Z` makes a release only if this file has a section `## [X.Y.Z]` with notes: they are the text
of the GitHub release (see "Making a release" in [AGENTS.md](AGENTS.md)).

## [Unreleased]

### Fixed

- Installer: on a host where the rewrite rule of the `.htaccess` goes round in a circle (every address answered 500, also the style sheet, so the setup page had no style), the installer now writes `RewriteBase` into the `.htaccess` of the document root, from the address it runs at, also for a sub-directory. An installation made with 0.9.0-beta.1 needs the line `RewriteBase /` by hand ([INSTALL.md](INSTALL.md), "When something does not work").
- Web interface of an installation from a release: the style sheet, the script, the favicon and the fonts are always asked for through `index.php?asset=...`, so a rewrite rule that does not work can no longer take away the look of the pages.

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
