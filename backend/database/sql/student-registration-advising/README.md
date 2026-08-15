# Student registration advising

Manual SQL runbook for the Academic Advisor registration-request workflow.

Do **not** execute these files from application code, seeders, or migrations.
Run them only in phpMyAdmin / the DBA workflow after `00_preflight.sql`
returns `OVERALL = READY`.

## What this runbook does

- Creates three request-workflow tables (separate from finalized
  `student_course_registrations`).
- Reuses the existing `academic_advisor` role. It does **not** create a
  second Academic Advisor role.
- Adds `registration_requests.view` and `registration_requests.review`
  under the existing `registration` system module.
- Grants those review permissions to `academic_advisor` and, temporarily,
  to `dean`.
- Grants `registration.view` to `academic_advisor` if missing.
- Does **not** grant `registration.manage` to Dean, Academic Advisor, or
  Student.

## Files

1. `00_preflight.sql` — read-only. Continue only when `OVERALL = READY`.
2. `01_apply.sql` — idempotent DDL + permission grants. Fail-closed.
3. `02_verify.sql` — read-only. Require `OVERALL = PASS`.

## Fully qualified objects

Every object is written as `` `alrowad_uni_rust`.`table` `` so the scripts
remain valid when phpMyAdmin is scoped to `information_schema`.
