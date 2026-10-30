# Phase 10 – a retention strategy: how many backups, for how long

**Status:** **planned** (2026-10-30; the decisions of section 7.2 are for the owner, each with a recommendation).
**Context:** [SCHEDULE.md](SCHEDULE.md) section 5 is the retention as it works today; [PHASE5.md](PHASE5.md) 3.4 is its design (the rules, the protections, the go-ahead); [WEB.md](WEB.md) describes the pages; [BACKUP.md](BACKUP.md) the profile file. The engine (`Retention\Retention`, `RetentionService`, the go-ahead, the audit entries) keeps its logic and gets one more kind of rule.

## 1. Goal and scope

**What exists today** (phase 5, built and proven): a profile may carry three rules, each optional - `keep` (at most N backups), `maxAgeDays` (none older), `maxBytes` (together at most this big). They are applied after every backup and once an hour; the newest usable backup, pinned backups and backups in use are never deleted; rules that would delete more than one backup at once wait for the owner's go-ahead; the profile page previews what would go and why.

**What is missing** is a *strategy*, not the mechanism:

1. **A new profile has no rules.** Backups then stay until a person deletes them; nothing says so except one sentence on the profile page. A site with a daily schedule fills its storage in months, and the first sign is a failing backup ("too little space").
2. **The rules are flat.** "The last 14" keeps fourteen days and nothing older; "none older than 90 days" keeps ninety dailies. What people want is the usual **staged** scheme: many recent backups, fewer older ones, a few very old ones (the last 7 days, 4 weeks, 12 months, 2 years). With the flat rules that costs either a lot of space or the old backups.
3. **Nothing says what a choice costs.** A person who sets "keep 30" does not see that this is 30 × 710 MB = 21 GB, or that the storage has room for 12.
4. **Backups of other kinds sit beside the scheduled ones** (a manual backup before an update, an interrupted one, a faulty one). Manual ones count in `keep` like any other and can push scheduled ones out; faulty and incomplete ones are left alone for ever.

| In scope | Out of scope (later, or never) |
|---|---|
| a **staged rule** (`tiers`): keep the newest backup of each of the last N days, weeks, months and years | remote storage and its own retention (the `transfers` list of the sidecar file is reserved) |
| **presets** with plain names, for the profile form and the command line | different rules for different kinds of backup (a separate "manual" set) |
| a **default for new profiles** made in the web form, and a **hint** where a profile has a schedule but no rules | deleting without any rule or without the go-ahead; a "delete everything older than" for faulty backups beyond the option of decision 8 |
| the **cost in the form and on the page**: how many backups the rules keep in the long run and what that is in bytes, against the free space | an estimate of growth from the history of sizes, a chart |
| why a backup is kept or goes, in the preview ("kept: newest of week 43") | compliance features (legal hold, immutable backups) |

**No migration.** The three existing rules stay as they are and stay valid. `tiers` is a new optional field of `retention`; a profile file without it reads as before. (One thing to say plainly: `WEB.md` "Updating" promises that a release reads the data of the one before it, so that a roll-back leaves the data alone. That stays true until a profile uses `tiers`: the older program refuses such a profile file ("Unknown field "tiers" in "retention""). Handling in work package 7: `WEB.md` and the update page say that after setting staged rules a roll-back needs the field removed from the profile files by hand; the first release with `tiers` is the one to say it in the notes of.)

## 2. Deliverables

1. `Profile\RetentionSettings`: the field `tiers` (`daily`, `weekly`, `monthly`, `yearly`, each a whole number or absent), its validation, `toArray()`, the words for the page and the command line.
2. `Retention\Retention` (and `RetentionPlan`/`RetentionAction`): the choice of the backups that the tiers keep, the reason of every deletion and of every backup that stays, the protections unchanged, the go-ahead unchanged.
3. `Retention\TierCalendar` (new): the periods (day, ISO week, month, year) in a time zone, for a moment; the one place that knows what "the last 4 weeks" means.
4. `App\RetentionService` and `Admin\RetentionController`: the preview with reasons, the forecast (how many backups the rules keep in the long run, what that is, against the free space of the storage).
5. `Admin\ProfileForm`: the section *Retention* with the choice **Simple** (the three rules as today) / **Staged** (presets and the four numbers) and the optional size limit; the default for a new profile; `profile add|edit` with `--keep-daily`, `--keep-weekly`, `--keep-monthly`, `--keep-yearly` and `--retention-preset=NAME`; `profile show`.
6. The hint for a profile with a schedule and no rules: on the dashboard card and the profile page, and a line in the output of `backup`.
7. Tests (section 4), documentation: `SCHEDULE.md` (section 5), `BACKUP.md` (the profile file), `WEB.md`, `README.md`, `CHANGELOG.md`, `AGENTS.md`, this plan's "As built".

## 3. Design

### 3.1 The rule

```json
"retention": {"tiers": {"daily": 7, "weekly": 4, "monthly": 12, "yearly": 2}}
"retention": {"tiers": {"daily": 7, "weekly": 4}, "maxBytes": 21474836480}
"retention": {"keep": 14}
```

A backup is **kept by the tiers** when it is the **newest usable backup of a period** that is among the last N periods of its kind:

- `daily: 7` - the newest backup of each of the last 7 calendar days (today counts, as the first);
- `weekly: 4` - the newest backup of each of the last 4 ISO weeks (Monday to Sunday);
- `monthly: 12` - of each of the last 12 calendar months;
- `yearly: 2` - of each of the last 2 calendar years.

One backup can be kept for several reasons (the newest of its day, its week and its month); it counts once. **A period without a backup is not made up:** if nothing was made in week 40, the four weeks still reach back four periods, and the one that is empty is empty. A backup that no tier keeps goes, **oldest first**, the same way as today. Periods are counted in the **time zone of the schedule** (decision 3), by the time in the name of the backup (UTC, as it is made).

**Combining.** `tiers` excludes `keep` and `maxAgeDays` (decision 7; both say "how many/how long" and would contradict each other); `maxBytes` stays possible next to `tiers` as a **cap**: when the tiers keep more than fits, the oldest of the kept ones go until it fits, and the note of the plan says so (as the existing size rule does: "cannot be met" when the rest is protected).

**Never deleted, unchanged:** the newest usable backup, pinned backups (a pin does not count in the tiers, its size counts towards `maxBytes`), a backup in use. Faulty and incomplete backups are left alone (decision 8). **The go-ahead is unchanged:** the first time the tiers are set on a storage that holds hundreds of backups, they wait until the owner has applied them by hand after looking at the preview.

### 3.2 The reasons

The plan names for every backup why it **stays** ("newest of 2026-10-29; newest of week 44; newest of October 2026") or why it **goes** ("no tier keeps it: not among the last 7 days or 4 weeks, and not the newest of its month"). The profile page shows both lists, a short one (the deletions) first, as today, and the kept ones in a table that can be opened. The command `retention <profile> --dry-run` prints the same.

### 3.3 The cost

The page and the form say, in the long run: **"These rules keep up to 24 backups (7 + 4 + 12 + 1 more year; one backup can serve several tiers, so usually fewer). At the size of the newest backup, 710 MB, that is up to 17 GB; the storage has 40 GB free."** The count is arithmetic on the numbers (the sum of the tiers is the upper bound; the real number is in the preview). A warning in the colour of the other warnings when the upper bound exceeds the free space. For the simple rules the same sentence ("keep 14 = up to 10 GB").

### 3.4 The presets

Plain names, numbers that the owner can change in the form:

| Name | Daily | Weekly | Monthly | Yearly | For |
|---|---|---|---|---|---|
| `light` | 7 | 4 | 3 | - | a small site that rarely changes |
| `standard` | 7 | 4 | 12 | 1 | the usual: a week of days, a month of weeks, a year of months |
| `archive` | 14 | 8 | 24 | 5 | a shop, where an old state may be asked for |

A preset is only a way to fill the four fields; the profile file holds the numbers, never the name (so a later change of a preset changes no profile).

### 3.5 Defaults and hints

- The **web form for a new profile** starts with `standard` filled in (visible, editable, and removable with "No rules"). The command line and the existing profiles are **not** given a default silently: a rule that deletes must be a rule that a person has seen.
- A profile **with a schedule and without rules** gets a hint on its card and page: "Backups stay until you delete them. At 710 MB a day that is 21 GB a month. Set rules." The hint disappears with any rule. `backup` prints one line.
- A profile **without a schedule** gets no hint (a person who backs up by hand deletes by hand).

### 3.6 Manual and scheduled backups

For this phase they are **equal** (decision 6): the newest backup of a day is the newest, whoever started it, and a **pin** is how a person keeps a manual backup (before an update, before a restore) for good. The sidecar file records `source` (`manual` or `schedule`) already; a later phase may use it.

## 4. Test strategy

### 4.1 Unit tests (no database)

- `TierCalendar`: day, ISO week and month and year of a moment in a time zone, across the end of a year (week 53 and week 1, 2026-12-28 to 2027-01-03), a leap day, the change of daylight saving time (a day of 23 and of 25 hours), a backup at 23:59:59 and one at 00:00:00 of the zone, a zone east and west of UTC (the same backup is on another day).
- `RetentionSettings`: `tiers` read and written, every number outside 1 to 1000 refused with a message that names the field, `tiers` with `keep` or `maxAgeDays` refused, an empty `tiers` read as no rule, the profile file of the old form unchanged.
- `Retention`: the plan for hundreds of generated backups (one a day for two years, one a day with holes, several in a day, only one) against the preset numbers: exactly the expected set stays; the newest backup, a pinned one and one in use stay even when no tier wants them; the oldest go first; the reasons say the period; `maxBytes` next to `tiers` as a cap and its note; faulty and incomplete backups are left alone and counted in the note; the go-ahead (more than one deletion waits) for the first application.
- `RetentionService` and the forecast: the numbers for a plan, the warning against the free space, no division by zero for a storage without backups.
- The form and the command: the fields and options, the preset names, the default of a new profile in the web form and none on the command line, the hint for a profile with a schedule and without rules, `profile show`.

### 4.2 Integration and the browser

- Phase 4c of `tests/stack/run.sh` (retention on real backups): a test with real backups (made one after the other, their time in the name set back by days) and the staged rule; what stays is what the plan said, on all three database servers.
- `tools/a11y-audit.js` on the profile page and the form in both colour schemes; the form seen in the browser with and without script (the choice Simple/Staged, a preset filling the fields, the forecast) and at phone width.

## 5. Work packages

| # | Package | Needs | Proof |
|---|---|---|---|
| 1 | `TierCalendar` and `RetentionSettings.tiers` (validation, file form, words) | - | unit tests of 4.1 |
| 2 | `Retention`: the plan with tiers, reasons, the cap, protections, the go-ahead | 1 | unit tests of 4.1 (generated histories) |
| 3 | `RetentionService`/`RetentionController`: the preview with reasons and the forecast | 2 | controller tests |
| 4 | The profile form and page (Simple/Staged, presets, new-profile default), `profile add|edit|show` options | 1, 3 | page and command tests, browser |
| 5 | The hint for a profile with a schedule and no rules (card, page, `backup`) | 4 | controller and command tests |
| 6 | Integration test in phase 4c, the a11y audit, the look in both schemes | 2 to 5 | `run.sh` phase 4c, matrix, audit |
| 7 | Documentation (`SCHEDULE.md`, `BACKUP.md`, `WEB.md`, `README.md`, `CHANGELOG.md`, `AGENTS.md`, "As built") | all | read through |
| 8 | **Field test** by the owner: set the preset on the real profile, read the preview against what is in the storage, apply it | 7 | the findings |

Package 1 and 2 are the heart (and the part with the most arithmetic); 3 to 5 are the interface. One commit per package (`Phase 10, work package N: ...`).

## 6. Acceptance criteria

1. A person who sets the preset `standard` on a profile with a hundred backups of two years sees, **before anything is deleted**, which backups stay and why, how many remain and what they take, and that the first application waits for their go-ahead.
2. With `standard` and one backup a day, the storage settles at the backups of the last 7 days, the newest of each of the last 4 weeks and 12 months and the last year's: never fewer than the rule says, never more than its sum.
3. A day, a week, a month or a year that has no backup does not make the tiers reach further back than N periods.
4. The newest usable backup, pinned backups and backups in use are never deleted, with any rule.
5. A new profile made in the browser has the rules of `standard` filled in; one made on the command line has none and says so; a profile with a schedule and no rules shows the hint.
6. The old rules (`keep`, `maxAgeDays`, `maxBytes`) work as before: `RetentionTest`, `TriggerTest` and the phase 4c tests pass unchanged.
7. `composer check` and all three database servers stay green; the form and page pass the contrast audit in both colour schemes.

## 7. Risks and decisions

### 7.1 Risks

| Risk | Handling |
|---|---|
| A wrong period boundary deletes a backup that a person counted on (the time zone, the start of a week) | the one class `TierCalendar`, tested at the boundaries (4.1); the preview names the period of every backup; the go-ahead for the first application; the newest backup is never deleted |
| "Keep 12 monthly" is read as "12 backups" | the words of the form and the forecast say "the newest of each of the last 12 months" and give the upper bound in numbers |
| A host with a short storage fills before the tiers settle (they keep more at first than later) | the forecast against the free space warns; `maxBytes` as the cap |
| A person with a daily and a manual backup the same day loses the one they wanted | the pin (3.6); the preview shows both; decision 6 |
| Staged rules look like a promise of a long history on a storage that is on the same server as the site | `SCHEDULE.md` says so in one sentence: a copy on the same disk is no protection against the loss of the server; remote storage is a later phase |
| An older program refuses the new field after a roll-back, which contradicts "a release reads the data of the one before it" | said in section 1, in `WEB.md` "Updating" and in the notes of the release; only profiles that use `tiers` are affected, and the message names the field |

### 7.2 Decisions (owner)

Each with the recommendation; none is settled yet.

1. **Model.** *Staged tiers* (3.1) **(recommended)**, or only presets that fill the three flat rules. The flat rules cannot express "a month of days and a year of months", which is the point.
2. **Default for new profiles.** Filled in the web form with `standard`, none on the command line, no change to existing profiles **(recommended)**; or only the hint; or nothing.
3. **Time zone of the periods.** The time zone of the schedule (`schedule.timezone`, UTC where nobody set one) **(recommended)**; or always UTC.
4. **First day of the week.** Monday (ISO 8601) **(recommended)**.
5. **Which backup stands for a period.** The **newest** of it **(recommended)**: the state at the end of the day, the usual scheme; or the first of it.
6. **Manual backups.** Equal to scheduled ones, a pin keeps one for good **(recommended)**; or counted apart.
7. **Combining.** `tiers` excludes `keep` and `maxAgeDays`; `maxBytes` stays as a cap **(recommended)**; or all four rules together (each deleting what it says).
8. **Faulty and incomplete backups.** Left alone as today, and named in the plan **(recommended)**; or an option "delete the ones older than N days" (off by default).
9. **The presets** `light`, `standard`, `archive` with the numbers of 3.4 **(recommended)**; the numbers are easy to change before building.
10. **Order of work.** After the owner's field tests of 0.9.0-beta.2 (the findings of the real hosts come first); packages 1 and 2 are pure logic and can start earlier.

## 8. As built

Not built yet.
