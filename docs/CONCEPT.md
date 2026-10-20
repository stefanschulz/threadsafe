# ThreadSafe – Concept

**Status:** Draft 1.0 (2026-10-20) – multi-site requirements included (site profiles, dashboard, backup management); phase 0 started.
**Goal:** A backup and migration tool for PHP web applications, modelled on Akeeba Solo. First supported application: **PrestaShop 9.x**, including automatic adjustments when restoring to a different domain or path. Extensible to other applications later (WordPress and others).

**Name:** **ThreadSafe** (chosen by the project owner, 2026-10-20). The earlier working titles "PrestaBack" (too closely tied to PrestaShop) and "DinkyArk" are discarded.

**Language rule:** All documentation, code comments, identifiers and tool output in this repository are written in English.

## Decisions (as of 2026-10-20)

| Topic | Decision |
|---|---|
| Applications | initially **PrestaShop 9.x only** (9.0: PHP 8.1–8.4, 9.1: PHP 8.1–8.5); other applications later |
| Approach | **own implementation**; Akeeba source code serves as a reference, not as a base |
| License | open source, **GPLv3 or later** (published on GitHub) |
| Priority | **own needs first** |
| Deployment | **in the same hosting** as the applications to be backed up (Solo model); no central server, no agent. **One instance manages several sites on that server** |
| Multi-site | one **site profile** per site (path, DB access, storage path, filters …); a **dashboard** with all profiles; per profile an **overview page** of the existing (later also transferred) backups; manual backup from the UI; **download** of existing backups with a warning about the problems of large archives (section 5) |
| Storage | backups go to a **server-local directory**; retrieval via existing server access |
| PHP version | target **PHP 8.4+** (modern language features allowed); the production hosting provides PHP 8.4 or newer (confirmed) |
| Database | **MariaDB and MySQL** (decided 2026-10-20, replacing "MariaDB only"; nothing else for now). MariaDB ≥ 10.2 and MySQL ≥ 5.7, tested with MariaDB 10.11 and MySQL 8.4. The PHP interface is chosen **per site profile**: `pdo_mysql`, `mysqli` or automatic. Manifest and restore check the server type; a dump with MySQL 8 collations is not restored onto MariaDB without conversion. See [PHASE1B.md](PHASE1B.md). |
| Production shop | **PrestaShop 9.0.x** (PHP 8.1–8.4 according to the docs) |
| Main use case | **back up live, restore to dev**; also domain A → B, production → local/Docker, path change/subdirectory |
| Same-domain restore | A backup must also be restorable on the **same domain** (disaster recovery, rollback after a failed update). The same restore core handles it as a migration in which nothing has to be rewritten (4.6) |
| Module knowledge | Support for individual (third-party) modules is an **extension point**: per-module rules as **data only** (no code), addable later without changing the core (4.7) |
| Website type plugins | Website types become **plugins** (`plugins/<type>/` with a manifest) loaded by the tool. Plugins come only from the author, live in the repository and are code with the tool's rights: no installation from the web interface, no signatures, no stable API or developer documentation yet. Extracted together with the `generic` type ([PHASE1B.md](PHASE1B.md)). |
| Schedule | **cron-driven backups belong in the core** (Akeeba offers them only in the Pro edition). Design (phase 5): the schedule is part of the site profile (cron expression, editable in the web interface) and **one trigger cron job** calls `threadsafe cron` every 1-15 minutes; per-task crontab entries remain possible (`backup <profile>`), but are not the way. See 5.6 |
| Users | initially **one admin**; download warning from **500 MB** |
| Encryption | wanted, firmly planned (phase 5): **key pair** for cron backups (public key on the server, private key with the owner) **and password** for manual backups |
| Remote storage | not used today, later (SFTP and similar) |
| Repository | `P:\dev\threadsafe`, GitHub `stefanschulz/threadsafe` |

**Deferred (not part of the first scope):** export via SFTP/S3/WebDAV (the interface is provided for), sites on *other* servers (remote access) and a cross-server dashboard, central control, agent, PrestaShop module, PrestaShop 1.6–8.x, WordPress and other applications, incremental backups, import of Akeeba `.jpa` archives.

---

## 1. Starting point

Akeeba does not know PrestaShop; restoring to another domain is manual work. The existing manual checklist:

| Step (manual) | What is behind it (verified on the test instance) |
|---|---|
| adjust `app/config/parameters.php` | DB host/name/user/password, table prefix |
| table `ps_config` → `PS_SHOP_DOMAIN`, `PS_SHOP_DOMAIN_SSL` | The table is actually called `{prefix}configuration`; the prefix is not always `ps_`. |
| adjust table `ps_shop_url` | `domain`, `domain_ssl`, `physical_uri`, `virtual_uri` (one row per shop URL) |
| delete `var/cache` | see 4.2 – plus caches *in the database* |
| back office → enable/disable SSL | `PS_SSL_ENABLED`, `PS_SSL_ENABLED_EVERYWHERE` |
| back office → save URL settings | regenerates `.htaccess` – **necessary because it is bound to the domain** (see 4.2) |

Core idea: everything above is asked for in the restore wizard and executed automatically – plus what is easily forgotten on the manual list.

## 2. Findings

### 2.1 Akeeba Solo Core 9.2.0 (source code analysed locally)

| Finding | Meaning for ThreadSafe |
|---|---|
| License `GPL-3.0-or-later` (Solo, engine, installer). The engine is a standalone Composer library `akeeba/engine ^10.4`; Solo is built on its own framework `akeeba/awf`. | Individual parts could be adopted with attribution (ThreadSafe is GPLv3+ as well). "Akeeba" is a trademark. |
| **The engine works in ticks**: `Base\Part::tick()` moves components through `INIT → PREPARED → RUNNING → POSTRUN → FINISHED`; state is serialised between steps; the only entry point is `Factory`. Replaceable sub-engines: `Archiver` (jpa, zip), `Dump` (native MySQL), `Scan` (smart, large), `Postproc` (storage/upload). Phase chain: `init \| installer \| packdb \| packing \| finale` (`Core/scripting.json`). | Template for the structure of our own engine (see 3.1). |
| **Installers are pluggable**: `Solo/assets/installers/brs.jpa` (base, ~327 files: wizard, DB restore, session, CLI) plus a small overlay per platform: `brs-generic.jpa` (9 files), `brs-joomla.jpa` (44), `brs-wordpress.jpa`, each described by a `.json`. The overlay defines the **steps** in `platform/platform.json` (generic: `main, database, offsitedirs, finalise`; Joomla adds `setup` with controller/model/view and parsers for `configuration.php`). | Template for the adapter idea "base + application overlay with steps". |
| **Platform detection ("Pythia")**: only `Oracle/Joomla.php` (134 lines) and `Oracle/Wordpress.php` (316). | Pattern for `detect()` in the adapter. |
| **History:** `PostUpgradeScript.php` deletes old `Prestashop{SkipDirs,SkipFiles,TableData}.php` filters; `Archiver/Base.php` maps the former installers `angie-prestashop`/`angie-drupal` to `brs-generic`. | Akeeba used to support PrestaShop and dropped it. Older versions would be a possible reference. |
| **No search/replace in the core installer** (no replace class in the unpacked `brs.jpa`; to my knowledge a Pro feature). | Serialisation-safe domain replacement is our core piece. |
| Solo's `composer.json`: `php >=7.4 <8.4`. | Solo itself is not an option for our PHP target (8.4+). |
| Abstract prefix `#__` in dumps. | A prefix change on import is possible; to be tested for PrestaShop. |

### 2.2 Test instance (PrestaShop 9.1.5, PHP 8.5.0, MariaDB 10.11, 301 tables)

A throw-away PrestaShop instance with shop domain `localhost:8090`. Search for the domain name:

| Location | Hits | Kind |
|---|---|---|
| `ps_configuration` | `PS_SHOP_DOMAIN`, `PS_SHOP_DOMAIN_SSL` | must be replaced |
| `ps_shop_url` | 1 row (`domain`, `domain_ssl`, `physical_uri=/`) | must be replaced |
| `.htaccess` | comment `#Domain: …` + **a dozen `RewriteCond %{HTTP_HOST} ^localhost:8090$`** in front of the image rewrites (`img/p/…`, `img/c/…`) | **must be replaced**, otherwise the "friendly image URLs" silently stop working after the move (404 on product images) |
| `ps_connections_source` | 58 rows | statistics/log data – do not replace, better leave out |
| `mails/<lang>/*.html`, `modules/ps_emailalerts/mails/…` | absolute URLs `http://localhost:8090///mails/themes/…` | generated mail templates; replacing makes sense |
| `var/cache/prod/**` (Smarty cache, `ps_mainmenu/…json`), `var/logs/*` | many | discard / exclude |
| serialised values (`s:NN:"…"`) | 0 | demo shop; in a real shop with modules **cannot be ruled out** |

Further findings:
- **Caches in the database:** `ps_smarty_cache`, `ps_smarty_lazy_cache`, `ps_smarty_last_flush` (and module cache tables) – empty them on restore.
- **The cache contains credentials:** `var/cache/prod/appParameters.php` is a copy of `parameters.php` (including the DB password). Do **not** put the cache into the archive, and delete it on restore.
- `parameters.php` contains values that must be **kept**: `secret`, `cookie_key`, `cookie_iv`, `new_cookie_key` (password hashes/cookies depend on them), `api_public_key/api_private_key`. The file is a `return array(...)` PHP array.
- The back office folder has a random name (e.g. `admin328efkopedr7g2vpunu`) – put it into the manifest so the self-test can find it.
- `ps_caching = CacheMemcache` plus `ps_memcached_servers`: the cache backend depends on the environment and may be missing on the target.
- Candidates for "structure only": `ps_connections`, `ps_connections_page`, `ps_connections_source`, `ps_guest`, `ps_log`, `ps_mail`, `ps_pagenotfound`, `ps_page_viewed`, `ps_statssearch`, `ps_psgdpr_log`, `ps_employee_session`, `ps_customer_session` and the Smarty cache tables.
- More columns with URLs, to be checked: `ps_gsitemap_sitemap.link`, `ps_quick_access.link`, `ps_carrier.url`, `ps_*_lang` (CMS/product texts).

### 2.3 Real shop: Akeeba backup of September 2026

The archive was inspected without modification (entries listed; only configuration files and SQL parts unpacked into an isolated directory, deleted after the analysis). It contains real customer data; the analysis was done locally and only aggregates are recorded here. Identifying details (domains, file names, folder names) are intentionally left out.

**Archive:** one file, 2.14 GB, 71,344 entries, 3.27 GB uncompressed, JPA 1.3, embedded installer `brs-generic` (steps `main, database, offsitedirs, finalise`, i.e. without any PrestaShop-specific adjustment). The DB dump is stored in ~2,360 part files (`installation/sql/site.sql`, `site.s01 …`, about 0.4 MB each) with the abstract prefix `#__`.

**Shop:** PrestaShop **9.0** (`AppKernel`: 9.0; first installed as 8.2.0, upgraded with `autoupgrade`), prefix `ps_`, locale `de-DE`, one shop domain (`shop_url`: `domain` = `domain_ssl`, `physical_uri` = `/`), SSL on, URL rewriting on, no multistore, no media server. 84 modules (among them `ps_accounts`, `ps_eventbus`, `ps_checkout`/`paypal`, `ps_facebook`, `ps_googleanalytics`, `psxmarketingwithgoogle`, `autoupgrade`; presumably third-party/custom: `eicaptcha`, `ets_addtocart_fromlist`, `ets_cookie_banner`, `exportproducts`, `featurenavigator`, `gc_gpsr`, `orderrevocator`, `ps_customtext4cart`, `ps_legalcompliance`).

| Finding | Numbers | Consequence |
|---|---|---|
| **Three log tables make up 98 % of the database:** `connections_source` 389 MB (2.99 M rows), `guest` 238 MB (2.83 M), `connections` 217 MB (2.82 M). The other 315 tables together ≈ 20 MB. | DB 863 MB, 843 MB of it logs | "Structure only" for these tables is the **default behaviour**, not an option. Domain replacement must also skip them (3.5 M hits in `connections_source`!). |
| **`autoupgrade` backups in the admin folder:** `<admin-folder>/autoupgrade/` = 1.26 GB (two file backups of ≈ 607 MB each from January 2026, logs up to 10 MB). | 1.26 of 3.27 GB | Default exclusion `<admin>/autoupgrade/{backup,download,tmp,logs}`. The admin folder has an arbitrary name → the adapter must detect it. |
| **Payload without this ballast:** 67,766 files, 997 MB uncompressed / 666 MB compressed. | | Realistic archive size ≈ 0.7 GB instead of 2.14 GB; split archives are not needed for this shop, but the 2 GB limit was almost reached. |
| **One admin folder, no `var/`, no `cache/` in the archive** (filter of the original backup). | `var`: 0 entries | The restore must create the directory skeleton `var/{cache,logs,…}` and make it writable. |
| **Host-bound files:** `php.ini` in the web root with an absolute path `error_log = /kunden/homepages/…/htdocs/<subfolder>/var/logs/php-errors.log` (typical shared-hosting path); `.htaccess` with `#Domain: <shop-domain>` and **9×** `RewriteCond %{HTTP_HOST} ^<shop-domain>$`; `.htaccess.bak`; gsitemap files `1_index_sitemap.xml`, `1_de_0_sitemap.xml`, `1_gb_0_sitemap.xml` (≈ 0.8 MB each, with absolute URLs). | | Additional adjustments on restore: `php.ini` path, sitemaps (replace or regenerate), `.htaccess` as in 2.2. |
| **Domain in content (without logs):** `cms_lang` (e.g. `http://www.<shop-domain>/images/…` – with `www.` and `http`), `configuration_lang` (absolute links such as `https://<shop-domain>/de/content/…`), `customer_message`, `configuration`, `shop_url`. Serialised (`s:NN:`) hits: **0**; JSON-escaped (`\/`): **0**. | | Replacement must know the variants: `http`/`https`, with/without `www.`. Build serialisation safety in anyway (other shops/modules); not evidenced here. |
| **A second, different domain `<main-domain>`** (sender/contact addresses, mails, product texts, mail log, PayPal log): in `configuration` (`PS_SHOP_EMAIL`, `PS_MAIL_USER`, `PS_MAIL_DOMAIN`, `MA_*`), `cms_lang`, `product_lang`, `customer`, `mail`, `paypal_*` and others. | | **Replace only explicitly named domains**, never "anything similar". The dry-run report lists foreign domains as "untouched". |
| **Configuration tied to the live environment:** SMTP (`PS_MAIL_METHOD=2`, `PS_MAIL_SERVER/USER/PASSWD`, `MA_*_EMAILS`), PrestaShop Account (`PS_ACCOUNTS_*`: Firebase/OAuth2 tokens, RSA keys, shop proof), `ps_eventbus`, PayPal (`PAYPAL_LIVE_CLIENTID/SECRET`, `PAYPAL_WEBHOOK_ID`, `PAYPAL_ENABLE_WEBHOOK`), `ps_checkout`, Facebook (`PS_FACEBOOK_ACCESS_TOKEN`, catalogue, pixel), Google (`ps_googleanalytics`, `psxmarketingwithgoogle`). Tables including `mail` (3,182 rows), `paypal_webhook`, `eventbus_incremental_sync`. | | With **live → dev** (the normal case) the copy would, without countermeasures, send mails to real customers and keep using the live PayPal credentials and cloud links. The dev mode is a **core feature** (4.4). |

## 3. Architecture

ThreadSafe runs **in the same hosting as the applications to be backed up**: its own directory, operated through a web UI or the command line, accessing file system and database directly. Unlike Akeeba Solo, which backs up exactly one website, **one ThreadSafe instance manages any number of sites** through site profiles (section 5). Prerequisite: all sites are reachable from the instance via the file system and their databases via the network (same server or account; `open_basedir` must not prevent access). There is no inbound remote access apart from the UI itself.

### 3.1 Principles taken from the Akeeba analysis
- **Ticks instead of one long run:** every component is a state machine (`INIT → PREPARED → RUNNING → POSTRUN → FINISHED`); one `tick()` fits into a PHP time window and the state is stored between calls. The CLI uses the same mechanism (no limit, but resumable).
- **Replaceable sub-engines selected by configuration key** (archiver, dump, scan, postproc) – the SFTP export will later be another `Postproc` engine.
- **Phase chain** `init → packdb → packing → finale`; backup types (full, DB only, files only) are scripts over these phases.
- **Restore = base + application overlay** with configurable steps.
- **Platform detection** per application, separated from the engine.

### 3.2 Overview

```
┌──────────────── ThreadSafe (one instance, directory in the hosting) ────────────────┐
│  Web UI (dashboard) ─┐                                                              │
│  CLI / cron ─────────┴─► job runner (ticks, state per run as JSON)                  │
│                                                                                     │
│  site profiles  ┌ blog (generic)       /var/www/blog  → /backups/blog               │
│  (one JSON each)├ shop (prestashop9)   /var/www/shop  → /backups/shop               │
│                 └ wiki (generic)       /var/www/wiki  → /backups/wiki               │
│                          │                                                          │
│  engine:  scanner ► db dumper ► zip writer (streaming, parts) ► postproc (local)    │
│                          ▲                                                          │
│           website types (adapters)  generic │ prestashop9 │ more later              │
└─────────────────────────────────────────────────────────────────────────────────────┘
        │ writes per profile to  <storage path>/   (outside web root or blocked)
        ▼
  <profile>_<date>_<random>.zip (+ .z02 …) + .json   contains: files/, db/*.sql, threadsafe.json
        │ download in the UI or retrieval via server access; restore on the target with
        ▼
┌──── restore.php (single file: extractor + wizard) ─────────────────────────────────┐
│ 1 extract ► 2 requirements ► 3 target values (DB, domain, SSL, path) ► 4 DB import ►│
│ 5 adapter steps (core from section 4) ► 6 self-test ► 7 self-deletion              │
└────────────────────────────────────────────────────────────────────────────────────┘
```

### 3.3 Archive format
- **Standard ZIP** (own streaming writer, ZIP64-capable, state stored between ticks) instead of a proprietary JPA: emergency extraction without ThreadSafe is possible, every file can be verified.
- **Splitting** as *several self-contained ZIPs* (order recorded in the manifest) instead of segmented archives.
- Database: **one SQL file per table** (`db/0042_product.sql`), table prefix stored abstractly, paging by primary key instead of `OFFSET`.
- **Manifest `threadsafe.json`:** format version, application + version, PHP/DB version, prefix, character set/collation, source domains including `shop_url` rows, admin folder, excluded tables/paths, checksums. The wizard pre-fills its fields from it.

### 3.4 Website types (adapters)

Every site profile has exactly one **website type**. The type determines how the site is detected, backed up and restored. In code a type is an "adapter" (a class implementing the interface below). Planned types: `prestashop9` and `generic`; later e.g. `wordpress`, `joomla`.

| Method | Purpose |
|---|---|
| `detect($path)` | detects application + version |
| `dbCredentials($path)` | reads the DB connection without booting the application |
| `defaultExcludes()` / `slimTables()` | suggested filters |
| `manifestExtras()` | application-specific metadata |
| `restoreForm()` | additional wizard fields |
| `rewriteConfig()` / `rewriteDatabase()` / `rewriteFiles()` | adjust configuration, DB and files (e.g. `.htaccess`) |
| `postRestore()` / `selfTest()` | clear caches, self-test |

`detect()` is also used when creating a site profile (enter a path → type and DB are detected or confirmed).

| | `prestashop9` | `generic` ("any website") |
|---|---|---|
| Detection | automatic (`parameters.php`, admin folder, version) | none – you choose the type |
| DB access | read from `parameters.php` | entered manually, **optional** (pure file sites without a DB are possible) |
| Filter defaults | PrestaShop-specific (caches, autoupgrade, log tables) | none; only your own exclusions and the storage directory |
| Manifest | domains, admin folder, PS version, … | basic data only (files, DB, sizes, checksums) |
| Restore | section 4 in full: domain rewrite, `parameters.php`, `.htaccess`, caches, dev mode, self-test | **extract and DB import only**; adjustments (credentials, domain) stay manual. Optionally a generic serialisation-safe search/replace later |

`generic` is thus a complete backup, but without any knowledge of the application. New types (WordPress, Joomla …) arise by extending `generic` with detection, filter defaults and restore steps. The interface stays application-neutral (names in the core say "application", not "shop").

### 3.5 Technology
- **PHP 8.4+**, modern language features (enums, readonly, typed properties). Note: PS 9.0 officially runs only up to PHP 8.4; a host with PHP 8.1–8.3 could therefore not run ThreadSafe.
- No runtime dependencies besides standard PHP extensions (`pdo_mysql` as the driver for MariaDB; `mysqli` only as a fallback because PrestaShop hosting does not always have it; `zip` optional, with a pure-PHP fallback via `gzinflate`).
- **MariaDB and MySQL**: SQL stays within what both understand (`information_schema`, plain DML); the few differences are handled where they occur ([PHASE1B.md](PHASE1B.md) 2). The manifest stores server type and version (`SELECT VERSION()`); a dump with MySQL 8 collations (`utf8mb4_0900_*`) is not restored onto MariaDB without conversion. The PHP interface (`pdo_mysql` or `mysqli`) is a setting of the site profile; `auto` prefers `pdo_mysql`.
- Development with Composer, PHPUnit, PHPStan; a build script produces the application ZIP and `restore.php`.
- State/configuration (site profiles, jobs, logs) as JSON in a protected data folder, no database of its own; server-rendered UI, Bootstrap 5, progress via `fetch`; CLI first.
- Development environment: Docker Compose (see `.docker/`).

## 4. PrestaShop 9 restore in detail (core library)

### 4.1 Inputs
New DB (host, name, user, password, keep/change prefix) · new domain · SSL domain · SSL no/yes/everywhere · path (`physical_uri`, e.g. `/shop/`) · mode "production" or "dev/staging".
Pre-filled from the manifest or from `ps_shop_url` of the restored DB. Domain mappings are **optional**: without a mapping (or with `from == to`) the run is a restore in place (4.6).

### 4.2 Automatic adjustments (in order)
1. **`app/config/parameters.php`**: write the new DB values, generate the file as a proper PHP array (no regex). Leave `secret`, `cookie_*`, `api_*_key` unchanged.
2. **Discard all caches**: `var/cache/*` (prod, dev, **including `appParameters.php`**), `cache/smarty`, `class_index.php`; empty `smarty_cache`, `smarty_lazy_cache`, `smarty_last_flush` in the DB.
3. **`{prefix}configuration`**: check `PS_SHOP_DOMAIN`, `PS_SHOP_DOMAIN_SSL`, `PS_SSL_ENABLED`, `PS_SSL_ENABLED_EVERYWHERE`, `PS_MEDIA_SERVER_1…3`.
4. **`{prefix}shop_url`**: `domain`, `domain_ssl`, `physical_uri` (multistore: old → new mapping table).
5. **Domain replacement in content**: absolute URLs of the old domain in all text columns (exclusion list for log tables), **safe for serialised data and JSON** (`s:NN:` lengths are recalculated). A **dry-run report** "table/column/hits" comes first. Special cases: `http`↔`https`, with/without `www`, path change.
6. **`.htaccess`**: set the `#Domain:` line and all `RewriteCond %{HTTP_HOST} ^…$` as well as `REWRITEBASE`/`ErrorDocument` paths to the new values (the section between the PrestaShop markers `# ~~start~~` … `# ~~end~~`; content outside it stays). Alternatively, after a successful boot, `Tools::generateHtaccess()` (verify the exact method in PS 9).
7. **`robots.txt`**, **`php.ini`** (absolute `error_log` path), **sitemaps** (`*_sitemap.xml`, `gsitemap_sitemap.link`) and generated mail templates: update or let them be regenerated.
8. **Directory skeleton and permissions**: create missing folders (`var/cache`, `var/logs` …) and make the writable folders (`var/`, `img/`, `upload/`, `download/`, `translations/`, `mails/`) writable.
9. **Finish**: optionally boot PrestaShop in a controlled way and trigger its own routines; if that fails, the state after steps 1–8 stays valid, plus a hint "back office → Traffic & SEO → save".

### 4.3 Self-test
HTTP requests: home page, a category, a product **including a product image URL** (exposes the `.htaccess` problem from 2.2), contact, login, back office login (folder name from the manifest). Check for redirect loops and for references to the old domain. The result is a traffic-light report.

### 4.4 Dev/staging mode (core feature for live → dev)
Chosen at restore time ("production" or "dev/staging"). The dev mode **performs** the steps instead of merely recommending them; every item is visible in the report and can be deselected individually:
- **Intercept outgoing mail (default: mail catcher):** redirect SMTP to a mail catcher (Mailpit in Docker; host/port configurable), remove the live SMTP credentials (`PS_MAIL_SERVER/USER/PASSWD`), neutralise the recipients `MA_*_EMAILS`. Without a configured catcher: set `PS_MAIL_METHOD` to "no sending".
- **Payments:** switch PayPal to sandbox (`PAYPAL_SANDBOX=1`) or deactivate payment modules; remove live credentials and `PAYPAL_WEBHOOK_ID`.
- **Detach cloud links:** remove the `PS_ACCOUNTS_*` tokens/keys, deactivate the modules `ps_accounts`, `ps_eventbus`, `ps_facebook`, `ps_googleanalytics`, `psxmarketingwithgoogle` (and similar ones, list is extensible), clear Facebook token/pixel.
- **Block indexing:** `noindex`/`Disallow`, flag cron/webhook modules.
- **Check the cache backend** (no Memcache/Redis available → switch off).
- **Customer data:** a notice that the copy contains real personal data (over 1,000 customers in the examined shop); anonymisation is planned as a later option; until then only the notice.

The list of affected configuration keys/modules is data (JSON in the adapter), not code, so it can grow without program changes.

*Implementation (phase 1, see PHASE1.md 3.9):* the recipients `MA_*_EMAILS` are not changed - with a catcher or without sending they never matter; `ps_checkout` is deactivated together with the PayPal rule; the cache backend and unhandled credential-like settings are reported as warnings, not changed.

### 4.5 Versions
PrestaShop **9.0 and 9.1** are supported (officially PHP 8.1–8.4 and 8.1–8.5 respectively, MySQL ≥ 5.7 / MariaDB ≥ 10.2 – ThreadSafe supports only MariaDB of these; [system requirements PS 9](https://devdocs.prestashop-project.org/9/basics/installation/system-requirements/)). Configuration exclusively through `app/config/parameters.php`; older versions (1.6–8.x) are out of scope.

### 4.6 Restore on the same domain (disaster recovery, rollback)

A backup must be restorable where it came from: after a failure of the original system (new server or repaired server, same domain) or to roll back a failed update. Nothing may be "migrated" then, but several steps are still needed because the **environment** can differ even when the domain does not.

| Step | Same domain, same path | Why it still matters |
|---|---|---|
| `parameters.php` | rewritten only if database credentials/host differ | a new server usually has other DB credentials |
| Caches | **always discarded**, also the DB cache tables | cached configuration (`appParameters.php`, compiled templates) belongs to the old environment |
| `configuration`, `shop_url` | unchanged (reported as "already correct") | – |
| Content replacement | no-op | – |
| `.htaccess` | kept; regenerated only if the file is missing or damaged | – |
| Host-bound files | `php.ini` `error_log` path and similar are fixed when the server path differs | a new server often has another absolute path |
| Directory skeleton, permissions | **always** | a fresh server has other owners/modes; `var/` may be missing |
| Dev mode | **off**; mode is `production` | the live shop must keep mail, payments and cloud links |
| Self-test | always | proves the restored shop actually serves |

Because cookie keys and `secret` are kept, sessions and password hashes keep working. The same core is therefore used for three situations: moving to a new domain/path, restoring in place, and cloning to dev. Restoring **over an existing installation** (rollback) additionally needs cleanup of leftovers (tables with the prefix that are not in the backup, files that are not in the backup); that belongs to the `restore.php` wizard (phase 3), not to the migration core.

### 4.7 Module rules (per-module knowledge)

Nobody can know all third-party modules, and every shop uses a different set. The core therefore knows **nothing** module-specific; module knowledge lives in **module rules**, which are added over time:

- A rule is a small JSON file named after the module (`plugins/prestashop9/resources/modules/<module>.json`) and may point to an optional PHP class for logic that cannot be expressed as data.
- Rules can declare: tables and columns that hold the domain (and in which form: plain, serialized, JSON), configuration keys to rewrite, keep or neutralise in dev mode, log/cache tables to skip or keep as structure only, files to rewrite or delete, and whether the module is deactivated in dev mode.
- **Own rules without changing the program:** a rules folder in the data directory (`data/rules/`) is loaded in addition to the shipped rules and may override them.
- Modules without a rule are still handled by the generic engine (all text columns, URL forms only); the dry-run report lists their domain occurrences as "no rule", which is how new rules are found.
- A rule is tested like code: it comes with a fixture in the test module or a small test case.

## 5. Multiple sites: profiles, dashboard, backup management

ThreadSafe is designed as a **multi-webapp tool**: one instance, many sites. Terms: **site profile** = one web application to be backed up, with its access and storage data (not to be confused with Akeeba's "backup profiles" or the dev mode from 4.4).

### 5.1 Site profile

| Field | Content |
|---|---|
| Name / identifier | display name and `id` (lower-case slug; part of file names and paths) |
| Website type | `prestashop9`, `generic`, more later; mandatory. The type determines detection, filter defaults, DB access, backup extras and restore steps. The application version is determined on detection and at every backup |
| Site path | root directory of the application on this server |
| Database | default: **read automatically from the application's configuration** (e.g. `parameters.php`) so that no second copy of the credentials exists. Only for types without a readable configuration (`generic`) entered manually: host, port, name, user, password, table prefix – stored in a protected file |
| Storage path | directory for this profile's backups. Checks: exists, writable, enough free space, **not** reachable from outside (outside the web root or blocked); if it lies inside the site path it is excluded from the backup |
| Filters | directories/tables to exclude ("structure only"); defaults come from the type, adjustable |
| Retention | last *n* backups, maximum age, storage cap (phase 5) |
| Schedule | cron expression (phase 5) |
| Encryption | key pair or password (phase 5) |
| Note | free text (e.g. "live, shared hosting") |

**Storage:** one JSON file per profile in the data folder (`data/profiles/<id>.json`, mode 0600, data folder outside the web root or blocked). **Creating a profile:** enter the path → the type detects application and DB and proposes filters → **connection test** (files readable, DB reachable and MariaDB, storage path writable, free space, `open_basedir`).

### 5.2 Dashboard (main view)
One row or card per profile: name, application + version, status indicator, **last backup** (time, age, size, result), next scheduled run, storage usage, running job with progress; actions **start backup**, **view backups**, **edit profile**. Warnings: no successful backup for *n* days, last runs failed, little free space, storage path not writable, site path no longer present. Header: number of profiles, total storage used, running/queued jobs.

### 5.3 Profile page: backup overview
A table of all existing backups of the profile: date/time, type (full/DB only/files only), size (and number of parts), duration, application version (from the manifest), encrypted yes/no, status (ok / unchecked / faulty), **location** (`local`; later `local + SFTP`, `external only` – these are the "transferred" backups). Actions: **download**, **verify integrity** (SHA-256 and ZIP structure), **details** (manifest: domains, tables, exclusions), **delete** (with confirmation), **restore help** (instructions and `restore.php`). Above it: a running backup with progress and log.

**The file system is the source of truth.** Every backup consists of archive(s) plus a small sidecar file `<archive>.json` (copy of the manifest with size, SHA-256, status, later `transfers[]` with target/time/result). Listing does not need to open the ZIPs. If the sidecar is missing (copied manually, foreign archive) it is reconstructed from the archive or the backup is shown as "unknown". An index cache is only an optimisation.

### 5.4 Download and large archives
Downloading in the web interface is a **convenience, not a reliable transport for large archives**. Technique: streaming in 1 MB blocks with `Content-Length` and **HTTP Range** (resume), no output buffering and no compression, `set_time_limit(0)` and `ignore_user_abort`; where the web server supports it, delivery via `X-Sendfile` / `X-Accel-Redirect`. Access only after login through a short-lived single-use token bound to the session (the storage is not directly reachable); multi-part backups part by part; encrypted archives are downloaded encrypted.

**Warning in the dialog** (above a threshold, default 500 MB, configurable): large downloads can fail because of PHP and web server time limits, reverse proxy/CDN limits, connection drops or the browser. In that case **server access** (SFTP/`scp`/`rsync`) is recommended; the dialog shows the absolute path, an example command and the **SHA-256 checksum** to compare after the download.

### 5.5 Backup run (engine)
- **Engine** (ticks, time budget ≈ 60 % of `max_execution_time` in web mode): init → file list (queue) → DB dump → archiving → manifest and sidecar file → retention. State: file offset, current table + last primary key.
- **Consistency:** DB dump inside a transaction (`REPEATABLE READ`); optionally maintenance mode for the site.
- **Concurrency:** at most one run per profile (lock file), globally one run at a time by default (queue); a run starts only if the free space exceeds the estimated size (last run × 1.2).
- **DB access without booting the application:** credentials directly from its configuration file (so a backup also works when the site is broken).
- **PrestaShop default filters:** directories `var/cache`, `var/logs`, `cache/smarty`, `img/tmp`, `.git`, the backup directory itself and `<admin folder>/autoupgrade/{backup,download,tmp,logs}` (1.26 GB in the real shop); tables **structure only**: `connections`, `connections_source`, `guest` (98 % of the DB in the real shop) as well as `connections_page`, `log`, `pagenotfound`, `page_viewed`, `statssearch`, `psgdpr_log`, `employee_session`, `customer_session`, Smarty cache tables; list configurable. The admin folder (arbitrary name) is detected by the adapter.
- **Operating modes:** `php threadsafe backup <profile-id>`, `backup --all`, `list`, web UI, cron (CLI or protected URL), see 5.6.
- **Storage:** local directory per profile. Retrieval by download (5.4) or server access. The `Postproc` interface is provided; SFTP/S3/WebDAV follow later and then fill `transfers[]`.

### 5.6 Scheduling (phase 5)
- **One trigger, schedule inside.** A single cron job calls `threadsafe cron` (CLI) or a protected URL at short intervals. The scheduler decides which profiles are due: a profile is due when its last scheduled time lies after its last run; a missed run is made up once within a grace period, otherwise skipped; the global lock admits one run at a time, so a second trigger while a backup runs ends at once. The same call continues interrupted runs and applies retention.
- **Why not one crontab entry per task:** the web interface cannot edit the crontab; shared hosting allows few cron jobs; queue, retention and resume would otherwise be missing. The trigger writes a **heartbeat** on every call and the dashboard warns when it stays away (the single point of failure).
- **CLI task or web task is decided by how the trigger is called, not by the scheduler.** `php bin/threadsafe cron` is a **CLI task**: no PHP time limit, due backups run in the calling process, the database dump in one consistent snapshot. A call of the protected URL (`wget`/`curl`) is a **web task**: PHP limits apply, every call performs one tick, the dump is consistent per table (stated in the manifest). The CLI is preferred; the URL is the fallback for hosts without a CLI cron (1&1 users of Akeeba report exactly that). If a host kills long cron processes, the next call resumes the run from its saved state - without the original snapshot, so the manifest then says `consistency: table`. A manual backup from the web interface is always a web task.
- The granularity of the schedule is that of the trigger (hourly cron = hourly schedule); `profile test` reports whether a PHP CLI binary is found.

## 6. Security
- Backup archives contain DB credentials, cookie keys and customer data: storage outside the web root or blocked, random file names, restrictive file permissions; no clear-text logging of passwords.
- Never back up cache directories (`appParameters.php` contains the DB password).
- Web UI: password login (Argon2id), brute-force protection, CSRF protection; cron URL only with a long secret. Primarily operated via CLI; the UI can be switched off.
- `restore.php` deletes itself after completion; access only with a one-time password stored in the archive; path validation against Zip Slip.
- GDPR notice for staging copies (customer data); anonymisation later.
- **Multi-site increases the damage of a compromise:** the instance can read files and databases of *all* profiles. Countermeasures: instance outside the web root or behind additional web server authentication, prefer the CLI, data folder mode 0600, do not duplicate DB credentials (read them from the application's configuration), audit log for download/delete/profile changes, download only with a single-use token.
- Hosting limits are checked and reported when a profile is created (`open_basedir`, file permissions, foreign account), not circumvented.

## 7. Test and quality concept
- **Data-protection rule:** a real live backup (it contains customer data) is used only locally as an additional restore test case – never in the repository, logs, error reports or documentation. Repository tests use only the Docker test instance and synthetic fixtures (including re-created problem cases such as log tables, `www.` variants, a second foreign domain).
- **Round trip in Docker** with the test instance as the source: backup → restore to a **different domain/port**, **different path**, **different DB**, **different prefix**, with/without SSL → self-test → comparison (table checksums, file count).
- Test matrix: PS 9.0 / 9.1 × MariaDB 10.11 LTS / 11.4 LTS × PHP 8.4 / 8.5.
- **Disaster-recovery test:** back up/copy a running shop, destroy its containers and volumes, recreate empty ones **on the same domain and port**, restore files and database, run the core without a domain mapping, smoke-test. Also a rollback test: restore an older state over a modified installation.
- Test cases: multistore, multilingual, shop in a subdirectory, serialised module values, large tables, artificially low PHP limits (tick abort/resume).
- Acceptance: a move to a new domain **without a single manual step** from the manual checklist.

## 8. Plan

| Phase | Content | Result |
|---|---|---|
| 0 (started 2026-10-20) | **Foundations:** reproducible Docker test environment, "domain inventory" script (DB + files) | documented list of adjustments (2.2), fixture |
| 1 | **Restore core (PrestaShop migrator)** as a library + CLI/mini UI: section 4 in full (including the dev mode for the normal live → dev case), dry run, self-test. Works on *any* already unpacked copy, also after an Akeeba restore | manual checklist automated; test matrix starts |
| 2 | **Profile model and backup engine:** site profiles (JSON) with connection test, website types `generic` and `prestashop9`, scanner, dumper, ZIP writer with ticks, splitting, manifest + sidecar file, storage per profile, CLI (`backup <profile>`, `backup --all`, `list`) | back up several sites from the command line, round-trip test |
| 3 | **`restore.php`:** extractor + wizard, uses the core from phase 1; also restores **over an existing installation** (drop leftover tables with the prefix, remove files that are not in the backup) | backup → move in one go |
| 4 | **Web UI:** login, dashboard, profile management, backup overview per profile, manual backup with progress, download (5.4) | multi-site interface, Solo replacement for own needs |
| 5 | **Schedule via one trigger cron job** (5.6: schedule in the profile, `threadsafe cron` as CLI or protected URL, heartbeat), retention, **archive encryption** | automatic, encrypted backups |
| later | SFTP/S3 export (fills "transferred"), further types (WordPress, Joomla), anonymisation of customer data | extensibility |

Phase 1 deliberately comes before the engine: it solves the actual problem (the move), works with existing Akeeba backups and is the riskiest domain logic.

## 9. Risks
- **Reliability of a custom implementation** (ticks, ZIP streaming, large tables, files > 2 GB): test early with artificial limits and large shops; use the Akeeba source as a cross-check.
- **Scope:** backup engine plus UI is large – hence restore core first, CLI before UI.
- **Multi-site on one server:** shared disk space, load and `open_basedir`/permissions; queue, space check and connection test limit this. Sites on other servers are out of scope.
- **PHP 8.4 requirement:** works only if the production hosting provides PHP 8.4.
- **Unknown domain occurrences** in third-party modules (dry run + generic, serialisation-safe replacement).
- **PrestaShop version drift** (new tables/configuration keys in 9.x) – test matrix.
- **Data protection/security** of archives with customer data (the examined shop contains real customers, orders, mail logs) – encryption and the dev mode reduce the risk.
- **Dev copy with live connections:** without neutralisation (4.4) a dev instance would send mails to customers or contact live services (PayPal, Facebook, PrestaShop Account).
- **License:** GPLv3+ requires source disclosure on distribution; adopted Akeeba code only with notices.

## 10. Decision log and open points

**Decided**
- Name: ThreadSafe (formerly PrestaBack, DinkyArk).
- Production shop: PrestaShop 9.0.x; production PHP 8.4 or newer → PHP 8.4 minimum is fine.
- Scenarios: usual case live → dev; also domain A → B, production → local/Docker, path change/subdirectory. `http`↔`https` is part of the domain rewrite. Consequence: the **dev/staging mode (4.4) and path changes belong in the core**.
- Real shop: an Akeeba backup is available (analysis in 2.3); no multistore, no media server.
- Test basis: the real live backup only locally as a restore test case (never repository/logs/docs); repository tests use the Docker instance and synthetic fixtures.
- Akeeba usage today: manual backups only; the cron schedule (Pro) is missing and moves into the core; no split archives, no encryption (wanted), no remote storage.
- Dev mail: mail catcher (Mailpit) by default, otherwise sending is switched off.
- Anonymisation: later, as an option; until then only a notice.
- Encryption: both (key pair for cron, password for manual backups).
- Scheduling (5.6): schedule in the profile plus one trigger cron job with a heartbeat, instead of one crontab entry per task; CLI task when the trigger is a CLI cron (preferred), web task when it is the protected URL.
- Database: first MariaDB instead of MySQL; since 2026-10-20 **MariaDB and MySQL** (nothing else for now), PHP interface selectable per profile (PHASE1B.md).
- Website types become plugins (author only, in the repository, internal API, no installation from the UI); module rules stay data only (PHASE1B.md).
- Same-domain restore (disaster recovery, rollback) is a core requirement (4.6); module-specific knowledge is an extension point (4.7).
- Phase 2 (PHASE2.md): consistent snapshot in the CLI and per table in ticked runs, no reliance on `exec`/`proc_open`; ZIP split only on request; symlinks skipped with a warning; `generic` database passwords in plain text in a 0600 profile file; one SQL file per table with real names; deflate 6; CRC32 per entry and SHA-256 per part.
- Multi-site (section 5): profiles, dashboard, backup overview, manual backup and download are core requirements; all sites live on the same server/account, one instance is enough; one admin user at first, roles can be added later; download warning from 500 MB (configurable).

**Open**
- Which database does the live host use (MariaDB or MySQL)? Check with `SELECT VERSION()` on the live system. Both are supported now; the answer decides which one the private reality check runs against.

Next step: phase 2 (profiles and backup engine; plan: [PHASE2.md](PHASE2.md), decisions settled) is in progress - work packages 1-10 are done, the status is at the top of that document. Phases 1 and 1b are done. How to continue the work: [AGENTS.md](../AGENTS.md).

## Sources
- Akeeba Solo Core 9.2.0 (local): `composer.json`, `vendor/akeeba/engine/CLAUDE.md`, `engine/Core/scripting.json`, `Solo/Pythia/**`, `Solo/assets/installers/**` (JPA packages unpacked and inspected), [Solo documentation](https://akeeba.com/documentation/solo.html), [ANGIE installers](https://akeeba.com/documentation/solo/angie-installers.html)
- PrestaShop: [System requirements v9](https://devdocs.prestashop-project.org/9/basics/installation/system-requirements/), [Webservice `shop_urls`](https://devdocs.prestashop-project.org/9/webservice/resources/shop_urls)
- Migration guides (cross-check): [Webkul](https://webkul.com/blog/move-prestashop-to-new-server/), [mypresta.rocks](https://mypresta.rocks/faq/prestashop-tips/how-do-i-move-my-prestashop-store-to-a-new-domain)
