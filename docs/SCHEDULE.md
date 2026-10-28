# Scheduled backups and retention

How ThreadSafe makes backups by itself and keeps the storage in order: the **schedule** of a profile (set on the **Schedule** page, for all profiles together), the **trigger** that must call it, and **retention**. The commands are in [BACKUP.md](BACKUP.md) (4.5 `cron`, 4.6 `retention`), the pages in [WEB.md](WEB.md); this page is the whole story in one place. The design and the reasons are in [PHASE5.md](PHASE5.md).

## 1. Quick start

```bash
# 1. say when (here: every day at 03:30, in the time zone of the installation) and what to keep
php bin/threadsafe admin set schedule.timezone Europe/Berlin
php bin/threadsafe profile edit shop --schedule="daily at 03:30" --keep=14 --max-age-days=90
# (or in the browser: menu "Schedule", the line of the profile, "Change")

# 2. make something call the trigger: either a cron job that runs the command ...
php bin/threadsafe admin cron-secret          # (only for the address: makes the secret and prints a crontab line for it)
crontab -e                                    # */5 * * * * /usr/bin/php8.4 -q /path/to/threadsafe/bin/threadsafe cron -q

# 3. look: the System page shows when the trigger called last, the dashboard the next scheduled backup
php bin/threadsafe profile show shop
```

Nothing happens without step 2: **a schedule is only a promise; the trigger keeps it.** The dashboard says "No trigger has called yet" until the first call, and warns when the trigger stays away.

## 2. The schedule of a profile

A profile has **no schedule**, or **one**, in one of four plain forms. There is no cron syntax to learn.

| Form | What you choose | Examples (in words) |
|---|---|---|
| **Every few hours** | every 1, 2, 3, 4, 6, 8 or 12 hours, at a minute of the hour | Every 6 hours at :15 |
| **Every day** | a time of day | Every day at 03:30 |
| **On chosen days of the week** | any of Monday to Sunday (at least one), a time of day | Every Monday and Thursday at 02:15 |
| **On a day of the month** | the 1st to the 28th (the 29th to the 31st would skip months), a time of day | On the 1st of every month at 04:00 |

A schedule can also be **paused**: it keeps its settings, no backup is made by it, it has no next run and the trigger's absence is no reason for a warning, until it is **resumed**.

- **Where to set it.**
  - The **Schedule** page (menu): one table with all profiles - profile, website type, when it runs, the next run, what became of the last scheduled run. It can be sorted by profile, type and time (the next run; paused and unscheduled profiles stay behind), filtered by website type, and the schedule of **one or of several ticked profiles at once** can be changed, paused or resumed. The form shows the **next three runs** of what you enter before you save. Profiles whose next runs lie within ten minutes of each other are marked: only one backup runs at a time, so the second starts when the first has ended.
  - The **profile form**, section *Automation*, has the same fields.
  - The command line: `profile add|edit --schedule="TEXT" | --no-schedule | --pause-schedule | --resume-schedule`. The text is `every 6 hours at :15` (or `hourly`), `daily at 03:30`, `weekly on mon,thu at 02:15`, `monthly on 1 at 04:00`; the words `on` and `at` may be left out. A new time keeps a paused schedule paused unless `--resume-schedule` is given. `profile show` prints the schedule in the same text, the time zone and the next run.
- **In the profile file** the schedule is a plain object: `"schedule": {"every": "week", "on": ["mon", "thu"], "at": "02:15"}` (`{"every": "hours", "interval": 6, "minute": 15}`, `{"every": "day", "at": "03:30"}`, `{"every": "month", "day": 1, "at": "04:00"}`, and `"paused": true` next to any of them). The engine turns it into a cron expression of five fields itself; a profile file of an older version with `{"cron": ...}` is refused with a message that says so and has to be changed (there was no release with it).
- **Time zone.** Times are read in `schedule.timezone` (`admin set schedule.timezone Europe/Berlin`, or the System page); empty means the time zone of PHP, which is UTC where nobody set one. The Schedule page names the zone and shows its times in it; other pages show times in the time zone of the browser, the file names of backups are UTC.
- **Daylight saving time.** A time that does not exist (02:30 on the day the clock jumps forward) runs at the first moment after the gap; a time that exists twice (the hour when the clock goes back) runs **once**, at its first occurrence. "Every N hours" counts on the wall clock from midnight, so on the two days of a change the gap between two runs is one hour longer or shorter. This is ThreadSafe's own rule, tested for Berlin, New York and Lord Howe (a half-hour shift).
- **A schedule counts from the moment it is set or resumed.** Setting, changing, pausing or resuming it starts counting at that moment, so a schedule `daily at 03:00` set at 05:00 does not make a backup at once because 03:00 is not long ago. A save that changes nothing does not start it again. A profile that has a schedule but no saved state (the state was lost, or the profile was copied in) is treated the same way at the first call of the trigger.
## 3. When is a backup made?

The scheduler looks only at the moment of a call of the trigger. It takes the **latest scheduled time that is not in the future**; the profile is **due** if that time has not been handled and the call comes at most `schedule.graceMinutes` (default 360, six hours) after it.

- **One run is made up, never a pile.** After the server was off for a day, one backup is made on the next call if the latest scheduled time is within the grace; older times are **given up** (audit entry `schedule.skipped`, a problem in the output of `cron`).
- **A cron job that runs once a day is enough** for a daily schedule, if its time lies shortly after the scheduled time (and the grace is not shorter than the gap).
- **A run that fails** is tried again 15 minutes later, **twice at most**, as long as the grace holds, and then given up (audit entry, warning on the dashboard). A retry happens only if a later call comes in time; on a trigger that comes once a day there is none.
- **A run that is interrupted** (the host killed the trigger, the machine stopped) is not a failure: the next call **continues it** into the same backup, from its saved state. The manifest then says `"consistency": "table"` (see below).
- **One backup at a time** in the whole installation. Profiles that are due wait in the order of their scheduled times; a manual backup that is going makes them wait for it. A scheduled backup and a manual `backup` never overlap.
- A backup made by the schedule is marked `schedule` in its sidecar file (`manual` otherwise); the details page says "Asked for by the schedule".

## 4. The trigger

One cron job is all the host needs. It can call the **command** or the **address**.

### 4.1 The command (preferred)

```
*/5 * * * *  /usr/bin/php8.4 -q /path/to/threadsafe/bin/threadsafe cron -q
```

`cron` writes the heartbeat, continues an interrupted backup, and runs every backup that is due, one after the other, **each to its end, in the calling process**: no PHP time limit, and the database is read in **one consistent snapshot** (`"consistency": "snapshot"`). It ends quietly at once if another trigger is working (a lock). `-q` prints only problems, which is what a cron job should mail. Exit codes: 0 all is well, 2 something was refused (for example a manual backup was running), 3 a backup failed. `admin cron-secret` and the System page print the line with the real paths. On a host whose shell `php` is old, name the binary of PHP 8 and use `-q` (see BACKUP.md 4.2).

If the host kills long processes (some cut cron jobs after minutes), a backup is continued by the next call and is then read table by table instead of in one snapshot.

### 4.2 The address (for a cron that can only fetch a web page)

Some hosts allow a cron job only to fetch an address, perhaps only once a day. For them:

```bash
php bin/threadsafe admin cron-secret              # makes the secret and shows a crontab line with it
php bin/threadsafe admin cron-secret --show       # shows it again
php bin/threadsafe admin cron-secret --remove     # the address no longer exists
```
```
0 4 * * *  curl -fsS -m 60 "https://YOUR-HOST/PATH-TO-PUBLIC/cron/<secret>" > /dev/null
```

The address `/cron/<secret>` is below `public/` and exists **whether or not the web interface is switched on**; without a secret there is no such address (everything else is "not found" too if the interface is off). The answer is plain text; its **first word** says what happened: `idle` (nothing to do), `started` (a worker was started), `working` (a worker is alive, or one step was done and more is due). A script on another machine can call again at once while the answer says `working`.

What a call does depends on `cron.mode` (`auto`, `worker`, `ticks`; System page or `admin set cron.mode`):

| Mode | What a call does | Consistency |
|---|---|---|
| `worker` (`auto`, if PHP can start a process: `exec` or `proc_open` allowed and a PHP binary is found) | If something is due or interrupted and no worker is alive, it starts `threadsafe cron` as a **detached process** and answers `started` at once. The process lives on after the request, has no time limit, and does what the command does. One call a day is enough. A second call while it works answers `working` and starts nothing. | `snapshot` |
| `ticks` (`auto`, if no process can be started) | **One step** of work under the limits of the request (the time of a step is a part of `max_execution_time`): continues the run that is going, or starts the next due backup and does its first step. It must be called often - every minute or every few minutes - for a backup to finish. | `table` |

The worker is started with a clean environment, no standard input and its output in `data/cron-worker.log` (it stays empty when all is well); never a second one within 30 seconds, and the worker itself takes the lock, so two never work.

**The secret in the address** ends up in the access log of the web server and in the history of whoever types it. It gives the right to **start backups and nothing else**. Wrong secrets get the same plain "not found" as any unknown address and are slowed down like wrong passwords; `admin cron-secret` makes a new one and the old one stops working at once. The secret is not a setting but a file of its own (`data/cron-secret`, mode 0600), because the settings are shown on the System page.

### 4.3 The heartbeat and the warnings

Every call writes `data/heartbeat.json` (the time, `cli` or `web`, one line of what was done). The **System page** shows when the trigger called last and the lines to set it up; the **dashboard** shows "Next scheduled backup" on every card of a profile with a schedule, says "No trigger has called yet" until the first call, and **warns when the trigger stays away** longer than the larger of twice `schedule.expectedIntervalMinutes` (default 15) and 30 minutes while a profile has a schedule. Set `expectedIntervalMinutes` to the real rhythm of your cron job (a trigger that comes once a day: 1440) so that the warning is meant right; a schedule more frequent than the rhythm of the trigger cannot be kept, and nothing warns about that.

A call that finds nothing to do is cheap: it reads the profiles and only the file names of the storage directories, not the sidecar files.

## 5. Retention

A profile may carry three rules, each optional, any combination (profile form, section *Retention*, or `profile add|edit --keep=N --max-age-days=D --max-bytes=SIZE`, `--no-retention`):

| Rule | Meaning |
|---|---|
| `keep` | keep at most this many backups |
| `maxAgeDays` | no backup older than this many days |
| `maxBytes` | the backups together at most this big (e.g. `20G`); the oldest go first |

Only **complete, usable** backups count (`ok`, `ok-with-warnings`, `unverified`). **Never deleted:**
- the **newest usable backup** - a failing schedule or a rule set wrongly cannot leave the site without a backup;
- a **pinned** backup (`backups <profile> --pin=ID`, the *Pin it* button on its page; it does not count towards `keep`, but its size counts towards `maxBytes`);
- a backup that a run is writing or a check is reading.

A `faulty` or `incomplete` backup is left alone too (it may be recoverable by hand; the page says it is there). Unfinished files of runs that died (work directories, `.part` files) that no run claims and that are older than a week are removed as well. The deletions come **oldest first** and the numbers are recomputed after each one.

**When it runs:** after every successful backup of the profile (command, browser or trigger), and by the trigger once an hour for every profile with rules, so that `maxAgeDays` works for a site that makes no backups any more. Never after a failed backup, and never when the storage directory cannot be read.

**The go-ahead.** The trigger deletes on its own only when the rules ask for **one** backup to go (one came, one goes). Rules that would delete more - a new rule on a full storage - wait until you have applied them by hand once: `retention <profile>` or the button *Apply the rules now*, after looking at the list. Changing the rules asks again. A waiting rule is said in the output of the backup, in the audit log (`retention.waiting`) and on the profile page.

```bash
php bin/threadsafe retention shop --dry-run    # what would be deleted and why; nothing is deleted
php bin/threadsafe retention shop              # delete it, and confirm these rules
```

The profile page shows the same list before anything is deleted. Every deletion is an audit entry (`retention.deleted`) with the rule that caused it. A pin lives in the sidecar file; a sidecar file that is made again from the archive has lost it, and an encrypted backup's sidecar cannot be made again at all ([ENCRYPTION.md](ENCRYPTION.md)).

## 6. Settings and files

| Setting | Default | Meaning |
|---|---|---|
| `schedule.timezone` | empty (PHP's) | the time zone in which the times of the schedules are read, e.g. `Europe/Berlin` |
| `schedule.graceMinutes` | 360 | how late a scheduled backup may still be made up (1 to 10080) |
| `schedule.expectedIntervalMinutes` | 15 | how often the trigger is meant to come (1 to 1440); the warning is derived from it |
| `cron.mode` | `auto` | `auto`, `worker` or `ticks` |

| File in the data directory | What |
|---|---|
| `schedule/<profile>.json` | the last scheduled time that was handled, the attempts, the time of the next retry (lost: at worst one run extra or skipped) |
| `heartbeat.json` | the last call of the trigger |
| `cron-secret` | the secret of the address (mode 0600) |
| `cron-worker.log`, `cron-spawned` | output of the detached worker, the time it was started |
| `retention/<profile>.json` | the rules that you confirmed, the last run |
| `locks/cron.lock`, `locks/backup.lock` | the trigger at work, the one backup at a time |

Audit log events: `backup.started` (with `source` and the scheduled time), `backup.finished`, `backup.failed`, `schedule.skipped`, `trigger.worker`, `trigger.worker.failed`, `retention.deleted`, `retention.waiting`, `cron.secret.made`, `cron.secret.removed`, `settings.changed`. Secrets are not in them.

## 7. When something does not work

| You see | Meaning and what to do |
|---|---|
| "No trigger has called yet" | Nothing has called `cron` or the address. Set up the cron job of section 4 and look at the System page afterwards. |
| "The trigger last called ... ago, but it is meant to come every ..." | The cron job stopped, or `expectedIntervalMinutes` does not match its rhythm. |
| A scheduled backup was not made | `schedule.skipped` in the audit log: the trigger came later than the grace after the scheduled time. Call it more often, or raise `schedule.graceMinutes`. |
| `cron` prints "Another backup is running" (exit 2) | A manual backup or another trigger is working. It is told again on the next call. |
| The address answers `idle` although a backup is due | The profile was not due yet: the schedule counts from the moment it was set, and the grace has passed for older times. `profile show <id>` says the next run. |
| The address answers `working` again and again, no backup appears (`ticks`) | The calls are too rare or too short for the backup; call every minute, or let the host start a worker (`cron.mode worker`, needs `exec` or `proc_open`). |
| `trigger.worker.failed` in the audit log | The worker could not be started (no PHP binary, `exec` forbidden). Use `ticks`, or the command in a real cron job. |
| `cron-worker.log` is not empty | The worker wrote something: usually the reason a backup failed. |
| A backup says `consistency: table` | It was made in steps (the address in `ticks` mode, a run that was interrupted and continued, `backup --tick-seconds`): every table is consistent in itself but not with the others. For a shop in daily use prefer the command or a worker. |

## 8. Limits

- A scheduled backup is made when the trigger calls; there is no daemon. A host that cannot call anything at all cannot make backups by itself.
- The retry of a failed run waits for the next call; it does not wait inside the process.
- One backup runs at a time, in the whole installation, also across profiles.
- Retention works on the local storage directory of a profile; transfers to other places are a later phase.
