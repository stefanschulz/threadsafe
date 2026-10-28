# Changelog

All notable changes of ThreadSafe. The format follows [Keep a Changelog](https://keepachangelog.com/); the versions are
[semantic](https://semver.org/). A tag `vX.Y.Z` makes a release only if this file has a section `## [X.Y.Z]` with notes: they are the text
of the GitHub release (see "Making a release" in [AGENTS.md](AGENTS.md)).

## [Unreleased]

Nothing is released yet. Rename this heading to the version (`## [0.1.0] - date`) and start a new `[Unreleased]` above it when you tag.

### Added

- Restore wizard: when the tool is deleted at the end, the page offers buttons that open the restored site and the login of its administration (the website type names the paths: `Restorable::links()`).
- Restore: the option to delete the existing tables of the target database first (`--drop-tables`; a box with a warning in the wizard), for a database that is not empty and has tables with the prefix of the restore.
- The pages of a running backup, check, restore and installation show what the step that is working does right now and a clock, and the buttons "Continue" and "Try the failed step again" can be pressed only where they make sense.
- Restoring a backup into an empty directory and database, on another domain, path or database, with the adaptation of the application
  (PrestaShop 9, Joomla 5 and 6, and any site by the domain in its database): the command `threadsafe restore` and the single file
  `restore.php` with a wizard for a host where nothing else is installed.
- Site profiles and the backup engine: standard ZIP archives with plain SQL dumps, in steps that fit a web request; verify, delete,
  download, pin.
- The web interface: profiles, backups with progress, checks, downloads, the dashboard and the System page.
- One trigger (`threadsafe cron` or an address), schedules, retention, and backups encrypted in the format of `age`.
- The Joomla website type, with a dev mode for a live to dev copy.
- A logo (a safe with a restore arrow) in the menu and the login of the web interface, in the heading of the installer and of `restore.php`, as favicon, and in
  the README.
- Release packages: `threadsafe-X.Y.Z.zip`, the web installer `install.php` (also a ZIP archive), `restore.php` and `SHA256SUMS`,
  reproducible, built by the release workflow; `threadsafe doctor`; updates from a package checked by its SHA-256, switched by a pointer
  and taken back by themselves if the new version does not start.
