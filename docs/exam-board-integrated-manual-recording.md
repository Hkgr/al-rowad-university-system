# Exam Board — integrated student-centered academic recording

## Base and scope

PR #128 was verified merged at `7572d366b6a4c0c215e724b950cd59b9231ddfce`. This corrective branch, `codex/exam-board-integrated-manual-grade-context`, starts at freshly fetched `origin/develop` `74a49e981e0ba53c3e60c0cf572113b91830258e` (the merge of #128). No other developer changes were overwritten. This is a functional correction, not a design-only refactor or a deployment.

The user explicitly authorized historical academic recording plus a narrowly scoped weekly-timetable exemption. No ordinary registration authority is changed.

## Operator journey (implemented, runtime verification pending)

Search student -> dedicated student page -> explicitly select actual year/semester -> enter local draft marks in a course row -> review marks, academic context, preparation, reason and unchecked responsibility acknowledgment -> save.

Period choices now include persisted academic-year/semester references independently of offerings. They are not automatically selected. The catalog still preserves actual offering/attempt choices; ambiguous contexts require selection. An authorized course with no offering, registration or components gets a read-only preview using current curriculum identity and authoritative grade policy. Resolved limits produce editable draft cells before any database write.

One confirmation invokes atomic preparation **and** the existing canonical draft-mark save. No visit to Dean or a technical component API is required for a supported course/term. Existing usable registrations retain the existing editor, correction reason, submission-readiness and offering-wide submission confirmation. No preview or Save operation submits, reviews, approves or finalizes grades.

The original Arabic RTL shell, colors, table primitives, native dialogs and responsibility wording are reused. Native keyboard Tab navigation and decimal LTR inputs are retained; no custom grading arithmetic is introduced in React.

## Endpoints and authorization

- GET `/api/v1/exams/manual-grade-entry/students/{student}/courses/{course}/context-preview`
- POST `/api/v1/exams/manual-grade-entry/students/{student}/courses/{course}/context-save`

Both use active account + actual effective `exam_officer` + assigned effective `exams.manage` and `grades.manage` + the existing Student read policy (`students.view`/DataScope). Course and offering scopes are independently checked. For an absent offering, `DataScopeService::canMutateProgram()` requires actual program/department/college/university scope, with no virtual super-admin or professor assignment bypass. A section-only operator cannot create a new program-wide context outside that authority.

Input permits actual year/semester and optional explicit offering/registration selection only. Save additionally requires preview revision, confirmation, acknowledgment, reason, correction confirmation where needed and strict `{key, mark}` components. Unknown keys, duplicate component keys, invalid precision, non-finite/negative/excessive values are rejected. Internal proposal keys are mapped to canonical component IDs by the server. There is no client bypass flag, supplied registration status, faculty/advisor identity or historical timestamp.

Existing routes remain compatible. `/periods` preserves `terms` and adds independent `academic_years` and `semesters` arrays. Preview performs no writes or row locks. Supplementary configuration preview and locked mutation share the same existing fixed/materialized-target predicate; a separate read entry merely avoids mutation locks.

## Explicit exception matrix

| Gate | Authorized recording boundary | Ordinary registration |
| --- | --- | --- |
| Student request/advisor approval | Exempt; no fabricated proof | Unchanged |
| Current academic year | Exempt; valid persisted historical year allowed | Unchanged |
| Student registration window | Exempt; no calendar event fabricated | Unchanged |
| Offering OPEN for enrollment | Exempt; reuse compatible CLOSED offering; new offering remains CLOSED | OPEN still required |
| Weekly timetable completeness and conflicts | Explicitly exempt; no slots or attendance created | Canonical checks remain mandatory |
| Active actor, actual role, assigned permissions, Student read policy, actual independent scope | Required again under locks | Unchanged |
| Actual course/program/year/semester identity | Required; new context uses shared `CourseOfferingContextService` | Unchanged |
| Current curriculum and requirement-group commitments | Retained for new registrations via shared registration/requirements logic | Unchanged |
| Passed-course prohibition and prerequisites | Retained for new registrations via official GradeService evidence | Unchanged |
| Credit limit | Retained canonical 18/21, calculated from official current GPA and actual selected-term registrations | Unchanged |
| Duplicate/ownership integrity | Existing compatible row reused; ambiguous attempts require selection; no second attempt manufactured to evade a lock | Unchanged |
| Existing dropped/withdrawn/cancelled or otherwise non-gradeable attempt | Not reactivated or reclassified by integrated preparation | Unchanged |
| Student existence/status | Existing non-deleted Student required. Current registration service has no additional student-status-code gate; none was invented or removed | Unchanged |
| Capacity | Existing registration semantics do not reserve/release legacy seats or enforce an additional seat gate; unchanged | Unchanged |
| Submitted/approved parts, official final approval, deprivation/deferral, fixed/materialized supplementary target | Remain blocking; no resets or overrides | Unchanged |

Important remaining academic limitations: current curriculum is not a historical curriculum archive. An old course absent from the current active curriculum, an unfulfilled prerequisite, an already officially passed course or exhausted credit/requirement allowance still prevents a **new** registration. These are specific backend errors, not instructions to create an offering elsewhere. Existing eligible attempts are reused under canonical grade-entry locks, not retroactively re-enrolled. No additional academic policy was silently waived.

## Ordinary records and consistency

`ExamManualGradePreparationService` coordinates the explicit boundary. `CourseOfferingContextService` resolves valid curriculum/program identity and creates a normal **closed** offering with no instructor. `RegistrationService::prepareManualGradeRegistration()` is the sole selector of server-owned `EXAM_MANUAL_RECORDING`. The same private registration persistence core creates the ordinary `registered` row, with the current processing date and actor, no advisor. The common academic eligibility block was extracted intact for read-only preview and shared mutation validation. Other materialization contexts still run timetable and OPEN checks; STUDENT_WINDOW still runs its deadline check.

Existing `RegistrationService::getSelfRegistrationOfferings()` filters `status=open`, so a new closed recording context is not offered for ordinary self-enrollment. `StudentCourseRegistration::allowsGradeEntry()` depends on the canonical registration status, not offering openness. Closed offerings therefore support canonical grading without reopening enrollment.

Grade components use `requiredRoles()` and `GradeService` policy compatibility, not a fixed 60/40 rule or teaching-hour ratio. Genuinely absent definitions are created. Valid existing multi-component definitions are reused without deleting, reweighting or replacing them. Partial, inactive, optional/extra, undefined or incompatible configurations fail with a specific error. A historical configuration cannot be guessed from absent evidence.

Save owns student -> course serialization lock (for absent context) -> offerings ascending -> approvals -> registrations -> components -> policy locks, then repeats authoritative resolution and revision validation. The existing offering identity unique constraint and student/offering uniqueness remain in force; no new schema is added. Independent manual preparations serialize on the course row; stale subsequent confirmation cannot duplicate context. True simultaneous MariaDB execution remains unverified here.

The existing `saveManualMarks()` writes draft component marks and `GradeAuditLog`, not official results. `UserActivityLog` records operator, reason, actual context, created/reused records and the exact five exemptions in the same transaction. Any preparation, validation, mark-save or audit failure rolls back the entire outer transaction. There is no committed half-preparation on a definite failed response. A lost response is nevertheless **uncertain**: the UI keeps proposed values, reads server state and requires explicit rebase/discard/reconfirmation. It never automatically retries a write or silently attaches a draft to new component IDs. Unrelated row drafts remain mounted.

The canonical submission/review/return/approval/finalization services are unchanged. Existing transcripts, GPA, requirements, academic-record export and executive reports consume the same final ordinary result; no manual-result class or downstream formula exists.

## Verification evidence and limitations

- Executed: 171 dependency-free Node tests, covering strict draft values, zero/null, explicit component-ID rebase, stale search/navigation/draft preservation and source integration. These are pure-logic/static checks, not React runtime evidence.
- Executed: 27/28 independent PHP contracts. The unchanged pre-existing `academic_calendar_schema_compatibility_repair_contract.php` fails because it expects the now-existing Phase 3 policy service to be absent.
- Executed successfully: syntax checks on all 15 changed/new PHP files, `composer validate --no-check-publish`, `composer check-platform-reqs --lock` and `git diff --check`.
- Added, **not executed**: `ExamManualGradePreparationBehaviorTest`, extending the real grid/entry fixtures, for no-context historical recording, closed reuse, zero/null/correction/stale handling, audit/invalid-mark rollback, ordinary incomplete/conflicting timetable rejection, retained curriculum/prerequisite/hour gates, ambiguity/forgery, single/multi-part configuration, official/supplementary locks, repeated confirmation and a full canonical finalization/transcript/GPA cycle.
- Existing `ExamManualGradeEntryBehaviorTest` and `ExamManualGradeGridBehaviorTest` remain included, including their distinct-context bounded-query regression. The earlier expectation that the authorized recording exception must obey the student window is updated only for the newly approved exception.
- Added to the existing local browser fixture: absent offering/registration draft input -> integrated review/save without navigation; another row's draft retention; lost context-save response -> read reconciliation -> explicit rebase/retry, without duplicate creation.
- **Not executed:** Laravel/PHPUnit (`backend/vendor` absent), frontend lint/build/component/browser/visual checks (`frontend/node_modules` absent), live backend integration and real multi-connection MariaDB concurrency. No dependencies installed, screenshot or production/visual acceptance claimed. The browser fixture uses mocked fetch, so even its later execution will not prove live Laravel integration.

No production SQL/dump execution, migration, seeder, new permission grant, database object, faculty/advisor/attendance fabrication, deployment or merge. This corrective PR must remain OPEN for runtime review.
