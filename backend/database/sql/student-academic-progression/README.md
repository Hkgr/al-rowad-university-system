# Formal academic record, progression, and graduation (Phase 10)

Manual SQL runbook. Do **not** execute these files from application code,
seeders, or Laravel migrations. Run them only in phpMyAdmin / the DBA
workflow.

Production database changes remain **MANUAL SQL only**.

SQL deployment order:

1. `00_preflight.sql` — continue only when OVERALL = READY
2. `01_apply.sql` — continue only when apply_status = APPLIED
3. `02_verify.sql` — continue only when OVERALL = PASS
4. `03_rollback.sql` — only if unused and OVERALL is not BLOCKED_IN_USE

## Existing academic record logic reused

Phase 10 does **not** add a second transcript, GPA, or graduation-requirements
calculator.

| Concern | Canonical source |
|---|---|
| Official academic attempt | `GradeService::officialAcademicAttempts()` — academic-attempt registrations with a result whose offering latest `grade_approvals` row is `approved` |
| Transcript | `GradeService::getTranscript()` |
| GPA / CGPA / overview | `GradeService::calculateGpa()`, `calculateCgpa()`, `getGpaOverview()` |
| Repeated courses | `highest_attempt_only` via `selectBestAttempts()` |
| Curriculum completion | `GraduationEligibilityService::evaluate()` / `assertEligible()` |
| Requirement mapping | `AcademicRequirementService` |

Draft, submitted-but-not-final, returned, and unapproved grade parts are
excluded because they never enter `officialAcademicAttempts()`.

## Canonical GPA scale and graduation GPA policy

`GradeService::getGpaOverview()` publishes `scale.maximum = 4.0`. Letter
C- (final mark ≥ 60) maps to **2.00** grade points. The published study
rules require 60% or 2.0/4.0. The repository stays on the 4.0 scale
without conversion.

`App\Support\GraduationGpaPolicy`:

- scale: `4.0`
- minimum cumulative GPA: `2.0`
- published percentage equivalent (documentation only): `60`

## Current bypasses removed

| Path | Before | After |
|---|---|---|
| `POST/PUT/PATCH/DELETE /api/v1/student-academic-terms` | Generic CRUD including client `term_gpa` / `cumulative_gpa` / `academic_level_id` | `academic_term_workflow_required`; finalized rows `academic_term_finalized` |
| `PUT/PATCH /api/v1/students/{student}` `current_academic_level_id` | Direct write | `academic_level_progression_workflow_required` |
| `PUT/PATCH /api/v1/students/{student}` into `graduated` | Eligibility check then write | `graduation_decision_workflow_required` |
| `PUT/PATCH /api/v1/students/{student}` out of `graduated` | Generic status write | `graduation_decision_workflow_required` |

Generic Student mutation rejects **both** entering and leaving `graduated`.
Phase 10 does not implement graduation reversal. Safe profile fields
(name, phone, address, etc.) remain writable for a graduated student.
`academic_program_id` is still a generic update (program transfer is out
of scope). A program change supersedes any current progression/graduation
decision.

Read routes remain:

- `GET /api/v1/student/transcript`
- `GET /api/v1/student/gpa-overview`
- `GET /api/v1/student/requirements`
- `GET /api/v1/student/graduation-eligibility`
- `GET /api/v1/student-academic-terms` (index/show)

## Authority matrix

Existing roles (schema seed): `super_admin`, `university_president`,
`vice_president`, `university_secretary_general`, `dean`,
`head_of_department`, `doctor_instructor`, `academic_advisor`,
**`registration_officer`**, `exam_officer`, `finance_officer`,
`hr_officer`, `librarian`, `board_member`, `student`.

There is **no** `student_affairs` role. Student Affairs dashboards and
`AcademicAuthorizationService::assertStudentAffairs()` already map to
`students.manage`, which P0-1 assigns to `registration_officer`.

Phase 10 authority:

| Actor | View records / progression / graduation | Finalize terms / submit / return / approve |
|---|---|---|
| `registration_officer` + assigned Phase 10 permission | yes (DataScope) | yes (actual role **and** `effectivePermissions()`) |
| Super Admin virtual `hasPermission()` | GET middleware may pass | **no** |
| Generic `vice_president`, `dean`, `exam_officer` | no (not granted) | no |
| Student self | existing transcript/GPA/eligibility only | no |

Permissions (students module):

- `academic_records.view` / `academic_records.finalize`
- `academic_progression.view` / `academic_progression.review`
- `graduation_decisions.view` / `graduation_decisions.review`

`students.manage` is **not** the final mutation authority.

## Progression evidence model

Calculated evidence (not an automatic promotion):

- student / program / current level / candidate next level (`academic_levels.level_order`, constrained by active `program_courses`)
- term GPA, cumulative GPA, earned/attempted hours (GradeService)
- official completed courses, failed courses
- incomplete/unfinalized academic work
- graduation eligibility (GraduationEligibilityService) when evaluable
- classification: `ready_for_review` or `blocked_incomplete_results`

No guessed promotion thresholds. The authorized actor submits
`promoted` or `retained`. Next level must be the immediately next active
program level. No skip, no backward move, no fake next level at the end.

Term GPA for formal progression:

- latest real semester in the requested year comes from GradeService official
  attempt chronology (`officialTermChronologyKey`), not from
  `student_academic_terms.semester_id DESC`
- a **finalized** `StudentAcademicTerm` may be used only for that exact
  student / academic year / semester identity
- an **unfinalized** or missing term row must never override GradeService
  official results for that same identity
- a finalized earlier semester must never override a later semester that has
  official academic activity
- approval revalidates the same canonical evidence under lock

## Term finalization

`student_academic_terms` is a system-computed snapshot. New columns:

- `is_finalized` TINYINT(1) NOT NULL DEFAULT 0
- `earned_hours` / `attempted_hours` INT NOT NULL DEFAULT 0
- `finalized_at` TIMESTAMP NULL DEFAULT NULL
- `finalized_by_user_id` INT NULL DEFAULT NULL, `fk_sat_finalized_by` → `users.user_id`

Existing unique key `uq_student_term (student_id, academic_year_id, semester_id)`
is required. Preflight BLOCKED on duplicate identity; apply never
deduplicates.

Finalization is allowed only after `AcademicRecordGraphLocker` and only when
`GradeService::unfinalizedAcademicWorkForTerm()` is empty for that exact
student / academic year / semester. That helper reuses the canonical
`unfinalizedAcademicWork()` official-result definition (academic-attempt
registrations: `registered` / `completed`). Dropped or withdrawn
registrations do not require a final result. Recalculation may still write a
provisional snapshot. Incomplete official work returns HTTP 409
`academic_results_not_final` and must not set `is_finalized = 1`.

## Canonical graduation student status

`student_statuses.status_code = graduated` (schema seed id 3). SQL
preflight BLOCKED if missing. Graduation materialization updates
`students.student_status_id` to that existing row. Registrations, grades,
attendance, and term history are not rewritten.

## Lock order

Compatible with grade finalization (`CourseOffering` then registrations)
and Phase 9 (`Student` then `CourseOffering` then registrations):

1. `students` (`student_id`)
2. involved `course_offerings` (`course_offering_id` ASC)
3. `student_course_registrations` (`student_course_registration_id` ASC)
4. `student_academic_terms` (year, semester, id ASC)
5. current progression or graduation decision
6. event / materialization inserts

Documented in `App\Support\AcademicRecordWorkflow`.

## Stale handling

If identity/results/eligibility change before final materialization:

1. `status = superseded`, `current_slot = NULL`, event `progression_stale` or `graduation_stale`
2. COMMIT
3. HTTP 409 `academic_progression_stale` / `graduation_decision_stale`

Never throw inside `DB::transaction` if that would roll back the supersede.

## Legacy compatibility

Historical students remain readable without generated Phase 10 decision
rows. Formal workflows are prospective. Insufficient evidence returns
`ready_for_review` / blocked incomplete results rather than a guessed
promotion. Existing term rows are **not** mass-finalized.

## Staff APIs

- `GET  /api/v1/academic-records/students/{student}/terms`
- `POST /api/v1/academic-records/students/{student}/terms/{year}/{semester}/recalculate`
- `POST /api/v1/academic-records/students/{student}/terms/{year}/{semester}/finalize`
- `GET  /api/v1/academic-progression`
- `GET  /api/v1/academic-progression/students/{student}/evaluate`
- `GET  /api/v1/academic-progression/{progressionDecision}`
- `POST /api/v1/academic-progression/{student}/submit`
- `POST /api/v1/academic-progression/{progressionDecision}/return`
- `POST /api/v1/academic-progression/{progressionDecision}/approve`
- `GET  /api/v1/graduation-decisions`
- `GET  /api/v1/graduation-decisions/{graduationDecision}`
- `POST /api/v1/graduation-decisions/{student}/submit`
- `POST /api/v1/graduation-decisions/{graduationDecision}/return`
- `POST /api/v1/graduation-decisions/{graduationDecision}/approve`

## Acceptance scenarios

AC10-01 transcript unchanged (GradeService reused)
AC10-02 GPA overview canonical
AC10-03 graduation eligibility endpoint functional
AC10-04 client cannot create term with arbitrary term_gpa
AC10-05 client cannot PATCH cumulative_gpa
AC10-06 term snapshot from official results
AC10-07 draft/unapproved grade excluded
AC10-08 term finalization idempotent
AC10-09 finalized term not generically changeable
AC10-10 generic Student update cannot change current_academic_level_id
AC10-11 generic Student update can change safe profile fields
AC10-12 progression evidence identifies current and candidate level
AC10-13 normal progression cannot skip levels
AC10-14 no next level → no fake promotion
AC10-15 unfinalized results block progression finalization
AC10-16 authorized actor approves promoted decision
AC10-17 promotion updates current_academic_level_id exactly once
AC10-18 retry approval does not promote twice
AC10-19 current level change before approval → superseded, COMMIT then 409
AC10-20 program change before approval → stale/superseded
AC10-21 Super Admin virtual permission cannot impersonate reviewer
AC10-22 wrong role + permission rejected
AC10-23 correct role without assigned permission rejected
AC10-24 graduation eligibility reuses GraduationEligibilityService
AC10-25 graduation GPA uses GradeService 4.0 / GraduationGpaPolicy 2.0
AC10-26 missing curriculum requirements → graduation blocked
AC10-27 below GPA minimum → blocked
AC10-28 eligible student may submit graduation decision
AC10-29 approval revalidates eligibility
AC10-30 materializes existing `graduated` status
AC10-31 generic Student PATCH cannot mark graduated
AC10-32 retry graduation approval idempotent
AC10-33 eligibility change → superseded COMMIT then 409
AC10-34 historical registrations unchanged
AC10-35 historical grades unchanged
AC10-36 attendance unchanged
AC10-37 legacy student without Phase 10 decision remains readable
AC10-38 duplicate term identity → preflight BLOCKED, no destructive dedupe
AC10-39 concurrent grade finalization vs progression: lock offerings first, recheck official state
AC10-40 concurrent graduation approvals materialize exactly once
AC10-41 one approved and one non-final result in the target term → finalize HTTP 409 `academic_results_not_final`; no `is_finalized` mutation
AC10-42 all relevant target-term academic attempts have official final results → finalize succeeds and snapshots official term evidence
AC10-43 unfinalized `StudentAcademicTerm` has stale `term_gpa` after later official grades → progression evidence ignores the mutable snapshot and uses GradeService
AC10-44 finalized `StudentAcademicTerm` may be used as the immutable official term snapshot for formal progression
AC10-45 graduated student generic PATCH of phone (or address) is allowed
AC10-46 graduated student generic PATCH of `student_status_id` to active is blocked by formal graduation workflow protection
AC10-47 academic year has finalized Semester 1 snapshot and official Semester 2 results with no snapshot → progression `term_gpa` uses Semester 2 GradeService chronology, not the newest `student_academic_terms` row
AC10-48 Semester 2 has a finalized `StudentAcademicTerm` → that exact snapshot is used

SQL-AC10: clean READY; partial compatible READY; conflict BLOCKED; apply APPLIED;
rerun idempotent; verify PASS; legacy duplicate term BLOCKED; missing graduation
status BLOCKED; wrong authority matrix BLOCKED; RBAC drift apply BLOCKED;
wrong UNIQUE/NON-UNIQUE fail; wrong engine fail; rollback absent safe;
rollback unused safe; rollback after history BLOCKED_IN_USE; optional table
absent rollback no #1146; pre-existing compatible RBAC survives rollback.
SQL-AC10-17 existing progression table has correct column names but wrong type → 00 BLOCKED/CONFLICT, 01 BLOCKED, 02 FAIL
SQL-AC10-18 FK has correct constraint name but wrong referenced target → 00 BLOCKED/CONFLICT, 01 BLOCKED, 02 FAIL
SQL-AC10-19 `fk_sat_finalized_by` wrong target → 00 BLOCKED, 01 BLOCKED, 02 FAIL
SQL-AC10-20 Phase 10 required infrastructure table missing → 00 OVERALL BLOCKED, no #1146
SQL-AC10-21 pre-existing compatible unused term finalization columns → rollback does not delete them without provenance (`retained_no_provenance`)
SQL-AC10-22 named NON-UNIQUE Phase 10 index exists as UNIQUE → 00 CONFLICT, 01 BLOCKED, 02 FAIL
SQL-AC10-23 `student_progression_decisions.student_id` INT NOT NULL DEFAULT 1 → 00 CONFLICT/BLOCKED, 01 BLOCKED, 02 FAIL
SQL-AC10-24 `updated_at` missing ON UPDATE CURRENT_TIMESTAMP → 00 BLOCKED, 01 BLOCKED, 02 FAIL
SQL-AC10-25 `submitted_at` unexpectedly has ON UPDATE CURRENT_TIMESTAMP → 00 BLOCKED, 01 BLOCKED, 02 FAIL
SQL-AC10-26 timestamp/default/extra definitions match intended DDL → compatible / APPLIED / PASS

Exact compatibility (00/01 CONFLICT, 02 FAIL) covers column names, data types,
lengths/precision/scale, nullability, defaults, AUTO_INCREMENT, primary key,
InnoDB, named indexes and column order, UNIQUE vs NON-UNIQUE, and every FK
source column plus referenced table/column. `CREATE TABLE IF NOT EXISTS` never
repairs an incompatible existing table. Missing required tables are detected
from `information_schema` first; business-table queries use guarded
PREPARE/EXECUTE so OVERALL is BLOCKED/FAIL rather than SQL error #1146.
Rollback never drops Phase 10 tables/columns or `fk_sat_finalized_by` because
provenance cannot be proven.

## Validation

- `php -l` on changed PHP
- `git diff --check`
- focused PHPUnit contract tests
- SQL **not** executed in this change
- no Laravel migrations added
