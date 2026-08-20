# Phase 2 — Dean Supplementary Exam Course Offerings

Manual SQL only. Cursor / agents must **not** execute these files.

Database: `alrowad_uni_rust`

Fully qualify every application table as `` `alrowad_uni_rust`.`table_name` ``.

`information_schema` filters use `table_schema = 'alrowad_uni_rust'`. Never use `DATABASE()`.

A `USE \`alrowad_uni_rust\`;` statement is **not** used.

**DO NOT use Laravel migrations.**
**DO NOT use seeders.**
**DO NOT execute `01_apply.sql` unless `00_preflight.sql` returns `OVERALL = READY`.**

Ownership marker:

`[phase2-supplementary-exam-offerings]`

## Academic concept

A supplementary exam offering means: this course is available for **theoretical** examination in this supplementary examination period.

It is **not** a `CourseOffering`, teaching offering, semester registration, attendance, teaching assignment, a new academic course attempt, or a `StudentCourseRegistration`.

This pack **never** creates, updates, or deletes:

- `course_offerings`
- `student_course_registrations`
- `supplementary_exam_results`
- `supplementary_exam_periods`
- `supplementary_exam_period_events`

## Phase 1 is a hard dependency

Phase 2 does **not** recreate Phase 1 schema.

Before apply, the target database must already have the governed Phase 1 contract:

- `supplementary_exam_periods` with `status`, `opened_by_user_id`, `opened_at`, `decision_note`
- canonical UNIQUE on `academic_year_id` + `semester_id` (semantic column order; physical index name is not an invariant)
- `supplementary_exam_period_events` full contract
- permissions `supplementary_exams.periods.view` and `supplementary_exams.periods.decide`

If Phase 1 is not deployed: `OVERALL = BLOCKED` with `blocker_code = PHASE1_NOT_DEPLOYED`. Do not partially apply Phase 2.

## New tables

### `supplementary_exam_offerings`

Canonical identity (UNIQUE):

`supplementary_exam_period_id` + `academic_program_id` + `course_id`

College is **not** stored. It is derived from AcademicProgram → Department → College.

Phase 2 statuses: `open`, `closed`. No DELETE workflow.

### `supplementary_exam_offering_sources`

Academic provenance. One supplementary offering may attach **all** qualifying original `course_offerings` (especially in summer).

UNIQUE: `supplementary_exam_offering_id` + `course_offering_id`

Future student eligibility (Phase 3, not implemented here) must be able to ask whether a student's original `StudentCourseRegistration.course_offering_id` exists in this table.

### `supplementary_exam_offering_events`

Audit: `opened`, `closed`, `reopened`. Mutations and event rows are atomic in application code.

## Source eligibility (application, not this SQL)

A source `CourseOffering` is eligible only when it was **actually offered**:

1. Source row exists
2. Same `academic_year_id` as the supplementary period
3. Non-null `academic_program_id`
4. Program inside the Dean's accessible college
5. Source semester_order allowed by policy
6. At least one `StudentCourseRegistration` whose `RegistrationStatus.status_code` is `registered` or `completed`

`ProgramCourse` / curriculum presence is **not** sufficient.
`dropped` / `withdrawn` are **not** proof.
Source `CourseOffering.status` may be `CLOSED`. Do not require `OPEN`.

Semester policy uses `semester_order`, never hard-coded `semester_id`:

| Period semester_order | Allowed source semester_order |
| --- | --- |
| 1 | `[1]` |
| 2 | `[2]` |
| 3 (summer) | `[1, 2, 3]` same academic year |
| anything else | fail closed |

Summer future student max is **3** courses. This pack does **not** create student registrations and does **not** enforce that limit.

## Permissions

| Code | Maps to |
| --- | --- |
| `supplementary_exams.offerings.view` | `dean` |
| `supplementary_exams.offerings.manage` | `dean` |

Manage is **not** mapped to `vice_president_scientific`, `vice_president_administrative`, `vice_president`, `super_admin`, `registration_officer`, or `exam_officer`.

Scientific VP view is **not** guessed here. Downstream reader mappings belong to later portal phases.

Module: existing `exams` (`system_modules.module_code = 'exams'`).

## SQL execution order

1. `00_preflight.sql` — READ ONLY. Continue **only** when `OVERALL = READY`.
2. `01_apply.sql` — idempotent schema + RBAC. Independently recomputes preflight guards. Performs **no** Phase 2 DDL/RBAC if guards fail.
3. `02_verify.sql` — READ ONLY. Must say `OVERALL = PASS`.
4. `03_rollback.sql` — **emergency only**. Fail-closed if any offering/source/event row exists (`BLOCKED_IN_USE`).

Every object: **ABSENT** → create, **COMPATIBLE** → adopt, **CONFLICT** → refuse to modify the unknown object.

MySQL DDL auto-commits. Rerun after a partial failure must safely continue.

phpMyAdmin: do not use `SIGNAL`, `DELIMITER`, stored procedures, or `DATABASE()`. Each file **ends** with one simple visible `SELECT`.
