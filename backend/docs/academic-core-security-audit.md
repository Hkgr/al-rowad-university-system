# Academic-core security audit (Phase 11)

**CODE HARDENING READY** for the academic-core code paths listed below.

**DATABASE DEPLOYMENT VERIFICATION PENDING.** This document does not claim
the system is production-ready. Manual DBA verifier outputs are required;
see `backend/docs/production-academic-core-checklist.md`.

## Audited surface

Routes under `backend/routes/api.php` (Sanctum group + public login),
policies (`StudentPolicy`, `CourseOfferingPolicy`,
`StudentCourseResultPolicy`, `StudentDocumentPolicy`),
`DataScopeService`, academic workflow services (course offering
open/close, teaching assignment, registration, grades, academic record,
progression, graduation), login/token middleware, and destructive
student deletion.

## Real findings fixed

| Class | Finding | Fix |
|---|---|---|
| BLOCKER | `POST /api/login` had no throttle | `throttle:login` — 5/minute per normalized email + IP |
| BLOCKER | Login did not write `login_audit_logs` | `LoginAuditService` records success / failed / inactive |
| BLOCKER | API catch-all `Throwable` turned 401/403/429 into HTTP 500 | Explicit `AuthenticationException`, `AuthorizationException`, `ThrottleRequestsException`, HTTP exceptions |
| BLOCKER | `forceDestroy` ignored Phase 9/10 history and could delete an active unused student | Archive is required first (`student_permanent_delete_requires_archive`); history still HTTP 409 `student_permanent_delete_blocked`; decision is `lockForUpdate` + transactional |
| SHOULD HARDEN | Generic offering update could write non-open/closed `status` and `available_seats` | Status and seats stripped; `available_seats` prohibited on update; create seats = capacity |
| SHOULD HARDEN | PATCH `capacity` could desync `available_seats` | Offering-first lock; occupied = current registered rows; `available_seats = capacity - occupied`; HTTP 409 `course_offering_capacity_below_occupied` |
| SHOULD HARDEN | Login skipped bcrypt when the email was unknown | Constant-cost `Hash::check` against a dummy bcrypt hash; success audit only after `createToken` |
| SHOULD HARDEN | Academic queues loaded unbounded result sets | Bounded pagination (`per_page` max 100) on progression, graduation, registration, and withdrawal queues |
| SHOULD HARDEN | Term identity `QueryException` catch-all hid real DB errors | Convert only MariaDB 1062 / `uq_student_term` |
| SHOULD HARDEN | Registration duplicate detector treated every SQLSTATE `23000` as duplicate | Convert only 1062 / `uq_student_course_offering` |

## Accepted legacy surfaces

Left unchanged because they are already fail-closed or intentionally
read-only:

- `CourseOfferingInstructorController` POST/PATCH/DELETE →
  `TeachingAssignmentException::workflowRequired()`
- `RegistrationController` register/drop/withdraw →
  `RegistrationException::liveWorkflowRequired()`
- Super Admin virtual `hasPermission()` for **read** visibility, including
  DataScope bypass for Super Admin reads
- Login-audit-logs API is `index`/`show` only
- Grade legacy endpoints remain behind `assertOfferingGradesEditable()` /
  `grades_locked` / `legacy_grade_workflow_disabled`
- CORS explicit origins + `supports_credentials=false` (not
  wildcard+credentials)
- Generic student PATCH already blocks level and graduated enter/leave

## Authorization matrix

Sensitive academic **mutations** require **actual role** plus assigned
permission from `effectivePermissions()`. `User::hasPermission()` alone is
not used for governance mutations (Super Admin virtual grants must not
become a mutation bypass).

| Workflow | Actual role | Assigned permission |
|---|---|---|
| Scientific VP review | `vice_president_scientific` | `teaching_assignments.review_scientific` |
| Administrative VP review | `vice_president_administrative` | `teaching_assignments.review_administrative` |
| Dean teaching-assignment manage | `dean` | `teaching_assignments.manage` |
| Academic advisor registration/withdrawal | `academic_advisor` | assigned review/view permissions |
| Academic record / progression / graduation mutations | `registration_officer` | Phase 10 `academic_records.*` / `academic_progression.*` / `graduation_decisions.*` |

Generic `vice_president` cannot impersonate either dedicated VP.

## DataScope findings

Object-level `DataScopeService` remains the only scope framework.

- Student show/update/delete: policy + `canAccessStudent`
- Course offering show/update: policy + `canAccessOffering`
- Progression/graduation show/approve: `canAccessStudent`
- Advisor request access: `canStaffAccessStudent` + scoped indexes
- Collection indexes for offerings, students, and academic queues are scoped
- Super Admin remains an intentional **read** scope bypass

## Destructive-operation safeguards

Permanent student delete is allowed only for an unused archived shell.
An active/non-trashed student is rejected with HTTP 409
`student_permanent_delete_requires_archive`. The request never archives and
force-deletes in one step. The destroy path locks `Student::withTrashed()`
with `lockForUpdate()`, then checks archive + blocking history before
`forceDelete()`. A remaining MariaDB parent-row FK race (errno 1451) maps
to `student_permanent_delete_blocked` without leaking SQLSTATE.

Blocking categories (safe names, never SQL constraint names):

`registrations`, `attendance`, `documents`, `academic_terms`,
`course_results`, `grade_components`, `registration_requests`,
`withdrawal_requests`, `progression_decisions`, `graduation_decisions`,
`disciplinary_cases`, `grade_appeals`

Optional Phase 9/10 tables are probed with `Schema::hasTable()` so missing
schema does not become HTTP 500. Soft archive remains.

Formal academic history continues to be superseded / inactivated /
soft-deleted where models already support that. Legacy instructor and live
registration mutation routes stay fail-closed.

## Concurrency / idempotency conclusions

No lock-order inversion was found between offering opening/closure and
teaching assignment. Canonical order:

1. `course_offerings`
2. teaching-assignment / exception / closure requests
3. reviews
4. `course_offering_instructors`

Registration remains student → offering → registrations.
Academic record remains student → offerings → registrations → terms →
current decision (`AcademicRecordGraphLocker`).

Retry/idempotency contracts from Phases 5–10 are preserved
(commit-before-409 stale supersede, unique `current_slot = 1`).
Phase 11 adds regression source contracts; it does not redesign those
workflows.

## Authentication hardening

- Login throttle: 5 attempts / minute / (`strtolower(email)` + IP)
- HTTP 429 `too_many_requests` (does not reveal whether the email exists)
- Invalid credentials remain the generic 422 message for unknown email and
  wrong password. One bcrypt `Hash::check` always runs; missing users are
  checked against a dummy hash constant, never a plaintext credential.
- `login_status = success` is recorded only after `createToken` succeeds
- Audit rows: `user_id`, `username_attempted`, `login_status`, `ip_address`,
  `user_agent`, `attempted_at` — never password, token, or hash
- If `login_audit_logs` is missing, login still succeeds and the write is
  skipped (operational gap to close by applying the existing table, not a
  new audit subsystem)

## Remaining operational requirements

- `APP_DEBUG=false` in production (env-driven; not hardcoded)
- Manual SQL verifiers Phase 8–11 `OVERALL = PASS`
- Bounded `per_page` on remaining generic list endpoints that still accept
  unbounded integers (queues listed above are now bounded)

## DB verification gate

See `backend/docs/production-academic-core-checklist.md` and
`backend/database/sql/system-hardening-audit/`.

The Phase 11 SQL audit is **READ ONLY**. Missing required tables or
required columns yield `OVERALL = FAIL` without `#1146` / `#1054`. It
reuses the exact Phase 8–10 current-slot UNIQUE index contracts and the
existing academic-governance mutation permission codes. Persisted
teaching-assignment `action_type` values are exactly `assign` and
`remove`; `replace` is not a stored action type. It does not apply schema.

## Known out-of-scope risks

No 2FA/SSO, no WAF/Redis/Kubernetes project, no financial/library/Ministry
integrations, no graduation certificate PDF, no new frontend. Those remain
future work.
