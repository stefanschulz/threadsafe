# Phase 8 – central schedule management

**Status:** **done, used by the owner without problems** (planned and built 2026-10-28, requested by the owner after the field test of phase 7; the decisions of section 7.2 were taken the same day; work packages 1 to 7 are done, section 8 says what differs from this plan).
**Context:** [SCHEDULE.md](SCHEDULE.md) is the schedule as it works today (cron expression in the profile, the scheduler, the trigger); [PHASE5.md](PHASE5.md) is its design (3.1 the setting, 3.2 the scheduler, 3.3 the trigger); [WEB.md](WEB.md) describes the pages; [DESIGN.md](DESIGN.md) the look. The engine (`Scheduler`, `TriggerService`, `ScheduleState`, `CronExpression`) keeps its logic; it gets a schedule that can be **paused**.

## 1. Goal and scope

Today a schedule is set per profile, in a text field of the profile form that wants a **cron expression** (`30 3 * * *`). That is hard for most people, there is no place where the times of all profiles can be seen next to each other, and nothing shows that two backups start at the same time (only one backup runs at a time in the installation, so the second waits).

Phase 8 adds one page for **all schedules**, a new entry in the menu, with plain settings and no cron syntax. The settings are stored in their own, plain form in the profile file (decision 1), so a person can also read them there.

| In scope | Out of scope (later, or never) |
|---|---|
| a menu entry **Schedule** and a page with a **table**: one row per profile - profile, website type, when it runs, next run, result of the last scheduled run | a second kind of schedule (several times per profile), a calendar view, drag and drop |
| the table is **sortable** by profile name, website type and **execution time** (the next run), in both directions; the type filter of the other pages | exceptions such as holidays, a settings editor for the engine (grace, time zone and trigger stay on the System page) |
| **simple settings**, restricted to what makes sense (section 3.2): every few hours, daily, on chosen weekdays, monthly | free choice of seconds or years, "the last day of the month" (cron cannot say it) |
| **pause and resume** a schedule without losing its settings | |
| change **one or several profiles at once** (tick rows, one form); the same fields in the **profile form** | retention and encryption rules (they stay in the profile form) |
| a hint when schedules lie so close together that backups wait for each other | remote storage, notifications by mail |

**No migration.** The only installation is the owner's test installation, which is installed again; a profile file with the old `{"cron": ...}` is simply refused (decision 1). The command line takes the same plain settings as text (3.5).

## 2. Deliverables

1. `Profile\ScheduleSettings` rebuilt: the structured schedule (every N hours, day, weekdays, month), its validation, its cron expression for the engine, its words, its text form for the command line, `paused`.
2. `Schedule\Scheduler` and `App\ProfileService`/`TriggerStatus`: a paused schedule is not due, shows no next run, and counts from the moment it is resumed.
3. `App\ScheduleService` (new): the rows of the table (profile, type, settings, next run, last result), sorting, the overlap hint, and `apply()` for one or several profiles (set, pause, resume, remove).
4. `Admin\ScheduleController` (new) with `Admin\ScheduleFields` (the form fields and their reading, shared with the profile form): the pages `GET /schedule`, `GET /schedule/edit`, `POST /schedule/edit`, `POST /schedule/pause`; the menu entry; styles.
5. The profile form and page, the dashboard words, `ProfileCommand`.
6. Tests (section 4), documentation: `SCHEDULE.md` (section 2 starts with the page), `WEB.md`, `BACKUP.md`, `README.md`, `DESIGN.md`, `CHANGELOG.md`, `AGENTS.md`, this plan's "As built".

## 3. Design

### 3.1 Storage: a plain object in the profile file

```json
"schedule": {"every": "hours", "interval": 6, "minute": 15}
"schedule": {"every": "day", "at": "03:30"}
"schedule": {"every": "week", "on": ["mon", "thu"], "at": "02:15"}
"schedule": {"every": "month", "day": 1, "at": "04:00"}
"schedule": {"every": "day", "at": "03:30", "paused": true}
```

No `schedule` key: not scheduled. Unknown keys and values outside section 3.2 are refused when the file is read, with a message that names the field. `ScheduleSettings` derives from it the cron expression that `CronExpression` and the `Scheduler` use (`cron()`, `expression()`), so the engine and the rules for daylight saving time stay as they are; "a changed schedule counts from the moment of the change" (`ProfileService::followSchedule()`) compares the derived expression and the paused flag.

### 3.2 What can be set (restricted on purpose)

| Setting | Values | Cron it makes |
|---|---|---|
| **Every N hours** | N = 1, 2, 3, 4, 6, 8, 12; at a minute 0-59 | `15 */6 * * *` |
| **Every day** | time of day | `30 3 * * *` |
| **On chosen weekdays** | any of Mon-Sun (at least one), time of day | `15 2 * * mon,thu` |
| **Every month** | day 1 to 28 (not 29-31: they would skip months), time of day | `0 4 1 * *` |

The forms offer the time in **steps of five minutes** (the trigger comes every five minutes at best); the file and the command line take any minute, and a value off the grid is kept and shown. Times are read in the **time zone of the installation** (`schedule.timezone`), which the page names at the top with a link to the System page, where grace, time zone and the trigger stay. The rules for daylight saving time are those of [SCHEDULE.md](SCHEDULE.md) section 2.

### 3.3 The page `/schedule`

A table, one row per profile (profiles without a schedule included, so that they can be given one):

| | Profile | Type | Runs | Next run | Last scheduled run |
|---|---|---|---|---|---|
| tick box | name, a link to the profile | website type | "Every day at 03:30", "Every Monday, Thursday at 02:15", "Every 6 hours at :15", "On the 1st of every month at 04:00", "Paused: ...", "Not scheduled" | date and time in the time zone of the installation, "in 3 hours"; "-" when paused or not scheduled | "made 28 Oct 03:31", "failed, tried again", "given up", "skipped (the trigger was late)", "-" |

- **Sorting:** the headers *Profile*, *Type* and *Runs* are links `?sort=name|type|time&dir=asc|desc` (works without script, the choice is in the address, as the type filter is). *Runs* sorts by the **next run** (earliest first); paused and unscheduled profiles come after the active ones, in both directions. The sorted column has `aria-sort`; ties are broken by name. Default: *Runs*, ascending. The type filter (`?type=`) of the dashboard and the profile list is reused above the table.
- **Above the table:** the time zone ("Times are in Europe/Berlin") and the state of the trigger as the dashboard says it, with a link to the System page: **a schedule is only a promise, the trigger keeps it**. Without any schedule the page explains that.
- **Overlaps:** when the next runs of two or more profiles lie within ten minutes of each other, the row says "Starts with *other*: they run one after the other". No stored state.
- **Buttons:** *Change* per row; above the table, for the ticked rows: *Change*, *Pause*, *Resume*. A paused row has *Resume*, an active one *Pause*, in the row as well. Without script the buttons are there and the page says "tick at least one" when none is.
- Narrow screens: the table scrolls as the others do (`.scroll`).

### 3.4 The form `/schedule/edit` and the fields

`ScheduleFields` renders and reads the fields: **How often** (not scheduled, every N hours, every day, on chosen weekdays, every month; a radio group), **Time**, **Weekdays**, **Day of the month**, **Every** (hours), **At minute**, and the check box **Paused**. With script only the fields of the chosen choice are shown; without script all are shown and each says which choice it belongs to. The page `/schedule/edit?profile=a&profile=b` uses it for one or several profiles ("Change the schedule of: *shop*, *blog*"); for several the form starts with "leave as it is". The **next three runs** of what is entered are shown by the server (`preview`) before *Save*. The profile form's section *Schedule* uses the same fields (decision 3).

`POST` checks the token, validates every field against 3.2 (an error next to the field, nothing is saved), writes only the profiles that really change (other fields of a profile are kept; no test of the connection), makes one audit entry per changed profile (`schedule.changed`: old and new settings, no secrets; `schedule.paused`, `schedule.resumed`), and returns to the table with a message that names the next run of each changed profile. A profile removed meanwhile is reported and skipped.

### 3.5 The rest of the interface

- **Profile form:** the text field *Back up by itself at* is replaced by the shared fields (3.4), with a link to the Schedule page.
- **Profile page:** the fact *Schedule* shows the words (and "paused"), with the link.
- **Dashboard:** "Next scheduled backup" is unchanged and absent for a paused schedule; the trigger warning counts only active schedules.
- **Command line:** `profile add|edit --schedule="TEXT"`, with the text forms `every 6 hours at :15`, `daily at 03:30`, `weekly on mon,thu at 02:15`, `monthly on 1 at 04:00` (the words `on` and `at` are optional); `--no-schedule`; `--pause-schedule` and `--resume-schedule`. `profile show` prints the words, the next run and "paused".
- **Menu:** *Dashboard, Profiles, **Schedule**, System*; icon `clock` (exists).

## 4. Test strategy

### 4.1 Unit tests (no database)

- `ScheduleSettings`: the four kinds read from and written to the file form, the cron each makes (also against `CronExpressionTest`'s expressions), the words, the text form of the command line, every value outside 3.2 refused with a message that names the field, `paused`.
- `Scheduler`/`ProfileService`/`TriggerStatus`: a paused schedule is not due and has no next run; resuming counts from now; a changed schedule or a resume resets the state, a save without a change does not.
- `ScheduleService`: the rows, sorting by name, type and next run in both directions with ties, paused and unscheduled profiles last, the overlap hint, `apply()` writes only what changed and keeps the other fields, a removed profile is reported.
- `ScheduleController` (the `AdminBrowser` of the other page tests): the table and its headers (`aria-sort`, the links keep `type`), the filter, the form for one and for several profiles, validation errors, the preview, pause and resume, the token check (403 without it), 405 for a wrong method, audit entries, the menu entry marked.
- The profile pages and the command: the new fields and options, the words where the cron text was.

### 4.2 Integration and the browser

- Phase 4b of `tests/stack/run.sh` (a real Apache, 5 s / 64 MB): a test in `WebInterfaceTest` sets a schedule through the form, reads it in the table, sorts it, pauses it, and checks that `threadsafe cron` finds a profile due only while it is not paused.
- `tools/a11y-audit.js` on the page and the form in both colour schemes; the page seen in the browser in both schemes and at phone width, with and without script.

## 5. Work packages

| # | Package | Needs | Proof |
|---|---|---|---|
| 1 | `ScheduleSettings` rebuilt (kinds, validation, cron, words, text form, `paused`); the engine ignores a paused schedule; all places that read `schedule` and their tests follow | - | unit tests of 4.1 |
| 2 | `ScheduleService`: rows, sorting, overlap hint, `apply()` | 1 | unit tests of 4.1 |
| 3 | The page `/schedule` (menu entry, table, sort headers, filter, trigger line, pause and resume), styles | 2 | controller tests, browser |
| 4 | `ScheduleFields` and the form `/schedule/edit` (one and several profiles, preview, validation, audit) | 2, 3 | controller tests, browser with and without script |
| 5 | The profile form and page with the same fields, the dashboard words, the command line | 1, 4 | page and command tests |
| 6 | Integration test in phase 4b, a11y audit, the look in both schemes and at phone width | 3, 4, 5 | `run.sh` phase 4b, audit |
| 7 | Documentation (`SCHEDULE.md`, `WEB.md`, `BACKUP.md`, `README.md`, `DESIGN.md`, `CHANGELOG.md`, `AGENTS.md`, "As built") | all | read through |

Package 1 touches the most files (every test that wrote `{"cron": ...}`), the others add. One commit per package (`Phase 8, work package N: ...`).

## 6. Acceptance criteria

1. A person who has never seen a cron expression can give three profiles different schedules on the Schedule page without reading anything but the page, and pause one of them.
2. The table is sorted by name, type and next run, ascending and descending, without script; paused and unscheduled profiles stay behind the active ones when sorting by time.
3. Saving a form without a change does not touch a profile file or a scheduler state; a changed schedule, and a resumed one, counts from the moment of the save.
4. A paused schedule is never due, makes no next run, and no trigger warning; its settings are kept.
5. The engine's rules are unchanged: `SchedulerTest`, `CronExpressionTest`, `TriggerTest` and the phase 4c tests of `run.sh` pass (with the new file format).
6. `composer check` and all three database servers stay green; the page passes the contrast audit in both colour schemes.

## 7. Risks and decisions

### 7.1 Risks

| Risk | Handling |
|---|---|
| Every test and fixture that wrote `{"cron": ...}` breaks at once | package 1 changes them together, with the code that reads them; the cron each old fixture meant is kept as the new setting that makes it |
| A change on the page and a change by `profile edit` at the same time | the last write wins, as for the profile form today; the table is read again on every request |
| "Every N hours" starts at midnight of the wall clock, so at a change of daylight saving time the gap between two runs is N plus or minus one hour once a year | that is the rule of the engine (SCHEDULE.md section 2) and is said in the help of the form |
| A person who set the schedule in the profile form for years looks for the field | it is still there, as the same fields, with a link to the page |

### 7.2 Decisions (owner, 2026-10-28)

1. **Storage: structured**, in the profile file (3.1). There is only a test installation, which is installed again, so no migration and no old format is read.
2. **Restrictions:** the table of 3.2 is right for now; it can be extended later (other intervals, more kinds).
3. **Editing also in the profile form**, with the same fields as the Schedule page.
4. **Pausing is part of this phase** (3.3, 3.4).

## 8. As built

**Done 2026-10-28**, all seven work packages in one go (the commits are split by package where the files allow it). What exists and where it differs from the plan:

- **`Profile\ScheduleSettings`** is the structured schedule (3.1): `hours`, `day`, `week`, `month` with `paused`; `fromArray()` refuses unknown keys, keys of another kind and values outside 3.2 with a message that names the field (and an old `{"cron": ...}` with "`schedule.every` is missing"); `cron()`/`expression()` are what the engine reads (so `Scheduler`, `CronExpression` and the rules for daylight saving time are untouched), `describe()` the words, `text()`/`parse()` the text form of the command line (`daily at 03:30`, `weekly on mon,thu at 02:15`, `monthly on 1 at 04:00`, `every 6 hours at :15`, `hourly`; `on` and `at` optional, full names of the days accepted). The minute is any 0-59 in the file and on the command line; the forms offer steps of five minutes and keep an off-grid value. A weekly schedule with all seven days is allowed and described as "Every day".
- **Pause** (decision 4): `Scheduler::plan()` skips a paused schedule (it is not reported as given up either) and `next()` is null; `TriggerStatus` leaves a paused schedule out, so it neither has a next run nor makes the trigger's absence a warning; `ProfileService::followSchedule()` resets the state of the scheduler when the times or the paused flag change (a resume counts from now) and leaves it alone when a save changes nothing. The state of a paused schedule is kept.
- **`App\ScheduleService`** (`rows()`, `set()`, `pause()`), with `ScheduleRow` and `ScheduleChange`: sorting by name, type and time as in 3.3 (paused, then unscheduled, behind the active ones in both directions; ties by name), the overlap hint (next runs less than ten minutes apart, only between active schedules), changes write only what changes and keep the rest of the profile (`ProfileService::store(..., test: false)`).
- **`Admin\ScheduleController`** and **`Admin\ScheduleFields`**. Routes: `GET /schedule`, `GET /schedule/edit?profile=ID`, `POST /schedule/edit` (the form: `action` = `save` or `preview`; a request without the schedule fields only shows the form, so it can never take a schedule away), `POST /schedule/action` (the buttons of the table: `do` = `edit`, `pause`, `resume`, or `pause:ID`/`resume:ID` for the small button of a line). The ticked lines are the fields `p_<ID>` = 1 (the request type allows no arrays), the sort, direction and type filter travel as hidden fields so that a change leads back to the same view. Differences from the plan: **"How often" is a select**, not a radio group; the preview is `action=preview` of the same POST; the times in the table are plain text with the zone in the title, **not `<time>` elements** (the script of the pages turns those into the time zone of the browser, and these are in the one of the installation); profile files that cannot be read are named above the table.
- **Profile form and page:** the text field is gone, `ScheduleFields::render()` is in the section *Automation* (a form that does not send `schedule_every` leaves the schedule as it is); the fact *Schedule* of the page and the line of the folded section say the words, with "paused".
- **Command line:** `--schedule=TEXT`, `--no-schedule`, `--pause-schedule`, `--resume-schedule` (a new time keeps a paused schedule paused unless it is resumed in the same call; a text that is no schedule is a usage error with the four forms in the message); `profile show` prints the text, "(paused)", the time zone and the next run.
- **Tests:** `ScheduleSettingsTest` (every kind both ways, the cron and the words, the text form, the refusals), `ScheduleServiceTest`, `SchedulePagesTest` (15 tests: table, sorting, filter, forms, validation, preview, several profiles, pause and resume, audit, token, 404/405, the trigger warning), pause tests in `SchedulerTest`, `ProfileServiceTest` and `ProfileAndBackupCommandTest`, the fixtures of the other tests converted to the new format; in the stack `WebInterfaceTest::testTheSchedulePageSetsSortsAndPausesAScheduleBehindApache` (phase 4b, real Apache) and the converted `TriggerStackTest` (phase 4c). The page and the form were seen in the browser (dark scheme, phone width, the fields switching with the choice, preview, save, pause) and pass `tools/a11y-audit.js` in both schemes without a finding.
- **Field test:** the owner used the page; it ran without problems (2026-10-30). **Not done:** the overlap hint looks only at the next run of each profile; there is no command that lists the table; a profile file of an older version (with `cron`) is not converted but named as unreadable.