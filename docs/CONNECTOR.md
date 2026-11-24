# The connector: back up a site that cannot run ThreadSafe

For a site on a host where ThreadSafe cannot live (no second website, no cron, no shell), but where a single PHP file can run. A small **agent** on the site makes the backups when a ThreadSafe elsewhere (the **central** one: your computer, a server of your own) asks it to. The central ThreadSafe plans them, pulls the parts over, checks them, keeps them and applies the retention, like for any other profile. The design and its reasons are in [PHASE13.md](PHASE13.md).

Terms: the **agent** is `threadsafe-backup.php` working in a mode that answers signed requests; the **pairing** is the shared secret and the address that tie one site to one profile of the central ThreadSafe; the **pairing string** is what you copy from the site to the central side once.

## 1. What you need

- On the site: PHP 8.4 or newer, and for WordPress the plugin and for PrestaShop the module below, or the file `threadsafe-backup.php` of a release ([BACKUP.md](BACKUP.md) section 11).
- On the central side: ThreadSafe 1.1.0 or newer ([INSTALL.md](../INSTALL.md)), with the web interface or the command line.
- A key pair for the backups: they are **always encrypted** and the site never holds the secret key ([ENCRYPTION.md](ENCRYPTION.md)). The wizard can make one; keep the secret key somewhere else than on the site.

## 2. Set it up with a plugin or a module

### WordPress (the plugin)

1. Download `threadsafe-connector-X.Y.Z.zip` from the release, upload it in the WordPress admin area (*Plugins > Add New > Upload*) and activate it. It needs PHP 8.4 and says so instead of failing on an older PHP.
2. Open *Tools > ThreadSafe*. The page folds: the section that is next is open, the done ones say in a line what they hold.
3. **What to back up:** paste your **public key** (`age1...`), say what to leave out and the size of a part. If the page finds archives of other backup plugins (UpdraftPlus, All-in-One WP Migration, BackWPup and others), it offers a button to leave them out; nothing is left out unasked. Save.
4. **The agent:** give the id of the profile (the name of the profile on the central side), check the address (it must be the public address of `agent.php` of the plugin), optionally the addresses that may ask, and press **Pair**. The **pairing string** is shown **once**: copy it with the button. It holds the secret of the pairing; treat it like a password.
5. Switch the agent **on**.

Where things live: the plugin is in `wp-content/plugins/threadsafe-connector/`; the settings, the pairing and the archives are in `wp-content/uploads/threadsafe-<random>/`, outside the plugin directory, so that a plugin update keeps them. A pointer file `wp-content/threadsafe-agent.php` finds that directory. All of it is left out of the backups, and a restored copy of the site has no agent (a copy cannot use the pairing of the live site).

### PrestaShop (the module)

For PrestaShop 9.0 (a shop with one shop, no multistore). It does the same as the plugin and looks the same.

1. Download `threadsafe-connector-prestashop-X.Y.Z.zip` from the release and upload it in the back office (*Modules > Module Manager > Upload a module*). It needs PHP 8.4 and says so instead of failing on an older PHP.
2. Open the module's *Configure* page. Steps 3 to 5 of the WordPress plugin are the same: your public key and what to leave out, the id of the profile and **Pair** (the pairing string is shown **once**), then switch the agent **on**. The address to enter is that of `modules/threadsafeconnector/agent.php` on the public address of the shop.

Where things live: the module is in `modules/threadsafeconnector/`; the settings, the pairing and the archives are in `var/threadsafe-<random>/`, outside the module, so that an update of the module keeps them; the web server does not serve `var/`. A pointer file `modules/threadsafe-agent.php` finds that directory. All of it is left out of the backups, and a restored copy of the shop has no agent.

PrestaShop's `modules/.htaccess` denies every PHP file; the module brings its own `.htaccess` that opens only `agent.php`. On a web server that ignores `.htaccess` (nginx) the door answers 403 and `profile test` says so: allow that one file in the server's configuration. Disabling the module switches the agent off, enabling it brings the same pairing back, and uninstalling removes the pairing and leaves the archives unless the box on the page says otherwise. Clearing the cache does not touch the workspace.

## 3. Set it up with the file (any PHP site)

Use `threadsafe-backup.php` as in [BACKUP.md](BACKUP.md) section 11: in the wizard the step *The agent* pairs the site and switches the agent on and off; in a shell `php threadsafe-backup.php agent --setup ...` does the same. `THREADSAFE_WORKSPACE` (or `--workspace`) puts the settings and the archives outside the directory of the tool, so that a replaced tool keeps them.

## 4. The central side

Put the pairing string into a file (it is a secret: not on the command line), then:

```bash
threadsafe profile add shop --pairing-file=pairing.txt --storage=/var/backups/shop
threadsafe profile test shop       # the agent's version, the clock, the limits, the free space
threadsafe backup shop             # one backup now
threadsafe verify shop --identity-file=key.txt
```

In the web interface the form *New remote profile* takes the string. An existing profile gets a new pairing with `threadsafe profile edit shop --pairing-file=FILE`. A profile with an agent is scheduled, kept and restored like any other ([SCHEDULE.md](SCHEDULE.md), [RESTORE.md](RESTORE.md)): the central side starts the backup on the site step by step, brings the parts over with resume (checked by SHA-256) and tries again after a failure that may pass. The site keeps the newest archives (`keepOnHost`) and removes one only after it was fetched.

## 5. Security in short

- Every request is signed (time, a nonce that counts once, method, path and body) with the secret of the pairing; the time window is 120 seconds. To anybody else the agent looks like a file that is not there.
- A request without a good signature never makes a good one wait. It is logged in `agent.log` (the log alone is slowed down).
- The list of addresses that may ask closes the agent to everybody else; set it when the central side has a fixed address.
- The central side does not trust what a site sends: a part is cut at the size that was listed, an answer at 4 MB.
- Plain `http` is refused unless you ask for it in the setup; use https.
- Deactivate the plugin or module (or switch the agent off) and the central `profile test` says the agent does not answer; activate it again and the same pairing works. Deleting the plugin removes the pairing and leaves the archives unless the box says otherwise.

## 6. Updating and removing

An update of the plugin or module (upload the newer ZIP over it and replace) keeps the pairing, the settings and the archives. To remove everything by hand: switch the agent off, forget the pairing on the plugin page, delete the plugin and the directory `wp-content/uploads/threadsafe-<random>/`.

## 7. Limits

- **Tried in the field:** one WordPress site on a host with PHP 8.4 as `cgi-fcgi` behind a firewall plugin, backups of 1.8 to 10.5 GB with the file tool, then seven nights of the plugin (about 1.9 GB each, four encrypted parts), `verify` with the key, and a restore with `threadsafe-restore.php` on a new domain. **Not tried:** a site of someone else's, a shop with WooCommerce on this host, a full disk on the site (the free space the agent reports is that of the volume, not of the quota), two requests at once.
- **One agent, one profile.** There is no remote storage in this version, and a restore always goes through the wizard or the command with the key.
- The tool has no button that removes itself when it was used only as an agent (section 6).
- `backup` and `restore` on the command line print nothing between their first and their last line.
- A run that the central side gives up leaves the run on the site marked as running for up to 7 minutes; a new backup started within that time takes it up instead of making another.
- The PrestaShop module is proven in Docker (PrestaShop 9.0.2 on three database servers, 9.1 and 9.2.1 on MariaDB 10.11) and was tried on one real shop (PrestaShop 9.0.2: pairing, two backups, `verify`, off and on, a restore with the wizard). PHP older than 8.4 at the door and a host without `.htaccess` are not tried; a restored shop has no agent (the tool leaves itself out of every backup), so the module is installed and paired anew there.
- Joomla and other satellites are not part of this release.
