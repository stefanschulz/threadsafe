# Phase 15 - The connector for PrestaShop: a module, the second satellite

Status: **plan; work package 0 (the spikes) done 2026-11-22, findings in section 8**; the service `prestashopconnector` of the stack exists (profile `psconnector`). Nothing of the module is built. The decisions of section 7.2 are open. Companions: [PHASE13.md](PHASE13.md) (the agent, the central side, the WordPress plugin: read 3.5 and 9.12 first), [CONNECTOR.md](CONNECTOR.md) (the user guide), [BACKUP.md](BACKUP.md) section 11 (the tool).

## 1. Goal and why now

The owner's PrestaShop site runs ThreadSafe on **the same server as the shop**, a server with no SSH. Backups that lie on the host they protect are the weak point. The connector already solves this for WordPress: a thin plugin places the single-file tool `threadsafe-backup.php`, pairs it with a **central** ThreadSafe elsewhere, and that one plans, pulls, checks and keeps the backups. PHASE13.md 3.5 said "PrestaShop modules would be possible and are not wanted for now"; the owner now wants it, so that decision is reversed here.

**Done when:** a module `threadsafeconnector` installs in a PrestaShop 9 back office from a ZIP, pairs the shop with a central ThreadSafe, survives a module update and a deactivate/activate, and a backup made through it restores with `threadsafe-restore.php`. Proven in Docker first (section 5), then on the owner's shop.

## 2. What exists and what is missing

| Part | State |
|---|---|
| The agent in `threadsafe-backup.php`, the central side (`RemoteSettings`, `AgentClient`, `profile test`) | done, field-tested with WordPress |
| The PrestaShop 9 website type (detect, database access from `app/config/parameters.php`, backup, restore) | done, field-tested |
| `Web\AgentPlugin` (pointer file, workspace, profile from the site, keys, patterns, part size) | done, but its documentation and constructor speak of WordPress (`ABSPATH`, `wp-content`, `wp-config.php`) |
| `Build\ConnectorBuilder` (the ZIP of the WordPress plugin) | done for WordPress only |
| `Web\AgentPlugin`'s site detection for PrestaShop | **to check** (spike 1) |
| The module, its page, its ZIP, its stack proof | **missing** |

## 3. Design

### 3.1 A thin wrapper again

The module does what the plugin does and nothing more (PHASE13.md 3.5): place the tool, show the state and the pairing string once, switch the agent on and off (install/enable and disable/uninstall), set the public keys, the patterns and the part size. It carries no backup logic and loads nothing of PrestaShop for a tick. It checks PHP 8.4 before anything else (`ps_versions_compliancy` is for PrestaShop's version, not PHP's; the check goes in `install()` and at the door).

### 3.2 The door: the one real PrestaShop difference

**Finding (2026-11-22, image `prestashop/prestashop:9.0.2-apache`):** `modules/.htaccess` denies every request to `*.php`, `*.json`, `*.txt`, `*.log`, `*.tpl` and more, for the whole tree under `modules/`. The WordPress plugin's `agent.php` is reached directly; in PrestaShop that address answers 403. Three ways, to be settled by spike 2:

| Option | How | For | Against |
|---|---|---|---|
| A | The module ships its own `.htaccess` in `modules/threadsafeconnector/` with `<Files "agent.php"> Require all granted </Files>`; a deeper `.htaccess` is applied after the parent's | the door stays inside the module, an update replaces it, nothing outside the module is written | Apache with `AllowOverride` only; nginx and hardened hosts ignore it. Many PrestaShop modules do this, but this must be proven on 9.x |
| B | On activation the module writes a small door file into the shop root (`threadsafe-agent.php`) and removes it on deactivation/uninstall | works wherever PHP runs in the root; the same pointer idea as WordPress | a file outside the module; the shop's `.htaccess` rewrite rules must let it through (it is a real file, so they do) |
| C | A front controller (`index.php?fc=module&module=threadsafeconnector&controller=agent`) | PrestaShop-native, no `.htaccess` | loads all of PrestaShop on every tick (memory, time, the 5 s limit of modest hosts) and breaks "the door loads nothing" (PHASE13.md 9.2); a cookie or maintenance mode can change the answer |

**Recommendation:** A, with B as the fallback when the module's own self-test (the module page requests its door and reports 403) shows A does not work on that host. C only if both fail. The self-test is the same idea as the reach probe of the WordPress page.

### 3.3 Where things live

- **Module:** `modules/threadsafeconnector/` (the tool `threadsafe-backup.php`, `agent.php`, `.htaccess`, the module class, `index.php` guards as PrestaShop expects).
- **Workspace** (state and archives): outside the module, so an update keeps it: `var/threadsafe-<random>/` or `upload/`? `var/` is cleared in parts by cache clearing and is denied by `.htaccess`; this is decision 7.2 (1), spike 3 shows what clearing the cache does to it.
- **The pointer** (`modules/threadsafe-agent.php`? or in the shop root): the same job as `wp-content/threadsafe-agent.php`: names the workspace and is the on/off switch. It must not live in `modules/threadsafeconnector/` (replaced by an update); and `modules/*.php` is denied from the web, which is fine for a file the door reads from disk.
- **Left out of the backups:** the workspace, the pointer, the door; and **a restored copy has no agent** (as in WordPress).
- **The existing ThreadSafe on the same host:** its `data/` and storage must not end up inside the agent's backup. Check how the tool's self-exclusion (`Web\BackupTarget`) treats another installation; if it does not, the module page offers the pattern, as it does for the archives of other WordPress backup plugins.

### 3.4 What the module page asks

Configuration through PrestaShop's `getContent()` (a back-office page, the PrestaShop way: the form helper or a plain Twig/Smarty template, spike 4): the public keys (`age1...`), patterns, part size, the profile id, the address of the door (checked: it must be the shop's real public address, over https), the allow list, **Pair** (the pairing string shown once, with a copy button), the switch. A failure shows on top, as in the plugin. Multistore: the first cut supports one shop context and says so (decision 7.2 (4)).

### 3.5 Library mode and `AgentPlugin`

`AgentPlugin` becomes CMS-neutral where it is WordPress-specific: the names of the roots, the strings of the documentation. A small interface or constructor parameters, not a second class; the WordPress plugin keeps working unchanged (its tests are the proof). The module calls the tool in library mode as the plugin does (PHASE13.md 9.12, M5.1).

### 3.6 Security

Unchanged from PHASE13.md 3.6 and 9.11: signed requests, a nonce that counts once, an allow list, https, the secret key never on the host. New for PrestaShop: the pairing string is shown once on a back-office page that already requires the admin session and the module's CSRF token (PrestaShop provides `Tools::getAdminTokenLite`); the module page needs the permission to configure modules and no other.

## 4. Work packages

| # | Package | Result |
|---|---|---|
| 0 | Spikes (section 6): the door, the workspace and cache clearing, the module install by CLI and in the back office, PrestaShop 9.0 and 9.1 | findings in section 8; the plan corrected |
| 1 | `AgentPlugin` neutral for PrestaShop (tests: PrestaShop layout in PHPUnit, WordPress unchanged) | green `composer check` |
| 2 | The module: class, install/enable/disable/uninstall, the door, the pointer | installs in the stack, answers `profile test` |
| 3 | The back-office page: settings, pairing, the switch, the self-test | pairs through the page |
| 4 | `Build\ConnectorBuilder` makes `threadsafe-connector-prestashop-X.Y.Z.zip` as well (the release workflow, `SHA256SUMS`, `tools/test-release.php`) | the ZIP in a release |
| 5 | The Docker stack and the proof (section 5), all three database servers | phase 4i of `run.sh`, group `connector-ps` |
| 6 | Documentation: `CONNECTOR.md` section for PrestaShop, `README.md`, `AGENTS.md` table, `CHANGELOG.md` | the user guide |
| 7 | The owner's field test on the real shop (pair, two backups, `verify`, deactivate/activate, a restore with the wizard) | report in section 8 |

## 5. Infrastructure for local development and tests (Docker)

Wanted by the owner: a PrestaShop instance and a ThreadSafe that runs as the central side, both local.

- **PrestaShop for the module:** a new service `prestashopconnector` in the profile `connector-ps`, image `prestashop/prestashop:${PS_TAG}`, **its own database** on the existing `db` service, **its own volume**, the limits of a modest host (the same `target-restore-limits.conf` as `wordpress7`, 5 s / 64 MB), port 8590. It must be its own shop, not the source shop `prestashop` (`ps_data`): that shop is the baseline of the migration and restore tests, and a module in it would change what they compare. Installing a PrestaShop takes minutes; a script `tests/stack/seed-prestashop-connector.sh` installs it once and keeps the volume (as `seed-wordpress.sh` does).
- **The central ThreadSafe:** the existing profile `web` (port 8390, data and storage volumes) is a running ThreadSafe instance and serves as the central side for manual tests: it reaches the shop by its service name. The automated proof uses the `tools` container as the central side, as `ConnectorStackTest` does. No new central service is needed; what is new is a short **how-to for a manual round trip** in `tests/stack/README.md`.
- **Starting point on the owner's machine today:** the volumes `threadsafe-dev_ps_data*`, `…_fresh_www` and the images `prestashop/prestashop:9.0.2-apache`, `9.1-apache` and `8.1-apache` exist; the containers of the stack are stopped (exited 3 days ago). The source shop `ps_data` is **not** reused for the module.
- **Tests:** `ConnectorStackTest` is WordPress-specific (`WordPressClient`). The PrestaShop proof is a second class `PrestaShopConnectorStackTest` with a small `PrestaShopClient` (back-office login, token, module page), sharing the helpers where they are the same. Proof points: install and enable with `bin/console prestashop:module install`, the page pairs, `profile test` answers, a backup, `verify` with the key, deactivate (`profile test` says the agent does not answer), activate (same pairing), update (replace the directory; pairing and archives stay), uninstall, restore of the backup with `threadsafe-restore.php` into the target shop, a real PHP below 8.4 is refused at the door.

## 6. Spikes (work package 0)

1. **Site detection:** does `AgentPlugin`, with the shop root, build a good profile for the PrestaShop 9 type (database access from `app/config/parameters.php`)?
2. **The door:** A, B and C of 3.2 on 9.0.2 and 9.1 behind the stack's Apache, with the shop's own `.htaccess` generated by PrestaShop (friendly URLs on and off) and with the 5 s / 64 MB limits.
3. **The workspace:** where it can live so that neither a cache clear, nor the module's "reset", nor an update removes it; mode of the files PHP creates there.
4. **The back-office page:** the least code that gives a form with a CSRF token on 9.x; the permissions; what `Configuration::updateValue` is for (small settings) and what stays in the workspace (secrets).
5. **Install paths:** ZIP upload in the back office, `bin/console prestashop:module install`, and by hand over FTP (the owner has no SSH: the back office or FTP are the real ways).
6. **Module ZIP rules:** the directory name equals the module name; the files PrestaShop's validator expects (`index.php` in every directory is a convention, not a rule for private modules).

## 7. Decisions

### 7.1 Settled (by the request)

- A **module** for PrestaShop, analogous to the WordPress plugin; PrestaShop 9.x first.
- Developed and proven in **Docker** with an own PrestaShop and the existing ThreadSafe `web` service as the central side.

### 7.2 Open (my recommendation first)

1. **Workspace location:** `var/threadsafe-<random>/` (denied by PrestaShop's `.htaccess`, outside the module). Alternative: `upload/`. Settled by spike 3.
2. **The door:** A, then B, then C (3.2).
3. **PrestaShop versions:** 9.0 and 9.1 (the owner's shop is 9.x); 8.x only if the module is the same code; not tested for it.
4. **Multistore:** not supported in the first cut; the page says so and pairs one shop.
5. **The existing ThreadSafe on the shop host:** keep it running for local backups beside the agent, or shut it down? That is the owner's choice; the module does not touch it, but the backup must not include its storage (3.3).
6. **Release:** a pre-release `1.3.0-rc.1` for the stack proof and the field test, then `1.3.0` (a new minor: a new satellite, as 1.1.0 was). The ZIP name `threadsafe-connector-prestashop-X.Y.Z.zip`; the WordPress ZIP keeps its name.
7. **Joomla:** stays "later" (PHASE13.md 3.5); this phase does not start it.

## 8. Findings

*(to be filled by the spikes and the field test)*

Spikes run 2026-11-22 on `prestashop/prestashop:9.0.2-apache` (service `prestashopconnector`, port 8590, own database `psconn`, 5 s / 64 MB limits as for the WordPress site), PHP 8.4.15. A throw-away module `threadsafeconnector` and a test door were used and removed again; the shop is clean.

1. **The door is denied by default.** `modules/.htaccess` denies PHP files in the whole `modules/` tree (see 3.2). A direct `agent.php` in the module directory answers 403.
2. **Spike 2, the door: A and B both work (Apache).**
   - A: a `.htaccess` in `modules/threadsafeconnector/` with `<Files "agent.php"> Require all granted </Files>` (and the Apache 2.2 `Allow from all` inside `IfModule`) turns the 403 into 200. B: a file `threadsafe-agent.php` in the shop root answers 200 at once (the shop's `.htaccess` lets real files through). C was not tried: A works, and C's cost (all of PrestaShop per tick) is the reason against it. **Settled: A first, B as fallback**, as recommended. Not tried: nginx and a host that ignores `.htaccess`; the module's self-test (3.2) has to say so there.
3. **Spike 3, the workspace: `var/threadsafe-<random>/` is good.** A directory there survived `bin/console cache:clear --env=prod`; `var/.htaccess` denies the web (403 for a file in it). `upload/` and `config/` have a `.htaccess` as well. **Settled (decision 7.2 (1)):** `var/`. The files created by PHP belong to `www-data` with the modes the tool sets itself.
4. **Spike 1, detection: works unchanged.** `ProfileService::autodetect('/psconnector')` answers `prestashop9` and reads the database access from `parameters.php` (`tools/spikes/ps-connector-detect.php`). In code only the check `$type !== 'wordpress'` in `AgentPlugin::profile()`, the messages and the doc comments are WordPress-specific; the rest is neutral (work package 1 is small).
5. **Spike 4, the page: the legacy `getContent()` works in 9.0.2.** A back-office login with `curl` (POST to `AdminLogin`) lands on the dashboard with both tokens in the address; `index.php?controller=AdminModules&configure=threadsafeconnector` redirects to the Symfony route `/improve/modules/manage/action/configure/threadsafeconnector?_token=...` and prints what `getContent()` returns. The same flow is the base of a `PrestaShopClient` for the stack test. A form posts back to the same page; the CSRF token to use is `Tools::getAdminTokenLite('AdminModules')`.
6. **Spike 5, install paths:** `bin/console prestashop:module install|disable|enable|uninstall threadsafeconnector` all work (run as `www-data`). **Uninstalling a module does not delete its files** (`modules/threadsafeconnector/` stays), so the module's own `uninstall()` must remove the pointer, the door (B) and, if the person ticks it, the workspace. `install()` can refuse on PHP below 8.4 (`$this->_errors[]`, return false). Not yet tried: the ZIP upload of the back office and an update by replacing the directory (work package 3 and 5).
7. **Infrastructure notes.** The image installs the shop on the first start only if the database exists: it does not create it (`CREATE DATABASE psconn` first; the seed script of work package 5 does that). A failed first start leaves a `parameters.php` behind, and the next start then skips the installer and the shop answers 500: remove the volume and start again. The tools container mounts the shop at `/psconnector`.
8. **Still open for the later packages:** spike 6 (the ZIP rules: directory name = module name, checked at the upload), PrestaShop 9.1 (the image is local), the allow list and the pairing through the page.
