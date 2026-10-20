# `threadsafe migrate prestashop9` – reference

This is the reference for the restore core of ThreadSafe (phase 1): the command, its request, its report, its journal and
its exit codes. The design is in [PHASE1.md](PHASE1.md), the reasons in [CONCEPT.md](CONCEPT.md).

`migrate` takes a PrestaShop 9 copy that is **already restored** (files in place, database imported – by ThreadSafe's
restore later, by Akeeba, by `tar` and `mariadb`, by hand) and makes it work where it is now: on a new domain, under a new
path, with a new database, or – without any new address – again in the place it came from (disaster recovery). Without
`--yes` it only plans and reports. MariaDB only, PHP 8.4+.

## 1. Quick start

```bash
# 1. look: plans everything, changes nothing
php bin/threadsafe migrate prestashop9 --root=/var/www/dev-shop --to=dev.shop.test \
    --db-host=127.0.0.1 --db-name=shop_dev --db-user=shop_dev --db-password-file=/root/shop_dev.pw

# 2. do it: same command with --yes (and a JSON report)
php bin/threadsafe migrate prestashop9 --root=/var/www/dev-shop --to=dev.shop.test \
    --db-host=127.0.0.1 --db-name=shop_dev --db-user=shop_dev --db-password-file=/root/shop_dev.pw \
    --report=/root/migration.json --yes

# live -> dev: additionally cut mail, payment and cloud connections, keep search engines out
php bin/threadsafe migrate prestashop9 ... --mode=dev --mail-catcher=127.0.0.1:1025 --yes

# the same shop in the same place again (new server, same domain): no --to
php bin/threadsafe migrate prestashop9 --root=/var/www/shop --yes
```

The old domain is read from the shop's database (`--from` overrides). Run as the owner of the shop's files (or as root:
files keep their owner, new files get the owner of their directory). Back up the database before `--yes` on a shop that
matters: there is no automatic undo, only the [journal](#5-the-journal).

## 2. Request

The request says what the user wants. It comes from command line options, from a JSON file (`--request=FILE`), or
both; **options win over the file**. The file uses the setting names of the table, the options the names in the
third column. Unknown settings are refused.

| Setting | Type | Option | Meaning, default |
|---|---|---|---|
| `root` | string | `--root=PATH` | Directory of the PrestaShop installation. **Required.** |
| `to` | string | `--to=DOMAIN` | New domain, with port if not the default one (`shop.example`, `localhost:8290`). Without it (and without `domains`) the run is a restore in place. |
| `from` | string | `--from=DOMAIN` | Old domain. Default: the main domain in `shop_url`. Needs `to`. |
| `domains` | list | `--domain=OLD=NEW` (repeatable) | More mappings for shops with several shop URLs: `"old=new"` or `{"from": "old", "to": "new"}`. |
| `path` | string | `--path=/shop/` | New base path (`physical_uri`), starts and ends with `/`. Default: unchanged. |
| `ssl` | string | `--ssl=MODE` | `none`, `login` (HTTPS for login and checkout) or `all`. Default: unchanged. |
| `mode` | string | `--mode=MODE` | `production` (default) or `dev`, see [section 8](#8-dev-mode). |
| `database` | object | `--db-host`, `--db-port`, `--db-name`, `--db-user`, `--db-password-file`, `--prefix` | Connection to use; every missing value comes from `app/config/parameters.php`. With a new database the command writes it into `parameters.php`. |
| `serverPath` | string | `--server-path=PATH` | The shop's directory as the **web server** sees it, if that differs from `root` (ThreadSafe in another container). Used for absolute paths written into files such as `php.ini`. |
| `replaceBare` | bool | `--replace-bare` | Also replace bare host names in content (not only URLs). Off by default: a bare name is often a mail domain or a note. |
| `skipTables` | list | `--skip-tables=a,b` | More tables (without prefix) the content replacement leaves alone. Logs and caches are skipped anyway. |
| `chunkSize` | int | `--chunk-size=N` | Rows per query and transaction in the replacement (default 2000, 1–100000). |
| `rulesDir` | string | `--rules-dir=DIR` | Your [module rules](#9-module-rules). Default: `data/rules` next to the program, if present. |
| `mailCatcher`, `devSkip`, `devRules` | string, list, string | `--mail-catcher=HOST[:PORT]`, `--dev-skip=LIST`, `--dev-rules=FILE` | Dev mode only. |
| `selfTest` | bool | `--no-self-test` | Check the migrated shop over HTTP at the end (default `true`). |
| `selfTestUrl` | string | `--self-test-url=URL` | Address of the web server if it differs from the shop's domain, e.g. `http://127.0.0.1:8080` (scheme, host, port; the shop path is added). |
| `selfTestInsecure` | bool | `--self-test-insecure` | Do not verify TLS certificates in the self-test. |
| `finalize` | bool | `--no-finalize` | Let PrestaShop clear its caches itself at the end (default `true`). |

Not part of the request: `--journal-dir=DIR` (default `<root>/var/threadsafe-journal`), `--report=FILE`, `--dry-run`
(the default), `--yes`, `-v`, `-q`.

```json
{
    "root": "/var/www/dev-shop",
    "to": "dev.shop.test",
    "ssl": "all",
    "mode": "dev",
    "mailCatcher": "127.0.0.1:1025",
    "devSkip": ["tracking"],
    "database": {"host": "127.0.0.1", "name": "shop_dev", "user": "shop_dev", "passwordFile": "/root/shop_dev.pw"}
}
```

**Passwords** are never accepted as a plain command line option (history, process list). The database password comes,
in this order, from `--db-password-file` (`database.passwordFile`; one line), a `password` in the request file (keep that
file private), the environment variable `THREADSAFE_DB_PASSWORD`, or `parameters.php`.

## 3. How a run goes

1. **Guards.** `ReadSourceState` looks at the installation and refuses (exit code 2) if this is not what the request
   expects: not PrestaShop 9, database not reachable or not MariaDB, no shop URL, or `--from` is not a domain of this
   shop. It changes nothing.
2. **Plan.** Every step computes what it would do (`plan()`, read-only). All plans are computed before anything is
   changed. This alone is the dry run.
3. **Apply** (only with `--yes`). The steps run in order; a step whose plan is empty is skipped ("already correct"). The
   first failing step ends the run; steps already applied stay applied and are reported, the rest is reported as
   skipped. Every step is idempotent: running the command again after it succeeded finds nothing to do.

Steps in order (details in [PHASE1.md](PHASE1.md) 3.5):

| Id | Does |
|---|---|
| `read-source-state` | the guards above; names the module rules in use |
| `write-parameters` | new database values into `app/config/parameters.php` (keeps `secret`, `cookie_key` … and all formatting) |
| `apply-dev-mode` | dev mode only: mail, payment, cloud links ([section 8](#8-dev-mode)) |
| `discard-caches` | empties `var/cache`, `cache/smarty`, the cache tables, files of module rules |
| `update-configuration` | `PS_SHOP_DOMAIN(_SSL)`, SSL switches, `shop_url` |
| `replace-content-domains` | the old domain in all text columns, serialization- and JSON-safe, journalled per cell |
| `rewrite-htaccess` | domain and base path in PrestaShop's block of `.htaccess` (product images stop working otherwise) |
| `rewrite-host-bound-files` | `php.ini` paths, `robots.txt`, sitemaps, mail templates |
| `block-search-engines` | dev mode only: `robots.txt` and `X-Robots-Tag` |
| `ensure-skeleton` | missing directories, permissions |
| `finalize` | PrestaShop clears its own caches in a child process; never fatal |
| `self-test` | HTTP checks of the migrated shop, [section 7](#7-self-test) |

`finalize` and `self-test` run on every applied run, also when nothing had to be changed. A real run **visits the
shop** (self-test, PrestaShop's cache rebuild), so counters and logs of the shop change – this is also why a second run
shows work for `discard-caches` again.

## 4. Exit codes and the report

| Code | Meaning | What to do |
|---|---|---|
| 0 | ok (also: a dry run) | – |
| 1 | usage error: unknown option, missing or invalid value | read the message, `threadsafe help migrate` |
| 2 | refused: wrong shop or domain, unsupported version or database, unreadable `parameters.php`, invalid rule file | nothing was changed; fix the request |
| 3 | a step failed | the report names the step; earlier steps stay applied; fix the cause and run again (idempotent) |
| 4 | self-test failed | the migration is applied but the shop does not work; the message lists the failed checks |
| 5 | internal error | a bug; please report with `-v` output |

### Text report

```text
Dry run – nothing was changed.

  = read-source-state – Read the installation to be migrated (already correct)
        note: PrestaShop 9.0.2, database "shop_dev" (prefix "ps_") on MariaDB 10.11, 1 shop URL(s), back office folder: admin-dev.
  ~ update-configuration – Set shop domain, SSL and shop URLs (4 change(s) planned)
      update db ps_configuration PS_SHOP_DOMAIN: set the new domain
  ~ replace-content-domains – Replace the old domain in database content (25 change(s) planned)
      update db ps_cms_lang.content (10): replace the old domain (50 URL(s) in 10 row(s))
      ! WARNING [ps_example_item]: No module rule for table "example_item": 6 mention(s) ...
  = self-test – Check that the migrated shop answers (already correct)

Summary: 29 change(s) planned, 1 warning(s).
Nothing was changed. Run the same command with --yes to apply these changes.
```

Markers: `=` already correct, `~` planned (dry run), `+` applied, `*` ran (steps that run anyway), `x` failed, `-` skipped
because an earlier step failed. `note` lines are information, `! WARNING` lines want a look. `-v` shows sample
values (before → after) of each change; values of secrets are always `***`. Warnings do not change the exit code.

### JSON report (`--report=FILE`, mode 0600)

```json
{
    "dryRun": false,
    "startedAt": "2026-10-20T13:12:34+00:00",
    "finishedAt": "2026-10-20T13:12:52+00:00",
    "journal": "/var/www/dev-shop/var/threadsafe-journal/threadsafe-journal-20261020-131234-029f.jsonl",
    "plannedChanges": 4574,
    "appliedChanges": 4574,
    "warnings": 2,
    "steps": [
        {
            "id": "discard-caches",
            "title": "Discard caches (directories and cache tables)",
            "status": "applied",
            "plan": {
                "changes": [{"kind": "delete", "target": "dir var/cache", "description": "remove cached files", "before": null, "after": null, "count": 4528}],
                "notices": [{"level": "info", "message": "...", "target": "var/cache/prod/appParameters.php"}]
            },
            "result": {"applied": 4528, "notices": []},
            "error": null
        }
    ]
}
```

- `status` is one of `planned` (dry run, has changes), `unchanged`, `applied`, `ran`, `failed`, `skipped`.
- A **change** has `kind` (`create`, `update`, `delete`), a `target` (`db <table>.<column>`, `db <table> <name>`, `file <path>`,
  `dir <path>`, `module <name>`), a `description`, optional example values `before`/`after` and a `count` (one change can
  stand for many cells or files). A change flagged secret has `before` and `after` replaced by `"***"`.
- A **notice** has `level` (`info`, `warning`), a `message` and an optional `target`.
- `result.applied` counts the individual modifications performed; `error` holds the message of a failed step.
- `journal` is the path of the journal (null in a dry run and when nothing was changed).

The report may contain domains, table and file names, and sample text from the database. It contains no secrets, but
treat it as private anyway.

## 5. The journal

An applied run that changes something writes a journal: `--journal-dir` (default `<root>/var/threadsafe-journal`,
**directory mode 0700**, file mode 0600) → `threadsafe-journal-<UTC date>-<time>-<random>.jsonl`. It is created on the
first change; a run that changes nothing leaves none. There is no automatic undo in phase 1 – the journal is what makes a
revert **by hand** possible.

**It contains old and new values, including passwords, tokens and keys that dev mode removed. Keep it private; delete it
when you no longer need it. Do not send it to anyone.** Outside the shop's `var/` it is better still: use
`--journal-dir` on a path the web server cannot serve.

Format: JSON Lines, one object per line, written and flushed immediately (it survives a crash). Every line has `t`
(UTC timestamp) and `type`:

| `type` | Fields | Meaning |
|---|---|---|
| `header` | `format` (1), `tool`, `started` | first line |
| `step` | `step` | the entries that follow belong to this step |
| `db` | `step`, `table`, `key`, `column`, `old`, `new` | one database cell; `key` is the primary key of the row (for settings that dev mode clears or removes also the `name`). `old: null` = the row was created, `new: null` = it was deleted. |
| `file` | `step`, `path`, `action` (`create`/`update`/`delete`), `backup` | a file; `backup` is the copy of the original below the journal directory (`files/0001-.htaccess.orig`), null for a new file |
| `note` | `step`, `message`, `context` | decisions and things that cannot be reverted cell by cell, e.g. `Emptied table ps_paypal_webhook` with the row count (the contents of emptied tables are not journalled) |

A value that is not valid UTF-8 is stored as `{"base64": "..."}`.

```json
{"t":"2026-10-20T13:12:34.120000Z","type":"header","format":1,"tool":"threadsafe","started":"2026-10-20T13:12:34+00:00"}
{"t":"2026-10-20T13:12:35.001000Z","type":"step","step":"update-configuration"}
{"t":"2026-10-20T13:12:35.004000Z","type":"db","step":"update-configuration","table":"ps_configuration","key":{"id_configuration":12},"column":"value","old":"shop.example","new":"dev.shop.test"}
{"t":"2026-10-20T13:12:36.100000Z","type":"file","step":"rewrite-htaccess","path":"/var/www/dev-shop/.htaccess","action":"update","backup":"files/0001-.htaccess.orig"}
```

**Reverting by hand**: for a `db` line, `UPDATE <table> SET <column> = <old> WHERE <key>` (or `INSERT`/`DELETE` for `null`
values); for a `file` line, copy `backup` back over `path`. Revert in reverse order. A database dump taken before the run
is the simpler way for anything big.

## 6. Restore in place (same domain)

Without `--to` (and without `--domain`) nothing is replaced: the steps that fix the **environment** still run – database
credentials in `parameters.php`, caches, directories and permissions, absolute paths in `php.ini`, `finalize`, `self-test`
– and the others say "already correct". Dev mode is never applied implicitly. Use it for a shop restored onto a new or repaired server
with the same domain, or after a rollback. Background: [CONCEPT.md](CONCEPT.md) 4.6.

## 7. Self-test

After a real run the shop is requested over HTTP – at its own domain (https if it uses https everywhere) or at
`--self-test-url`, with the shop's domain as `Host` header: home page (complete, no links to the old domain), category,
product, **a product image through its friendly URL** (it only works with a correct `.htaccess`), contact, login and back
office login. Redirect loops, redirects to another host and any status but 200 fail the run with exit code 4; things the
shop does not have (no product, no `.htaccess`) are skipped with a warning. Details: [PHASE1.md](PHASE1.md) 3.10.

## 8. Dev mode

`--mode=dev` is for copies of a live shop that must not touch the live world. Rules (`mail`, `payment`, `accounts`,
`tracking`, `noindex`, plus one per module rule) are data in `resources/prestashop9/dev-mode.json`; `--dev-skip=LIST` leaves
rules out, `--dev-rules=FILE` adds or replaces rules. With `--mail-catcher=HOST[:PORT]` the shop's mail goes to an SMTP catcher
such as Mailpit, without it the shop sends nothing. The customers' personal data stays in the database – dev mode warns about
that and about settings that look like credentials no rule handles. Details: [PHASE1.md](PHASE1.md) 3.9.

## 9. Module rules

ThreadSafe knows nothing about third-party modules. What you know about one goes into `<rules dir>/<module>.json`: columns
with a known format or bare host names, settings to keep, tables to skip, files to delete or rewrite, what dev mode must
switch off. The dry run lists tables with the domain in them that no rule knows ("No module rule for table ..."). Format,
validation and limits: [PHASE1.md](PHASE1.md) 3.8 and `resources/prestashop9/module-rule.schema.json`.

## 10. Trying it on a real backup (private, local only)

The automated tests need no customer data – they use a fresh PrestaShop with synthetic fixtures (`tests/stack/`). A real
backup is only a final reality check for what nobody can synthesise: unknown modules, old configuration, real content. It
contains customer data, so:

- keep it outside the repository (`/private/` is ignored by Git; nothing from it ever goes into the repository, logs or
  documentation);
- restore it **by hand** into a disposable environment (the isolated target stack of `tests/stack/` has no connection to
  anything else; its `clone.sh` shows what a restore must leave behind), never onto a host that can reach the live services;
- migrate with `--mode=dev` and a mail catcher, so that nothing the copy does reaches customers or payment providers;
- read the dry run first; every "No module rule" warning and every unfamiliar warning is a finding;
- turn findings into **synthetic** fixtures or module rules (names of tables and settings, never contents), then delete the
  copy, the report and the journal – they hold customer data and secrets.

## 11. Limits of phase 1

- PrestaShop 9.x and MariaDB only; one `migrate` per shop at a time.
- The restore itself (unpacking an archive, importing the dump) and the backup are not part of this command yet.
- No automatic undo; no anonymisation of customer data; multistore is only tested with one shop.
- The self-test and `finalize` need the shop to be reachable and, for `finalize`, a PHP that can start PrestaShop.
- A changed base path is handled in `.htaccess` and, through `finalize`, in `robots.txt`; other files that hold the path
  (custom rewrite rules outside PrestaShop's block, theme files) are not touched.
