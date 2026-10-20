# Phase 1 – Restore core ("PrestaShop 9 migrator")

**Status:** plan, draft 1 (2026-10-20); design decisions in section 7 are settled. Nothing in this document is implemented yet.
**Context:** see [CONCEPT.md](CONCEPT.md), sections 4 (restore details) and 8 (plan). Facts about PrestaShop were checked against the 9.0.2 test container (`.docker/`); items marked *(verify)* still have to be confirmed.

## 1. Goal and scope

Turn an **already unpacked copy** of a PrestaShop 9 site (files in place, database imported) into a working site on a **different domain, path, database or SSL setting** – or on the **same domain** after a failure of the original system – with no manual step from the manual checklist, and report exactly what was changed.

The copy can come from any source: a ThreadSafe backup (later phases), an Akeeba restore, or a plain file/database copy. Phase 1 therefore does **not** depend on the backup engine (phase 2) or the `restore.php` wizard (phase 3).

| In scope | Out of scope (later phases) |
|---|---|
| everything in CONCEPT 4.2 (parameters, caches, configuration, `shop_url`, content domains, `.htaccess`, host-bound files, directory skeleton) | creating or reading backup archives |
| restore in place on the same domain (CONCEPT 4.6) | restoring over an existing installation with cleanup (phase 3 wizard) |
| module rules as an extension point (CONCEPT 4.7); only a few rules shipped | rules for specific third-party modules (added over time) |
| dev/staging mode (CONCEPT 4.4) | web UI, profiles, scheduling, encryption |
| self-test (CONCEPT 4.3) | the `restore.php` wizard (it will call this core) |
| dry-run report and change journal | importing a SQL dump (the database is already there) |
| CLI `threadsafe migrate`, test harness, dev container | multistore beyond a basic mapping (see open question 2) |

## 2. Deliverables

1. PHP library `ThreadSafe\` (PSR-4, `src/`), no runtime dependencies besides `ext-pdo_mysql` (and `ext-json`, `ext-mbstring`).
2. CLI `bin/threadsafe` with the command `migrate prestashop9` (flags and JSON request file).
3. Unit tests and a Docker based integration test that clones a shop, migrates it and smoke-tests the result.
4. A dev container with PHP 8.4, `pdo_mysql` and Composer (the host PHP has no database driver).
5. Documentation of the request format, the report format and the journal.

## 3. Design

### 3.1 Package layout

```
bin/threadsafe                      CLI entry point
src/
  Cli/                              argument parser, command classes, exit codes
  Core/                             Step, StepResult, Plan, Runner, Report, Journal, Logger
  Migration/                        MigrationRequest, DomainMapping, SourceState
  Replace/                          DomainMapping, UrlMatcher, ValueCodec (SerializedCodec, JsonCodec), ValueRewriter, Stats; TableRewriter (WP 5)
  Modules/                          ModuleRule, RuleLoader, RuleRegistry (shipped + user rules)
  Database/                         Connection (PDO wrapper, MariaDB check), SchemaInspector
  PrestaShop9/
    ParametersFile.php              safe reader + writer for app/config/parameters.php
    Steps/                          one class per step (section 4)
    DevMode/                        data-driven rules, loader
    SelfTest/                       HTTP checks
resources/prestashop9/              dev-mode.json, skip-tables.json, host-bound-files.json
resources/prestashop9/modules/      one rule file per known module
tests/
  Unit/ Integration/ fixtures/
```

The code outside `PrestaShop9/` must not mention PrestaShop. `Replace/`, `Database/` and `Core/` are reused by the other website types later.

### 3.2 Step contract

```php
interface Step {
    public function id(): string;
    public function plan(Context $ctx): Plan;          // read-only; list of intended changes
    public function apply(Context $ctx, Plan $plan): StepResult;  // performs and journals changes
}
```

- `Runner` runs `plan()` of all steps first (this *is* the dry run), shows the combined report, and only then calls `apply()` in order. `--dry-run` stops after planning.
- Steps are **idempotent**: running the migration twice with the same request yields no further changes.
- Every `apply()` writes to the **journal** (`threadsafe-journal-<timestamp>-<random>.jsonl`, JSON Lines, flushed per entry so a crash loses nothing; directory mode 0700, file mode 0600): old and new values for database rows, copies (`.orig`) of changed files. The journal can contain passwords and keys and must be kept private. Phase 1 has no automatic undo, but the journal makes a manual revert possible.
- A step failing aborts the run; steps already applied stay applied (reported). Database changes within one step run in one transaction where MariaDB allows it.

### 3.3 Request

`MigrationRequest` (CLI flags or a JSON file):

| Field | Meaning |
|---|---|
| `root` | PrestaShop root directory |
| `database` | target host, port, name, user, password, prefix (defaults: values already in `parameters.php`) |
| `domains` | **optional** list of `{from, to}` mappings; `from` defaults to the domain found in `shop_url`. Without a mapping (or with `from == to`) the run is a **restore in place** (3.7) |
| `physicalUri` | new base path (default: unchanged) |
| `ssl` | `none` \| `login` \| `all` (maps to `PS_SSL_ENABLED`, `PS_SSL_ENABLED_EVERYWHERE`) |
| `mode` | `production` \| `dev` |
| `options` | skip tables, extra bare-domain columns, mail catcher host/port, HTTP self-test base URL |

**Guards** (refuse to run unless satisfied): `parameters.php` and the database must belong together (the DB connection works with the *new* values or the old ones, whichever the request names); when a mapping is given, the `from` domain must actually appear in `shop_url` (otherwise the tool would silently do nothing or hit the wrong site); `from == to` is allowed and means restore in place; real changes need `--yes`; the database server must be MariaDB.

### 3.4 Domain replacement engine (the risky part)

Matching rules, in order of importance:

1. **URL forms only by default.** Replace `(https?:)?//[www.]<from>[:port]` and the JSON-escaped form `\/\/`. A bare domain in free text is *reported* but not changed, unless the column is on an allow-list (`configuration.PS_SHOP_DOMAIN*`, `shop_url.domain*`, `configuration.PS_MEDIA_SERVER_*`) or the user passes `--replace-bare`.
2. **Never touch e-mail addresses.** An occurrence directly after `@` (or in a context like `name@<from>`) is skipped. The real shop shows why: sender addresses may use the shop's own domain.
3. **Only named domains.** A second brand domain is untouched and listed as "foreign domain" in the report.
4. Host names are case-insensitive; the scheme is kept unless the request changes SSL; both `www.` and bare forms are handled; repeated slashes such as `http://host///mails/…` (seen in generated mail templates) keep their shape.

Value codecs (a value may be nested in another):

| Codec | Handles |
|---|---|
| `Serialized` | PHP `serialize()` strings. Implemented as a tokenizer (not `unserialize()`, which would instantiate classes): walks `s:N:"…";`, `a:`, `O:`, `i`, `d`, `b`, `N`, recomputes `N` as the **byte** length after replacement. |
| `Json` | string-level replacement inside JSON text, honouring `\/` and `\uXXXX`, so that numbers and key order stay byte-identical. |
| `Plain` | everything else |

Algorithm per table: read primary-key ranges in chunks (default 2,000 rows), apply codecs to text columns, write back changed rows only (`UPDATE … WHERE pk = …`). Tables without a primary key are reported and skipped. Log and cache tables (`resources/prestashop9/skip-tables.json`) are skipped by default and *counted* in the report. The dry run uses the same code path with writes disabled and reports table, column, hits, serialized/JSON flags and a redacted before/after sample.

### 3.5 Steps (order matches CONCEPT 4.2)

Steps 5 and 9 are driven by the engine **plus module rules** (3.8).

| # | Step | Notes and test oracle |
|---|---|---|
| 1 | `ReadSourceState` | reads `parameters.php`, `shop_url`, relevant `configuration` keys, PrestaShop version (`AppKernel`), admin folder (directory with `themes/new-theme`), checks MariaDB. Produces `SourceState`; no changes. |
| 2 | `WriteParameters` | `ParametersFile` parses the `return array(...)` literal with `token_get_all` (no `include`), keeps all unknown keys and multi-line values (PEM keys), writes the new `database_*` values, same formatting style as PrestaShop. Round-trip test: read → write → byte-identical when nothing changes. Keeps `secret`, `cookie_*`, `api_*`. |
| 3 | `DiscardCaches` | empties `var/cache/*` (keeps the directory and `CACHEDIR.TAG`), `cache/smarty`, `class_index.php`; truncates `smarty_cache`, `smarty_lazy_cache`, `smarty_last_flush`. Must include `appParameters.php`. |
| 4 | `UpdateConfiguration` | `PS_SHOP_DOMAIN`, `PS_SHOP_DOMAIN_SSL`, `PS_SSL_ENABLED`, `PS_SSL_ENABLED_EVERYWHERE`, `PS_MEDIA_SERVER_1…3` (flag if set); `shop_url` (`domain`, `domain_ssl`, `physical_uri`); inside one transaction. |
| 5 | `ReplaceContentDomains` | engine from 3.4 over all text columns. |
| 6 | `RewriteHtaccess` | rewrites only the block between `# ~~start~~` and `# ~~end~~`: the `#Domain:` comment, every `RewriteCond %{HTTP_HOST} ^<domain>$`, `REWRITEBASE`, `ErrorDocument`. PrestaShop writes the domain unescaped and only escapes `[` `]` *(verified in `Tools::generateHtaccess`)*. **Oracle:** after the DB is updated, PrestaShop's own `Tools::generateHtaccess()` is run in a test container; our rewrite must equal its output for the block. |
| 7 | `RewriteHostBoundFiles` | `php.ini` (`error_log` absolute path → new site path), `robots.txt`, `*_sitemap.xml` (replace or delete for regeneration), generated mail templates in `mails/` and `modules/*/mails/`, `ps_gsitemap_sitemap.link`. Rule list in `host-bound-files.json`. |
| 8 | `EnsureSkeleton` | creates missing `var/cache`, `var/logs` etc., sets writable directories (`var/`, `img/`, `upload/`, `download/`, `translations/`, `mails/`). |
| 9 | `ApplyDevMode` (only `mode=dev`) | data-driven (`dev-mode.json`): mail catcher (`PS_MAIL_METHOD=2`, `PS_MAIL_SERVER`, `PS_MAIL_SMTP_PORT`, encryption off, clear user/password, neutralise `MA_*_EMAILS`), PayPal sandbox and removal of live credentials/webhook id, removal of `PS_ACCOUNTS_*` secrets, module deactivation, `noindex`/`robots.txt` disallow. Module deactivation mirrors `Module::disable()`: delete the `module_shop` rows and set `module.active = 0` once no shop association is left *(verified in `Module.php`; overrides, if any, need `uninstallOverrides`, (verify))*. Each rule can be switched off in the request. |
| 10 | `Finalize` (optional) | boots PrestaShop in a child process and calls its own routines (`Tools::clearAllCache`, `Tools::generateHtaccess`, `Tools::generateRobotsFile`); failure is reported as a warning, steps 1–9 stay valid. |
| 11 | `SelfTest` | HTTP checks against the base URL of the request: home page, a category, a product, **a product image via its friendly URL** (proves the `.htaccess` fix), contact, front login, back-office login page; redirect-loop detection; scan of the home page for the old domain. Traffic-light report. |

### 3.6 CLI

```
threadsafe migrate prestashop9 --root=/var/www/html --to=shop.example.test [--from=localhost:8190]
    [--path=/] [--ssl=none|login|all] [--mode=production|dev]
    [--db-host=… --db-name=… --db-user=… --db-password-file=…] [--prefix=ps_]
    [--request=request.json] [--dry-run] [--yes] [--report=report.json]
    [--replace-bare] [--skip-tables=a,b] [--no-finalize] [--self-test-url=http://…]
```

Passwords are never accepted as plain command-line arguments (they end up in shell history and process lists); use `--db-password-file`, an environment variable or the request file. Exit codes: `0` ok, `1` usage error, `2` guard failed, `3` step failed, `4` self-test failed, `5` internal error.

### 3.7 Restore in place (same domain)

With no domain mapping the same steps run, but most of them find nothing to change and say so ("already correct") in the report. Mode defaults to `production`; dev mode is never applied implicitly. What still happens: step 2 when database credentials changed, step 3 (caches, always), step 7 for host-bound values such as an absolute `error_log` path that differs on a new server, step 8 (directory skeleton and permissions, always) and step 11 (self-test). Details: CONCEPT 4.6. The journal is written as usual.

### 3.8 Module rules

The core contains no module-specific code. A **module rule** (`resources/prestashop9/modules/<module>.json`, plus optional user rules in `data/rules/`, user rules win) describes one module:

```json
{
  "module": "ps_example",
  "domainColumns": [
    {"table": "example_option", "column": "value", "form": "serialized"},
    {"table": "example_link", "column": "url", "form": "plain"}
  ],
  "configuration": [
    {"key": "EXAMPLE_API_TOKEN", "dev": "clear"},
    {"key": "EXAMPLE_CALLBACK_URL", "migrate": "replace"}
  ],
  "skipTables": ["example_log"],
  "files": [{"glob": "modules/ps_example/cache/**", "action": "delete"}],
  "dev": {"deactivate": true},
  "handler": null
}
```

- Table names are written without the prefix. `form` tells the engine which codec to force; without a rule the engine detects it.
- `handler` may name a PHP class implementing `ModuleHandler` for logic that data cannot express; it is optional and rare.
- Steps 3, 5, 7 and 9 load all rules for modules that are actually installed (`ps_module`), so rules for absent modules cost nothing.
- Modules without a rule: handled by the generic engine; their domain hits are listed as "no rule" in the dry-run report, which is how new rules get written.
- A schema file validates rules; a rule without a test case is not accepted into the shipped set.

## 4. Test strategy

### 4.1 Unit tests (PHPUnit, no database)

- `ParametersFile`: round trip is byte-identical; changed values only; PEM multi-line values; hostile input.
- `Serialized` codec: property tests (generate nested arrays and objects with strings containing the domain, multi-byte characters, quotes; after rewrite `unserialize()` must succeed and equal the expected structure).
- `Json` codec: escaped slashes, unicode escapes, numbers and key order unchanged.
- `UrlMatcher`: URLs with/without `www`/scheme/port/triple slashes; **e-mail addresses at the shop domain stay untouched**; foreign domains untouched; bare domain reported but not replaced.
- `HtaccessRewriter`: golden files, including content outside the PrestaShop markers (must be preserved).
- `PhpIni`/sitemap/robots rewriters.

### 4.2 Integration tests (Docker)

1. **Source stack** `threadsafe-dev` (exists): PrestaShop 9.0.2 + MariaDB + Mailpit on `localhost:8190`.
2. **Problem fixtures on top of the fresh install** *(implemented in WP 9: fixture module and seed SQL)*. A fresh install already contains demo data (19 products, 2 customers, 5 orders, 5 CMS pages, 2 languages, 75 modules in the 9.0.2 image) and is the default test base. What a fresh install does not contain is added deterministically:
   - a small open-source **fixture module** `threadsafe_fixture` (in `tests/fixtures/modules/`), installed through the normal module install path. It stores the shop domain the way real modules do: a serialized option, a JSON option, URLs in a language table, a generated file with absolute URLs, a cron/webhook URL.
   - `tests/fixtures/seed-problem-cases.sql`: CMS text with `http://www.<domain>/…`, e-mail addresses at the shop domain, a second foreign domain, a generated log table with 200k rows, a changed `php.ini`, a Smarty cache entry.
   - variants of the install: SSL on/off, other language set, other table prefix, and a sub-path variant (created by migrating to a path and then checking, see acceptance criterion 1).
3. **Clone script** `tests/stack/clone.sh`: dumps the source database and copies the files (without `var/cache`) into the **target stack** (own volumes and network, port 8290, other database name and credentials). Scripts, stack layout and extension notes: [tests/stack/README.md](../tests/stack/README.md). *(implemented in WP 9)*
4. **Migrate and verify:** run `threadsafe migrate` in the tools container; then HTTP smoke test, DB assertions (no old domain left outside skip tables, e-mail addresses unchanged, serialized values still decodable), and the `.htaccess` oracle comparison.
5. **Disaster-recovery scenario (same domain):** copy files and dump from the source stack, destroy its containers and volumes, recreate empty ones on the same port, restore, run `threadsafe migrate` without a mapping, smoke-test. A second variant restores an older state over a modified installation by hand to confirm what phase 3 will have to clean up.
6. **Module rules:** the fixture module ships its own rule; a test removes the rule and checks that the dry run lists the module's occurrences as "no rule".
7. Matrix later: PS 9.0 / 9.1 × MariaDB 10.11 / 11.4 × PHP 8.4 / 8.5.

### 4.3 Private real-shop test (optional, local only)

Fresh installs plus fixtures are the basis of all automated tests; no customer data is needed for them. A real backup remains useful only as a **final reality check** for what nobody can synthesise: unknown third-party modules, years of configuration drift (the examined shop was upgraded from 8.2 to 9.0), real encodings and content. It is restored into the target stack by hand and migrated with the same command, stays under `/private/` (ignored by Git) and never produces output in the repository, logs or documentation. Findings from it are turned into new fixtures, not into references to the real data. The procedure is documented without any customer data.

## 5. Work packages

| WP | Content | Depends on | Size |
|---|---|---|---|
| 1 | Dev container (PHP 8.4 CLI, `pdo_mysql`, Composer) in `.docker/`, `composer.json`, PHPUnit and PHPStan setup, CI workflow for lint, static analysis and unit tests | – | S |
| 2 | `Core/` (Step, Plan, Runner, Report, Journal), `Cli/` skeleton, exit codes — **done** | 1 | M |
| 3 | `ParametersFile` reader/writer with tests | 1 | M |
| 4 | `Replace/` engine: `UrlMatcher`, `Serialized`/`Json`/`Plain` codecs with property tests | 1 | L |
| 5 | `Database/` and steps 1, 3, 4, 5 (including skip tables, chunking, dry run) | 2, 4 | L |
| 5b | `Modules/` rule system: JSON schema, loader, registry, user rules folder; integration into steps 3, 5, 7, 9; rule for the fixture module | 4, 5 | M |
| 6 | steps 2, 6, 7, 8 (files) with golden tests | 2, 3 | M |
| 7 | step 9 dev mode (data-driven rules) | 5 | M |
| 8 | steps 10, 11 (finalize, self-test) | 5, 6 | M |
| 9 | integration harness: target stack, fixture, clone script, assertions, `.htaccess` oracle — **done** (migration assertions follow with WP 5–8) | 1 | L |
| 10 | documentation: request/report/journal format, usage, private-test procedure | all | S |

WP 3, 4 and 9 can run in parallel after WP 1. Suggested order: 1 → (3, 4, 9) → 2 → (5, 6) → 7 → 8 → 10. Milestone A (after WP 5, 6): domain, parameters, `.htaccess` and caches migrated and verified in Docker; milestone B (after WP 7, 8): dev mode and self-test complete; phase 1 done.

## 6. Acceptance criteria

1. Cloning the source stack to the target stack and running one `threadsafe migrate` command produces a site whose home page, a category, a product, a product image (friendly URL), the contact page and the back-office login all answer correctly under the new domain **without any manual step**.
2. After the run no occurrence of the old domain remains outside the skip tables and the reported "untouched" items; e-mail addresses at the old domain are unchanged; every serialized value still unserializes.
3. A second run changes nothing (idempotence).
4. `--dry-run` changes nothing (file hashes and database checksums equal before and after) and lists every change the real run performs.
5. `.htaccess` block equals PrestaShop's own regeneration for the same state.
6. Dev mode leaves no live SMTP credentials, no live PayPal credentials and no `PS_ACCOUNTS_*` secrets in the database, and outgoing mail arrives in Mailpit.
7. The 200k-row log table is skipped, and the migration of the fixture finishes in a few seconds.
8. A restore on the **same domain** (destroyed and recreated stack) works with one command and no domain mapping: home page, product image, back office login answer, sessions-related keys are unchanged, dev mode was not applied.
9. A module without a rule shows up in the dry-run report as "no rule"; adding a rule file in the user rules folder changes the next dry run without any code change.
10. Unit tests, PHPStan (level to be fixed in WP 1) and the integration test pass in CI/locally.

## 7. Risks and open questions

**Risks**

- Third-party modules storing the domain in unknown places: the dry-run report plus the "bare domain reported" rule keep this visible; the real-shop private test is the check.
- Serialized objects with private/protected properties (`\0` bytes in property names): the tokenizer must treat lengths as bytes and not trim; covered by property tests.
- Very large tables: chunked updates; tables without primary key are skipped and reported.
- Path changes (`physical_uri`) touch more places than a domain change (`.htaccess`, `ErrorDocument`, possibly module settings); separate test cases.
- Behaviour differences between PS 9.0 and 9.1 *(verify)*; the test matrix covers both once WP 9 exists.

**Decisions (2026-10-20)**

1. Safety net: guards plus a journal for manual revert; no automatic `undo` command in phase 1.
2. Multistore: simple mapping (one `from → to` per `shop_url` row); tested with a single shop only.
3. Bare domains in free text: reported, not replaced (allow-list for known columns, `--replace-bare` as a switch).
4. Command structure: `threadsafe migrate prestashop9 --root=… --to=…`.
