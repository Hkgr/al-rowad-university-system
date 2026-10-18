# Phase 5 — Instructor coverage before normal offering opening

Manual SQL only. Cursor must not execute these files.

Database: `alrowad_uni_rust`

Fully qualify every application table as `` `alrowad_uni_rust`.`table_name` ``.

`information_schema` filters use `TABLE_SCHEMA = 'alrowad_uni_rust'`. Never use `DATABASE()`.

## Files

This phase does **not** change the database schema.

1. `00_preflight.sql` — READ ONLY audit. Continue only when the final row is `OVERALL | READY`.
2. `02_verify.sql` — READ ONLY structural verification. Continue only when the final row is `OVERALL | PASS`.

There is no `01_apply.sql` and no `03_rollback.sql` because no table, index, or RBAC change is required.

Every file ends with a visible phpMyAdmin result row. Do not treat “query OK / 0 rows affected” as success.

## What this package audits

Existing objects used by application coverage logic:

- `courses.theoretical_hours` / `courses.practical_hours` (required teaching roles)
- `course_offerings` including legacy `faculty_member_id`
- `course_offering_instructors` (canonical effective coverage)
- Phase 4 workflow tables (`teaching_assignment_requests`, `teaching_assignment_reviews`, `teaching_assignment_events`)
- Unique indexes:
  - `uq_course_offering_role` UNIQUE on `course_offering_instructors (course_offering_id, instructor_role)`
  - `uq_course_offering_program_term` UNIQUE on `course_offerings (course_id, academic_program_id, academic_year_id, semester_id)` in that exact order

`02_verify.sql` also visibly PASS/FAIL-checks the columns used by coverage:

- `courses.theoretical_hours`, `courses.practical_hours`
- `course_offerings.status`, `course_offerings.faculty_member_id`
- `course_offering_instructors.course_offering_id`, `faculty_member_id`, `instructor_role`, `is_active`
- `faculty_members.is_active`, `faculty_members.employee_id`
- `employees.employee_status_id`
- `employee_statuses.status_code`, `employee_statuses.is_active`

## What SQL cannot verify

SQL cannot prove PHP opening behavior. After `OVERALL | PASS`, accept the application manually using the Phase 5 scenarios (A–AC) in the pull request:

- new offerings persist `closed`
- `closed → open` requires complete effective coverage
- pending teaching-assignment requests are not coverage
- Super Admin / `courses.manage` cannot bypass the invariant
- existing `open` offerings are not retroactively closed

## What this phase does not do

- No migrations or seeders
- No schema change
- No fake users, assignments, or VP approvals
- No retroactive closing of open offerings
- No exceptional opening (Phase 6)
- No offering closure workflow (Phase 8)
- No academic `delivery_type` column
