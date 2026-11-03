# Phase 13 – the connector: a site that cannot run ThreadSafe is backed up, planned and kept by a ThreadSafe elsewhere

**Status:** **planned, not built** (2026-11-03; written down at the owner's request after a discussion; nothing is decided to be built, the decisions of section 7.2 are for the owner, each with a recommendation).
**Context:** [PHASE12.md](PHASE12.md) is `threadsafe-backup.php`, the one-off backup of a site without ThreadSafe (its wizard, its shell mode, its closed directories); [CONCEPT.md](CONCEPT.md) section 9 "A remote source" is the older idea of a profile that reads its site over SFTP, which this plan replaces with a better one for the case below; [SCHEDULE.md](SCHEDULE.md) is the trigger and the schedule that stay as they are; [BACKUP.md](BACKUP.md) section 11 describes the tool; [WORDPRESS.md](WORDPRESS.md) and [JOOMLA.md](JOOMLA.md) the website types.

## 1. Goal and scope

**The case.** A WordPress site on a host where nothing but the site itself can be installed (no second website, so no ThreadSafe), with no cron and probably no shell. PHP 8.4 runs (a `cgi-fcgi` build; the limits of a request, say 30 s, are read at run time). The owner has a ThreadSafe with cron elsewhere and wants **planning, retention and the catalog there**, and the backups to arrive there without a person clicking through the wizard of `threadsafe-backup.php` every time.

**What exists today.** `threadsafe-backup.php` makes an encrypted backup of the site it lies in, in steps that fit a request, and offers a download per part with resume ([BACKUP.md](BACKUP.md) section 11). It has no schedule, no retention, no list of sites, and **nothing that a program can drive**: its only door is the wizard in a browser, with a login by key file and a session. The engine, the encryption (the host needs only the public key), the throttle and the download (`Web\Download`, `Web\ByteRange`) are there.

**What is missing:**

1. **A door for a program**: start a backup, run its steps, list the parts, fetch them, tell the host that a part has arrived. Signed, throttled, off when switched off.
2. **The other side**: a profile that is a *remote site*: the schedule of ThreadSafe starts the backup there, runs it step by step over HTTPS, pulls the parts, checks them and files them in its own storage as an ordinary backup (so that listing, checking, retention and the restore wizard work unchanged).
3. **A way to install and switch it for people who work in the CMS**: a thin **plugin** (WordPress first, Joomla if it is wanted) that brings the tool, shows the pairing string and has the off switch, and lets the administrator choose the directory of the archives.

| In scope | Out of scope (later, or never) |
|---|---|
| the **agent** mode of `threadsafe-backup.php`: a signed API with `status`, `start`, `tick`, `list`, `part`, `done`, `abort` | **SFTP / FTP** in either direction (PHP has no SFTP built in; the pull is HTTPS with `Range`; an SFTP copy by hand stays possible, the files are ordinary) |
| a **remote profile** (`remote` block) and its run in ticks: start, steps, pull with resume, SHA-256 check, sidecar, the word to the host | a **restore** onto the remote host (the restore wizard of `threadsafe-restore.php` is for that, unchanged) |
| the **backup directory** as a setting of the host side (plugin form, wizard, shell), also outside the web root | a push from the host to the central ThreadSafe (the central side asks, the host never calls out) |
| the **WordPress plugin** as the packaging (install, pairing, switch, directory); the Joomla one as an option | WP-Cron as the scheduler (it runs on page views only) |
| tests on the Docker stack behind a real Apache with limits (all three database servers), a field test on the owner's host | a hosted service, several users, roles |

## 2. Deliverables

1. `threadsafe-backup.php`, agent mode: `Web\Agent` (the API: the actions of 3.1 on `Request` and `Response`, like the wizards), `Web\AgentSignature` (the signing and the check, the replay store), `Web\AgentConfig` (the pairing secret and the settings on the host side), reuse of `BackupService`, `Throttle`, `Download`, `BackupWorkspace`, `BackupTarget`.
2. The location of the **workspace** and of the **archives** as a setting (3.4): a key in the host's configuration, `--workspace` / `--storage` in the shell, the field in the plugin.
3. `Profile\SiteProfile`: the optional block `remote` (`url`, `keepOnHost`, the secret in the profile file, mode 0600, like a database password); `profile add|edit --remote-url=... --pairing-file=...`; the form in the web interface; `profile test` calls `status`.
4. `Remote\RemoteBackupRun` (a `Task`): the phases of 3.3, state in plain data, resumable at every phase and at every byte of a part; `Remote\AgentClient` (HTTPS with PHP streams or curl, signed, `Range`).
5. The **plugin** `threadsafe-connector` for WordPress (`plugins` here means a ZIP for WordPress, not a ThreadSafe website type): header with `Requires PHP: 8.4` and an early check, activation, the settings page, the pairing string, the off switch, uninstall; built by `tools/build-release.php` as `threadsafe-connector-X.Y.Z.zip`; the Joomla one as a later work package.
6. Tests (section 4), documentation (`BACKUP.md`, `SCHEDULE.md`, `WEB.md`, a new `CONNECTOR.md` for the person who installs it, `README.md`, `CHANGELOG.md`, `AGENTS.md`, this plan's "As built").

## 3. Design

### 3.1 The agent API

One address on the host, `threadsafe-backup.php?api=<action>` (or the same path of the plugin's copy of the tool). It answers JSON, never HTML, and only when the agent is **switched on** (the switch is a flag in the host's configuration; the plugin's activate and deactivate set it; off, the answer is the same 404-like page a stranger gets today).

| Action | Method | What it does |
|---|---|---|
| `status` | GET | version, PHP and its limits (`max_execution_time`, `memory_limit`), the type and the version of the site, free space, the state of the last run, the time of the host (for the clock check). Used by `profile test`. |
| `start` | POST | starts a run with the profile of the host (3.4), the one-at-a-time rule and the space check of the app. Returns the run id. |
| `tick` | POST | one step of at most the budget of the host (20 s); returns the progress (the same words the wizard shows) and whether the run is finished. |
| `list` | GET | the backups on the host: name, parts with size and SHA-256, the sidecar file, status. |
| `part` | GET | one part or the sidecar, with `Range` / `If-Range` and the SHA-256 as `ETag` (`Web\Download`, unchanged). |
| `done` | POST | the central side confirms that it holds a backup whole (it names the SHA-256 of every part); the host may delete older ones by its rule `keepOnHost`. |
| `abort` | POST | gives up the run and cleans up, as the wizard's "give up". |

**Signed requests, no password and no `Authorization` header.** Every request carries `X-ThreadSafe-Time`, `X-ThreadSafe-Nonce` and `X-ThreadSafe-Signature` = HMAC-SHA256 (a 256-bit secret shared at pairing) over method, path with query, time, nonce and the SHA-256 of the body. The host accepts a time within ±120 s, remembers the nonces of that window (a small locked file) and refuses a repeat. A wrong signature counts in `Throttle::begin()` as a wrong key does today (and, per the open finding 2 of the review, the global count is accepted for backups: they are not time-critical). Why not the REST API of WordPress with application passwords: it loads the whole of WordPress (all plugins, the cache plugin, the firewall plugin) for every step of 20 s, and on `cgi-fcgi` a host often does not pass the `Authorization` header to PHP at all. Custom headers usually pass; if a host strips them too, the same three values may travel as query parameters (decision 5, spike 1).

**What the API does not do: it does not configure** (decision 2). It cannot change the recipients of the encryption, the directory of the archives, the exclusions or the database access; these are set on the host by a person with the rights of the CMS (3.4). A leaked secret then lets an attacker start backups and read encrypted archives they cannot open; it does not let them add their own key to later backups.

### 3.2 Pairing and the keys

1. On the host (plugin page, wizard, or `php threadsafe-backup.php agent --pair` in a shell) the agent makes the secret and shows the **pairing string** once: `ts1:` and base64url of `{url, secret, profile-id, fingerprint}`; the secret is stored in the host's configuration (mode 0600, a file in the workspace with the random name of `BackupWorkspace`).
2. On the central ThreadSafe the person makes the profile (`--pairing-file` or the field in the form), and makes a **key pair** there (`keys new`): the **secret key stays on the central side**, the public key is pasted into the plugin page (or `--recipient` in a shell). Nothing on the host can decrypt a backup, also not an old one.
3. `profile test` calls `status` (signed) and shows the PHP limits, the type and the clock difference.

The `profile-id` of the pairing is the id the central profile has: the host makes its backups under that name (`BackupTarget::profile()` takes it instead of `site`), so that the pulled files, whose names and sidecar carry the profile id, are ordinary members of the central profile's storage (open point 3, spike 2).

### 3.3 The remote profile and its run

A profile with a `remote` block has no local site; its `backup` is `RemoteBackupRun`, a task like every other, started by the existing schedule, the trigger and `backup <profile>`:

1. **start**: `status`, then `start` (a run already going on the host is taken up, not doubled); the run id goes into the state.
2. **tick**: `tick` in a loop, each call within the budget of the central tick; a failed request is retried with a pause, the run on the host keeps its state and continues (its records are the app's).
3. **list**: the parts and their SHA-256 from `list`.
4. **pull**: every part with `Range` from the byte that the local `.part` file has (as the browser resumes today), checked against the SHA-256; the sidecar file the same way. The files appear in the storage of the profile **only when whole and checked** (written to the work directory first, as the encrypted parts are today).
5. **done**: `done` with the SHA-256 list; the host keeps `keepOnHost` backups (default 1: so that a transfer that failed in the night can be fetched again) and deletes older ones.

The sidecar is **fetched, not made**: the sidecar of an encrypted backup is minimal and cannot be made again from the archive ([ENCRYPTION.md](ENCRYPTION.md) section 2), and the catalog lists by sidecar. Retention, the pins, the checks and the restore work on the pulled backup as on any other. A failed run is visible like any failed backup (the dashboard, the heartbeat).

### 3.4 The directory of the archives (and of the state)

A setting of the host side, with a default that survives updates:

- **Where:** the field in the plugin ("Directory for backups"), the options page of the wizard (exists), `--storage` and a new `--workspace` in the shell. It may lie **outside the web root** (the parent of the document root is often allowed on a shared host); the checks are the ones that exist (`SitePath`, `open_basedir`, symlinks, writable, free space: `BackupTarget`, `BackupWizard`), and the test of reach is needed only for a directory inside the web root.
- **Default:** `wp-content/uploads/threadsafe-<random>/` for WordPress (inside the web root: closed by `.htaccess` and tested by the probe), **never inside the plugin directory**: WordPress replaces the whole directory of a plugin when it updates or uninstalls it, which would delete the backups and the pairing. For the same reason the **state of the tool** (profile, run records, the pairing secret) lives in the workspace there, not next to the file of the tool; today the tool unpacks itself into `.threadsafe-backup/` next to itself, so a **setting for the place of the workspace** is a change of the tool (work package 2).
- **Who may set it:** only a person with `manage_options` (WordPress) or the corresponding right; the path is checked like every path of the tool: no stream wrapper (`phar://`, `php://`), no program directory, no directory of another site.
- **Changing it later:** archives that exist stay where they are; the plugin says so and offers to move them or to leave them. The agent knows one directory at a time, so `list` shows what the current one holds; the central profile pulls and then asks the host to forget what it has (`done`).

### 3.5 The plugin (WordPress) and its sibling (Joomla)

A packaging, nothing more: it **places the tool** (and, in agent mode, no key file: the pairing replaces it), **shows the state and the pairing string**, **switches the agent on and off** (activate/deactivate), and **sets the directory** and the **public keys**. It does not carry logic of the backup, loads nothing of WordPress for a tick (the agent is the tool's own entry, which reads the database access from `wp-config.php` through the existing WordPress type), and checks `Requires PHP: 8.4` before it does anything else. The settings page also shows the result of the reach probe of the directory, the free space, the time of the last backup and the clock difference. Uninstall removes the workspace; the archives only if the box is ticked.

**Joomla** (Joomla 5 and 6, which ThreadSafe already supports) needs the same thing: a system plugin for the install, the switch (enable/disable in the plugin manager) and the settings (the pairing string as a read-only parameter, the directory and the keys as fields), with `php_minimum` in the manifest; a page of its own would need a small administrator component and can wait. The agent is the same; only the wrapper differs. PrestaShop modules would be possible and are not wanted for now.

**Distribution:** a ZIP in the GitHub release, uploaded in the CMS. The directory of wordpress.org will probably not take the self-unpacking single file (a payload archive inside code is hard to review); a build with the plain source files would be needed for it, and is not planned. Joomla's JED is the same.

### 3.6 Security, in short

- **A resident door on a production site** is new: the one-off tool takes itself off after the backup. Mitigations: the switch (off by default until paired; off when the plugin is deactivated), HTTPS only (the agent refuses a plain-http request unless the owner ticks it, as the login does), a 256-bit secret, signed and timed requests, the throttle, an optional **IP allowlist** in the host's configuration (the address of the central server), and no action that configures.
- **A firewall plugin** (NinjaFirewall on the owner's host) may block a regular signed request with a long path: to be tried on the real host (spike 3); the answer may be a rule for the address of the agent.
- **The database password** lies in the workspace as in `wp-config.php` (the tool reads it from there, or the profile holds it, mode 0600).
- **The review of this session applies:** the three single-file tools got the throttle that counts before it compares, the closed directories with a reach probe, no message before the key, and the security headers; the agent reuses them and adds the signature.

## 4. Tests

- **Unit:** the signature (a changed method, path, query, body, time or nonce is refused; a repeat is refused; the window; a missing header), the throttle on wrong signatures, each action through `Request`/`Response` objects (`WizardHttpTest` style) including the switch, the 404-like answer when off or unpaired, the plain-http refusal, `Range` on `part`, the rule `keepOnHost`, the settings of 3.4 (a path with a stream wrapper, the plugin directory, a symlink), the pairing string (round trip, a damaged one), `RemoteBackupRun` against a fake client (each phase, a failure and a resume at every phase and at every byte of a part), the profile field and its validation, the sidecar that is fetched.
- **Integration on the Docker stack (all three database servers):** the central ThreadSafe (the tools container) backs up a WordPress site (`wordpress7`) served by a real Apache **with the limits of 5 s / 64 MB** through the agent: the steps run in many requests, the parts arrive with the right SHA-256, one transfer is cut and resumed, the backup is listed, checked, kept by retention and **restored with the restore wizard** onto another domain (the existing disaster-recovery tail); the agent switched off answers like a stranger; a replayed request is refused; a wrong secret is throttled. As a group `agent` in `run.sh` (a phase of its own after 4e).
- **The plugin:** installed in `wordpress7` and `wordpress6` with WP-CLI (it is in the tools image), activated, paired, run; deactivated (the door is closed), reactivated; the plugin directory replaced as an update does (the archives and the pairing survive); uninstalled (the workspace is gone, the archives as ticked); a PHP below 8.4 is simulated (the early check, no fatal error).
- **Field test (the owner):** the plugin on the real host (PHP 8.4 `cgi-fcgi`, no cron, NinjaFirewall), the first backup by hand, then by the schedule of the central ThreadSafe for a few days; the limits in practice (time per step, the size of the archive, the transfer).

## 5. Work packages

1. **Spikes** (a day): (1) do the custom headers `X-ThreadSafe-*` reach PHP on the owner's host (`cgi-fcgi`)? the fallback in the query string; (2) a pulled backup in another profile's storage: how `BackupName`, the sidecar and `BackupCatalog` treat the profile id, so that the pairing's id works, or what has to change; (3) what NinjaFirewall says to a regular signed request; (4) the size of the transfer through the host's limits (a 700 MB archive in parts of 100 MB); findings go into section 8.
2. **The agent in the tool:** `Web\Agent`, `Web\AgentSignature` with the replay store, `Web\AgentConfig` (the switch, the pairing secret, the allowlist, the settings), `agent` command for the shell (`--pair`, `--on`, `--off`, `--recipient`, `--storage`, `--workspace`), the setting for the place of the workspace, `BackupEntry` routes `?api=`; the unit tests; the bundles (`BackupBuilder`'s file lists; the restore tool does not carry it).
3. **The central side:** the `remote` block and its validation, `Remote\AgentClient`, `Remote\RemoteBackupRun` and its registration where the engine starts a backup (`BackupService`), the form and the commands, `profile test`; the unit tests.
4. **The proof on the stack:** the group `agent` as a phase of `run.sh`, all three database servers.
5. **The WordPress plugin:** the files, the settings page (a view in the style of `docs/DESIGN.md`, checked in a browser at both widths and both schemes), the build and its test in `tools/test-release.php`, the stack tests of section 4.
6. **The documentation**, including `CONNECTOR.md` for a person who installs the plugin and pairs it, and the limits that are known.
7. **The field test** (the owner).
8. *(Optional)* **The Joomla wrapper**, with the stack's `joomla5` and `joomla6`.

Packages 2 to 4 are the real work; 5 is a few hundred lines of PHP around them. The order keeps the agent CMS-neutral, so that the second wrapper costs only its own package.

## 6. Risks and open points

- A host or a firewall that blocks or rewrites the regular signed requests (spike 3): the fallback is the query string, and in the worst case no agent on that host; the manual wizard remains.
- The **number of requests**: a backup of a few hundred MB is some dozens of 20-second steps and as many requests for the parts; a host with a low limit of simultaneous or per-minute requests may refuse some. The central side retries with a pause.
- A pulled backup in the catalog (open point 3, spike 2): the profile id in the name and in the sidecar must agree with the central profile.
- **The clock:** a difference of more than the window makes every request fail; `status` reports the host's time, and `profile test` says so in words.
- **Plugin updates** replace the plugin directory: everything that must survive lies in the workspace (3.4), and a test proves it.
- The tool needs **PHP 8.4**: an older PHP on the host cannot run any of it; the plugin says so instead of failing.
- A resident agent is a **permanent target** (3.6): it is the price of the planning from outside; a person who wants no resident door uses the wizard by hand, as today.

## 7. Decisions

### 7.1 Settled

None yet: the plan is the result of a discussion on 2026-11-03; the owner has not decided to build it.

### 7.2 For the owner (each with a recommendation)

1. **Signed requests of our own, or the REST API of WordPress?** *Recommended: signed requests of our own* (3.1): CMS-neutral, no WordPress load per step, no `Authorization` header.
2. **May the API configure?** *Recommended: no* (3.1): the recipients, the directory, the exclusions and the database access are set on the host only. The price: the pairing needs a visit to the plugin page once.
3. **The pulled backup's profile id** (3.2): the pairing carries the central profile's id and the host makes its backups under it. *Recommended*, to be confirmed by spike 2.
4. **Resident or one-shot?** *Recommended: resident, switched off by default and off when the plugin is deactivated*, with an optional IP allowlist; the one-off tool with its self-removal stays as it is.
5. **A fallback of the headers into the query string** if spike 1 shows that the host strips them. *Recommended: yes, only then.*
6. **`keepOnHost`, default 1** (3.3): the host keeps the newest backup after a verified pull, so that a failed transfer can be repeated. *Recommended.*
7. **The directory default** `wp-content/uploads/threadsafe-<random>/`, never in the plugin directory (3.4). *Recommended.*
8. **WordPress first, Joomla only on demand** (3.5). *Recommended.*
9. **Distribution as a ZIP in the GitHub release, not in a directory** of the CMS (3.5). *Recommended.*
10. **No SFTP and no WP-Cron** (section 1). *Recommended*; the owner's SFTP copy by hand stays possible.
11. **Do it at all, and when?** The field test of `threadsafe-backup.php` on the owner's host (phase 12, work package 10) comes first: it shows whether PHP, the limits, the firewall and the transfer are what this plan assumes. *Recommended: after that test.*

## 8. Findings of the spikes

(Empty: nothing is built.)
