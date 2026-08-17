# Course offering academic context

Manual SQL runbook for the unique Course Offering identity index.

Do **not** execute these files from application code, seeders, or migrations.
The application already rejects duplicate program-term offerings. This index
is defense-in-depth and race protection only.

Run them only in phpMyAdmin / the DBA workflow after `00_preflight.sql`
returns `OVERALL = READY`.

## What this runbook does

Adds a UNIQUE index on non-null program offerings:

`course_id + academic_program_id + academic_year_id + semester_id`

It does **not**:

- add `college_id`
- make `academic_program_id` or `department_id` NOT NULL
- rewrite or delete legacy rows
- change `courses.course_code` uniqueness
- change offering status values

Legacy rows with `academic_program_id IS NULL` remain valid. MySQL/MariaDB
NULL uniqueness allows multiple historical NULL-program rows.

## Files

1. `00_preflight.sql` — read-only. Continue only when `OVERALL = READY`.
   BLOCK only when the unique index cannot be applied safely (missing
   columns, conflicting existing index, or duplicate non-null identities).
   Legacy NULL context is reported, not a blocker.
2. `01_apply.sql` — idempotent `ALTER TABLE ... ADD UNIQUE INDEX`.
   Fail-closed: if `@apply_ready = 0`, the prepared statement is a no-op
   `SELECT`.
3. `02_verify.sql` — read-only. Require `OVERALL = PASS`.
4. `03_rollback.sql` — drops only `uq_course_offering_program_term`.
   No data changes.

## Fully qualified objects

Every object is written as `` `alrowad_uni_rust`.`table` `` so the scripts
remain valid when phpMyAdmin is scoped to `information_schema`.
