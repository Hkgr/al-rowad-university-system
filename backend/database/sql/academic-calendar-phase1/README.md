# Academic Calendar Phase 1 — Foundation

Manual SQL only for MariaDB 10.11. These files establish the university-wide
academic-calendar data model; they do not expose an API, render a calendar UI,
or enforce any academic workflow window.

Database: `alrowad_uni_rust`

All application objects are fully qualified. The scripts do not use
`DATABASE()`, stored procedures, `DELIMITER`, `SIGNAL`, Laravel migrations, or
production seeders.

Ownership marker: `[academic-calendar-phase1]`

## Operator run order

Run each file manually in phpMyAdmin as a database operator:

1. `00_preflight.sql` — read only. Continue only when the final `OVERALL` row
   says `READY`.
2. `01_apply.sql` — guarded and rerunnable. Its final result is `APPLIED`,
   `ALREADY_APPLIED`, or `BLOCKED`.
3. `02_verify.sql` — read only. Accept the deployment only when the final
   `OVERALL` row says `PASS`.
4. `03_rollback.sql` — emergency use only, before calendar data is used in
   production.

Do not treat CI or source-level tests as proof that these files ran against the
production database.

## Existing academic structure

Phase 1 reuses, without rewriting:

- `academic_years.academic_year_id`
- `semesters.semester_id` and all existing semester codes, including `first`,
  `second`, and `summer`
- the existing `course_offerings` foreign keys to both tables
- `users.user_id` for actor provenance

`academic_years.is_current` remains the runtime authority used by the existing
Laravel application in Phase 1. `academic_years.is_active` also retains its
existing meaning. Neither column is renamed, reinterpreted, or rewritten.

Phase 1 adds `calendar_lifecycle_status` as parallel calendar lifecycle
metadata:

| Value | Meaning |
| --- | --- |
| `draft` | A future academic year may be prepared but is not operational. |
| `active` | The single calendar lifecycle-active academic year. |
| `closed` | A historical academic year. |

The backfill maps the sole current year to `active`, non-current years ending
before the current year's start to `closed`, and other non-current years to
`draft`. It explicitly preserves the existing `updated_at` values.

The generated `calendar_active_slot` unique key permits at most one lifecycle
`active` row. Preflight and verification additionally require exactly one and
require it to be the existing `is_current` row. Phase 2 lifecycle actions must
update `is_current` and `calendar_lifecycle_status` together in one transaction.

`academic_calendar_year_lifecycle_events` is the narrow append-only history for
future activate, close, and reopen actions. Every recorded transition requires
an actor and a nonblank reason. The initial deterministic backfill does not
fabricate an actor or an audit event.

## Calendar event model

`academic_calendar_event_types` provides stable machine-readable codes and
Arabic/English labels. `system` types are vocabulary that later backend phases
may use for enforcement; `general` types are informational. The type-level
default is only a creation aid. Every version persists its own explicit
`is_enforcement` value.

`academic_calendar_events` is the logical event identity and immutable academic
context: one existing academic year, an optional existing semester, and one
event type. A `NULL` semester means a university-wide/year-wide event. There is
no college, program, role, or audience targeting.

`academic_calendar_event_versions` stores historical content revisions with
`DATETIME` boundaries in the application's existing timezone convention
(currently Laravel UTC). There is no date-only event representation and no
second timezone system.

The revision model supports this publication sequence:

1. Version 1 is `published` and remains the effective version.
2. Version 2 is created as `draft` with `replaces_version_id` pointing to
   version 1.
3. Until version 2 is explicitly published, version 1 remains `published`.
4. Publication later occurs transactionally: version 1 becomes `superseded`
   with its original content and publication provenance intact, then version 2
   becomes `published`.

A generated nullable publication slot plus a unique key permits at most one
`published` revision for a logical event while allowing drafts and historical
superseded revisions to coexist.

There is deliberately no uniqueness constraint on event type + academic year +
semester. Multiple windows of the same type are valid. Different event types
may overlap, and Phase 1 adds no overlap trigger or warning logic.

## Cancellation and retention

Cancellation is recorded on the logical event with
`cancelled_by_user_id`, `cancelled_at`, and a mandatory nonblank
`cancellation_reason`. Published and superseded revisions remain stored and
recoverable. Restrictive foreign keys prevent actor provenance or institutional
history from disappearing through parent deletion.

Draft-only deletion policy and cancellation actions belong to Phase 2. Phase 1
creates only the data foundation.

## Seeded event type codes

System vocabulary:

- `admission_registration`
- `course_registration`
- `withdrawal`
- `study_period`
- `exam_preparation`
- `practical_exams`
- `theoretical_exams`
- `grade_appeals`
- `supplementary_exams`

General vocabulary:

- `university_break`
- `preparation_period`
- `holiday`
- `general_event`

Workflow and examination windows default to enforcement. `study_period`,
`exam_preparation`, and every general type default to informational. No current
workflow reads these defaults or calendar rows.

## Explicit Phase 1 boundaries

Phase 1 adds no:

- React page or calendar editor
- public calendar API or event CRUD endpoint
- calendar permissions or role mappings
- `isWindowOpen()` service or workflow blocking
- admission, registration, withdrawal, appeals, exam, or notification logic
- exact course examination schedule, room, seating, or course examination time
- regular grading dependency or approval change
- supplementary-examination integration or backfill

The existing supplementary period, offering, registration, grading, approval,
and materialization subsystems remain authoritative and unchanged. The
`supplementary_exams` calendar type is vocabulary for a later integration phase
only.

## Rollback warning

Rollback is intended only before the feature is used in production. It returns
`BLOCKED_IN_USE` if logical events, revisions, lifecycle history, or custom event
types exist. It removes only objects carrying the Phase 1 ownership marker and
never drops, recreates, or deletes rows from `academic_years`, `semesters`,
`course_offerings`, or supplementary/grade tables. Adopted or unowned objects
are left untouched.
