# Academic Calendar deployed-schema compatibility repair

This manual MariaDB 10.11 package reconciles the deployed August 24, 2026
calendar schema with the Phase 1/2 contract merged into the application. It is
a deployment repair only. It adds no feature, migration, seeder, API, model, or
workflow integration.

## Operator run order

1. Put the Laravel application in maintenance mode and verify that no calendar
   writes can reach the database. Do not run this repair while the application
   can write.
2. Take and retain a database backup while maintenance mode remains active.
3. Run `00_preflight.sql` in the `alrowad_uni_rust` phpMyAdmin SQL tab.
4. Continue only when the final visible row is `OVERALL | READY`.
5. Run `01_apply.sql` once. It is guarded and rerunnable after a safe partial
   DDL interruption. Continue only when its final row is `OVERALL | APPLIED`.
6. Run `02_verify.sql` and accept the repair only when its final row is
   `OVERALL | PASS`.
7. Re-run `../academic-calendar-phase1/02_verify.sql`; it must also finish with
   `OVERALL | PASS`.
8. Exit maintenance mode only after both verification scripts return `PASS`.

`REPAIRABLE_SOURCE` identifies the known deployed layout. `ALREADY_COMPATIBLE`
means no repair remains. `SAFE_PARTIAL` identifies only a known, empty,
partially repaired layout that may safely resume. Any other layout is
`CONFLICTING` and blocks execution.

Both operator-facing read-only scripts use one ordinary CTE-backed `SELECT`
result set. They contain no prepared execution or `SELECT ... INTO` reporting,
so phpMyAdmin displays every report row and the terminal `OVERALL` row in the
same grid.

## Safety and ownership

The repair fails closed unless both `academic_calendar_events` and
`academic_calendar_event_versions` are empty. It never copies, rewrites, or
deletes academic years, semesters, event-type vocabulary, lifecycle history,
permissions, or role mappings. It does not seed data.

Maintenance mode is a hard operational gate because MariaDB DDL commits
implicitly and cannot make the full multi-table repair atomic. The apply script
also repeats fresh zero-row checks immediately before every destructive
revision-context operation and returns `BLOCKED` if either table is non-empty
at its final check; these guards supplement, but do not replace, maintenance
mode.

The structural repair moves semester and event-type context to the logical
event, restores the merged indexes and foreign keys, converts state enums to
the merged `VARCHAR` plus named-check representation, and restores the Phase 1
checks and ownership comments.

`03_rollback.sql` is emergency-only. It refuses to run after any logical event
or revision exists. Rolling back intentionally restores the known deployed
layout, which is incompatible with the merged Phase 2 application; normally
restore the pre-repair backup instead. Its only successful terminal result is
`ROLLBACK_RESULT | ROLLED_BACK`; any `BLOCKED` result means no rollback DDL was
authorized.

## Phase 3 boundary

Do not begin or deploy Academic Calendar Phase 3 until this package and the
original Phase 1 verification both return `PASS`. This package does not add the
temporal policy service and does not connect the calendar to registration,
withdrawal, grading, appeals, or supplementary-exam workflows.
