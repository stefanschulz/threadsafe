# The web interface

The web interface is the browser view of what the command line does: it shows all sites and the state of their backups at a glance, creates and changes site profiles, makes and checks backups, lets you download them, and hands you the tools to restore one. It adds nothing the command line cannot do (`profile`, `backup`, `backups`, `verify`, `restore`, see [BACKUP.md](BACKUP.md) and [RESTORE.md](RESTORE.md)); both use the same code and the same files, so you can mix them.

How it was designed and built, and the measurements behind it: [PHASE4.md](PHASE4.md).

## What to know first

- **One administrator, one password.** There are no other users or roles.
- **It is off until you switch it on.** A fresh installation answers "not found" on every address and writes nothing. That is on purpose: a tool that can read the files and the databases of all your sites should not offer a login just because it was unpacked on a web server.
- **A backup made in the browser is made in steps.** Every request of the page does a few seconds of work (about 60 % of the PHP time limit) and the next request goes on. You may close the page: the backup stops after the step in hand and can be **continued from where it was**. The database is then read table by table, not in one consistent snapshot as in a command line run (the backup says so in its details). For a shop that changes while it is backed up, prefer the command line for the backups you rely on.
- **One backup at a time**, in the whole installation, whether it was started in the browser or on the command line.
- **The interface does not restore.** Restoring is done by the separate tool `restore.php` on the target server; the interface builds it for you.
- The pages are in English only.

## Installing

**[INSTALL.md](../INSTALL.md) is the guide**: the web installer `install.php` for a host with FTP and a browser (it makes the layout below by itself), the package unpacked by hand, and a clone. This chapter is the reference for the pieces: where the web server points, and the data directory. An installation made by the installer has the home (program in `releases/<version>/`, `data/`, `bin/threadsafe`, the pointer `current.php`) next to the document root, which holds only a stub `index.php` and an `.htaccess`; the chapter [Updating](#updating) is for such installations.

The rest of this chapter is about a directory (a clone of the repository, or an unpacked package) with PHP 8.4 or newer and, for a clone, `composer install --no-dev` run once. The extensions `zlib`, `hash`, `json` and `mbstring` are needed, and `pdo_mysql` or `mysqli` for sites with a database; `sodium` only if backups are to be encrypted (it is a suggestion in `composer.json`, not a requirement, so that a host without it can still install and use everything else). The **System** page (below) tells you what your server lacks.

### Where the web server points

Make **`public/`** the document root. That directory holds only `index.php`, `app.css`, `app.js` and the fonts (`fonts/`, seven WOFF2 files of IBM Plex and their license); `src/`, `data/`, `plugins/`, `vendor/` and the rest are then not reachable by any address.

**Apache:** `public/.htaccess` hands every address to `index.php` (it needs `mod_rewrite` and `AllowOverride All` for that directory; without them set `web.prettyUrls` to `false`, see the settings below, and the pages are `index.php?route=/profiles` instead).

**nginx** (not tried in the test stack; the rule is the usual front controller):

```nginx
server {
    server_name backup.example.org;
    root /srv/threadsafe/public;

    location / {
        try_files $uri /index.php?$query_string;
    }
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }
    location ~ /\. { deny all; }
}
```

**A host where you cannot change the document root** (shared hosting): put the whole ThreadSafe directory below the web root, for example into `public_html/threadsafe/`. The `.htaccess` in its top directory then closes everything, and the one in `public/` opens `public/` again. The interface is then at `https://example.org/threadsafe/public/`. This works on Apache only and it is the weaker layout: the System page and the test below tell you whether the data directory is really closed. Better: keep the **data directory** outside the web root (`THREADSAFE_DATA=/home/you/threadsafe-data`, set in the environment of the web server, e.g. `SetEnv THREADSAFE_DATA ...` in the Apache configuration or the PHP-FPM pool, and also in the shell for the command line).

### The data directory

Everything the installation keeps is in `data/` next to the program (or in `THREADSAFE_DATA`). It holds database passwords of sites and the hash of your password, so it is created with mode 0700 and closed to the web server by an `.htaccess` and an empty index page; it belongs to the user the web server runs as.

| Path | What |
|---|---|
| `admin.json` | the administrator: user name and the password **hash** (Argon2id, or bcrypt where PHP has no Argon2id), mode 0600 |
| `config.json` | the settings below; only what differs from the defaults |
| `profiles/` | one JSON file per site profile (may hold a database password), mode 0600 |
| `runs/` | the state of backups and checks that are going or just ended |
| `sessions/` | the sessions (file names are hashes of the cookie) |
| `throttle/` | the failed logins and setup attempts that slow guessing down |
| `audit.log` (+ `.1`, `.2`) | what was done, one JSON line each |
| `cache/` | the `restore.php` built for the page |
| `probe.txt` | the file for the test whether the data directory can be fetched |

The **storage directory** of a profile (where its backups are) is a directory you name; keep it outside the web root. ThreadSafe puts protection files into it (`.htaccess`, `web.config`, an empty `index.php`) for the case that it is not.

## Switching it on

Either on the command line, in the program directory:

```bash
php bin/threadsafe admin password        # asks twice, or: --password-file=FILE, or THREADSAFE_ADMIN_PASSWORD
php bin/threadsafe admin enable
php bin/threadsafe admin status          # on or off, administrator set, the settings
```

The password has at least 10 characters. It is never an option of the command (the shell history and the process list would keep it).

Or, where you have no shell, **in the browser with a key file**: create a file `setup-key.php` in the data directory with the content

```php
<?php return 'a secret you choose, at least 8 characters';
```

and open the interface. It shows a single page, the setup, where you enter that secret and choose the password. The setup then switches the interface on and deletes the key file. (The file is PHP on purpose: a plain text file would be handed out to anyone who asks for it. While the key file exists and no administrator does, the setup is the only thing reachable, so remove the file if you change your mind.)

`php bin/threadsafe admin disable` switches it off again; the command line is not affected.

**Use https.** Over plain http (from any other machine than the server itself) the login and the setup ask you to confirm that you know the password crosses the network in clear text, and the System page warns about it.

### Logging in

Wrong passwords are slowed down: after three, the next try has to wait 5 seconds, then 10, doubling up to 5 minutes; during the wait even the right password is not accepted. A success clears it. A session ends after two idle hours (a setting) and after 12 hours in any case; logging in again always makes a new session.

## Using it

### The menu and the colour scheme

The menu is on the left: **Dashboard**, **Profiles**, **System**, each with an icon and a name; at the top the mark of ThreadSafe (the letters TS until there is a logo), at the bottom the colour scheme and **Log out**. The arrow at the top **folds the menu to the icons** (the names are then in the tooltips and stay available to screen readers); on a narrow screen the menu is always folded. The **switch at the bottom has three positions: Auto** (the scheme of your system or browser, the default), **Light** and **Dark**. Both choices are kept in two cookies of this page (`threadsafe_nav`, `threadsafe_theme`; they hold one word each, are sent to no one else and have no other use), so the next page is drawn right from the start. The switch and the arrow need script; without script the menu is open and the scheme is the system's. Before the login the page has the mark and the switch in a bar on top. The look is described in [DESIGN.md](DESIGN.md).

### Dashboard

At the top is a **strip of figures**: *Sites*, *Backups*, *In storage* and *Needs a look* (the number of sites with a warning or a problem; amber or red when it is not zero), and *Running* while a backup is going. Each cell has its label above and its value below, with a thin line between the cells; on a narrow screen the cells stack. Below the cards, **Recent activity** shows the last five events of the audit log; the button *See more* leads to the whole log on the System page.

A card for every site, kept short: the **name** in bold with a **pencil** beside it (it edits the profile; no label, a tooltip *Edit profile*), the state on a badge, the **newest backup** (date, time and state) and the **next scheduled backup** (date and time, if the profile has a schedule), the backup that is going, and the **warnings**. Two buttons: **Back up now** and **Profile** (the page of the site with its backups, settings and retention). How long ago, how big and how many backups are on the profile page. The dates and times are shown in the time zone of your browser, without seconds; the tooltip has what the server wrote. The warnings:

| Warning | When |
|---|---|
| no backup yet / no backup that can be used | the storage holds nothing usable |
| no successful backup for N days | older than the setting (7 days) |
| the newest backup is faulty / incomplete | a check found damage, or parts are missing |
| the last backup failed (with the reason) | a run failed and was not dismissed |
| a backup was interrupted | it can be continued or given up |
| little free space | less than 1.2 times the size of the last backup |
| storage not writable / without protection files | |
| the site directory no longer exists | |

A card is **Healthy**, **Needs a look** or **Problem**; the words are on the badge, the colour is only a help. Above the cards is a **filter by website type**: *All* and one entry per type that your profiles have (for example *Other website*, *PrestaShop 9*), each with its number; the chosen one is brass. The entries are links (`/?type=prestashop9`), so the choice is in the address, can be bookmarked and works without script; an unknown type shows all. The totals and the activity below are not filtered. After the cards comes an **empty tile with a plus, *New profile***, **always**, also when a type is chosen and when there are no sites yet: the whole tile is the link to the form for a new site (its edge is grey, because it has no state). Everything on the dashboard is read cheaply (the small `.json` file next to every backup, the disk, the run records): it opens no archive and asks no database. The database is tried only by the **Test the connection** button.

### Site profiles

**Profiles** in the menu is the technical side, for looking after them: a **card for each profile, one under the other, sorted by name** (upper and lower case alike, numbers by their value; the dashboard has the same order). The card has the **name** (it wraps where the card is narrow; one longer than 80 characters is cut, the tooltip has it whole), two icon buttons - the **eye** leads to the page of the profile (its backups, checks and settings), the **pencil** to the form - and the website type at the right. Below are the two **paths** over the whole width (*Site*, and *Backups* for the directory the backups go to) and the **options** that the profile has, in as many columns as fit, each with an **icon** in place of a label (a tooltip names it): a clock for the *schedule* (the cron expression), a box for the *retention* (what it keeps) and a lock for *encryption* (*Encrypted*). An option that is not set is left out. A label or an icon always stands in one line with its value. The schedule, the retention and the keys are set on the edit page of the profile, in the sections *Schedule*, *Retention* and *Encryption*. How the backups are doing (the newest one, the state, the next one) is on the dashboard, and backing up is done there or on the page of the profile; retention and the other settings are on the page of the profile. The same **filter by website type** as on the dashboard stands above the cards (`/profiles?type=generic`); **New profile** is the button at the right of the title, and when there are no profiles yet the page shows the empty tile of the dashboard instead of the cards. A profile whose file is damaged stays in the list with the reason.

**The page of a profile** (the eye on its card) has a head that is always there - the **name** with the pencil that edits it, the type and the state (*Healthy*, *Needs a look*, *Problem*) on badges, the two paths, and what needs a look - and one main action at the right of the title, **Back up now** (or *Follow the backup* / *Continue the backup* while a run is going or interrupted). Under the head are three **sections**, one at a time (`/profiles/shop`, `?tab=retention`, `?tab=settings`; links, so they work without script):

- **Backups** (opens first): a backup that is going or a check that is going, **Back up with options** (folded: ignore the space check, encrypt this one backup with a passphrase) and the list of the backups with their actions.
- **Retention:** the rules, what they would delete now, the last run, the go-ahead and the button *Apply the rules now*.
- **Settings:** database, filters, structure-only tables, encryption, archives and the note, then **Edit**, **Test the connection** and **Keys**, and at the bottom, set apart, **Remove the profile**.

**The form** (new profile and edit are the same form) is a card for each theme. Two are open because they are needed: **Site** (name, website type, site directory with the button *Look at the site*, the note; the id when creating) and **Storage** (storage directory, compression, part size). Four are folded and say in their line what they are set to - **Database** (*Read from the site* or *Entered here*), **What to back up** (the filters and the tables of which only the structure is kept), **Automation** (the schedule and the retention) and **Encryption** (the public keys) - so that one sees what is set without opening them. When a mistake is reported, every section is open. The buttons (*Test and create* / *Test and save*, *Only test*, *Cancel* and the box *Save without testing*) stand in a bar that stays at the lower edge of the window while the form is scrolled. The form needs no script.

*Profiles* → *New profile*. Choose the website type and the site directory and press **Look at the site**: you see what ThreadSafe found (for PrestaShop: the version, the back office folder, the database from `app/config/parameters.php`, how much the type leaves out of a backup by default; for Joomla the same, see [JOOMLA.md](JOOMLA.md)). Give an id (letters, digits, hyphens, underscores; it becomes part of the file names of the backups and cannot be changed) and the storage directory, and press **Test and create**: the profile is tested first (files readable, database reachable, privileges, storage writable, free space ...) and **stored only if the test passes**, unless you tick *Save without testing*. **Only test** shows the report and stores nothing.

The database is read from the site's own configuration where the type can do that, so no second copy of the password exists. For other sites choose *Enter it here*; the password is stored in the profile file and is never shown again (an empty field on an existing profile keeps it).

The form has a *Schedule* section (a cron expression such as `30 3 * * *`; the time zone is the one of the System page; something must call the trigger, see the System page) and a *Retention* section (keep at most N backups, delete after D days, stay under a size). On the profile page the **Retention** box shows the rules, **what they would delete now and why**, and when they last ran; *Apply the rules now* deletes those backups and also tells the trigger that you have seen the rules (a rule that would delete more than one backup waits for that). See [SCHEDULE.md](SCHEDULE.md) (schedules, the trigger, retention) and [BACKUP.md](BACKUP.md) 4.6.

The form also has an *Encryption* section: the **public keys** (one per line, `age1...`; a mistyped one is refused, its checksum is checked) that the backups of this profile are encrypted for. **Keys** on the profile page (or the link in the form) opens the page where a **key pair is made**: the secret key is shown **once**, as the key file of `age-keygen`, with buttons to **copy** it, **save it as a file** and **print** it (the print view shows only the key), and the public key with its own copy button. ThreadSafe keeps nothing of the secret key - not in the profile, the session or the audit log. Only after you tick *I have stored the secret key somewhere else than on this server* is the public key put into the profile. Without the secret key the encrypted backups cannot be opened by anyone; you may also make the pair on your own computer and enter only the public key. See [ENCRYPTION.md](ENCRYPTION.md) and [BACKUP.md](BACKUP.md) section 5.

*Remove* asks first. The profile goes; the site is not touched; the backups stay unless you tick *Also delete its backups*.

### Backing up

*Back up now* (on the card, or on the profile page) opens the page of the run: a progress bar, what is being done, and a **Cancel the backup** button. If the storage seems to have too little room the backup stops with a message; the profile page has an option to go on anyway.

- **Closed the page, lost the connection, the server stopped?** The run becomes *interrupted* about a minute after its last step (longer where the PHP time limit is long). Its page then offers **Continue** (from the saved place, to the same archive that an uninterrupted run would have made) and **Give it up** (removes what it wrote). The command line can do the same: `php bin/threadsafe backup --resume=RUN` and `backups --discard-run=RUN --yes`.
- **A passphrase for one backup:** the start form has a field *Encrypt this backup with a passphrase* (**Suggest one** makes a passphrase of five groups of four characters in the browser, **Copy** copies it). That backup is encrypted for the passphrase only, not for the public keys of the profile. The passphrase is kept only in the state of the run until it ends; write it down before you start. One under 12 characters that was not suggested is refused.
- **A second backup** is refused while one is going, with a link to the running one. An *interrupted* run does not block a new one.
- **A failed backup** removes what it wrote; its page keeps the reason until you dismiss it.
- Browsers without script get a button *Do the next step* instead of the automatic stepping.

### The backups of a site

On the profile page, newest first: when it was made (your local time, the UTC time as the tooltip), size and number of parts, duration, application and version, and the **state**:

| State | Meaning |
|---|---|
| ok | made without warnings (or checked and found intact) |
| ok, with warnings | made, but something was skipped (symbolic links, vanished files, very large cells ...); the details page lists it |
| not checked | found without a record of how it was made (copied in, or the record was lost); only a check says whether it is intact |
| FAULTY | a check found damage |
| INCOMPLETE | parts named in the record are missing or have another size |
| unfinished | only leftovers of a run that did not end |

**Pin it** (on the details page) keeps a backup out of reach of the retention rules; the table then says "(pinned)".

**Details** shows the manifest: when and with what it was made, application, how many files and tables, how the database was read, what was left out, the parts with their SHA-256, the addresses of the shop, warnings.

An encrypted backup shows "(encrypted)" in the state column, and its details page says for which keys or that it is for a passphrase. The web interface holds no key, so **Check** does the quick check of an encrypted backup (size and SHA-256 of the sealed files) and says so; the full check is `php bin/threadsafe verify <profile> --identity-file=FILE`.

**Check** reads every part, compares its size and SHA-256 with the record, reads it as an archive (every entry against its CRC32) and compares the manifest and the counts. It works in steps like a backup and reports the result; a faulty backup is marked in the list. Only one check runs at a time. (`php bin/threadsafe verify` does the same on the command line; `--quick` skips the archive reading.)

**Delete** asks first, removes the parts and the record, and cannot be undone. A backup that a run is still writing, or a check is reading, cannot be deleted.

### Downloading

*Download* lists the parts with their size and SHA-256. Press a button and the browser fetches that part. The address contains a random token that works for that one part, in your session, for two hours; it stops working when you log out. The download can be **resumed** (browsers do it by themselves, `curl -C -` too).

An encrypted backup is downloaded as it is on the server (`.zip.age`, as `application/octet-stream`); only the key opens it, here or elsewhere.

A download through the browser is a convenience, not a dependable transport for large archives: PHP, the web server, a proxy or a CDN in front, and your connection all have limits. Above a size (500 MB by default, a setting) the page says so and gives the **absolute path** on the server, example `scp` and `rsync` commands (with `USER@HOST` for you to fill in) and the checksums to compare afterwards. For several gigabytes use the server's own file access.

If the web server can send files by itself, you can let it (it then also does the ranges and spares PHP): *Apache* with `mod_xsendfile` (`XSendFile on` and `XSendFilePath` for the storage directories) and the setting `X-Sendfile`; *nginx* with an internal location, for example

```nginx
location /ts-files/ { internal; alias /; }
```

and the settings `X-Accel-Redirect` and `/ts-files/`. Try it with a small file first; the headers are tested, a web server that honours them is not part of the test stack.

### Restoring: the restore help

The page of a backup has **How to restore it**: the four steps (get the tool and a key, upload them with the parts, open the tool, answer the questions), the parts to upload with their checksums, and what the target needs (PHP 8.4, an empty directory with room for the files, an empty database if the backup has one). Two buttons make the tools:

- **Download restore.php**: the single-file tool, built from this installation (about 230 KB, the same for every backup and every website type; kept in `data/cache/` so that the second request is instant).
- **Download restore-key.php with a new key** (or *Show a new key instead*): a PHP file that returns a new random key. The key is **not stored** on this server; keep it until the restore is done and delete the file afterwards. Without that file next to it, `restore.php` does nothing.

For an **encrypted** backup the help says so: the wizard asks, on its first page, for the backup's **secret key** (or the passphrase it was made for), which this server does not have, and the target PHP needs the `sodium` extension. The size of `restore.php` is about 275 KB with the decryption in it.

What `restore.php` then does, what it checks and refuses, and how to read its messages: [RESTORE.md](RESTORE.md).

### The System page

The page has five **sections**, side by side under the title; one is shown at a time. They are links (`/system`, `/system?tab=cron`, `/system?tab=settings`, `/system?tab=update`, `/system?tab=activity`), so the choice is in the address and works without script.

**General** - what is known about the installation:

- **Installation check:** the PHP version and the length of one step, the extensions, whether a PHP command line binary is found, https, running as root, how this program is installed (a clone, or a release with its home and the previous release), the data directory (writable, permissions, closed), and for every profile the site directory, **left-over `restore.php`, `restore-key.php` or `.threadsafe-restore` in it**, and the storage (writable, protection files, free space). A left-over restore tool on a web server is a risk; remove it. The same list is available on the command line as `php bin/threadsafe doctor` (exit code 2 if something stops ThreadSafe), also before the interface is switched on; `doctor --release=release.json` says whether the host can run the version that a release package describes.
- **Can the data directory be fetched?** The page writes a small file into `data/` and your browser asks for it at the addresses where `data/` would be served if the program lay below the web root (for an installation from a release whose home lies below the document root, also at the address of the home, `/threadsafe-home/data/probe.txt`). "Not reachable" is what you want. If it says **REACHABLE FROM OUTSIDE**, anyone can fetch your profiles and sessions: close it at once (make `public/` the document root, or move the data directory out with `THREADSAFE_DATA`). With `public/` as the document root nothing in `data/` is served at all, and the test says so.
- **Trigger:** when the trigger (the cron job that makes the scheduled backups happen) called last, a warning when it stays away, and how many profiles have a schedule. How to call it is on the *Cron* section.

**Cron** - what to put into the crontab of the host: the command line, and the address with its secret for a cron that can only fetch an address, with buttons to make, replace or remove the secret (after pressing one you come back here). See [BACKUP.md](BACKUP.md) 4.5.

**Settings** - the form of the next chapter (after saving you come back to it).

**Update** - for an installation from a release (made by the installer, `install.php`): the installed version, the upload of a newer package with the SHA-256 from its release page, the update in steps with a progress bar, and a button that goes back to the previous release. See the next chapter. An installation from a clone is told to use `git pull` and `composer install`.

**Activity** - the audit log, the last 50 events; the button *See more* under the last five events on the dashboard leads straight to it (`/system?tab=activity`).

### Updating

The update never looks for anything on its own: ThreadSafe makes no request to find a new version. You download `threadsafe-X.Y.Z.zip` and note its SHA-256 from the release page, then on *System → Update* upload the file and type the SHA-256 (a file larger than the upload limit of the host, or put there by FTP, goes into `data/updates/` and is offered in a list). The same on the command line: `php <home>/bin/threadsafe update threadsafe-X.Y.Z.zip --sha256=<hash> --url=https://example.org/threadsafe`.

What happens, in this order, and what stays untouched until the step before it has passed:

1. **Check.** The SHA-256 of the file must be the one you gave (otherwise nothing is installed); the manifest `release.json` must be readable; the version must be newer (an older one only if you tick the box); the data directory must not be of a newer format than the package writes; this host must meet the requirements of the package (PHP, extensions). Nothing has changed.
2. **Unpack** into `releases/X.Y.Z.partial/` beside the running release, every file checked against its hash in the manifest. The running program is not touched.
3. **Switch**, once nothing is running (no backup, check or trigger; the update waits, it never interrupts): a copy of the small state files (profiles, configuration, schedule, retention state, administrator) goes to `data/update-backup/`, the release gets its name, and the pointer `current.php` is rewritten. Then the **health check**: ThreadSafe asks its own address for a page that only the new version can answer, with a token it has just stored. If the new version does not answer, or answers as another version, **the pointer and the state files are put back** and the page says what happened. The new version migrates the data at its first start (the health check is that start). A host whose PHP may not make a request to its own address (`allow_url_fopen` off) cannot be checked; the page says so, and says how to go back. A host that cannot reach its own address (a firewall, NAT without loop-back, a certificate PHP does not know; the page then says "no answer" and gives PHP's reason) would take back every update: tick **Do not start the new version to check it** on the form, and the update is switched without the check (on the command line the check is made only with `--url`). Then nothing is taken back by itself; if the page does not open afterwards, go back as described below. Without the rewrite rule (`web.prettyUrls` false) the check asks `index.php?route=/health/<token>` when the path is answered with a 4xx.
4. **After.** The previous release stays for a roll-back (the button *Go back*, or `update --rollback`; the data is not touched, because a release reads the data of the one before it); older releases are removed. The audit log has `update.applied` or `update.rolled_back`. Make a new `restore.php` from the page *Restore*, so that your copy of it is the new version.

If the web interface itself cannot start after an update and the health check was skipped, go back by hand: write the name of the previous release into `current.php` (the second line is JSON: `{"release":"0.1.0","previous":null}`), or run `php <home>/releases/<previous>/bin/threadsafe update --rollback`.

### Settings

On the System page, or in `data/config.json` (only what differs from the default is stored; unknown keys and values out of range are refused).

| Key | Default | Meaning |
|---|---|---|
| `web.enabled` | `false` | the interface exists (set with `admin enable`/`disable`; not on the page, so that you cannot lock yourself out) |
| `web.prettyUrls` | `true` | addresses like `/profiles/shop`; `false` gives `index.php?route=/profiles/shop` for a host without the rewrite rule |
| `web.sessionIdleMinutes` | 120 | idle minutes before the login is asked again (5 to 1440) |
| `dashboard.warnAfterDays` | 7 | warning when the newest good backup is older |
| `download.warnAbove` | 500 MB | size above which the download page warns (at least 1 MB) |
| `download.accel` | `none` | `none`, `sendfile` or `nginx` |
| `download.accelPrefix` | empty | for `nginx`: the internal location, e.g. `/ts-files/` |
| `audit.maxBytes` | 5 MB | the audit log is rotated at this size, two older files are kept |
| `schedule.timezone` | empty | the time zone in which the cron expressions of the profiles are read, e.g. `Europe/Berlin`; empty = the time zone of PHP |
| `schedule.graceMinutes` | 360 | how late a scheduled backup may still be made up (1 to 10080); an older scheduled time is given up |
| `schedule.expectedIntervalMinutes` | 15 | how often the trigger is meant to come (1 to 1440); the warning about a trigger that stays away is derived from it |
| `cron.mode` | `auto` | `auto`, `worker` or `ticks`: how the address of the trigger does its work |

The four rows from `schedule.timezone` on are for the scheduled backups ([BACKUP.md](BACKUP.md) 4.5); they are on the *Settings* section of the System page too. On the command line: `php bin/threadsafe admin set KEY VALUE` (for example `admin set schedule.timezone Europe/Berlin`; an empty value is written as `""`); `admin status` lists all settings. The secret of the trigger address is not a setting (this file is shown on the page): it is in `data/cron-secret`.

### The audit log

`data/audit.log` records, one JSON line each: setup, logins and failed logins, logout, profile created / changed / removed / tested, backup started / finished / failed / given up, check started / done, backup deleted, downloads (start and end, bytes sent), restore tool and key made, settings changed, password changed and interface switched on or off from the command line. It never holds a password, a key, a token, a cookie or a file content; a field whose name sounds like a secret is dropped before writing.

## Security in short

- Off by default; the setup in the browser needs a key file that only someone with access to the server can create.
- Passwords only as hashes; slowed-down guessing; a new session at every login; cookies are `HttpOnly` and `SameSite=Strict` (and `Secure` over https); sessions end after idle and absolute times.
- Every change is a `POST` with a token that belongs to the session, and requests that name another origin are refused; nothing changes on a plain link.
- A strict content security policy (no inline script or style, no other origin), no framing, no caching of pages.
- The storage is never served by path; a download needs the token of your session.
- Files that are not meant for the web are closed by the document root or by `.htaccess`; the System page tests it from your browser.
- **A compromised interface can read every site on the server.** Run it behind https, keep the web server's user unprivileged, consider an additional HTTP authentication in front, and switch it off (`admin disable`) when you do not need it. The command line is the preferred way for anything automated.

## When something does not work

| Symptom | Cause and remedy |
|---|---|
| Every address says "Not found" | the interface is off (`php bin/threadsafe admin status`), or no administrator exists and there is no `setup-key.php` |
| The login page works, all other pages say "Not found" or the server's own error | the rewrite rule is missing: enable `mod_rewrite` and `AllowOverride All`, add the nginx rule, or set `web.prettyUrls` to `false` in `data/config.json` |
| "Too many wrong attempts" | wait the seconds it names; it clears after a right password |
| The backup stops after a few steps | the connection was lost or the page was closed: open the run again and press Continue |
| "Another backup is running" | one at a time; follow the running one, or give an interrupted one up |
| A step fails with a PHP memory or time message | the limits of the host are lower than a single unit of work needs; the profile page's test and the System page show them. Use the command line for that site |
| Downloads break off | see Downloading: fetch the file with `scp` / `rsync` |
| "Cannot create the directory ..." / the interface cannot write | the data directory must belong to the user the web server runs as |
| The session ends at once | the host does not keep the session files (a cleaner of temporary files, a read-only directory): `sessions/` in the data directory must be writable |

## Limits

- One administrator; no roles; English only.
- Backups made in the browser read the database table by table (no snapshot); the command line can take a snapshot.
- Storage is a local directory; there is no remote storage, schedule, retention or encryption yet (phase 5).
- The interface cannot restore, and the restore tool restores only onto an empty directory and database ([RESTORE.md](RESTORE.md) section 9).
- The web server modes `X-Sendfile` and `X-Accel-Redirect` and the nginx rule above are documented but not tried in the test stack.
- Tested on Apache with `mod_php`; PHP-FPM and nginx should work the same way (nothing in the code depends on the SAPI) but are not in the test stack.
