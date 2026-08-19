# Phase 11 — system hardening audit (READ ONLY)

Manual DBA package. Do **not** execute these files from application code,
seeders, or Laravel migrations.

Production database changes remain **MANUAL SQL only**.

Phase 11 does **not** apply schema. It only audits load-bearing
cross-phase invariants after Phases 8–10 (and earlier academic-core SQL)
have been applied.

## Files

| File | Purpose |
|---|---|
| `00_audit.sql` | READ ONLY. Expected result: `OVERALL = PASS` |

There is **no** `01_apply.sql`. Phase 11 discovered no schema defect that
requires a repair script.

## How to run

1. Confirm earlier phase verifiers already returned `OVERALL = PASS`:
   - `backend/database/sql/teaching-assignment-lifecycle/02_verify.sql`
   - `backend/database/sql/student-registration-lifecycle/02_verify.sql`
   - `backend/database/sql/student-academic-progression/02_verify.sql`
2. If an earlier phase was never applied, run that phase's own
   `00_preflight` → `01_apply` → `02_verify`. Never use Phase 11 as a
   substitute for missing schema.
3. Take a database backup immediately before any earlier-phase apply.
4. Run `00_audit.sql` in phpMyAdmin / the DBA workflow against MariaDB.
5. Continue only when the final row is `OVERALL = PASS`.

## Rules encoded in `00_audit.sql`

- Fully qualified schema: `alrowad_uni_rust`
- Does not use `DATABASE()`
- No `INSERT` / `UPDATE` / `DELETE` / `ALTER` / `CREATE` / `DROP` / `TRUNCATE`
- Missing infrastructure yields `FAIL`, not SQL error `#1146` / `#1054`
- A required **table present with a required column missing** yields `FAIL`
  (`required_core_columns`) without querying that object
- Phase 8/9/10 workflow queries run only after both TABLE and COLUMN guards
- `teaching_assignment_requests.action_type` missing is `FAIL`
- Persisted teaching-assignment `action_type` values are exactly `assign`
  and `remove` (Phase 8 / `TeachingAssignmentWorkflow`). Replacement is a
  workflow outcome, not a third stored `action_type`. `replace` is FAIL.
- Current-slot UNIQUE indexes reuse the exact Phase 8/9/10 contracts:
  `uq_tar_current_slot`, `uq_srwr_current_slot`, `uq_spd_current_slot`,
  `uq_sgd_current_slot`
- Course Offering `status` must be `open` or `closed`
- `capacity >= 1`, `available_seats` between 0 and `capacity`
- Seat occupancy: `available_seats = capacity - count(registered)` using
  `registration_statuses.status_code = 'registered'` only (dropped/withdrawn
  are not occupied). `student_course_registrations` has no `current_slot`;
  registered status is the current-row definition. If this FAILs, the seat
  counter drifted; repair is a DBA operation, not a Phase 11 apply script.
- Generic `vice_president` must not hold dedicated academic mutation review
  authority from Phases 6–10 (teaching assignment, exceptional opening,
  closure, withdrawal, academic-record finalize, progression, graduation)
- `super_admin` must not hold those explicit mutation mappings either

## Acceptance cases (SQL-HARD11)

| Id | Condition | Expected |
|---|---|---|
| SQL-HARD11-01 | Required table exists, required referenced column missing | `OVERALL = FAIL`, no `#1054` |
| SQL-HARD11-02 | `action_type` missing | `teaching_assignment_action_types` / OVERALL FAIL |
| SQL-HARD11-03 | `uq_srwr_current_slot` missing | `withdrawal_current_slot_unique` FAIL |
| SQL-HARD11-04 | `uq_spd_current_slot` missing | `progression_current_slot_unique` FAIL |
| SQL-HARD11-05 | `uq_sgd_current_slot` missing | `graduation_current_slot_unique` FAIL |
| SQL-HARD11-06 | CourseOffering `status='banana'` | `course_offering_canonical_status` FAIL |
| SQL-HARD11-07 | `available_seats > capacity` | `course_offering_available_seats_bounds` FAIL |
| SQL-HARD11-08 | generic `vice_president` has dedicated academic review mutation | `generic_vp_without_dedicated_reviews` FAIL |
| SQL-HARD11-09 | `super_admin` has explicit forbidden academic mutation mapping | `super_admin_without_explicit_academic_mutations` FAIL |
| SQL-HARD11-10 | Clean fully applied Phase 8–10 database | `OVERALL = PASS` |
| SQL-HARD11-11 | `teaching_assignment_requests.action_type = 'replace'` | `teaching_assignment_action_types` / OVERALL FAIL |

## Result meaning

`OVERALL = PASS` means the **database contract** for academic-core
hardening is intact.

It does **not** by itself mean the university system is production-ready.
See `backend/docs/production-academic-core-checklist.md`.
