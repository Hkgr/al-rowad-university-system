# Exam Board student course grid

Base: `3f9a3d1151b1a7157551b5ba2909d497ad824d31` (`origin/develop`, including merged #126 and #127).
Branch: `codex/exam-board-student-grade-grid`.

## Audit and read model

The old `ExamManualGradeEntryService::registrations()` starts from registrations and cannot show unregistered courses. The old page also combines search and editors in one route. Both existing registration/mark APIs remain compatible; the new student page reads a catalog projection instead.

`Course.courseDepartments/departments` (`course_departments`) and `Course.programCourses/academicPrograms` (`program_courses`, academic program -> department -> college) are the existing catalog mappings. The student college is resolved from the student's current academic program. The new query unions these college memberships, active student-program curriculum membership (including mapped shared/university requirements), and existing authorized student registrations outside the catalog. SQL existence predicates deduplicate course IDs. `catalog_source` and `own_program` distinguish these cases; `CourseRequirementClassification` supplies existing requirement presentation.

Course scope uses actual university/college/department/program/section scope, without virtual super-admin or teaching-assignment access. Student and offering scope are independently checked. Actual year/semester restrict offering choices, not catalog membership. Every persisted offering and attempt in the selected context is returned distinctly; multiple choices require explicit selection. The catalog is paginated, with batched offering/registration/component/mark reads and controlled limits of 500 offering contexts/1,000 registrations per page (narrow the period on overflow; no silent truncation).

## API additions

All paths are below `/api/v1/exams/manual-grade-entry`:

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/students/{student}/catalog` | Catalog, terms, student identity and batched grade contexts |
| GET | `/students/{student}/periods` | Independent scoped actual-period choices, including after catalog overflow |
| GET | `/students/{student}/offerings/{offering}/component-preview` | Read-only policy-derived proposal |
| POST | `/students/{student}/offerings/{offering}/components` | Confirm the preview revision and prepare missing components |
| POST | `/students/{student}/offerings/{offering}/registration` | Explicit exceptional registration confirmation and reason |

All require an active account, actual effective `exam_officer`, assigned effective `exams.manage` and `grades.manage`, existing student-read policy, actual student scope and independent offering scope. Component creation also uses existing `ResourceAuthorizationService` configuration authority. No permission grants or role mappings were added. Unknown request fields are rejected. GET performs no mutation or row locking.

## Explicit registration exception

`RegistrationService::prepareManualGradeRegistration()` is the authoritative exception boundary, not the controller or marks-save endpoint. It locks student -> offering -> grade approvals -> registrations, reauthorizes, and verifies the confirmed course/year/semester against the persisted offering. Only the student-request/advisor-proof prerequisite is waived. No request, review, advisor assignment or approval is fabricated.

| Gate | Behavior |
| --- | --- |
| Student/offering access and academic identity | Required under locks |
| New registration: offering OPEN, current actual year, student program/current curriculum | Existing `assertSelfRegistrationAllowed()` retained |
| Original registration student window | Existing `STUDENT_WINDOW` deadline evaluation retained |
| Passed course, prerequisites, canonical 18/21-hour limit, requirement commitments | Existing registration persistence core retained |
| Official timetable completeness/conflicts | Existing schedule service retained |
| Capacity | Existing semantics retained; no legacy `available_seats` reservation/release introduced |
| Duplicate same offering / same course in the same term | Reuse a single eligible existing row; reject ambiguity and duplicate academic attempts |
| Dropped/withdrawn/cancelled/ineligible existing row | Not reclassified or silently replaced |
| Part submission/approval, final official approval, supplementary fixed/materialized roster | Block preparation; canonical mark guards remain separately authoritative |

Creation calls the existing `registerStudentWithinTransaction()` persistence core with server-derived IDs and actor, never the disabled `registerStudent()` endpoint. Existing suitable registrations are reused without changing their academic identity. The `UserActivityLog` entry (`grades`, `manual_grade_entry.registration`) records the reason, safe context IDs, reuse and exact waived prerequisite in the same transaction. Audit failure rolls back creation. No marks or results are created here.

Historical limitation: this is **not** a waiver of current-year, OPEN-offering, curriculum or calendar requirements for new registrations. Existing eligible historical registrations remain usable under canonical grading rules. Creating arbitrary historical/out-of-program registrations would need separate explicit policy authority and is not supported by this change.

## Component preparation

Required parts come from `CourseOfferingInstructorCoverageService::requiredRoles()`. Limits come from `GradeService::gradingPolicyLimits()` and `assertRequiredPartsPolicyCompatible()`, not teaching hours or a hardcoded 60/40 split. A single-part course works only when the canonical policy supports it. Undefined delivery parts or incompatible policy produce actionable errors.

Preparation locks the offering, part approvals, canonical supplementary configuration structures, components and default policy. It checks the preview revision, finality and configuration again. Only an entirely absent configuration is created. An already identical configuration is an unaudited no-op, including repeated confirmation of the same proposal. Partial, optional, inactive, extra, weighted or incompatible definitions are refused, not repaired/reweighted. Existing marks are untouched. `manual_grade_entry.components` is written to the existing activity log transactionally.

There is no existing dedicated frontend grade-component configuration page in this repository. Incompatible definitions require an explicitly authorized correction through the existing generic `grade-components` configuration API; this grid does not invent a route or silently repair them.

## Frontend and canonical downstream flow

Search remains `/exam-board/manual-grade-entry`; selection navigates to `/exam-board/manual-grade-entry/students/:studentId`. Both use the existing authorized Exam Board shell. The latter direct-loads official identity and a compact RTL table, with actual context selectors, LTR component inputs, server state, zero/blank distinction and separate preparation actions.

The existing editor persistence/readiness/correction logic is extracted into table rows. Drafts remain keyed by registration, with separate baseline/proposal/server revisions. Saving B does not clear A; stale writes require explicit rebase/discard. Context changes require explicit draft discard. Router blockers cover sidebar and POP navigation; pending writes cannot be discarded, while authorization loss immediately clears sensitive state. Registration/component preparation also participates in pending-write guards and uncertain-write recovery, without automatic retries.

Marks still save only draft `StudentGradeComponent` values with canonical `GradeAuditLog` records. Sending a part still requires offering-wide readiness and confirmation. Review/return/approval/finalization remain in the existing queue. No special result class, transcript, GPA, requirement or executive-report treatment is introduced.

## Verification evidence and limits

- Executed: 168 dependency-free Node tests (pure logic/source contracts); changed PHP syntax checks; `composer validate --no-check-publish`; `composer check-platform-reqs --lock`; `git diff --check`.
- Executed: 26/27 dependency-free PHP contracts pass. The pre-existing `academic_calendar_schema_compatibility_repair_contract.php` fails because it requires `AcademicCalendarPolicyService.php` to be absent; both the assertion and that service already exist unchanged in the base.
- Added real Laravel coverage in `ExamManualGradeGridBehaviorTest`: zero-registration catalog/period independence, deduplication, independent scope/sections, preparation/idempotency/incompatible configurations, single/undefined parts, audit rollback, official-lock revalidation, exception without student/advisor request and the inherited complete canonical grade cycle, calendar/identity/role denials, bounded query counts.
- **Not executed:** PHPUnit (`backend/vendor` absent), frontend lint/build/component/browser/visual checks (`frontend/node_modules` absent). The updated local browser fixture contains populated editable rows and sidebar/back/forward/conflict scenarios; it was not rendered. No screenshots or production verification are claimed.
- **Not verified:** true multi-connection MariaDB lock scheduling/concurrent preparation. SQLite coverage is not a substitute for it. Visual acceptance and runtime integration remain pending; static tests do not establish merge-readiness.

No production SQL/dump executed; no migrations, schema objects, permissions, dependencies, grade/appeal/supplementary workflow changes, or global design changes.

## PR #128 review corrections

Reviewed/fetched starting head: `b9ffa9016b41f637cbd69d56f2672109263c7a50`. The changes update this PR only.

| Finding and reproduction | Focused fix | Verification and limitations |
| --- | --- | --- |
| Search `Fixture`, then add/remove surrounding spaces: the old input handler cleared results while the normalized effect dependencies stayed unchanged. Changing intent also allowed the old request to finish during debounce; clearing could retain loading/errors. | A synchronous intent generation invalidates/aborts old reads before debounce. Equivalent trimmed input preserves the current request/results/page. Applied query and page travel together; stale success/error/finally callbacks cannot update state. Clearing resets results, errors and loading immediately. | Three executed Node lifecycle regressions cover normalization/pagination, clearing in-flight reads and delayed responses during debounce (including returning to an earlier query). The actual React/router fixture additionally exercises these interactions, but was **not executed** because frontend dependencies are absent. |
| Initial catalog request with 501 offering contexts returns 422 before the UI obtains `terms`, leaving no way to narrow the request. | New GET-only `periods` lookup uses the same student authorization and actual offering scope. Its loading/error/retry state is independent of the catalog. Period selectors always render, remain explicitly unselected initially, and remain usable after overflow. Existing catalog `terms`, limits and response shape remain compatible; no truncation or automatic period selection. | Added real HTTP/SQLite regression: 501 contexts -> 422 -> independent two-period lookup -> explicit year/semester -> one offering, with no registration/audit writes. Added scope/student denial and unknown-input coverage. Executed Node source/path contract; added actual browser overflow/recovery scenario. Laravel and browser coverage is **unexecuted**, not reported as passed. |
| The grid subclass installs the production student/offering unique pair, but the inherited bounded-query test inserted six duplicate pairs. | The parent regression now creates six distinct courses/offerings with required components and one student registration per offering. Both classes retain the inherited test. It asserts seven returned registrations and preserves the original query-count ceiling (`first + 2`). The subclass unique constraint is unchanged. | PHP syntax and source contract executed. Reviewed both fixture schemas and copied columns/keys for compatibility. Both `ExamManualGradeEntryBehaviorTest` and `ExamManualGradeGridBehaviorTest` remain **unexecuted** because `backend/vendor` is absent. |

Relevant changed files: the context service/controller/routes; the two Laravel behavior classes and grid contract; student search page/lifecycle helper; grid page/path helper; focused Node tests and the existing browser fixture; this report. No registration-exception, audit, mark-save, submission/review/finalization, authorization-policy, schema, permission-grant or design implementation was changed by these corrections.

Runtime commands still required in an environment with existing dependencies: `php artisan test --filter='ExamManualGrade(Entry|Grid)BehaviorTest'`, `npm run lint`, `npm run build`, and the local `/tests/browser/manual-grade-review.html` fixture under Vite. The fixture intercepts API responses: even when executed it verifies components/router, not live Laravel integration or production visual acceptance. No dependencies were installed and no CI execution is assumed. PR remains intended OPEN and unmerged.
