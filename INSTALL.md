# Installing ThreadSafe

Three ways, from the easiest for a host with only FTP to the one for a developer. The way you choose does not change what you get; only
the updates differ (section 5).

| You have | Use |
|---|---|
| FTP (or a file manager) and a browser, no shell | **the web installer** `install.php` (section 2) |
| a shell, and no wish to use Composer | **the package** `threadsafe-X.Y.Z.zip`, unpacked by hand (section 3) |
| a shell, Git and Composer | **a clone** of the repository (section 4) |

## 1. What the host needs

- **PHP 8.4 or newer**, as the web server's PHP. Many hosts offer it as a setting in the control panel. If the installer says it needs PHP 8.4,
  that is the setting to look for.
- The extensions `zlib`, `hash`, `json`, `mbstring` and `pdo`, and `pdo_mysql` or `mysqli` for sites with a database. `sodium` only if
  backups are to be encrypted. `php bin/threadsafe doctor` (or the installer's first page) says what is missing and what to ask the host for.
- A directory that PHP may write in: the document root for the small `index.php`, and a place for the program. No database, no Composer, no
  shell, no cron access are needed to install; a cron job (or something that calls an address) is needed for scheduled backups, see
  [docs/SCHEDULE.md](docs/SCHEDULE.md).

## 2. The web installer

1. **Download** `install.php` and `SHA256SUMS` from the [releases page](https://github.com/stefanschulz/threadsafe/releases) of the project. Check that the SHA-256 of the
   file is the one in `SHA256SUMS` (`sha256sum -c SHA256SUMS`, or any tool that shows a SHA-256). That shows that the file arrived whole and
   is the one on the release page; it does not prove who made it.
2. **Upload** `install.php` to the **document root** of the site, the directory the web server serves for the address you want
   (often `public_html/` or `htdocs/`). Use an empty directory or one with no `index.php` and no `.htaccess`: the installer writes both and
   never overwrites yours.
3. **Make the key.** Next to `install.php` create a file `install-key.php` with this content, and a secret of your own choice (at least
   8 characters):

   ```php
   <?php return 'a secret you choose';
   ```

   It is a PHP file on purpose: a plain text file could be fetched by anyone. Without it the installer shows an explanation and does nothing.
4. **Open** `https://your-site.example/install.php`, enter the key, and follow the pages:
   - **Check and place.** The page lists what this host allows and what the release needs. Then you choose where the program goes, from tiles; one is
     marked, and a place that is not possible here is shown with the reason:
     - **Next to the document root** (`../threadsafe`): nothing of the program can be fetched from outside. Recommended; it needs PHP to be
       able to write in the directory above the document root.
     - **In a directory this host allows**: on a host that lets PHP use only some directories (`open_basedir`) the installer offers one of them
       outside the web root, often `private/`. Recommended as well.
     - **Below the document root** (`threadsafe-home/`), marked "weaker choice": closed by an `.htaccess`. This works on Apache; on nginx or IIS the
       directory would be reachable unless you add a rule that denies it. The tile has its own confirmation ("I run Apache, or I have added a
       rule ..."), which you tick for this choice.
     - **Another directory**: a full path, new or empty, that PHP may use and write in.
   - **Install.** The installation works in steps and shows a progress bar; each step fits the time limit of the host. Keep the page open; if
     it is closed, open the installer again and it goes on. Every file is checked against the manifest of the release while it is written.
   - **Remove the installer.** Press the button: it deletes `install.php`, `install-key.php` and its own work files. If PHP may not delete
     them, the page says which to delete by hand. **Do not leave the installer on the server.**
   - **Set up.** The last page leads to the setup page of the interface with the key filled in; choose the password of the administrator and
     log in.
5. Open **System → General** and look at the installation check. For scheduled backups put the command from **System → Cron** into your
   crontab, or call the address from there.

**If the host does not let you install outside the document root** (no writable directory above it, and `open_basedir` allows nothing else), choose the tile **Below the document root**. The home is then `threadsafe-home/` in the document root, closed by an `.htaccess` (and an empty `index.html`). That works on **Apache with `AllowOverride` set so that `.htaccess` files count** (the usual setting on shared hosting); the installer makes you confirm it. It does **not** work on nginx or IIS, which ignore `.htaccess`: there you must add a rule that denies the directory (`location ^~ /threadsafe-home/ { deny all; }` for nginx), or install elsewhere. The data directory holds the database passwords of your sites and the hash of your password, so **test it**: after the setup open *System → General*, which asks your browser for a file in `data/` at the address where it would be served ("Not reachable" is right), and try `https://your-site.example/threadsafe-home/data/probe.txt` yourself: it must give an error, not the text `threadsafe-data-probe`.

What the installer left: in the document root only `index.php` (a few lines that find the active release) and `.htaccess`; in the home
`releases/X.Y.Z/` (the program), `data/` (everything that is yours: profiles, settings, the administrator, runs; mode 0700, closed to the web),
`bin/threadsafe` (the command; use this one in cron jobs, it survives updates) and `current.php` (names the active release).

**Back up `data/`** together with your sites: it holds the profiles and the settings. The backups themselves are in the storage directories you
chose for the profiles, outside the web root.

## 3. The package, unpacked by hand

For a host with a shell. `install.php` is also a ZIP archive, so `unzip install.php` works as well.

```bash
unzip threadsafe-X.Y.Z.zip
cd threadsafe-X.Y.Z
php bin/threadsafe doctor                 # what the host allows
php bin/threadsafe admin password         # the one administrator
php bin/threadsafe admin enable           # switch the web interface on
```

Make `threadsafe-X.Y.Z/public/` the document root of a site (or, on a host where you cannot change it, follow [docs/WEB.md](docs/WEB.md)
"Where the web server points"). This way has no home and no pointer, so the System page cannot update it; use the command line, or install
again.

## 4. A clone (for developers)

```bash
git clone https://github.com/stefanschulz/threadsafe.git && cd threadsafe
composer install --no-dev
php bin/threadsafe doctor
```

Then as in section 3. The System page says "installed from a clone": update it with `git pull` and `composer install --no-dev`.

## 5. Updating

An installation made by the installer is updated from **System → Update**: download `threadsafe-X.Y.Z.zip` and note its SHA-256 from the
release page, upload the file, type the SHA-256, and the update is checked, unpacked beside the running version and switched; if the new
version does not start it is taken back by itself. The same works on the command line:

```bash
php /path/to/threadsafe/bin/threadsafe update threadsafe-X.Y.Z.zip --sha256=<hash> --url=https://your-site.example/
php /path/to/threadsafe/bin/threadsafe update --rollback        # back to the previous release
```

ThreadSafe never looks for a new version on its own. All of it is in [docs/WEB.md](docs/WEB.md) "Updating".

## 6. When something does not work

| What you see | What to do |
|---|---|
| "This installer needs PHP 8.4 or newer" | Switch the PHP version of the site in your host's control panel, or ask the host. |
| "This installer does nothing until you give it a key" | Create `install-key.php` next to `install.php` (step 3). |
| "Too many wrong keys. Wait ..." | Wait the seconds it names; wrong keys are slowed down. |
| A problem on the check page | The sentence says what to ask the host for (an extension, a writable directory). Press "check again" when it is done. |
| `"index.php" exists in ... already` | The installer does not overwrite your files. Move the file away, or install into an empty directory. |
| The check page says a place is "outside the directories that this host lets PHP use (open_basedir: ...)" | The host restricts PHP to a few directories (the setting `open_basedir`; common on managed hosts). The installer then offers an allowed directory outside the web root by itself, often `private/`: choose it. Do not choose a place that is not in the list the message names. |
| "PHP cannot make ..." for the home | The directory above the document root is not writable for PHP. Choose the place below the document root, or a directory of your own that PHP can write in. |
| The installation stops with a message about a hash | The file `install.php` is damaged or was changed. Upload it again and compare its SHA-256. Nothing is activated before the last step, so the installation can be started again. |
| After the setup every page says "not found" | The host has no rewrite rule. Set `web.prettyUrls` to `false` in `data/config.json` (`{"web": {"prettyUrls": false}}`); the pages are then `index.php?route=/login`. See [docs/WEB.md](docs/WEB.md). |
| The installer is still there after the last page | Delete `install.php`, `install-key.php` and the directory `.threadsafe-install/` by hand. Do it now. |
| An update says the new version could not be started to check it | The host's PHP may not make requests to its own address. If the interface does not open after the update, go back with `update --rollback` or by writing the previous release into `current.php` ([docs/WEB.md](docs/WEB.md) "Updating"). |

## 7. Removing ThreadSafe

Delete the home directory (and the `index.php` and `.htaccess` the installer put in the document root). Your backups are in the storage
directories of the profiles; they stay where they are until you delete them.
