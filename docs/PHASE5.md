# Phase 5 – schedule, retention, encryption

**Status:** **planned, decisions settled** (2026-10-22); no work package is done yet. Decisions 1-3 were settled with the owner in answer to questions (encryption in the format of `age`; public keys of the profile for every backup, plus an optional passphrase for a backup started by hand; a minimal sidecar file next to an encrypted archive). Decisions 4-12 were proposed and the owner changed only one, **9**: it stands as proposed, with the addition that every key and passphrase the program makes must be easy to keep and to copy (3.5 "Keys", 3.6). The findings of the spikes (section 8) may still change the design; then the text is corrected together with the code.
**Context:** [CONCEPT.md](CONCEPT.md) section 5.6 (the trigger and the schedule) and section 6 (security) are the requirements; [BACKUP.md](BACKUP.md), [RESTORE.md](RESTORE.md) and [WEB.md](WEB.md) describe what this phase extends; [PHASE4.md](PHASE4.md) 3.4 has the rule for runs that are active or interrupted, which the trigger relies on. Facts about the code were read from the repository on 2026-10-22; items marked *(verify)* are what the first work package (spikes) has to confirm.

## 1. Goal and scope

Today a backup happens when a person asks for it. Phase 5 makes it happen **by itself**, keeps the storage from growing without end, and makes it safe to keep the archives somewhere other people can reach: **one cron job** calls ThreadSafe every few minutes and the schedule of every site, kept in its profile, decides what is due; **retention** removes the backups that are not wanted any more; **encryption** makes an archive unreadable without a key that the server does not hold.

| In scope | Out of scope (later) |
|---|---|
| schedule per profile (cron expression, a time zone for all), the trigger `threadsafe cron` (command line) and a protected address for hosts without a command line cron, a heartbeat the dashboard watches, a queue (one backup at a time), missed runs made up once, failed runs retried a few times, interrupted runs continued | a graphical cron editor beyond a text field with a preview of the next runs; several schedules per profile; schedules of other kinds of job (checks, mails) |
| retention per profile: keep the last *n*, delete older than *d* days, stay under a size; the newest good backup is never deleted; a backup can be pinned; a dry run and a preview | grandfather-father-son schemes; retention across profiles; retention on remote storage |
| archive encryption in the format of `age` with public keys (the key pair of the owner) and an optional passphrase; keys made by a command; encrypted archives that can be checked (as far as the key is not needed), downloaded, restored by the command and by `restore.php` | encrypting the files of a site at rest, encrypting profiles or the data directory, key escrow, hardware keys |
| the changes of the command line, the profile page, the dashboard (next run, heartbeat) and the System page that these need | **remote storage** (SFTP, S3, WebDAV; `Postproc` stays as it is), notifications by mail, a second administrator |

Phase 5 ends with the **proof on the Docker stack**: a backup that the trigger starts at the scheduled time (command line and address, under the limits of 5 seconds and 64 MB), retention applied to real backups, an encrypted backup that the standard tool `age` can open, and that same backup restored by `threadsafe restore` and by `restore.php` through the wizard.

## 2. Deliverables

1. `src/Schedule/` (cron expression, due computation, state), the command `threadsafe cron`, the address `/cron/<secret>`, the heartbeat and its places in the dashboard and the System page.
2. `src/Retention/` and the command `threadsafe retention`, pinned backups, the changes to the profile page.
3. `src/Crypto/` (the `age` format: keys, header, chunked streams that can be resumed and read at random places), the command `threadsafe keys`, the encryption stage of the backup, the key-aware paths of `verify`, `restore` and the wizard.
4. The new profile fields (`schedule`, `retention`, `encryption`) in `SiteProfile`, the `profile` command and the profile form.
5. Unit tests (cron arithmetic with daylight saving time, due computation, retention rules, the `age` primitives against published test vectors), integration tests in Docker with the real `age` tool as the oracle, a browser walk-through.
6. `docs/SCHEDULE.md` (trigger, schedule, retention) and `docs/ENCRYPTION.md` (keys, what is encrypted, how to decrypt by hand, what is lost when the key is lost), changes to `WEB.md`, `BACKUP.md`, `RESTORE.md`.

## 3. Design

### 3.1 What changes in the data

- **`SiteProfile`** gets three optional fields (a profile without them is what it is today):

```json
{
  "schedule":   {"cron": "30 3 * * *"},
  "retention":  {"keep": 14, "maxAgeDays": 90, "maxBytes": 21474836480},
  "encryption": {"recipients": ["age1ql3z7hjy54pw3hyww5ayyfg7zqgvc7w3j2elw8zmrj2kg5sfn9aqmcac8p"]}
}
```

  Every key inside is optional; `null` or absent means "no such rule". Editing them is `profile edit --schedule=... --keep=... --recipient=...` and the profile form.
- **`config.json`** gets `schedule.timezone` (an identifier such as `Europe/Berlin`; default: the time zone of PHP, which is UTC where nobody set one), `schedule.expectedIntervalMinutes` (how often the trigger is meant to come; default 15; the heartbeat warning is derived from it), `schedule.graceMinutes` (how late a scheduled run may still be made up; default 360), `cron.secret` (the secret of the address; empty = the address does not exist).
- **State of the scheduler:** `data/schedule/<profile>.json` (the last instant that was handled, the number of attempts, the time of the next retry) and `data/heartbeat.json` (time, how it was called, what it did). Both are caches in the sense of the rule "the file system is the source of truth": lose them and the worst case is one extra or one skipped run, and the backups on disk show what exists.
- **Backups and sidecar:** the sidecar file gets `encryption` (`{"format": "age", "recipients": 2, "passphrase": true}`), the entry `pinned` (a backup that retention must not delete) and `source` (`manual`, `schedule`). Older files read as before.

### 3.2 The schedule

- **Cron expressions** are the five fields of the classic `crontab` (minute, hour, day of month, month, day of week) with `*`, lists, ranges, steps, names of months and days, and the shortcuts `@hourly`, `@daily`, `@weekly`, `@monthly`. When both day fields are restricted either may match (the rule of Vixie cron). `CronExpression::next(DateTimeImmutable $after)` returns the next instant in the configured time zone and `previous()` the last one before now; both are pure functions of the expression, the instant and the time zone.
- **Daylight saving time:** an instant that does not exist (02:30 on the day the clock jumps forward) is run at the first instant after the gap; an instant that exists twice (the hour when the clock goes back) runs once, at its first occurrence. A test table pins both cases for several zones.
- **When is a profile due?** The scheduler takes the **latest scheduled instant `t` that is not after now**. The profile is due if `t` has not been handled (state file) and now is at most `graceMinutes` after `t`; handling means "a backup with this `t` was started, or `t` was skipped on purpose". A `t` older than the grace is **skipped** (audit entry `schedule.skipped`) and the next one waits. So after the server was off for a day exactly one run is made up if it is still within the grace, and never a pile of them.
- **A run that fails** is tried again after 15 minutes, twice at most, as long as the grace holds; then it is given up (audit entry, dashboard warning). A run that is stopped by the trigger being absent is not a failure: it is *interrupted* and continued (3.3).
- **The queue:** only one backup runs at a time in the whole installation (the rule of [PHASE4.md](PHASE4.md) 3.4). Profiles that are due wait, ordered by their scheduled instant. A manual backup counts: if one is going, the due ones wait for it.

### 3.3 The trigger

One cron job (or one call of a monitoring service) is all the host needs. It does two things.

**`php bin/threadsafe cron`** (command line, preferred): a **CLI task**. No PHP time limit, due backups run in the calling process, the database is read in one consistent snapshot. In order: write the heartbeat; take the instance lock `locks/cron.lock` without waiting (if another trigger is working, end quietly with exit code 0); continue a run that was interrupted (a run whose lock is free and that is not a recently ticked web run); then, for each due profile in the order of the queue, start a scheduled backup and run it to its end, apply retention, go on to the next; end. If a host kills the process (some hosts cut cron jobs after minutes), the next call finds the run interrupted and continues it, and the manifest then says `consistency: table`.
  Output is one line per action, so that a mail from cron says something useful; `--quiet` prints only problems; the exit code is 0 when nothing went wrong, 2 when something was refused, 3 when a backup failed.

**`GET /cron/<secret>`** (a protected address, for hosts whose cron can only fetch a URL, `wget -q -O - ...` or `curl -s ...`): a **web task**. Each call writes the heartbeat and does **one tick** of work under the limits of the request: it continues the run that is going or interrupted, or, if a profile is due, starts a run and does its first tick; with a call every minute a backup goes on by itself. The database is then read table by table. The answer is a few lines of plain text.
  - The address exists only if `cron.secret` is set (`threadsafe admin cron-secret` makes a long random one, 32 bytes, and prints the line for the crontab). It exists **independently of `web.enabled`**: the interface can be switched off (every other address is "not found") and the trigger still works; without a secret nothing is there at all. The secret is compared in constant time, wrong ones are slowed down by the same `Throttle` as the login, and the answer to a wrong one is the same plain "not found".
  - A secret in an address ends up in the access log of the web server and in the history of whoever types it. Say so in the documentation; the secret gives the right to start backups and nothing else, and it can be replaced with one command.

**The heartbeat** is written on every call: the time, `cli` or `web`, what was done. The dashboard shows "last trigger: 4 minutes ago" and **warns when it stays away** for longer than `max(2 × expectedIntervalMinutes, 30)` minutes while at least one profile has a schedule; the System page says how to set the trigger up (the exact `crontab` line with the real paths, the exact address). A profile that has a schedule and no heartbeat ever shows "no trigger has called yet".

### 3.4 Retention

- **Rules** (all optional, any combination): `keep` - at most this many backups are kept; `maxAgeDays` - older ones are removed; `maxBytes` - the oldest ones are removed until the total is under the limit. Only **complete, usable** backups count (status `ok`, `ok-with-warnings`, `unverified`).
- **Never deleted:** the newest usable backup (so a failing schedule or a mis-set rule cannot leave the site without a backup); a **pinned** backup (`backups --pin=ID`, a button on the profile page; it does not count towards `keep`); a backup that a run is writing or a check is reading. A `faulty` or `incomplete` backup is **not deleted by retention** (it may be recoverable by hand; the dashboard says it is there). Leftovers of runs that died (work directories and `.part` files) that no run record and no lock claims and that are older than seven days are removed.
- **When:** after every successful backup of the profile, and by the trigger once per hour per profile (so that `maxAgeDays` works for a site that no longer produces backups). **Never after a failed backup**, and never when the listing of the storage is incomplete (the storage cannot be read).
- **Safety:** `threadsafe retention <profile> [--dry-run]` shows what would be deleted and why; the profile page shows the same as a preview; every deletion is an audit entry with the rule that caused it. The rules are applied oldest first, one backup at a time, and the numbers are recomputed after each deletion.

### 3.5 Encryption

**Format.** An encrypted part is the finished ZIP part wrapped in the **`age` v1 format** (`shop_..._p01.zip.age`). The ZIP inside stays an ordinary ZIP, so what has been decided in the earlier phases (archives open with ordinary tools) holds after decryption with an ordinary tool: `age -d -i key.txt shop_..._p01.zip.age > shop_..._p01.zip`. What the format gives, and what is implemented in PHP with `ext-sodium` (which is part of PHP 8.4):

- **Recipients:** X25519 public keys (`age1...`). A backup is encrypted for every recipient of the profile; any one of the keys opens it. The secret keys (`AGE-SECRET-KEY-1...`) belong to the owner and **never touch the server**, except as typed into the restore (3.5, "Restoring").
- **Streams:** the payload is cut into chunks of 64 KiB, each sealed with ChaCha20-Poly1305 under a key derived from the file key and a per-file nonce; the last chunk is marked, so truncation is detected. Because a chunk's nonce is its number, **a reader can jump to any chunk**: that is what lets `ZipReader` read an encrypted part without first decrypting it to a temporary file (below), and lets encryption stop and resume at a chunk boundary in a tick.
- **Keys, easy to keep:** `threadsafe keys generate` and the profile page make a key pair and present it so that it can be kept without effort: the secret key and the public key each with a **copy button** (the clipboard API where the page is https or local, otherwise the text is selected for copying by hand); a **file to download or save** (`--output=FILE` on the command line, mode 0600) with exactly the content of `age-keygen` (`# created: ...`, `# public key: age1...`, `AGE-SECRET-KEY-1...`), so that the real `age` tool opens it directly; and a **print view** (a page that prints cleanly, for a paper copy) that holds the secret key, the public key and the sentence that says what is lost without it. The owner stores the secret key somewhere safe and the public key goes into the profile. The page shows the secret key **once**; it is not stored on the server. The same for a **passphrase**: `keys passphrase` and a button next to the field offer a generated one that is easy to read aloud, type and copy - five groups of four letters and digits from an alphabet without look-alike characters (about 95 bits), e.g. `kr7m-xq3d-hp9t-w4nc-2vfe` - and show it once with the same copy button and file; a passphrase that the person chooses is accepted as well.
- **Passphrase.** `age` itself uses `scrypt` for passphrases, and `scrypt` with its default work factor needs 256 MB and minutes in pure PHP. *(Spike 1a measures this.)* The rule of this plan: **if a work factor that is safe enough runs in PHP within the memory and time of a request, the passphrase is the native `age` passphrase recipient** (`age -d` then simply asks for it). **If not, the passphrase becomes an identity**: a key pair derived from the passphrase and a random salt with Argon2id (`sodium_crypto_pwhash`, native speed), the public key becomes an ordinary X25519 recipient, and a second, ignorable stanza in the header carries the salt and the cost parameters. `threadsafe keys derive` prints the secret key for a passphrase and a file, so that plain `age` can open it too; ThreadSafe opens it from the passphrase directly. Either way the person types a passphrase, and the file is a valid `age` file.

**In the backup.** A new stage `encrypt` follows the packing of each part: when a part is closed it is sealed into `<part>.age` in ticks (chunk by chunk, with the position and the stream's counters in the run state), the plain part is removed after the sealed one has been checked, and packing goes on. So at most one plain part is on disk at a time besides the sealed ones, and a plain part never lies in the storage after the run (it lies in the work directory, which is closed to the web like the storage). The file key of a part is random; it is wrapped for every recipient in the header of that part and, until the part is done, kept in the run state (mode 0600, deleted with the run), like the database password of a restore. A **passphrase typed for a web backup** is held the same way for the length of the run only. If the run fails, the sealed and the plain files are removed like any other leftover.

**What is stored where.**
- `…-pNN.zip.age` - the sealed part. The part marker and the manifest are inside the ZIP, so they are encrypted.
- The **sidecar file** of an encrypted backup (decision 3) is minimal: time, size, the parts with their SHA-256 *of the sealed file*, application and version, status, the number of files, directories and tables, `encryption`. No domains, paths, table names, filters, warnings with file names. The profile page and the details page show what that holds and say "details are in the encrypted archive".
- The web interface and the commands list, check (quickly), download and delete such a backup without a key.

**Checking.** Quick (size and SHA-256 of the sealed files) needs no key. A **full** check needs the secret key or the passphrase (`verify --identity-file=FILE` / `--passphrase-file=FILE`): it reads the ZIP *through* the decrypting stream and does everything it does for a plain backup, and the authentication tags of the chunks make the check stronger (every byte is authenticated, not only checksummed). The web interface does the quick check only and says so; it never sees a secret key. `threadsafe keys check` decrypts the first chunk of a part with a key to show that the key fits.

**Downloading** sends the sealed file unchanged ("encrypted archives are downloaded encrypted").

**Restoring.** `BackupSource` accepts `.zip.age` parts. `restore` takes `--identity-file=FILE` or `--passphrase-file=FILE`; the wizard asks for the secret key or the passphrase on the page where it asks which backup, keeps it in the run state like the database password (mode 0600, blanked at the last tick, deleted with the state) and says that it is sent over the connection (the plain-http confirmation of the wizard applies). The extractor and the importer read the ZIP through the **decrypting stream** (a PHP stream wrapper that implements `fread`, `fseek` and `ftell` over the chunks), so no decrypted copy of a multi-gigabyte part is written and the free-space check does not double. The preflight reads the manifest through the same stream. `restore.php` needs `ext-sodium`; the preflight says so when it is missing, in the sentence that names the extension.

**When the key is lost, the backups are lost.** The documentation says so before the first key is made, `keys generate` repeats it, and the profile form requires the person to tick that the secret key has been stored somewhere other than this server.

### 3.6 The interface and the command line

- **Command line:** `cron` (3.3), `retention`, `keys generate|derive|check`, `admin cron-secret`; `profile add|edit` get `--schedule`, `--keep`, `--max-age-days`, `--max-bytes`, `--recipient` (repeatable), `--no-schedule`, `--no-encryption`; `backup` gets `--passphrase-file`; `backups` shows the lock and the pin and `--pin=ID`/`--unpin=ID`; `verify`/`restore` get the key options of 3.5.
- **Profile form:** a *Schedule* section (the expression, a few presets, the next three runs shown in the configured time zone), a *Retention* section (three numbers and the preview of what the rules would delete now), an *Encryption* section (the recipients, "make a key pair" with the copy buttons, the file and the print view of 3.5, "suggest a passphrase", the tick that the secret key is stored elsewhere).
- **Dashboard:** "next run" on every card with a schedule, the last trigger and its warning in the header; a card shows a lock for an encrypted newest backup. **Profile page:** a pin button per backup, the state "encrypted" in the list, a passphrase field next to *Back up now* when the profile is encrypted.
- **System page:** the trigger (the crontab line, the address, the last heartbeat), the time zone, `ext-sodium` present or not, the retention that ran last.

## 4. Test strategy

### 4.1 Unit tests (no database, no network)

- **Cron:** a table of expressions and instants with the expected next and previous instants (lists, ranges, steps, names, both day fields, `@daily`, leap day, end of year); daylight saving time in `Europe/Berlin`, `America/New_York`, `Australia/Lord_Howe` (a half-hour shift) and `UTC`; malformed expressions refused with a message that names the field.
- **Scheduler:** due, not due, handled, skipped after the grace, one run made up after a long absence, retries after a failure and their limit, the queue order, a manual backup in the way, state files lost, a clock that went back.
- **Retention:** every rule alone and combined, the newest good backup kept whatever the rules say, pinned backups, faulty and incomplete ones left alone, leftovers only when old and unclaimed, the order, recomputing after each deletion, a dry run that deletes nothing, an unreadable storage that deletes nothing.
- **`age`:** bech32 encoding of keys against the published vectors; the X25519 stanza, the header MAC and the payload against the **test vectors of the `age` project (CCTV)** and against files made by the real tool; chunk boundaries (empty, one byte, exactly 64 KiB, 64 KiB + 1, many chunks); truncation, a flipped bit, a wrong key, a missing last-chunk mark all refused; random access (`fseek` to every kind of position) equal to a sequential read; the derived identity of a passphrase is stable and differs with the salt.
- **Trigger address:** a wrong or empty secret is the plain "not found"; the throttle; present with the interface off; absent without a secret; one tick per call.
- **The CLI tests** of `profile`, `backup`, `backups`, `verify`, `restore`, `admin` stay green after the extensions.

### 4.2 Integration tests (Docker)

The tools image gets the real `age` tool (`apt-get install age`), the oracle in both directions.

| Test | Proves |
|---|---|
| **Scheduled run by the command** | a profile with `* * * * *` and a trigger call: a backup is made, `consistency: snapshot`, source `schedule`; a second call within the minute does nothing; the heartbeat is written; retention applied afterwards |
| **Scheduled run by the address** | the same through `/cron/<secret>` on the `web` service under 5 s / 64 MB: several calls make one backup; `consistency: table`; a wrong secret gets "not found"; with the interface switched off the address still works |
| **Interrupted by the host** | the trigger process is killed in the middle of a backup (SIGKILL); the next call continues it and the archive equals an uninterrupted one |
| **Missed and retried** | the clock (a setting of the test) is moved past a scheduled instant by less and by more than the grace: made up once / skipped; a failing profile is retried twice and then given up |
| **Retention on real backups** | several real backups; each rule; the dry run; the newest good one and a pinned one survive; a faulty one is left |
| **Encryption oracle** | an encrypted backup of the seeded shop (command line and web with a passphrase): `age -d` with the secret key and with the passphrase (or the derived key) gives the parts; `unzip -t` on them passes; the SHA-256 of a sealed part equals the sidecar's; the sidecar holds no domain, no table name |
| **Keys as presented** | the key file that `keys generate` and the page offer is accepted by `age -d -i` and by `age-keygen -y` (which prints the same public key); a generated passphrase has the shape and the entropy promised and opens a backup; the page shows the secret key once and a reload does not show it again |
| **Encrypted restore** | the encrypted backup restored by `threadsafe restore --identity-file` and by `restore.php` through the wizard under 5 s / 64 MB (with the key typed into the wizard), tables and files equal; a wrong key is refused before anything is written |
| **Cross-server** | the whole of it on MariaDB 10.11, MySQL 8.4 and MariaDB 11.8 (`matrix.sh`) |

### 4.3 The browser walk-through

As in phase 4: create a profile with a schedule, retention and a key in the built-in browser, see the next run on the dashboard, the heartbeat warning appear and go, the pin, an encrypted backup in the list; at 375 px and desktop width, light and dark, the console open.

## 5. Work packages

| WP | Content | Depends on | Size |
|---|---|---|---|
| 1 | **Spikes** (section 8): (a) the `age` format in PHP: X25519 stanza, header MAC, chunk stream, checked against the real tool (installed in the tools image) and the CCTV vectors; speed in MB/s; **scrypt in pure PHP** (time and memory at several work factors) against the derived-identity alternative; a seekable decrypting stream wrapper read by `ZipReader`; `ext-sodium` on the live host; (b) cron arithmetic and daylight saving time against `DateTimeZone`; (c) the trigger on the live host: which PHP binary a cron job can name, whether it can fetch the address, how long a cron process may live there; (d) what a trigger every minute costs a host (the work of a call that finds nothing to do) | – | M |
| 2 | **Schedule core:** `CronExpression`, `Scheduler` (due, grace, retry, queue, state files), the profile fields `schedule`/`retention`/`encryption` in `SiteProfile`, `profile add|edit` options, the settings | 1 | L |
| 3 | **The trigger:** `threadsafe cron`, the address `/cron/<secret>`, the heartbeat, `admin cron-secret`, continuing interrupted runs, the queue, the dashboard's next run and heartbeat warning, the System page's trigger section | 2 | L |
| 4 | **Retention:** `Retention`, `retention` command, pinned backups, the preview, application after a backup and by the trigger, the profile page | 2 | M |
| 5 | **`age` core:** `src/Crypto/` (bech32, keys, stanzas, header, chunk encrypt and decrypt that can resume, the stream wrapper with seek), `keys generate|derive|check`, the passphrase rule of 1a | 1a | L |
| 6 | **Encryption in the backup:** the `encrypt` stage in `BackupRun`, naming and patterns in storage and catalog, the minimal sidecar, quick and full `verify` for encrypted backups, download, the profile form and page, the passphrase for a web backup | 5, 4 | L |
| 7 | **Encryption in the restore:** `.zip.age` in `BackupSource`, the decrypting stream in extractor, importer and preflight, `restore` options, the wizard field, the check of a missing `ext-sodium` | 5 | M |
| 8 | **Integration:** the `age` tool in the tools image, the tests of 4.2, the trigger under the limits of the `web` service, the matrix, the browser walk-through | 2-7 | L |
| 9 | **Documentation:** `docs/SCHEDULE.md`, `docs/ENCRYPTION.md`, `WEB.md`, `BACKUP.md`, `RESTORE.md`, README, CONCEPT and AGENTS status | all | S |

Suggested order: 1 → 2 → (3, 4) → 5 → 6 → 7 → 8 → 9. **Milestone A** (after 4): backups happen by themselves and the storage keeps itself in order. **Milestone B** (after 7): backups can be encrypted and restored. **Milestone C** (after 8): proven on all three database servers.

## 6. Acceptance criteria

1. A profile with a schedule is backed up by the trigger at the scheduled time, by the command (snapshot) and by the address (under 5 s / 64 MB, table by table); never two backups at once; one call too many does nothing.
2. A long absence makes up one run if within the grace and skips it otherwise; a failing run is retried twice; a killed trigger is continued by the next call and gives an archive equal to an uninterrupted one.
3. The dashboard shows the next run of every scheduled profile and warns when the trigger stays away; the System page gives the exact line for the crontab and the exact address.
4. Retention keeps the rules of 3.4 on real backups, never deletes the newest usable backup or a pinned one, shows a dry run and a preview that agree with what it then does, and writes every deletion to the audit log.
5. An encrypted backup opens with the real `age` tool with the secret key and with the passphrase; the ZIP inside passes `unzip -t`; the server holds no secret key and no passphrase after the run; the sidecar file holds no domain, path or table name.
6. `threadsafe restore` and `restore.php` (through the wizard under 5 s / 64 MB) restore an encrypted backup into an empty target with equal tables and files; a wrong key is refused before anything is written; a truncated or damaged sealed part is found by the full check and by the restore.
7. The quick check, the list, the download and the deletion work on an encrypted backup without a key; the web interface never asks for a secret key except in the wizard of `restore.php` on the target.
8. Secrets (the cron secret, passphrases, secret keys, the file key) appear in no log, audit entry, report, manifest or page, except the one page that shows a freshly made key or passphrase once; the file key and a typed passphrase live only in the run state until the run ends.
9. A key pair or passphrase made by the program can be copied with one click, saved as a file that `age` itself accepts, and printed; the page that shows it says what is lost without it.
10. Unit tests, PHPStan level 8 and the integration tests (`tests/stack/matrix.sh`) pass on MariaDB 10.11, MySQL 8.4 and MariaDB 11.8; `composer test:bundle` passes (the restore tool carries the decryption).

## 7. Risks and decisions

**Risks**

- **Cryptography written by us.** The primitives are libsodium's; what is ours is the *composition* of the `age` format. Mitigation: the format is public and has test vectors; the real tool is the oracle in both directions; no invention (no own header, no own mode); the work package that writes it starts with the spike that proves the compatibility.
- **`scrypt` in PHP** is too slow at a safe work factor (spike 1a). Mitigation: the rule of 3.5 - a derived identity with Argon2id, still a valid `age` file.
- **`ext-sodium` is missing** on a host. Encryption and decryption are then unavailable and say so by name; nothing else changes. The live host is checked in spike 1a.
- **A lost key means lost backups.** Documentation first, the tick in the form, `keys check`, and the sidecar that says which public keys a backup was made for (their short fingerprints) so that the right secret key can be found.
- **The restore tool carries more code** (the decrypting stream, bech32, X25519 handling). The bundle grows by a few tens of KB; `composer test:bundle` runs the tests against it.
- **Retention deletes what a person wanted.** Never the newest usable one, pinned ones are safe, faulty ones are left, a dry run and a preview exist, every deletion is audited; and the first run of a new rule on a profile that has more backups than `keep` shows the list and waits for one confirmation (command and page) before the trigger applies it on its own.
- **Time zones and daylight saving time** are where schedulers go wrong. A test table per zone, a fixed rule for both kinds of gap, and the time zone shown next to every instant in the interface.
- **Two triggers at once** (the cron job and a monitoring service call the address): the instance lock, the active-run rule and one tick per call make that harmless; a test calls the address in parallel.
- **A host that kills long cron processes.** The next call continues the run from its saved state; the manifest says `consistency: table`; the documentation recommends a call every few minutes and says what the host's limits do to a long backup.
- **The secret in the address** is logged by the web server. It grants nothing but the start of a backup; it can be replaced; the documentation says so.
- **Scope.** Three features, each with a command, a page and a restore path. They are separate work packages with their own proof, and the order lets the owner stop after milestone A or B.

**Decisions** (1-3 settled in answer to questions; 4-12 proposed and confirmed, with the addition to 9)

1. **Format - settled (2026-10-22):** encrypted archives are `age` files around the ZIP parts (`.zip.age`); rejected: ZIP AES (no key pair, slow in PHP, file names visible), an own format.
2. **Keys for backups made by hand - settled (2026-10-22):** the public keys of the profile are used for every backup, scheduled or manual; the web interface can add a passphrase for one backup, held only in the run state until the run ends. The command line has `--passphrase-file`.
3. **Metadata - settled (2026-10-22):** the sidecar file of an encrypted backup is minimal (3.5).
4. **Passphrase realisation - settled (2026-10-22):** native `age` passphrase if spike 1a shows a safe work factor within the limits of a request, otherwise a derived identity (3.5). The spike decides; the owner is told which.
5. **Trigger - settled (2026-10-22):** `threadsafe cron` and `/cron/<secret>` as in 3.3; the address exists only with a secret and independently of `web.enabled`; the command is preferred and documented first.
6. **Schedule semantics - settled (2026-10-22):** five-field cron expressions in one configurable time zone; grace of six hours; two retries at 15 minute intervals; one backup at a time; a missed run is made up once.
7. **Retention - settled (2026-10-22):** the three rules, the newest usable backup and pinned backups protected, faulty ones left, old unclaimed leftovers removed after seven days, a first-time confirmation for a rule that would delete many (3.4, risks).
8. **Scheduled backups are CLI tasks when called by the command - settled (2026-10-22):** snapshot consistency; when called by the address they are web tasks and the manifest says `table`.
9. **Secrets - settled (2026-10-22), as proposed with one addition:** secret keys and passphrases are never stored on the server; a file key and a typed passphrase live in the run state (0600) only for the length of the run; the key pair or passphrase made by the program is shown once, **and is easy to keep: copy buttons, a file in the format of `age-keygen`, a print view** (3.5).
10. **Restore of encrypted backups - settled (2026-10-22):** in this phase, in the command and in `restore.php`, through a decrypting stream, without a decrypted copy on disk; `restore.php` needs `ext-sodium`.
11. **Encryption is per profile - settled (2026-10-22):** switching it on affects the backups made afterwards; older ones stay what they are; one storage may hold both kinds.
12. **Out of scope - settled (2026-10-22):** remote storage and notifications stay for a later phase; `Postproc` is not changed.

## 8. Findings of work package 1 (spikes)

*To be filled in when the spikes are done. Each finding that changes the design above is also corrected in the text of the section it concerns, as in the earlier phases.*
