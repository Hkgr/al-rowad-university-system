# Grades API Contract

## Phase 2 additive Incomplete read projection

See [Phase 2 implementation and actual verification](../../docs/course-result-symbols-phase2.md). The Phase 1 six endpoints, mutation payloads, authorization and lifecycle ownership are unchanged. Deadline modification remains **PATCH**, not POST.

`GET /exams/incomplete-results` retains the paginator inside `data`, adds safe `student {id,number,full_name}`, `course {id,code,name}`, actual `academic_year`, `semester`, `offering` identities and `registration_status` per row. The actual registration/student match and independent offering scope are checked. Relations load for the page, not per row. Added `filter_options: {terms: [{academic_year_id,year_name,semester_id,semester_name}], complete, limit:100}` comes from the same authorized case scope; the selected student narrows options, state/period do not. Over 100 distinct pairs is explicit (`complete:false`); case pagination is not truncated. Strict original query allowlist and max100 per page remain.

Registration and case GETs retain every prior field and add those identities, required actual `components [{grade_component_id,name,type,max_mark,mark,editable,blocking_code}]`, `resolution {available,blocking_code}`, and nullable `current_result {id,status,theoretical_total,practical_total,final_mark,letter_grade,grade_points,is_official,result_announced_at}`. Completed non-NULL marks, including 0, are readonly. Resolution availability also checks actual component maxima/policy compatibility through GradeService. Missing components are not invented. No result stays NULL; pending and official result visibility are labelled separately. Case events additionally have `actor_label`, looked up in one query from recorded actor IDs (system expiry stays system).

These internal case reads require the existing Exam Affairs/manual-entry guard and independent student/offering scope, including the existing administrative office 735 authority. They expose no contact/account data. Reasons, requirements and actors never enter the public transcript/PDF projection. All GETs are read-only; no context, registration, calendar, grant or requirement is created.

The UI freezes method/target/UUID/revision/serialized payload for a write; no automatic retry. A user-selected exact replay after reading current state relies on Phase 1 replay-before-revision, not a replacement UUID. 409 retains baseline/proposed/current states until explicit review. Shared screen/PDF symbol helpers consume the existing safe `result_lifecycle` and official metrics; no new GPA, repeat or deadline formula is introduced. Phase 1 durable cache/atomic lock/scheduler requirements still apply. No Phase 2 schema or permission installation is needed.

## Base URL

`http://127.0.0.1:8000/api/v1`

## Introduction

This module manages course grades tied to a **student course registration** (`student_course_registration_id`). It supports viewing, creating, updating marks, and recalculating letter grades and result status. Related CRUD resources cover grading policies, grade components, appeals, approvals, and supplementary exams.

## Authentication Requirements

All endpoints require:

```http
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json
```

## Grading Business Rules

| Rule | Detail |
|------|--------|
| Theoretical mark | Out of **60** — `numeric\|min:0\|max:60` |
| Practical mark | Out of **40** — `numeric\|min:0\|max:40` |
| Final mark | `theoretical_mark + practical_mark` (max **100**) |
| Passing (default policy) | `theoretical_mark >= 15`, `practical_mark >= 10`, `final_mark >= 50` |
| Letter grade F | Counts as **0.00** grade points |
| F/Z | Zero points; credit hours enter the denominator, including an officially eligible persisted failure without numeric marks |
| W/I | Excluded from GPA/CGPA while W or unresolved I; public points are null, not a numeric zero |
| Deprived (Z) | Absence > 15%; cannot recalculate automatically |
| Dropped/withdrawn | Grading blocked; excluded from GPA |

### Letter grade scale (when passed)

A+ (≥98), A (≥95), A- (≥90), B+ (≥85), B (≥80), B- (≥75), C+ (≥70), C (≥65), C- (≥60), D+ (≥55), D (≥50), F (<50 or component fail)

### GPA / CGPA

- **GPA:** Credit-hour weighted average for a specific term.
- **CGPA:** Best attempt per course across all terms.
- Excludes: dropped/withdrawn/cancelled registrations; incomplete, pending-approval and withdrawn results. Z is not excluded.
- A later excluded W/I never removes a prior eligible F/Z attempt. Official approval remains mandatory.

---

## Endpoint List

### Registration grade operations

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/registrations/{id}/grades` | Get grades for registration |
| POST | `/api/v1/registrations/{id}/grades` | Enter grades (first time) |
| PUT | `/api/v1/registrations/{id}/grades` | Update existing grades |
| POST | `/api/v1/registrations/{id}/calculate-result` | Recalculate letter grade & status |

### Offering-level grade views

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/course-offerings/{id}/grade-sheet` | Full grade sheet |
| GET | `/api/v1/course-offerings/{id}/results-summary` | Pass/fail statistics |

### Student grade views

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/v1/students/{student}/transcript` | Transcript |
| GET | `/api/v1/students/{student}/gpa` | Term GPA |
| GET | `/api/v1/students/{student}/cgpa` | CGPA |

### CRUD resources

| Resource | Base path |
|----------|-----------|
| Grading policies | `/api/v1/grading-policies` |
| Grade components | `/api/v1/grade-components` |
| Student course results (read-only) | `/api/v1/student-course-results` |
| Grade appeals | `/api/v1/grade-appeals` |
| Grade approvals | `/api/v1/grade-approvals` |
| Grade audit logs | `/api/v1/grade-audit-logs` |
| Supplementary exam periods | `/api/v1/supplementary-exam-periods` |
| Supplementary exam results | `/api/v1/supplementary-exam-results` |

Raw student results support only GET list/detail. Raw grade-component routes are not exposed. Grade writes must use `/registrations/{id}/grades`, which enforces role, section ownership, and registration eligibility.

---

## GET /api/v1/registrations/{id}/grades

**Purpose:** Retrieve marks, letter grade, and result status for one registration.

**URL parameter:** `{id}` = `student_course_registration_id`

**Request body:** None

### Success response (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "registration": {
      "student_course_registration_id": 10,
      "registration_date": "2026-09-01",
      "registration_status": { "status_code": "registered", "status_name": "Registered" }
    },
    "student": {
      "student_id": 1,
      "student_number": "2026-0001",
      "full_name": "Ahmad Ali"
    },
    "course": {
      "course_id": 1,
      "course_code": "CS101",
      "course_name": "Intro to Programming",
      "credit_hours": 3
    },
    "theoretical_mark": 45.0,
    "practical_mark": 32.0,
    "final_mark": 77.0,
    "letter_grade": "B",
    "grade_points": 3.0,
    "result_status": { "status_code": "passed", "status_name": "Passed" },
    "notes": null
  }
}
```

---

## POST /api/v1/registrations/{id}/grades

**Purpose:** Enter grades for the first time.

### Request body

```json
{
  "theoretical_mark": 45,
  "practical_mark": 32,
  "notes": "Midterm included"
}
```

### Validation rules

| Field | Rules |
|-------|-------|
| `theoretical_mark` | `required\|numeric\|min:0\|max:60` |
| `practical_mark` | `required\|numeric\|min:0\|max:40` |
| `notes` | `nullable\|string` |

### Success response (201)

Same shape as GET grades response with calculated `final_mark`, `letter_grade`, `grade_points`, `result_status`.

### Error response (422)

```json
{
  "success": false,
  "message": "Grades already exist for this registration. Use update endpoint instead.",
  "errors": {}
}
```

Other errors: registration dropped/withdrawn; no marks when deprived.

### Frontend notes

- Validate marks client-side against 0–60 and 0–40 before submit.
- Show live preview: `final_mark = theoretical + practical`.
- Highlight component failures (< 15 theoretical or < 10 practical) even if final ≥ 50.

---

## PUT /api/v1/registrations/{id}/grades

**Purpose:** Update marks on an existing result.

### Request body

Same as POST.

### Validation rules

Same as POST.

### Success response (200)

Updated grade object (same shape as GET).

### Error response (422)

```json
{
  "success": false,
  "message": "No grades found for this registration. Use create endpoint first.",
  "errors": {}
}
```

---

## POST /api/v1/registrations/{id}/calculate-result

**Purpose:** Recalculate letter grade, grade points, and result status from stored marks using the active grading policy.

**Request body:** None

### Success response (200)

```json
{
  "success": true,
  "message": "Result calculated successfully",
  "data": {
    "registration_id": 10,
    "theoretical_mark": 45.0,
    "practical_mark": 32.0,
    "final_mark": 77.0,
    "letter_grade": "B",
    "grade_points": 3.0,
    "result_status": { "status_code": "passed", "status_name": "Passed" },
    "calculation_details": {
      "minimum_theoretical_mark": 15,
      "minimum_practical_mark": 10,
      "minimum_final_mark": 50,
      "theoretical_passed": true,
      "practical_passed": true,
      "final_passed": true
    }
  }
}
```

### Error response (422)

```json
{
  "success": false,
  "message": "Deprived results cannot be recalculated automatically.",
  "errors": {}
}
```

### Frontend notes

- Call after manual mark edits if UI needs refreshed letter grade without re-PUTting marks.
- Deprived (Z) results require administrative handling, not recalculation.

---

## GET /api/v1/course-offerings/{id}/grade-sheet

**Query:** `include_inactive` (boolean, default false)

### Success response (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "course_offering_id": 5,
    "course_code": "CS101",
    "course_name": "Intro to Programming",
    "students": [
      {
        "student_course_registration_id": 10,
        "student_number": "2026-0001",
        "full_name": "Ahmad Ali",
        "theoretical_mark": 45.0,
        "practical_mark": 32.0,
        "final_mark": 77.0,
        "letter_grade": "B",
        "grade_points": 3.0,
        "result_status": { "status_code": "passed" }
      }
    ]
  }
}
```

---

## GET /api/v1/course-offerings/{id}/results-summary

### Success response (200)

```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {
    "course_offering_id": 5,
    "total_registered_students": 30,
    "total_students_with_results": 28,
    "passed_count": 22,
    "failed_count": 4,
    "incomplete_count": 1,
    "deprived_count": 1,
    "withdrawn_count": 0,
    "average_final_mark": 68.5,
    "pass_rate": 78.57
  }
}
```

---

## Standard validation error (422)

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "theoretical_mark": ["The theoretical mark must not be greater than 60."],
    "practical_mark": ["The practical mark field is required."]
  }
}
```

### Frontend notes

- Grade entry UI should key off `student_course_registration_id` from the grade sheet.
- Display W/Z/I distinctly. W and unresolved I are excluded; Z contributes zero points and its credit hours once officially eligible.
- F shows 0.00 points but remains in GPA denominator.

## Course-result symbols completion — Phase 1 backend

### Ownership and compatibility

This phase changes backend semantics only. Frontend presentation/integration is Phase 2; final acceptance/deployment preparation is Phase 3. No new grade bands, passing thresholds, rounding, curriculum rules, progression thresholds, deregistration, financial rules or permission grants.

The read-only supplied `alrowad_uni_rust16-10.sql` was audited structurally: MariaDB 10.11.18, signed INT domain keys, unique registration/result, NOT NULL decimal(5,2) result totals, grade audit IDs signed BIGINT, and existing academic-plan/catalog revision triggers. No production records/credentials are imported or included in fixtures. The 12-page symbols PDF supplies F/Z/W/I meanings; explicit task decisions govern authority, summer and per-case F/Z expiry. Report2 `9dfe2a7523c973975b24f40d008c832d92573670` is reference only: its old service, global expiry policy, destructive rollback and deregistration changes are not imported.

### Persistence and authoritative results

Single installation owner: `backend/database/sql/course-result-symbols-phase1/00_preflight.sql` → `01_apply.sql` → `02_verify.sql`; `03_rollback.sql` explicitly refuses destructive downgrade. No parallel migration/seeder/schema-snapshot installation path.

- Two new business tables: `student_course_incomplete_cases` (one per registration for its lifetime) and `student_course_incomplete_events` (append-only). Restrictive FKs match the deployed signed INT parents; event IDs are signed BIGINT.
- The existing result row/ID is reused. Only `theoretical_total`, `practical_total`, `final_mark` become nullable decimal(5,2), default NULL. Existing zero values are not converted and old `incomplete` rows do not gain invented grants.
- Case state is `active`, `resolved` or `expired`. Revision is monotonic, independent of timestamps/value fingerprints. Original grant identity/time, reason, requirements and individually selected F/Z outcome are immutable. Deadline revisions preserve their previous calendar snapshot in events.
- Every state owns a result snapshot and matching revision event. Finalization validates the case/result/event/context and preserves its result instead of calculating it again. Ordinary grade/component/result-status writes return `409 incomplete_outcome_locked`; conflicting evidence returns `409 incomplete_outcome_conflict` without automatic repair.
- A documented expiry to F with missing required marks has NULL missing aggregates/final, `failed` status, zero grade points and GPA denominator hours once the offering is officially approved. It is a completeness exemption, not an approval exemption. Z remains a distinct deprivation, never a pass inferred from retained numeric marks.
- Existing completed components, including approved practical work, are preserved. Grant aggregates reflect available completed parts without inventing absent marks. Resolution only fills outstanding required marks and logs actual component changes; newly supplied marks are individually reviewed by Exam Affairs. It never resets offering/part approvals or publishes other students. Pending offerings still need canonical offering approval.
- Ordinary submission/review bulk component updates exclude all lifecycle-owned registrations. Required-component configuration is locked for these cases. Historical `completed` registration resolution is available only through the dedicated operation, not general grade editing.
- Registration ownership also applies at the canonical locked drop/withdraw/cancel boundaries and the registration model backstop. Every lifecycle state rejects these unsupported transitions with `409 incomplete_registration_transition_locked`, without a new cancellation operation or history rewrite. The supported `registered`/`completed` states and dedicated completed-registration settlement remain available.
- This does not add an override/correction workflow. Existing ordinary locks remain; a conflicting supplementary deferral/fixed roster/materialization is rejected explicitly. Materialized supplementary evidence is never rewritten. A terminal I outcome cannot be reopened by deadline editing.
- `result_announced_at` is preserved throughout grant/deadline/resolve/expire/finalization; lifecycle updates never restart an appeal window. System expiry has a null human actor, not the original grant actor.

### Authority and API

All routes below are under `/api/v1/exams/incomplete-results`, authenticated/active-account middleware and existing Exam Affairs access: actual exam officer with assigned `exams.manage`, `grades.manage`, current student read authorization and independent actual student/offering scopes. The established scoped Administrative VP office authority and central super-admin authorization behavior remain intact; they do not bypass academic validity locks. No permission/role/scope grants are added.

| Method | Relative URL | Input |
|---|---|---|
| GET | `/` | Optional `state`, `student_id`, `academic_year_id`, `semester_id`, `page`, `per_page` (1–100, default 15) |
| GET | `/registrations/{registration}` | Authorized current state, capabilities and deadline suggestion; no writes |
| POST | `/registrations/{registration}` | `request_id` UUID, `expected_revision:0`, `reason`, `requirements`, `expiry_outcome:F|Z`, optional `deadline_at` |
| GET | `/{case}` | Authorized case and append-only lifecycle history |
| PATCH | `/{case}/deadline` | New UUID, current `expected_revision`, `deadline_at`; no mandatory justification |
| POST | `/{case}/resolve` | New UUID, current `expected_revision`, optional `components:[{grade_component_id,mark}]` to fill outstanding marks |

Unknown action fields/filters/component fields and duplicate component IDs are rejected. Reasons/requirements must be nonblank, max 4000 characters (TEXT capacity); marks use actual component limits and at most two decimals, including genuine zero. No generic 60/40 payload is required; theoretical-only, practical-only and multi-component parts reuse the existing policy compatibility checks.

Successful writes return the existing envelope with `data:{case:{...},changed:true}`. Repeating the same UUID/content/actor returns its saved response **after current authorization and graph locks but before comparing current revision**. A UUID with a different target/action/content/actor returns `409 incomplete_request_conflict`; stale revisions return `409 incomplete_stale`. There is no automatic mutation replay/retry after uncertain responses.

Operational reads return `schema_ready`, `registration_id`, `state`, `is_official`, `legacy_incomplete_without_grant`, `case`, `overdue`, `allowed_operations:{grant,deadline_update,resolve}`, `blocking_code`, `deadline_suggestion`. States distinguish `no_result`, `ordinary_missing_components`, `pending_approval`, `official_result`, `incomplete_active`, `incomplete_resolved`, `incomplete_expired`. An untracked historical `incomplete` is not proof of a formal grant. Generic result and transcript/GPA reads also expose limited lifecycle metadata and mask unresolved I final marks/points as NULL.

Error meanings: 401 unauthenticated; 403 unauthorized/out of scope; 404 missing target; 422 invalid payload or `deadline_at` requiring an explicit manual deadline; 409 state/revision/settlement/supplementary conflict; 503 `course_result_symbols_schema_not_ready`. Reads report missing lifecycle readiness; there is no inferred installation/backfill on GET.

### Calendar and expiration

- Next semester uses academic-year start date plus `semester_order`, never identifier arithmetic: first → second → summer → first of the following year.
- A unique active `study_period` type and valid, published, non-superseded version on a non-cancelled event for the exact next term are required. `is_enforcement` is deliberately not required (`study_period` defaults to false). Future-year publication is allowed under existing calendar rules; global current-year registration-window fallback is not used.
- Local study start **date** is day 1. Deadline is the end of day 21 (`start local date + 20 days, 23:59:59 Asia/Damascus`), stored UTC at second precision. The final second is inclusive; expiration is strictly later. No current-date-plus-21 or academic-year-start substitute.
- Accepted calendar version/start/publication and rule/timezone are snapshotted. Later edits/cancellation do not silently rewrite existing cases. Missing/ambiguous calendar requires a manual deadline. Date-only manual inputs mean Damascus end-of-day; offset datetimes are normalized to UTC. New accepted deadlines must not already be past.
- An overdue **unresolved** case may be explicitly extended first, then resolved. Resolve checks time under locks even if cron has not run. A terminal case cannot be extended/reopened.
- `php artisan course-results:expire-incomplete --batch=100` examines at most 100 due cases per invocation (allowed 1–1000), each in its own transaction. It is scheduled every minute with `withoutOverlapping`; database locks/revisions provide correctness even for overlapping direct invocations. Conflicts are reported by case ID/error code, not silently repaired.
- A constant-size `(deadline_at, incomplete_case_id)` cursor in the configured persistent cache advances after each examined case, including a blocked case whose transaction rolled back. The next invocation continues after it; one bounded wrap revisits earlier unresolved cases. There are at most two bounded selection queries and no offset/full-dataset scan. A blocked oldest case therefore does not pin every scheduled invocation. The cursor is operational state, not academic history; it never changes a case, deadline, evidence or event to hide an error.
- The cache must survive separate scheduled processes and support atomic locks (shared by scheduler hosts if more than one is used). Volatile `array`/`null`/session/Octane/failover stores are rejected. Cache/lock/cursor-write failures are visible failures, not an in-memory fallback; per-case conflicts also emit a structured application warning and a nonzero command result. The command refuses an outer database transaction so every case really commits/rolls back independently. No cache table or configuration is created automatically by this feature.

### Consistency and historical reads

Affected grade/approval/manual/attendance/disciplinary/supplementary writers acquire the existing academic-catalog transaction boundary before their operational locks when the catalog is installed. I operations lock student, offering, approvals, registration, components/marks/result and case in a consistent graph; revisions/evidence are rechecked after locking. No remote operation is involved.

Official PHP and SQL GPA now share status eligibility: F/Z include absent-mark attempts at zero points; a `passed` row still needs genuine required numeric aggregates. Repeated attempts use the existing highest-points then highest-final-mark policy. W/I cannot erase earlier eligible failures. Numeric averages exclude NULL and unresolved I, including historical zero-valued I rows. Result counts/status distributions remain distinct from numeric averages.

Transcript history includes W even without a result; W is not added to `result_statuses`. A nonempty wholly withdrawn term reports `withdrawal_status:full_withdrawal` and `counts_towards_maximum_study_duration:true`; no study-duration enforcement/dismissal or GET-side term creation is added. This checks the actual registered/completed/withdrawn set, not just the visible result subset: another pending registration prevents a false full-withdrawal label. Dropped/cancelled rows do not become W.

Historical attempts, GPA denominator hours, earned hours and current registration workload are distinct. Official term metrics expose `gpa_denominator_credit_hours` and `registration_workload_hours` separately. Pending registration workload is not injected into the official transcript snapshot. Finalized `student_academic_terms` and progression/graduation decisions are not rewritten; term reads expose separate `current_metrics`, and new progression evaluations use current official calculations after feature installation rather than silently treating an old frozen snapshot as updated evidence. No standing thresholds change.

The supplied legacy `calculate_final_grade`, `mark_deprived_students`, `vw_student_transcript` have incompatible numeric/default/approval behavior; source tracing found no active application calls to those procedures/view. They are not adopted as the canonical calculation path or broadly rewritten here. Direct DBA invocation is outside the authorized application boundary; operators must not use them to alter lifecycle-owned outcomes. All active application grade write paths use the lifecycle guard and ordinary model/CRUD locks.

### Operator activation — instructions only, never executed on production

1. Take a recoverable backup and enter maintenance; stop affected grade/registration/catalog writers and scheduled workers. Do not run old application workers on the changed constraints/nullable marks.
2. In the explicitly selected target database, execute the package's read-only preflight and require READY; inspect signed keys, existing triggers and ownership. Apply the sole SQL package, then require verify PASS. It creates no grants, backfill, accounts or student cases.
3. Deploy the matching backend code together, clear/rebuild existing application caches through the authorized deployment process, and run `php artisan course-results:check`. The runtime database identity must be able to inspect the required metadata, including protective trigger bodies; invisible/missing definitions fail closed, not as permission to grant privileges automatically. Confirm the existing configured persistent cache is writable and supports atomic locks across scheduled invocations. The supplied snapshot has no usable study-period rows; `MANUAL_DEADLINE_REQUIRED` is a legitimate calendar limitation, not permission to invent dates.
4. Smoke-check authorized/denied reads and synthetic controlled operations on an isolated/staging database, verify the existing scheduler calls `schedule:run` every minute, then resume affected traffic. No frontend build or permission-grant command is required for Phase 1.
5. Rollback refuses by design. Never delete cases/events or replace NULL with fabricated zeros. Any recovery uses a separately authorized, tested backup restoration; do not resume incompatible old code against lifecycle data.

### Verification evidence

New behavioral coverage includes all-state outcome preservation, active I and expired F/Z during real approval/finalization, absent marks, retained passing components, historical completed resolution, component variants, rollback, UUID replay, deadline edits/boundaries/calendar/summer/year rollover, scope/authority and GPA/average parity through real HTTP projections. Existing executive fixtures were completed with real `user_roles.assigned_at` and `student_course_results.is_deprived`; the previous failed-attempt-with-missing-marks exclusion assertion is intentionally updated to the newly approved semantics, while passed attempts with missing required marks remain excluded.

The isolated MariaDB harness is `backend/tests/mariadb/course-result-symbols.ps1`; it starts an existing binary in a new marked temporary datadir/port with generated test credentials and checks actual server identity before any operation or shutdown. It uses synthetic projected legacy schema/records, not the supplied production dump. It tests populated upgrade, compatible partial resume, reapplication, restrictive FKs, original revision triggers, append-only events/history, and independent-process lock races. Existing university services are not started or stopped.

Executed verification and baseline limitations are recorded in the draft PR. MariaDB executable/server tested: **11.4.9-MariaDB**, not 10.11.18. SQLite HTTP tests and source contracts do not establish MariaDB compatibility or concurrency. No frontend/browser/production acceptance, no live university data, no deployment and no Phase 2 implementation are claimed.

Initial executed checks at `16a3ddc63256b4dd3f0af6101d32f9cd35918eed` (superseded by the review-follow-up results below):

| Check | Actual result |
|---|---|
| Phase 1 lifecycle + executive SQL/HTTP GPA + existing manual-entry suites + new contract wrapper | 73 tests, 793 assertions, PASS (includes 29 new lifecycle behavioral tests) |
| Expanded 15-file targeted regression selection | 298 tests, 2,375 assertions: 255 passed; 8 failures and 35 errors |
| Clean `develop` at `d73e93ab58ca630253ad759abd176f9e973500dd`, affected historical classes | The same 43 failing/error test identities reproduced; no additional failing identity in the expanded selection |
| Dependency-free selected grade/calendar/registration/transcript contracts | 17 PASS, 2 FAIL; new Phase 1 contract PASS |
| Isolated MariaDB package and independent-process races | PASS on `SELECT VERSION() = 11.4.9-MariaDB`; populated upgrade, blocked configuration/incompatible layout, resume/reapply, six race scenarios, terminal history/append-only constraints and rollback refusal |
| PHP syntax / Composer / whitespace | All 43 changed/new PHP files passed `php -l`; `composer validate --no-check-publish`, `composer check-platform-reqs`, and `git diff --check` passed |

The 43 historical failures/errors are: partial-theory supplementary fixtures (2; missing component timestamps), supplementary registration/runtime fixtures (23; schema-readiness/FK/Mockery assumptions), supplementary Phase 7 source checks (2), calendar Phase 2 fixtures (15; missing `users.username`), and a registration GradeBoundary assertion (1; expects 4.0 for mark 90 although the unchanged official band is 3.5). They were not rewritten here to hide their failures.

The two dependency-free failures are explicit old PR-scope assertions: `academic_calendar_schema_compatibility_repair_contract.php` requires the Phase 3 policy service to be absent (also fails on clean develop); `supplementary_exam_end_to_end_hardening_contract.php` forbids any SQL in the diff, incompatible with this task's required manual SQL package. Neither is represented as passing. No full-suite-green, exhaustive stress-test, MariaDB 10.11, or production-data acceptance is claimed.

### PR #167 review follow-up — installation, ownership and progress

`IncompleteSchemaContract` is the single server-owned definition for both runtime readiness and the rendered manual preflight/apply/verify package. `php backend/tools/render-incomplete-schema.php --check` checks drift without a database connection; `--write` only mechanically renders the three reviewed files, never applies them. There is no request-controlled SQL identifier or runtime DDL.

Validation now checks exact lifecycle column types (including signedness, capacities, datetime precision, nullability, defaults, generated/extra attributes and text charset/collation), table ownership/engine, named index column sequences/uniqueness/prefixes, FK source/target schema/table/columns and update/delete rules, required CHECK expressions, and trigger target/operation/timing/orientation and full protective bodies. Integer display widths and insignificant whitespace/backticks in CHECK serialization are normalized; significant expression structure and trigger bodies are not substituted with name/count checks. Extra unique indexes, columns, FKs, CHECKs and owned-table triggers are rejected; additional nonunique FK-support indexes are allowed. Core result marks must retain the exact decimal definition; runtime requires nullable marks.

All existing owned definitions are inspected **before** business DDL. An incompatible table or same-named no-op/wrong-target trigger blocks apply without creating other missing objects, changing academic data, or overwriting that definition. Apply creates only missing compatible tables/triggers, never `CREATE OR REPLACE TRIGGER`; recognized existing triggers retain their creation timestamps and bodies. A populated compatible trigger-only partial installation returns `RESUMED`; complete reapplication returns `ALREADY_APPLIED`. No new business schema, migration, repair/backfill or parallel installer was added by this follow-up. DDL remains nontransactional and still requires the maintenance procedure above.

Lifecycle operations take their identity preview before entering the catalog/domain transaction and retain the registration/result models obtained by current locking reads. Re-reading an ordinary registration after waiting could otherwise use a stale REPEATABLE READ snapshot. Academic identity changes are a controlled `incomplete_context_stale` conflict. The linked offering-closure entrypoint now also enters the existing catalog-first boundary before offering locks; no closure or cancellation policy is replaced.

The isolated installed-path fixture includes ready catalog/plan controls, an approved fixed plan, actual student assignments and membership references, plus whitelisted **existing repository** registration-plan and result/approval/audit epoch routines/triggers. It asserts `AcademicPlanContext::installed()` and actual catalog revision advancement. This is a synthetic projection exercising the real installed transaction path, not the entire production schema or a full academic-plan migration test. Fallback row-lock tests are retained separately.

Review-follow-up checks actually executed with existing dependencies:

| Check | Actual result |
|---|---|
| Lifecycle, contract wrapper, executive SQL/HTTP GPA and existing manual-entry suites | **84 tests / 896 assertions PASS**, including 40 lifecycle behavioral cases |
| Expanded 15-file selection | **309 tests / 2,478 assertions: 266 passed, 8 failures, 35 errors**; all 43 failing/error identities match the executed clean-develop baseline, no new failing identity |
| Additional Phase 5 modification suite | Discovery blocked by existing private `seed()` conflicting with Laravel's public `TestCase::seed()`; the fatal was reproduced on clean develop; no execution/pass claimed |
| Additional Phase 6 minimum/cancellation suite | 15 tests: 11 passed, 1 failure, 3 errors, 2 risky; the same four failing/error identities and counts were reproduced on clean develop (Carbon identity assertion and existing DataScope/Mockery/fixture assumptions) |
| MariaDB isolated package and concurrency harness | **OVERALL PASS, `SELECT VERSION() = 11.4.9-MariaDB`, `@@tx_isolation = REPEATABLE-READ`** |
| Independent-process concurrency | **22 scenarios**: nine fallback and nine installed-catalog scenarios observed two actual InnoDB lock waiters before release; four additional ordered grant-first/drop-first scenarios held the first domain lock and observed the second connection's in-flight `FOR UPDATE` before release |
| Named-but-incompatible MariaDB definitions | **11 negative cases PASS**: deadline type, reduced reason capacity, nullability, two unique-index sequence changes, wrong FK target/rule, weakened CHECK, no-op protective body, wrong trigger timing and wrong target. Each produced preflight BLOCKED, verify FAIL, runtime 503 and controlled apply rejection; data and rejected definitions were preserved |
| Compatible resume/reapply | PASS with populated history and unchanged retained trigger bodies/creation times; incompatible partial table rejected before creation of its missing companion |
| Expiration fairness | PASS in SQLite behavioral regression and **three separate MariaDB CLI processes** sharing only persistent file-cache progress: `batch=1`, blocked oldest unchanged, later valid case expired, blocked case revisited visibly |
| Static/pure checks | Phase 1 dependency-free contract and rendered-package drift check PASS; selected contracts remain 17 PASS / 2 known FAIL as described above; all 49 PHP files in the complete PR passed syntax checks, Composer validation/platform requirements and diff checks PASS |

Before the fix, the new active/drop behavioral case reproduced the unsupported transition, and the batch=1 fairness test reproduced starvation on its second invocation. After the fix they pass alongside registered-to-completed settlement and the other eight lifecycle/transition combinations. Tests use actual self-drop HTTP/service mutation paths and the canonical locked transition methods, not only model/source-string assertions.

The four ordered races supplement the 18 observed InnoDB-waiter scenarios; they are not claimed to have recorded an InnoDB wait-table row (MariaDB may show the pending locking statement during planning). No MariaDB 10.11 verification, full production-schema upgrade, exhaustive contention/stress, deployed scheduler/cache, frontend/browser, production-data or Phase 2 acceptance is claimed. Existing historical suite failures remain visible and unmodified. PR #167 remains DRAFT, open and unmerged; nothing was deployed or applied to production.
