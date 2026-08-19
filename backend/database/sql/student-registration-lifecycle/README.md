# Student semester registration lifecycle (Phase 9)

Manual SQL runbook for add/drop, formal withdrawal, and live-workflow
hardening. Do **not** execute these files from application code, seeders,
or Laravel migrations. Run them only in phpMyAdmin / the DBA workflow.

Production database changes remain **MANUAL SQL only**.

## Existing workflow (preserved)

Canonical initial registration is unchanged:

Student draft request
  -> add/remove items
  -> submit
  -> Academic Advisor review
  -> returned **or** approved
  -> approved items materialize into `student_course_registrations`

Reused as-is:

- `StudentRegistrationRequest`
- `StudentRegistrationRequestItem`
- `StudentRegistrationRequestEvent`
- `RegistrationRequestService`
- statuses `draft` / `submitted` / `returned` / `approved`
- `submission_version`, approval-hour snapshots, eligibility, seats,
  prerequisites, credit-hour limits, program curriculum checks

Do not create another table for initial registration. Do not mutate an
approved initial request when withdrawing later.

## Lifecycle on `student_course_registrations`

Never DELETE a registration row to express state. Historical rows remain
permanent, including legacy `registered` / `dropped` / `withdrawn` rows
created before Phase 9.

| Transition | When | Who |
|---|---|---|
| (none) -> `registered` | Offering OPEN, advisor approval of the initial request | Academic Advisor materialization |
| `registered` -> `dropped` | Offering OPEN (add/drop period) | owning Student self-drop |
| `registered` -> `withdrawn` | Offering CLOSED, formal withdrawal request approved | Academic Advisor materialization |

DROP and WITHDRAWAL are not aliases.

## Direct-route changes (no hidden bypass)

These staff endpoints remain for historical callers (Bruno collections)
behind `registration.manage`, but they now reject live-semester use with
`registration_live_workflow_required`:

- `POST /api/v1/registrations/register-student`
- `POST /api/v1/registrations/{id}/drop`
- `POST /api/v1/registrations/{id}/withdraw`

`registration.manage` and Super Admin virtual `hasPermission()` grants
cannot silently bypass Academic Advisor governance. Internal
`RegistrationService::registerStudentWithinTransaction()` remains the
advisor approval materialization path only.

Student self-drop:

- `POST /api/v1/student/registration/{registration}/drop`

Withdrawal APIs:

- `POST /api/v1/student/registration/{registration}/withdrawal`
- `POST /api/v1/student/registration/withdrawals/{withdrawalRequest}/resubmit`
- `GET  /api/v1/student/registration/withdrawals`
- `GET  /api/v1/academic-advising/registration-withdrawals`
- `GET  /api/v1/academic-advising/registration-withdrawals/{withdrawalRequest}`
- `POST /api/v1/academic-advising/registration-withdrawals/{withdrawalRequest}/return`
- `POST /api/v1/academic-advising/registration-withdrawals/{withdrawalRequest}/approve`

## Authority matrix

| Actor | View withdrawals | Submit / resubmit | Review (return / approve) |
|---|---|---|---|
| Student (own identity) | own rows | own current `registered` row | no |
| Academic Advisor with assigned `registration_withdrawals.view` | scoped | no | no |
| Academic Advisor with assigned `registration_withdrawals.review` **and** actual `academic_advisor` role | via view | no | yes, DataScope |
| Dean | no (not expanded) | no | no |
| Generic `vice_president` | no | no | no |
| Super Admin virtual `hasPermission()` | GET may pass the view middleware | no | **no** — mutations require actual advisor role + assigned permission |

Existing Dean `registration_requests.view` / `registration_requests.review`
grants are preserved and **not** copied onto withdrawals.

Read-only GET uses `hasPermission()` (broader, consistent with other
inspection routes). Mutations use `User::effectivePermissions()` plus
`User::isAcademicAdvisor()`.

## Lock order

Canonical order for every mutation that shares seats or lifecycle status:

1. `students` (`student_id`)
2. `course_offerings` (`course_offering_id`, ascending when several)
3. `student_course_registrations` (`student_course_registration_id`, ascending)
4. current `student_registration_withdrawal_requests` when present

Initial `StudentRegistrationRequest` approval additionally locks that
request row first (distinct workflow-root table, never locked by drop or
withdrawal). Shared resources then follow 1–3.

Documented in `App\Support\RegistrationLifecycle`.

## Seat-count invariant

- registered create / dropped reactivation: `available_seats -= 1` exactly once
- `registered` -> `dropped`: `available_seats += 1` exactly once
- `registered` -> `withdrawn`: `available_seats += 1` exactly once
- retries must not apply the delta twice
- `available_seats` never becomes negative (row lock + guarded decrement)

## Reactivation

`findReactivatableRegistration()` reuses only `dropped` rows, and only
while the offering is OPEN and eligibility still passes.

Formally `withdrawn` rows in the same `course_offering_id` are not
reactivated. A later retake uses a different offering.

## Grades / attendance

Drop and withdrawal never delete attendance, grade components, grade
audit, or results. Those rows stay attached to the registration.

Authoritative lock already in the repository: offering-level
`GradeApproval` via `allowsGradeEditing()` (editable only when status is
`returned_for_correction`). If a latest approval exists and is locked
(`grades_locked`), late withdrawal is rejected. No new grade state model
was added. Attendance is keyed by student + offering and is left untouched.

## Legacy data

Existing `registered` / `dropped` / `withdrawn` rows remain readable.
No mass backfill of fake withdrawal requests. The formal withdrawal
workflow is prospective.

## Schema additions

- `alrowad_uni_rust.student_registration_withdrawal_requests`
- `alrowad_uni_rust.student_registration_withdrawal_events`
- unique `(student_course_registration_id, current_slot)` with
  `current_slot = 1` current and `NULL` history
- permissions `registration_withdrawals.view` and
  `registration_withdrawals.review` on module `registration`
- grants of those two permissions to `academic_advisor` only

Statuses: `submitted`, `returned`, `approved`, `superseded`.

## SQL deployment order

1. `00_preflight.sql` — read-only. Continue only when `OVERALL = READY`.
2. `01_apply.sql` — fail-closed, independently recomputes guards including
   the RBAC matrix. RBAC DML COMMITs only after post-write verification.
3. `02_verify.sql` — read-only. Require `OVERALL = PASS`.
4. `03_rollback.sql` — unused/absent/partial safe. `BLOCKED_IN_USE` when
   withdrawal history exists. Never drops original registration tables.
   Optional tables are never referenced inside statically parsed `IF()`.

Fully qualify `alrowad_uni_rust`. Never use `DATABASE()`.

## Acceptance scenarios

REG9-01 Student creates a normal registration request.
REG9-02 Student adds an eligible offering.
REG9-03 Prerequisite failure is rejected.
REG9-04 Credit limit exceeded is rejected.
REG9-05 No seats is rejected.
REG9-06 Student submits.
REG9-07 Advisor returns with reason.
REG9-08 Student resubmits.
REG9-09 Advisor approves; registrations materialize atomically.
REG9-10 Retry approval does not duplicate registrations.
REG9-11 Student drops own REGISTERED course while Offering OPEN.
REG9-12 Drop releases exactly one seat.
REG9-13 Retry drop does not release a second seat.
REG9-14 Student drop after Offering CLOSED is rejected (`registration_self_drop_closed`).
REG9-15 Student withdrawal on OPEN Offering is rejected (`registration_withdrawal_requires_closed_offering`).
REG9-16 Student submits withdrawal on CLOSED Offering with reason.
REG9-17 Other student cannot request withdrawal (`registration_not_owned`).
REG9-18 Advisor returns withdrawal with reason.
REG9-19 Student resubmits returned withdrawal.
REG9-20 Advisor approves -> `registered` -> `withdrawn`.
REG9-21 Withdrawal releases a seat exactly once.
REG9-22 Second approval cannot materialize twice (`registration_withdrawal_already_materialized`).
REG9-23 Registration dropped before withdrawal approval -> request superseded, committed, then HTTP 409 (`registration_withdrawal_stale`).
REG9-24 Registration already withdrawn before approval -> stale/superseded.
REG9-25 Super Admin virtual permission cannot impersonate Academic Advisor for mutation.
REG9-26 Wrong role + review permission is rejected.
REG9-27 Advisor role without assigned review permission is rejected.
REG9-28 Legacy withdrawn registration remains readable.
REG9-29 Withdrawn registration cannot silently reactivate.
REG9-30 Dropped registration may reactivate only through the valid OPEN registration flow.
REG9-31 Attendance history untouched.
REG9-32 Grade history untouched.
REG9-33 Concurrent drop vs withdrawal -> exactly one lifecycle transition wins.
REG9-34 Concurrent duplicate registration approval -> one registration only.
REG9-35 `available_seats` never becomes negative.
REG9-36 `registration.manage` direct API cannot bypass the normal live workflow.

SQL-REG9:

- clean DB -> READY
- partial compatible -> READY
- conflicting object -> BLOCKED
- apply -> APPLIED
- rerun -> APPLIED / idempotent
- verify -> PASS
- rollback absent -> safe
- rollback unused -> safe
- rollback after history -> BLOCKED_IN_USE
- RBAC drift between preflight/apply -> apply BLOCKED
- optional table absent rollback -> no `#1146` error
