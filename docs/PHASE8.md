# Phase 8 – central schedule management

**Status:** **planned** (2026-10-28, requested by the owner after the field test of phase 7); nothing is built.
**Context:** [SCHEDULE.md](SCHEDULE.md) is the schedule as it works today (cron expression in the profile, the scheduler, the trigger); [PHASE5.md](PHASE5.md) is its design (3.1 the setting, 3.2 the scheduler, 3.3 the trigger); [WEB.md](WEB.md) describes the pages; [DESIGN.md](DESIGN.md) the look. The engine (`Scheduler`, `TriggerService`, `ScheduleState`) does **not** change.

## 1. Goal and scope

Today a schedule is set per profile, in a text field of the profile form that wants a **cron expression** (`30 3 * * *`). That is hard for most people, there is no place where the times of all profiles can be seen next to each other, and nothing shows that two backups start at the same time (only one backup runs at a time in the installation, so the second waits).

Phase 8 adds one page for **all schedules**, a new entry in the menu, with plain settings and no cron syntax.

| In scope | Out of scope (later, or never) |
|---|---|
| a menu entry **Schedule** and a page with a **table**: one row per profile - profile, website type, when it runs, next run, result of the last scheduled run | a second kind of schedule (several times per profile), a calendar view, drag and drop |
| the table is **sortable** by profile name, website type and **execution time** (the next run), in both directions; the type filter of the other pages | pausing a schedule without deleting it (a "paused" flag), exceptions such as holidays |
| **simple settings**, restricted to what makes sense (section 3.2): every few hours, daily, on chosen weekdays, monthly | free choice of minutes, seconds, years; a settings editor for the engine (grace, time zone and trigger stay on the System page) |
| change **one or several profiles at once** (tick rows, one form) | retention and encryption rules (they stay in the profile form) |
| the profile form loses the cron field; the profile page shows the schedule in words and links to the Schedule page | remote storage, notifications by mail |
| a hint when schedules lie so close together that backups wait for each other | |

The command line keeps cron expressions (`profile add|edit --schedule`, `profile show`): they are the stored format and the way to say what the simple settings cannot (decision 1).

## 2. Deliverables

1. `Schedule\ScheduleRule` (new): the simple form of a schedule - recognised from a cron expression, turned into one, described in words; `ScheduleSettings` gets `rule()` and `describe()`.
2. `App\ScheduleService` (new): the rows of the table (profile, type, rule, next run, last result), sorting, and `apply()` for one or several profiles.
3. `Admin\ScheduleController` (new): the pages `GET /schedule`, `GET /schedule/edit`, `POST /schedule/edit`; the menu entry; styles for the sortable table and the form.
4. Changes in `ProfileForm`/`ProfileController` (the field goes, the summary and the link come), `ProfileCommand` (help text), `Overview` (the wording of "next scheduled backup" is unchanged).
5. Tests (section 4), documentation: `SCHEDULE.md` (section 2 starts with the page, cron becomes "for the command line"), `WEB.md`, `README.md`, `DESIGN.md`, `CHANGELOG.md`, `AGENTS.md`, this plan's "As built".

## 3. Design

### 3.1 Storage: nothing changes in the profile file

The profile keeps `"schedule": {"cron": "30 3 * * *"}`. Reasons: no migration, the command line and every existing profile file keep working, the engine reads one thing, and `ProfileService::followSchedule()` (a changed schedule counts from now) keeps comparing the cron text. The page **translates** in both directions:

- *reading:* `ScheduleRule::fromCron()` recognises the expressions that the simple settings can make (section 3.2), also when they were typed by hand (`0 3 * * *`, `@daily`, `15 2 * * mon,thu`). An expression it does not recognise (`*/7 * * * *`, `0 4 1,15 * *`) is a **custom schedule**: the table shows it in words as far as possible and prints the cron text in small type; it keeps running unchanged.
- *writing:* the form produces a rule, `ScheduleRule::toCron()` the expression, `ProfileService::store($profile, test: false)` saves it (a schedule change needs no test of the connection). Only the profiles whose expression really changed are written, so a save that changes nothing does not restart the counting of a schedule.

### 3.2 What can be set (restricted on purpose)

| Setting | Values | Cron it makes |
|---|---|---|
| **Off** | - | no `schedule` |
| **Every N hours** | N = 1, 2, 3, 4, 6, 8, 12; at minute 0, 5 ... 55 | `15 */6 * * *` |
| **Every day** | time of day | `30 3 * * *` |
| **On chosen weekdays** | any of Mon-Sun (at least one), time of day | `15 2 * * mon,thu` (all seven: the same as every day) |
| **Every month** | day 1 to 28 (not 29-31: they would skip months), time of day | `0 4 1 * *` |

The time of the day is chosen in **steps of five minutes** (the trigger comes every five minutes at best, `schedule.expectedIntervalMinutes`); a recognised expression that is off the grid (minute 7) keeps its value and is shown. The times are read in the **time zone of the installation** (`schedule.timezone`), which the page names at the top with a link to the System page, where grace, time zone and the trigger stay. The rules for daylight saving time are those of [SCHEDULE.md](SCHEDULE.md) section 2 and do not change.

### 3.3 The page `/schedule`

A table, one row per profile (profiles without a schedule included, at the end of the list when sorted by time, so that they can be given one):

| | Profile | Type | Runs | Next run | Last scheduled run |
|---|---|---|---|---|---|
| tick box | name, a link to the profile | website type | "Every day at 03:30", "Mon, Thu at 02:15", "Every 6 hours at :15", "On the 1st at 04:00", "Not scheduled", custom: the words and the cron text | date and time in the time zone of the installation, "in 3 hours" | "made 28 Oct 03:31", "failed, tried again", "given up", "skipped (the trigger was late)", "-" |

- **Sorting:** the headers *Profile*, *Type* and *Runs* are links `?sort=name|type|time&dir=asc|desc` (works without script, the choice is in the address, as the type filter is). *Runs* sorts by the **next run** (earliest first), not by the words; unscheduled profiles come last in both directions. The sorted column has `aria-sort`; ties are broken by name. Default: *Runs*, ascending. The type filter (`?type=`) of the dashboard and the profile list is reused above the table.
- **Above the table:** the time zone ("Times are in Europe/Berlin"), and the state of the trigger as the dashboard says it ("No trigger has called yet", "last called 3 minutes ago") with a link to the System page: **a schedule is only a promise, the trigger keeps it**. Without any schedule the page explains that and offers the button.
- **Overlaps:** when schedules of two or more profiles lie within ten minutes of each other, the row says "Starts with *other*: they run one after the other" (the engine does that anyway; the hint only explains why a backup starts a little late). Derived from the next run of each profile; no stored state.
- **Buttons:** *Change* per row, and *Change the selected* above the table (enabled with script when at least one row is ticked; without script the button is there and says "tick at least one" when pressed with none).
- Narrow screens: the table is scrollable as the other tables are (`.scroll`); the first two columns stay readable.

### 3.4 The form `/schedule/edit`

`GET /schedule/edit?profile=a&profile=b` shows one form for one or several profiles ("Change the schedule of: *shop*, *blog*"). Fields: **How often** (the five choices of 3.2, a radio group), **Time** (a select in five-minute steps), **Weekdays** (seven check boxes), **Day of the month** (select 1-28), **Every** (select of hours) and **At minute** (select). With script only the fields of the chosen choice are shown; without script all are shown and each says which choice it belongs to. For several profiles the form starts empty ("leave as it is" until a choice is made); for one it starts with the current rule. The page shows the **next three runs** of what is entered (computed by the server on `POST ... preview`, no script needed) before the button *Save*.

`POST` checks the token, validates every field against the table of 3.2 (an invalid value is an error next to the field, nothing is saved), writes the profiles, makes one audit entry per changed profile (`schedule.changed`, with the old and new expression, no secrets), and returns to the table with a message that names the next run of each changed profile. A profile that was damaged or removed meanwhile is reported and skipped.

### 3.5 The rest of the interface

- **Profile form:** the field *Back up by itself at* goes; `ProfileForm::OPTIONAL` already keeps `schedule` untouched when the form does not send it. The *Automation* section keeps retention and says "The schedule is set on the Schedule page" with a link. A **new** profile starts without a schedule; the profile page after creation links to the page.
- **Profile page:** the fact *Schedule* shows the words, with the link.
- **Dashboard:** unchanged ("Next scheduled backup" on the cards); the words come from `describe()` where it printed the cron text.
- **Command line:** `profile add|edit --schedule`, `--no-schedule` keep the cron text; `profile show` prints the words and the cron text. A command `threadsafe schedule` that lists the table is possible later (not planned).
- **Menu:** *Dashboard, Profiles, **Schedule**, System*; icon `clock` (exists). The route `/schedule` is below `Kernel::router()` with the others.

## 4. Test strategy

### 4.1 Unit tests (no database)

- `ScheduleRule`: a table of rules and the cron they make, both ways; the expressions that are recognised (`@daily`, `0 3 * * *`, `15 2 * * mon,thu`, all seven weekdays = daily, `*/6` and `0,6,12,18` = every six hours); the ones that are not (`*/7`, lists of days of the month, ranges of months); every value outside 3.2 is refused with a message that names the field; the words for each kind.
- `ScheduleService`: the rows for a set of profiles, sorting by name, type and next run in both directions with ties and unscheduled profiles, the overlap hint, `apply()` writes only what changed and keeps retention and encryption, resets the state of the scheduler for a changed schedule and not for an unchanged one (`ScheduleStore`), a damaged profile is reported.
- `ScheduleController` (the `AdminBrowser` of the other page tests): the table and its headers (`aria-sort`, the links keep `type`), the filter, the form for one and for several profiles, validation errors, the preview, the token check (403 without it), 405 for a wrong method, audit entries, the menu entry marked on the page.
- `ProfilePagesTest`/`DashboardFilterTest`: the profile form has no cron field and keeps the schedule when saved; the words appear where the cron text was.

### 4.2 Integration and the browser

- Phase 4b of `tests/stack/run.sh` (a real Apache, 5 s / 64 MB): a test in `WebInterfaceTest` sets a schedule through the form, reads it in the table, sorts it, and checks that `threadsafe cron` finds the profile due at the right time (the clock of the test is moved as in `TriggerTest`).
- `tools/a11y-audit.js` on the page and on the form, in both colour schemes; the page seen in the browser in both schemes and at phone width (the sort headers, the check boxes, the form with and without script).

## 5. Work packages

| # | Package | Needs | Proof |
|---|---|---|---|
| 1 | `ScheduleRule` (recognise, make, describe, validate) and `ScheduleSettings::rule()/describe()` | - | unit tests of 4.1 |
| 2 | `ScheduleService`: rows, sorting, overlap hint, `apply()` | 1 | unit tests of 4.1 |
| 3 | The page `/schedule` (menu entry, table, sort headers, filter, trigger line), styles | 2 | controller tests, browser |
| 4 | The form `/schedule/edit` (one and several profiles, preview, validation, audit) | 2, 3 | controller tests, browser with and without script |
| 5 | The profile form, profile page, dashboard words, command line help | 1 | page tests |
| 6 | Integration test in phase 4b, a11y audit, the look in both schemes and at phone width | 3, 4 | `run.sh` phase 4b, audit |
| 7 | Documentation (`SCHEDULE.md`, `WEB.md`, `README.md`, `DESIGN.md`, `CHANGELOG.md`, `AGENTS.md`, "As built") | all | read through |

Packages 1 and 2 come first and carry the risk (the translation of cron); 3 and 4 are the interface; 5 to 7 finish. One commit per package, as everywhere (`Phase 8, work package N: ...`).

## 6. Acceptance criteria

1. A person who has never seen a cron expression can give three profiles different schedules on the Schedule page without reading anything but the page.
2. Every schedule that exists today is shown; those that the simple settings can make are shown in words and can be changed with the form, the others are shown as custom and keep running until they are replaced on purpose.
3. The table is sorted by name, type and next run, ascending and descending, without script; unscheduled profiles stay at the end when sorting by time.
4. Saving the form without a change does not touch any profile file or scheduler state; a changed schedule counts from the moment of the save, as before.
5. The engine is unchanged: `SchedulerTest`, `TriggerTest` and the phase 4c tests of `run.sh` pass as they are.
6. `composer check` and all three database servers stay green; the page passes the contrast audit in both colour schemes.

## 7. Risks and decisions

### 7.1 Risks

| Risk | Handling |
|---|---|
| The recogniser calls two expressions equal that behave differently (day-of-month and day-of-week both restricted, the Vixie rule) | it recognises only expressions with `*` in the day-of-month and month fields (weekly) or `*` in the weekday field (monthly); everything else is custom; tested with the expressions of `CronExpressionTest` |
| A change on the page and a change by `profile edit` at the same time | the last write wins, as for the profile form today; the table is read again on every request |
| "Every N hours" starts at midnight of the wall clock, so at a change of daylight saving time the gap between two runs is N plus or minus one hour once a year | that is the rule of the engine (SCHEDULE.md section 2) and is said in the help of the form |
| The new menu entry is one more place for a person who set the schedule in the profile form for years | the profile form says where it went, and links to it |

### 7.2 Decisions for the owner

1. **Storage.** Proposed: the cron text stays the stored format, the pages translate (3.1). The alternative is a structured object in the profile file (`{"every": "week", "on": ["mon", "thu"], "at": "02:15"}`) with cron only for the engine: cleaner to read in the file, but it needs a migration and makes the command line and the profile files two dialects.
2. **Restrictions.** Proposed: the table of 3.2. Open: should the hourly choice allow other intervals, and should monthly allow "the last day" (cron cannot say it; the engine would need a rule)?
3. **Where the schedule is edited.** Proposed: only on the Schedule page; the profile form loses the field (3.5). The alternative is to keep a small copy of the form in the profile form as well.
4. **Pause.** Not planned. If wanted, a flag `paused` in `schedule` that the scheduler skips, a switch in the table - a small addition after package 4.
